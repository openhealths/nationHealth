<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Concerns;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\CarePlanStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Repositories\CarePlanRepository;
use App\Repositories\CarePlanActivityRepository;
use App\Services\MedicalEvents\EHealthJobResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

trait ManagesCarePlanLifecycle
{
    public function sign(CarePlanRepository $repository, CarePlanActivityRepository $activityRepository): void
    {
        try {
            $validated = $this->validate($this->rulesForSigning());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());
            $this->showSignatureModal = false;

            return;
        }

        if ($this->actionType === 'sign_activity') {
            $this->signActivity($repository, $activityRepository);

            return;
        }

        if ($this->actionType === 'sign_eprescription') {
            $this->signEPrescription();

            return;
        }

        if ($this->actionType === 'sign_servicerequest' || $this->actionType === 'sign_devicerequest') {
            $this->signReferral();

            return;
        }

        if (in_array($this->actionType, ['complete_activity', 'cancel_activity'])) {
            $this->signStatusActivity($activityRepository);

            return;
        }

        if ($this->actionType === 'cancel_prescription') {
            $this->signCancelPrescription();

            return;
        }

        if ($this->actionType === 'cancel_referral') {
            $this->signCancelReferral();

            return;
        }

        if (empty($this->carePlan->uuid)) {
            if ($this->actionType === 'sign_plan') {
                $this->signPlan($repository);

                return;
            }
            Session::flash('error', __('care-plan.care_plan_not_synced'));
            $this->showSignatureModal = false;

            return;
        }

        $this->carePlan->loadMissing(['encounter', 'encounterIdentifier', 'effectivePeriod', 'author', 'categoryConcept.coding']);

        $systemMap = [
            'cancel' => 'eHealth/care_plan_cancel_reasons',
            'complete' => 'eHealth/care_plan_complete_reasons',
        ];

        $statusReasonCodeableConcept = [
            'coding' => [
                [
                    'system' => $systemMap[$this->actionType] ?? 'eHealth/care_plan_cancel_reasons',
                    'code' => $this->statusReason,
                ]
            ]
        ];

        $payloadForSign = $this->buildCarePlanStatusChangePayload($statusReasonCodeableConcept);

        Log::info('CarePlanShow: Original JSON payload for signing: ' . json_encode(
            Arr::toSnakeCase($payloadForSign),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        ), [
            'actionType' => $this->actionType,
        ]);

        try {
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($payloadForSign),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            // Send to eHealth based on action type
            $apiMethod = $this->actionType === 'complete' ? 'complete' : 'cancel';

            $eHealthResponse = EHealth::carePlan()->{$apiMethod}(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                [
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64',
                    'status_reason' => $statusReasonCodeableConcept,
                ]
            );

            $responseData = $eHealthResponse->getData();
            $finalResponse = $responseData;

            // Job Polling
            if (isset($responseData['links'][0]['href']) && str_contains($responseData['links'][0]['href'], '/jobs/')) {
                $jobId = str_replace('/jobs/', '', $responseData['links'][0]['href']);
                $jobApi = EHealth::job();
                $attempts = 0;
                do {
                    sleep(2);
                    $finalResponse = $jobApi->getDetails($jobId)->getData();
                    $attempts++;
                } while ($finalResponse['status'] === 'pending' && $attempts < 15);
            }

            if (($finalResponse['status'] ?? null) === 'failed') {
                throw new EHealthValidationException($finalResponse);
            }

            // Extract status
            $carePlanStatus = $finalResponse['status'] ?? $payloadForSign['status'];
            if (isset($finalResponse['result']) && is_array($finalResponse['result'])) {
                $entity = $finalResponse['result'][0] ?? $finalResponse['result'];
                $carePlanStatus = $entity['status'] ?? $carePlanStatus;
            }

            // Update local state
            $repository->updateById($this->carePlan->id, [
                'status' => $carePlanStatus,
            ]);

            $this->refreshCarePlan();

            Session::flash('success', __('care-plan.care_plan_updated'));
            $this->showSignatureModal = false;

        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlanShow: connection error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.connection_error'));
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            if (method_exists($exception, 'report')) {
                $exception->report();
            }
            Log::error('CarePlanShow: eHealth error: ' . $exception->getMessage(), [
                'details' => method_exists($exception, 'getDetails') ? $exception->getDetails() : null
            ]);
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : __('care-plan.ehealth_error_prefix') . $exception->getMessage();
            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanShow: unexpected error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.unexpected_error'));
            $this->showSignatureModal = false;
        }
    }

    private function signPlan(CarePlanRepository $repository): void
    {
        $legalEntity = legalEntity();

        // Build eHealth payload from model
        $carePlanPayload = removeEmptyKeys([
            'intent' => 'order',
            'status' => CarePlanStatus::DRAFT->value,
            'category' => is_array($this->carePlan->category) ? ($this->carePlan->category['coding'][0]['code'] ?? null) : $this->carePlan->category,
            'instantiates_protocol' => $this->carePlan->clinical_protocol ? [['display' => $this->carePlan->clinical_protocol]] : null,
            'context' => $this->carePlan->context ? ['identifier' => ['type_code' => $this->carePlan->context]] : null,
            'title' => $this->carePlan->title,
            'period' => array_filter([
                'start' => $this->carePlan->period_start ? $this->carePlan->period_start->format('Y-m-d') : null,
                'end' => $this->carePlan->period_end ? $this->carePlan->period_end->format('Y-m-d') : null,
            ]),
            'addresses' => $this->carePlan->addresses, // Already stored as array of diagnoses
            'supporting_info' => array_merge(
                array_map(fn ($e) => ['display' => $e['name']], $this->carePlan->supporting_info['episodes'] ?? []),
                array_map(fn ($m) => ['display' => $m['name']], $this->carePlan->supporting_info['medical_records'] ?? [])
            ),
            'encounter' => $this->carePlan->encounter?->uuid ? ['identifier' => ['value' => $this->carePlan->encounter->uuid]] : null,
            'care_manager' => ['identifier' => ['value' => Auth::user()?->activeDoctorEmployee()?->uuid]],
            'description' => $this->carePlan->description ?: null,
            'note' => $this->carePlan->note ?: null,
            'inform_with' => $this->carePlan->inform_with ?: null,
        ]);

        try {
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($carePlanPayload),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            $eHealthResponse = EHealth::carePlan()->create($this->carePlan->person->uuid, [
                'signed_data' => $signedContent,
                'signed_data_encoding' => 'base64',
            ]);

            $responseData = $eHealthResponse->getData();

            // Update local model
            $repository->updateById($this->carePlan->id, [
                'uuid' => $responseData['id'] ?? null,
                'status' => $responseData['status'] ?? 'new',
                'requisition' => $responseData['requisition'] ?? null,
            ]);

            $this->refreshCarePlan();

            Session::flash('success', __('care-plan.signed_and_sent'));
            $this->showSignatureModal = false;

        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlanShow: connection error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.connection_error'));
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            if (method_exists($exception, 'report')) {
                $exception->report();
            }
            Log::error('CarePlanShow: eHealth error: ' . $exception->getMessage(), [
                'details' => method_exists($exception, 'getDetails') ? $exception->getDetails() : null
            ]);
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : __('care-plan.ehealth_error_prefix') . $exception->getMessage();
            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanShow: unexpected error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.unexpected_error'));
            $this->showSignatureModal = false;
        }
    }

    private function signActivity(CarePlanRepository $repository, CarePlanActivityRepository $activityRepository): void
    {
        if (!$this->activityToSign) {
            Session::flash('error', __('care-plan.no_activity_selected'));
            $this->showSignatureModal = false;

            return;
        }

        $activity = $activityRepository->findById($this->activityToSign);
        if (!$activity) {
            Session::flash('error', __('care-plan.activity_not_found'));
            $this->showSignatureModal = false;

            return;
        }

        if (empty($activity->uuid)) {
            $activity->uuid = \Illuminate\Support\Str::uuid()->toString();
            $activity->save();
        }

        if (str_contains(strtolower((string) $activity->kind), 'device') && empty($activity->program)) {
            Session::flash('error', __('care-plan.device_program_required_before_sign'));
            $this->showSignatureModal = false;

            return;
        }

        if (method_exists($this, 'getDeviceSignReadinessWarning')) {
            $deviceWarning = $this->getDeviceSignReadinessWarning($activity);
            if ($deviceWarning !== null) {
                Session::flash('error', $deviceWarning);
                $this->showSignatureModal = false;

                return;
            }
        }

        if (str_contains(strtolower((string) $activity->kind), 'device')) {
            $employee = Auth::user()?->activeDoctorEmployee();
            $uuids = [
                'person_uuid' => $this->carePlan->person->uuid,
                'encounter_uuid' => $this->carePlan->encounter?->uuid,
                'employee_uuid' => $employee?->uuid,
                'legal_entity_uuid' => $employee?->legalEntity?->uuid,
            ];

            try {
                $prequalifyPayload = $activityRepository->buildDevicePrequalifyPayload($activity, $this->carePlan, $uuids);
                $jobResolver = app(EHealthJobResolver::class);
                $prequalifyResponse = EHealth::deviceRequest()->prequalify(
                    $this->carePlan->person->uuid,
                    $prequalifyPayload
                );
                $jobResolver->assertPrequalifyValid($jobResolver->resolve($prequalifyResponse->getData()));
            } catch (EHealthValidationException $exception) {
                Session::flash('error', $exception->getTranslatedMessage());
                $this->showSignatureModal = false;

                return;
            }
        }

        // Build Payload
        $activityPayload = $activityRepository->formatCarePlanActivityRequest($activity);
        Log::info('CarePlanActivity: Signing activity ID=' . $activity->id . ', UUID=' . $activity->uuid, [
            'payload' => $activityPayload,
            'snake_case_payload' => Arr::toSnakeCase($activityPayload)
        ]);

        try {
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($activityPayload),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );
            Log::info('CarePlanActivity: Signing key succeeded');

            $eHealthResponse = EHealth::carePlanActivity()->create(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                [
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64',
                ]
            );

            $responseData = $eHealthResponse->getData();
            Log::info('CarePlanActivity: EHealth response received', ['response' => $responseData]);
            $finalResponse = $responseData;

            // If it is an async job, poll it
            if (isset($responseData['links'][0]['href']) && str_contains($responseData['links'][0]['href'], '/jobs/')) {
                $jobId = str_replace('/jobs/', '', $responseData['links'][0]['href']);
                Log::info('CarePlanActivity: Polling job: ' . $jobId);
                $jobApi = EHealth::job();
                $attempts = 0;
                do {
                    sleep(2);
                    $finalResponse = $jobApi->getDetails($jobId)->getData();
                    $attempts++;
                    Log::info("CarePlanActivity: Job {$jobId} attempt {$attempts} status: " . ($finalResponse['status'] ?? 'unknown'));
                } while ($finalResponse['status'] === 'pending' && $attempts < 15);
            }

            Log::info('CarePlanActivity: Final response from eHealth/Job', ['final_response' => $finalResponse]);

            if (($finalResponse['status'] ?? null) === 'failed') {
                Log::error('CarePlanActivity: Job failed in eHealth', ['final_response' => $finalResponse]);
                throw new EHealthValidationException($finalResponse);
            }

            // Extract the actual CarePlanActivity data
            $activityUuid = $finalResponse['id'] ?? null;
            $activityStatus = $finalResponse['status'] ?? 'new';

            if (isset($finalResponse['result']) && is_array($finalResponse['result'])) {
                $entity = $finalResponse['result'][0] ?? $finalResponse['result'];
                $activityUuid = $entity['id'] ?? $activityUuid;
                $activityStatus = $entity['status'] ?? 'active';
            }

            // If the job was processed but we didn't find the activity uuid directly, try parsing from links
            if (empty($activityUuid) && isset($finalResponse['links']) && is_array($finalResponse['links'])) {
                foreach ($finalResponse['links'] as $link) {
                    if (isset($link['href']) && str_contains($link['href'], '/activities/')) {
                        $activityUuid = basename($link['href']);
                        break;
                    }
                }
            }

            if ($activityStatus === 'processed') {
                $activityStatus = 'scheduled';
            }

            // Store to Mongo
            /*
            try {
                \App\Models\MedicalEvents\Mongo\CarePlanActivity::create($finalResponse);
            } catch (\Exception $e) {
                Log::warning('Failed to save CarePlanActivity to Mongo: ' . $e->getMessage());
            }
            */

            $activityRepository->updateById($activity->id, [
                'status' => $activityStatus,
                'uuid' => $activityUuid,
            ]);

            // Sync parent Care Plan to catch status transition (e.g., Draft -> Active) triggered by activity creation
            try {
                $planResponse = EHealth::carePlan()->getDetails($this->carePlan->person->uuid, $this->carePlan->uuid);
                $repository->syncCarePlans(['data' => [$planResponse->getData()]], $this->carePlan->person_id);
                $activityRepository->syncActivities($this->carePlan->person, $this->carePlan);
            } catch (\Exception $e) {
                Log::warning('CarePlanShow: failed to sync plan status or activities after activity creation: ' . $e->getMessage());
            }

            $this->refreshCarePlan();
            Session::flash('success', __('care-plan.activity_signed'));
            $this->showSignatureModal = false;

        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlanActivity: connection error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.connection_error'));
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            if (method_exists($exception, 'report')) {
                $exception->report();
            }
            Log::error('CarePlanActivity: eHealth error: ' . $exception->getMessage(), [
                'exception' => $exception,
                'errors' => method_exists($exception, 'getErrors') ? $exception->getErrors() : null
            ]);
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getTranslatedMessage()
                : __('care-plan.ehealth_error_prefix') . $exception->getMessage();
            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanActivity: unexpected error: ' . $exception->getMessage(), [
                'exception' => $exception
            ]);
            Session::flash('error', __('care-plan.unexpected_error'));
            $this->showSignatureModal = false;
        }
    }

    private function signStatusActivity(CarePlanActivityRepository $activityRepository): void
    {
        if (!$this->activityToSign) {
            Session::flash('error', __('care-plan.no_activity_selected'));
            $this->showSignatureModal = false;

            return;
        }

        $activity = $activityRepository->findById($this->activityToSign);
        if (!$activity) {
            return;
        }

        $systemMap = [
            'cancel_activity' => 'eHealth/care_plan_activity_cancel_reasons',
            'complete_activity' => 'eHealth/care_plan_activity_complete_reasons',
        ];

        $statusReasonCodeableConcept = [
            'coding' => [
                [
                    'system' => $systemMap[$this->actionType] ?? 'eHealth/care_plan_activity_cancel_reasons',
                    'code' => $this->statusReason,
                ]
            ]
        ];

        // Sign the same payload shape that was used at activity creation (formatCarePlanActivityRequest),
        // not the enriched eHealth GET response — eHealth validates against the original signed content.
        $payloadForSign = $this->cleanActivityPayload(
            $activityRepository->formatCarePlanActivityRequest($activity)
        );

        if (!isset($payloadForSign['detail'])) {
            $payloadForSign['detail'] = [];
        }

        $currentStatus = $activity->status;
        if (strtolower((string) $currentStatus) === 'processed') {
            $currentStatus = 'scheduled';
        }
        $payloadForSign['detail']['status'] = $payloadForSign['detail']['status'] ?? $currentStatus;
        if (strtolower((string) ($payloadForSign['detail']['status'] ?? '')) === 'processed') {
            $payloadForSign['detail']['status'] = 'scheduled';
        }

        if ($this->actionType === 'cancel_activity') {
            $payloadForSign['detail']['status_reason'] = $statusReasonCodeableConcept;
        } elseif ($this->actionType === 'complete_activity') {
            if ($this->outcomeCode) {
                $payloadForSign['outcome_codeable_concept'] = [
                    'coding' => [
                        [
                            'system' => 'eHealth/care_plan_activity_outcomes',
                            'code' => $this->outcomeCode,
                        ]
                    ]
                ];
            }

            if (!empty($this->outcomeReferences)) {
                $payloadForSign['outcome_reference'] = collect($this->outcomeReferences)->map(fn ($id) => [
                    'identifier' => [
                        'value' => $id,
                    ]
                ])->toArray();
            }
        }

        Log::info('CarePlanActivityStatus: Original JSON payload for signing: ' . json_encode(
            Arr::toSnakeCase($payloadForSign),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        ));

        try {
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($payloadForSign),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );
            Log::info('CarePlanActivityStatus: Signing key succeeded');

            $payloadData = [
                'signed_data' => $signedContent,
                'signed_data_encoding' => 'base64',
            ];

            if ($this->actionType === 'cancel_activity') {
                $payloadData['status_reason'] = $statusReasonCodeableConcept;
            } elseif ($this->actionType === 'complete_activity') {
                if ($this->outcomeCode) {
                    $payloadData['outcome_codeable_concept'] = [
                        'coding' => [
                            [
                                'system' => 'eHealth/care_plan_activity_outcomes',
                                'code' => $this->outcomeCode,
                            ]
                        ]
                    ];
                }

                if (!empty($this->outcomeReferences)) {
                    $payloadData['outcome_reference'] = collect($this->outcomeReferences)->map(fn ($id) => [
                        'identifier' => [
                            'value' => $id,
                        ]
                    ])->toArray();
                }
            }

            $apiMethod = $this->actionType === 'complete_activity' ? 'complete' : 'cancel';

            $eHealthResponse = EHealth::carePlanActivity()->{$apiMethod}(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                $activity->uuid,
                $payloadData
            );

            $responseData = $eHealthResponse->getData();
            Log::info('CarePlanActivityStatus: EHealth response received', ['response' => $responseData]);
            $finalResponse = $responseData;

            if (isset($responseData['links'][0]['href']) && str_contains($responseData['links'][0]['href'], '/jobs/')) {
                $jobId = str_replace('/jobs/', '', $responseData['links'][0]['href']);
                Log::info('CarePlanActivityStatus: Polling job: ' . $jobId);
                $jobApi = EHealth::job();
                $attempts = 0;
                do {
                    sleep(2);
                    $finalResponse = $jobApi->getDetails($jobId)->getData();
                    $attempts++;
                    Log::info("CarePlanActivityStatus: Job {$jobId} attempt {$attempts} status: " . ($finalResponse['status'] ?? 'unknown'));
                } while ($finalResponse['status'] === 'pending' && $attempts < 15);
            }

            Log::info('CarePlanActivityStatus: Final response from eHealth/Job', ['final_response' => $finalResponse]);

            if (($finalResponse['status'] ?? null) === 'failed') {
                Log::error('CarePlanActivityStatus: Job failed in eHealth', ['final_response' => $finalResponse]);
                throw new EHealthValidationException($finalResponse);
            }

            $activityStatus = $finalResponse['status'] ?? ($payloadForSign['detail']['status'] ?? $activity->status);
            if (isset($finalResponse['result']) && is_array($finalResponse['result'])) {
                $entity = $finalResponse['result'][0] ?? $finalResponse['result'];
                $activityStatus = $entity['status'] ?? $activityStatus;
            }

            $updateData = [
                'status' => $activityStatus,
            ];

            if ($this->actionType === 'complete_activity') {
                if ($this->outcomeCode) {
                    $code = \App\Repositories\MedicalEvents\Repository::codeableConcept()->store([
                        'coding' => [
                            [
                                'system' => 'eHealth/care_plan_activity_outcomes',
                                'code' => $this->outcomeCode,
                                'display' => $this->dictionaries['care_plan_activity_outcomes'][$this->outcomeCode] ?? '',
                            ]
                        ]
                    ]);
                    $updateData['outcome_codeable_concept_id'] = $code->id;
                }

                if (!empty($this->outcomeReferences)) {
                    $ids = [];
                    foreach ($this->outcomeReferences as $uuid) {
                        $identifier = \App\Repositories\MedicalEvents\Repository::identifier()->store($uuid);
                        $ids[] = $identifier->id;
                    }
                    $activity->outcomeReferences()->sync($ids);
                }
            }

            $activityRepository->updateById($activity->id, $updateData);

            $this->refreshCarePlan();
            Session::flash('success', __('care-plan.activity_updated'));
            $this->showSignatureModal = false;

        } catch (EHealthValidationException $exception) {
            Log::error('CarePlanActivityStatus: eHealth validation error: ' . $exception->getMessage(), [
                'details' => $exception->getDetails()
            ]);
            Session::flash('error', $exception->getTranslatedMessage());
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanActivityStatus: error: ' . $exception->getMessage());
            Session::flash('error', $exception->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function openMethodSelectionModal(): void
    {
        if (empty($this->carePlan->uuid)) {
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'План лікування ще не синхронізовано з ЕСОЗ.']);

            return;
        }

        try {
            $this->authMethods = EHealth::person()->getAuthMethods($this->carePlan->person->uuid)->getData();
            $this->showMethodSelectionModal = true;
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to load auth methods: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Не вдалося завантажити методи аутентифікації']);
        }
    }

    public function selectAuthMethod(string $methodUuid): void
    {
        $this->showMethodSelectionModal = false;
        $this->createApproval($methodUuid);
    }

    protected function createApproval(string $methodUuid): void
    {
        try {
            $payload = [
                'resources' => [
                    [
                        'identifier' => [
                            'type' => [
                                'coding' => [['system' => 'eHealth/resources', 'code' => 'care_plan']]
                            ],
                            'value' => $this->carePlan->uuid,
                        ]
                    ]
                ],
                'granted_to' => [
                    'identifier' => [
                        'type' => [
                            'coding' => [['system' => 'eHealth/resources', 'code' => 'employee']]
                        ],
                        'value' => Auth::user()?->getCarePlanWriterEmployee()?->uuid,
                    ]
                ],
                'access_level' => 'write',
                'authorize_with' => $methodUuid ?: null,
            ];

            $response = EHealth::approval()->createApproval($this->carePlan->person->uuid, $payload);
            $responseData = $response->getData();

            if (in_array($response->getStatusCode(), [200, 201, 202])) {
                if ($response->getStatusCode() === 202) {
                    $jobId = basename($responseData['links'][0]['href'] ?? '');
                    $attempts = 0;
                    do {
                        sleep(2);
                        $jobResponse = EHealth::job()->getDetails($jobId)->getData();
                        $attempts++;
                    } while (($jobResponse['status'] === 'pending' || $jobResponse['status'] === 'accepted') && $attempts < 15);

                    if ($jobResponse['status'] !== 'processed') {
                        throw new \RuntimeException('Approval job failed: ' . json_encode($jobResponse['error'] ?? 'unknown error'));
                    }
                    $responseData = $jobResponse['result'] ?? $jobResponse;
                }

                $this->approvalId = $responseData['response_data']['id'] ??
                                   $responseData['data']['id'] ??
                                   $responseData['id'] ?? null;

                $authenticationMethodCurrent = $responseData['response_data']['authentication_method_current'] ??
                                               $responseData['data']['authentication_method_current'] ??
                                               $responseData['authentication_method_current'] ??
                                               $responseData['urgent']['authentication_method_current'] ?? null;

                $urgentOtp = isset($authenticationMethodCurrent['type']) && $authenticationMethodCurrent['type'] === 'OTP';

                if (($methodUuid || $urgentOtp) && $this->approvalId) {
                    $this->openAuthModal();
                } else {
                    // Approval granted without OTP (e.g., OFFLINE or document)
                    $this->syncPlanStatus();
                    Session::flash('success', 'План лікування успішно активовано.');
                }
            }
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to create approval: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Не вдалося створити запит на дозвіл: ' . $e->getMessage()]);
        }
    }

    public function verify(): void
    {
        $this->validate($this->approvalVerificationRules());

        try {
            $response = EHealth::approval()->verify($this->carePlan->person->uuid, $this->approvalId, [
                'code' => (int) $this->verificationCode,
            ]);

            if ($response->successful()) {
                $this->closeAuthModal();
                $this->syncPlanStatus();
                Session::flash('success', 'План лікування успішно активовано.');
            }
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to verify approval: ' . $e->getMessage());
            $this->addError('verificationCode', 'Невірний код підтвердження або помилка сервісу');
        }
    }

    public function resendSms(): void
    {
        if ($this->smsResent) {
            return;
        }
        try {
            EHealth::approval()->resendSms($this->carePlan->person->uuid, $this->approvalId);
            $this->smsResent = true;
            $this->dispatch('flashMessage', ['type' => 'success', 'message' => 'SMS надіслано повторно']);
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to resend SMS: ' . $e->getMessage());
        }
    }

    public function sync(): void
    {
        $this->syncPlanStatus();
        $this->dispatch('flashMessage', ['type' => 'success', 'message' => 'Дані успішно синхронізовано з ЕСОЗ']);
    }

    private function buildCarePlanStatusChangePayload(array $statusReasonCodeableConcept): array
    {
        // Fetch the original care plan from eHealth GET details endpoint.
        // Signing the exact returned payload ensures cryptographic match with the server database state.
        $payloadForSign = null;

        try {
            $planResponse = EHealth::carePlan()->getDetails($this->carePlan->person->uuid, $this->carePlan->uuid);
            $planData = $planResponse->getData();
            if (isset($planData['data']) && is_array($planData['data'])) {
                $planData = $planData['data'];
            }
            if ($planData && is_array($planData)) {
                $payloadForSign = $planData;
                Log::info('CarePlanShow: fetched care plan from eHealth for signing');
            }
        } catch (\Throwable $exception) {
            Log::warning('CarePlanShow: failed to fetch original care plan from eHealth, falling back to local payload: ' . $exception->getMessage());
        }

        if (!$payloadForSign) {
            $payloadForSign = $this->buildLocalCarePlanStatusChangePayload();
            Log::info('CarePlanShow: generated local care plan payload for signing');
        }

        // Inject transition reason while keeping the current status from eHealth (e.g. active).
        $payloadForSign['status_reason'] = $statusReasonCodeableConcept;

        return $payloadForSign;
    }

    private function buildLocalCarePlanStatusChangePayload(): array
    {
        $categoryCoding = $this->carePlan->categoryConcept?->coding?->first();
        $categorySystem = $categoryCoding?->system ?? 'eHealth/care_plan_categories';
        $categoryCode = $categoryCoding?->code
            ?? (is_array($this->carePlan->category)
                ? ($this->carePlan->category['coding'][0]['code'] ?? null)
                : $this->carePlan->category);

        $encounter = $this->carePlan->encounter;
        if (!$encounter && $this->carePlan->encounterIdentifier?->value) {
            $encounter = \App\Models\MedicalEvents\Sql\Encounter::where('uuid', $this->carePlan->encounterIdentifier->value)
                ->with(['diagnoses.condition'])
                ->first();
        }

        $addresses = [];
        if ($encounter) {
            $encounter->loadMissing(['diagnoses.condition']);
            foreach ($encounter->diagnoses as $d) {
                $conditionUuid = $d->condition?->value;
                if ($conditionUuid) {
                    $actualCondition = \App\Models\MedicalEvents\Sql\Condition::where('uuid', $conditionUuid)->with('code.coding')->first();
                    if ($actualCondition) {
                        $coding = $actualCondition->code?->coding?->first();
                        if ($coding) {
                            $addresses[] = [
                                'coding' => [
                                    [
                                        'system' => $coding->system,
                                        'code' => $coding->code,
                                    ],
                                ],
                            ];
                        }
                    }
                }
            }
        }

        if (empty($addresses) && !empty($this->carePlan->addresses)) {
            $addresses = $this->carePlan->addresses;
        }

        $periodStart = null;
        $periodEnd = null;

        if ($this->carePlan->effectivePeriod?->start) {
            $periodStart = convertToEHealthISO8601($this->carePlan->effectivePeriod->start);
        } elseif ($this->carePlan->period_start) {
            $periodStart = convertToEHealthISO8601($this->carePlan->period_start->format('Y-m-d') . ' 00:00:00');
        }

        if ($this->carePlan->effectivePeriod?->end) {
            $periodEnd = convertToEHealthISO8601($this->carePlan->effectivePeriod->end);
        } elseif ($this->carePlan->period_end) {
            $periodEnd = convertToEHealthISO8601($this->carePlan->period_end->format('Y-m-d') . ' 23:59:59');
        }

        $period = array_filter([
            'start' => $periodStart,
            'end' => $periodEnd,
        ]);

        return removeEmptyKeys([
            'id' => $this->carePlan->uuid,
            'intent' => 'order',
            'status' => $this->carePlan->status,
            'category' => [
                'coding' => [
                    [
                        'system' => $categorySystem,
                        'code' => $categoryCode,
                    ],
                ],
            ],
            'instantiates_protocol' => $this->carePlan->clinical_protocol ? [['display' => $this->carePlan->clinical_protocol]] : null,
            'title' => $this->carePlan->title,
            'period' => $period,
            'addresses' => !empty($addresses) ? $addresses : null,
            'encounter' => ($encounter?->uuid ?? $this->carePlan->encounterIdentifier?->value) ? [
                'identifier' => [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => 'encounter']],
                    ],
                    'value' => $encounter?->uuid ?? $this->carePlan->encounterIdentifier->value,
                ],
            ] : null,
            'author' => [
                'identifier' => [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => 'employee']],
                    ],
                    'value' => $this->carePlan->author?->uuid ?? Auth::user()?->activeDoctorEmployee()?->uuid,
                ],
            ],
            'description' => $this->carePlan->description ?: null,
            'note' => $this->carePlan->note ?: null,
            'terms_of_service' => $this->carePlan->terms_of_service ? [
                'coding' => [
                    ['system' => 'PROVIDING_CONDITION', 'code' => $this->carePlan->terms_of_service],
                ],
            ] : null,
        ]);
    }

    public function syncPlanStatus(): void
    {
        try {
            $planResponse = EHealth::carePlan()->getDetails($this->carePlan->person->uuid, $this->carePlan->uuid);
            app(CarePlanRepository::class)->syncCarePlans(['data' => [$planResponse->getData()]], $this->carePlan->person_id);

            // Sync approvals as well!
            app(\App\Repositories\ApprovalRepository::class)->syncApprovals($this->carePlan, 'care_plan');

            $this->refreshCarePlan();
            $this->dispatch('refreshApprovals');
        } catch (\Exception $e) {
            Log::warning('CarePlanShow: failed to sync plan status: ' . $e->getMessage());
        }
    }
}
