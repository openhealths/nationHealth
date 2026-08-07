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
use App\Services\MedicalEvents\CarePlanApprovalService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

trait CarePlanManager
{
    public function sign(CarePlanRepository $repository, CarePlanActivityRepository $activityRepository): void
    {
        try {
            $validated = $this->validate($this->rulesForSigning());
        } catch (ValidationException $exception) {
            $this->dispatch('flashMessage', ['message' => $exception->validator->errors()->first(), 'type' => 'error']);
            $this->setErrorBag($exception->validator->getMessageBag());
            $this->showSignatureModal = false;

            return;
        }

        if ($this->actionType === 'sign_activity') {
            $this->signActivity($repository, $activityRepository);

            return;
        }

        if ($this->actionType === 'complete') {
            $this->completePlan($repository);

            return;
        }

        if ($this->actionType === 'sign_eprescription') {
            if (!method_exists($this, 'signEPrescription')) {
                $this->dispatch('flashMessage', ['message' => __('care-plan.unexpected_error'), 'type' => 'error']);
                $this->showSignatureModal = false;

                return;
            }

            $this->signEPrescription();

            return;
        }

        if ($this->actionType === 'sign_servicerequest' || $this->actionType === 'sign_devicerequest') {
            if (!method_exists($this, 'signReferral')) {
                $this->dispatch('flashMessage', ['message' => __('care-plan.unexpected_error'), 'type' => 'error']);
                $this->showSignatureModal = false;

                return;
            }

            $this->signReferral();

            return;
        }

        if (in_array($this->actionType, ['complete_activity', 'cancel_activity'])) {
            $this->signStatusActivity($activityRepository);

            return;
        }

        if ($this->actionType === 'cancel_prescription' || $this->actionType === 'reject_prescription') {
            if (!method_exists($this, 'signRejectPrescription')) {
                $this->dispatch('flashMessage', ['message' => __('care-plan.unexpected_error'), 'type' => 'error']);
                $this->showSignatureModal = false;

                return;
            }

            // eHealth medication-request flow in this app uses reject (no separate cancel signer).
            $this->signRejectPrescription();

            return;
        }

        if ($this->actionType === 'cancel_referral') {
            if (!method_exists($this, 'signCancelReferral')) {
                $this->dispatch('flashMessage', ['message' => __('care-plan.unexpected_error'), 'type' => 'error']);
                $this->showSignatureModal = false;

                return;
            }

            $this->signCancelReferral();

            return;
        }

        if (empty($this->carePlan->uuid)) {
            if ($this->actionType === 'sign_plan') {
                $this->signPlan($repository);

                return;
            }
            $this->dispatch('flashMessage', ['message' => __('care-plan.care_plan_not_synced'), 'type' => 'error']);
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
            $apiMethod = 'cancel'; // complete is now handled separately

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

            $this->dispatch('flashMessage', ['message' => __('care-plan.care_plan_updated'), 'type' => 'success']);
            $this->showSignatureModal = false;

        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlanShow: connection error: ' . $exception->getMessage());
            $this->dispatch('flashMessage', ['message' => __('care-plan.connection_error'), 'type' => 'error']);
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
            $this->dispatch('flashMessage', ['message' => $msg, 'type' => 'error']);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanShow: unexpected error: ' . $exception->getMessage());
            $this->dispatch('flashMessage', ['message' => __('care-plan.unexpected_error'), 'type' => 'error']);
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
            'care_manager' => [
                'identifier' => [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => 'employee']]
                    ],
                    'value' => Auth::user()?->activeDoctorEmployee()?->uuid
                ]
            ],
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

            $this->dispatch('flashMessage', ['message' => __('care-plan.signed_and_sent'), 'type' => 'success']);
            $this->showSignatureModal = false;

        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlanShow: connection error: ' . $exception->getMessage());
            $this->dispatch('flashMessage', ['message' => __('care-plan.connection_error'), 'type' => 'error']);
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
            $this->dispatch('flashMessage', ['message' => $msg, 'type' => 'error']);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanShow: unexpected error: ' . $exception->getMessage());
            $this->dispatch('flashMessage', ['message' => __('care-plan.unexpected_error'), 'type' => 'error']);
            $this->showSignatureModal = false;
        }
    }

    public function completePlan(CarePlanRepository $repository): void
    {
        $this->validate([
            'statusReason' => 'required|string'
        ]);

        if (empty($this->carePlan->uuid)) {
            $this->dispatch('flashMessage', ['message' => __('care-plan.care_plan_not_synced'), 'type' => 'error']);
            $this->showSignatureModal = false;

            return;
        }

        // Validate all activities are completed/cancelled
        $hasActiveActivities = $this->carePlan->activities()->whereNotIn('status', [
            'completed', 'cancelled', 'entered-in-error'
        ])->exists();

        if ($hasActiveActivities) {
            $this->dispatch('flashMessage', ['message' => 'Неможливо завершити план лікування. Всі призначення повинні мати фінальний статус (завершені або скасовані).', 'type' => 'error']);

            return;
        }

        $statusReasonCodeableConcept = [
            'coding' => [
                [
                    'system' => 'eHealth/care_plan_complete_reasons',
                    'code' => $this->statusReason,
                ]
            ]
        ];

        try {
            $eHealthResponse = EHealth::carePlan()->complete(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                [
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
            $carePlanStatus = $finalResponse['status'] ?? 'completed';
            if (isset($finalResponse['result']) && is_array($finalResponse['result'])) {
                $entity = $finalResponse['result'][0] ?? $finalResponse['result'];
                $carePlanStatus = $entity['status'] ?? $carePlanStatus;
            }

            // Update local state
            $repository->updateById($this->carePlan->id, [
                'status' => $carePlanStatus,
            ]);

            $this->refreshCarePlan();

            $this->dispatch('flashMessage', ['message' => __('care-plan.care_plan_updated'), 'type' => 'success']);
            $this->showSignatureModal = false;

        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlanShow: connection error: ' . $exception->getMessage());
            $this->dispatch('flashMessage', ['message' => __('care-plan.connection_error'), 'type' => 'error']);
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
            $this->dispatch('flashMessage', ['message' => $msg, 'type' => 'error']);
        } catch (\Throwable $exception) {
            Log::error('CarePlanShow: unexpected error: ' . $exception->getMessage());
            $this->dispatch('flashMessage', ['message' => __('care-plan.unexpected_error'), 'type' => 'error']);
        }
    }

    private function signActivity(CarePlanRepository $repository, CarePlanActivityRepository $activityRepository): void
    {
        if (!$this->activityToSign) {
            $this->dispatch('flashMessage', ['message' => __('care-plan.no_activity_selected'), 'type' => 'error']);
            $this->showSignatureModal = false;

            return;
        }

        $activity = $activityRepository->findById($this->activityToSign);
        if (!$activity) {
            $this->dispatch('flashMessage', ['message' => __('care-plan.activity_not_found'), 'type' => 'error']);
            $this->showSignatureModal = false;

            return;
        }

        if (empty($activity->uuid)) {
            $activity->uuid = \Illuminate\Support\Str::uuid()->toString();
            $activity->save();
        }

        if (str_contains(strtolower((string) $activity->kind), 'device') && empty($activity->program)) {
            $this->dispatch('flashMessage', ['message' => __('care-plan.device_program_required_before_sign'), 'type' => 'error']);
            $this->showSignatureModal = false;

            return;
        }

        if (method_exists($this, 'getDeviceSignReadinessWarning')) {
            $deviceWarning = $this->getDeviceSignReadinessWarning($activity);
            if ($deviceWarning !== null) {
                $this->dispatch('flashMessage', ['message' => $deviceWarning, 'type' => 'error']);
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
            $this->dispatch('flashMessage', ['message' => __('care-plan.activity_signed'), 'type' => 'success']);
            $this->showSignatureModal = false;

        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlanActivity: connection error: ' . $exception->getMessage());
            $this->dispatch('flashMessage', ['message' => __('care-plan.connection_error'), 'type' => 'error']);
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
            $this->dispatch('flashMessage', ['message' => $msg, 'type' => 'error']);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanActivity: unexpected error: ' . $exception->getMessage(), [
                'exception' => $exception
            ]);
            $this->dispatch('flashMessage', ['message' => __('care-plan.unexpected_error'), 'type' => 'error']);
            $this->showSignatureModal = false;
        }
    }

    private function signStatusActivity(CarePlanActivityRepository $activityRepository): void
    {
        if (!$this->activityToSign) {
            $this->dispatch('flashMessage', ['message' => __('care-plan.no_activity_selected'), 'type' => 'error']);
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

        if ($this->actionType === 'cancel_activity') {
            // API-007-006-0005: signed content must match activity stored in eHealth DB.
            // Use remote activity snapshot and change only detail.status_reason.
            $basePayload = $activityRepository->resolveActivityPayloadForCancelSigning(
                $activity,
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
            );
            $payloadForSign = $activityRepository->buildActivityCancelSignPayload(
                $basePayload,
                $statusReasonCodeableConcept,
            );

            $debugContext = $activityRepository->buildCancelSignatureDebugContext($basePayload, $payloadForSign);
            Log::info(
                'CarePlanActivityStatus cancel debug: original vs signed content (status_reason excluded)',
                [
                    'activity_uuid' => (string) $activity->uuid,
                    'person_uuid' => (string) $this->carePlan->person->uuid,
                    'care_plan_uuid' => (string) $this->carePlan->uuid,
                    'diff_count' => $debugContext['diff_count_excluding_status_reason'],
                    'diffs' => $debugContext['diffs_excluding_status_reason'],
                ]
            );
            Log::info('CarePlanActivityStatus cancel debug original payload: ' . json_encode($debugContext['original_snake'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            Log::info('CarePlanActivityStatus cancel debug signed payload: ' . json_encode($debugContext['signed_snake'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $basePayload = $activityRepository->resolveActivityPayloadBase(
                $activity,
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
            );
            $payloadForSign = $activityRepository->buildActivityCompleteSignPayload(
                $basePayload,
                $this->outcomeCode ?: null,
                $this->outcomeReferences,
            );
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

            if ($this->actionType === 'complete_activity') {
                // eHealth requires 'detail' in the PATCH body (status_reason).
                // Cancel (API-007-006-0005) carries status_reason only inside signed_data.
                $payloadData['detail'] = $activityRepository->buildActivityCompletePatchDetail(
                    $statusReasonCodeableConcept,
                );

                if ($this->outcomeCode) {
                    // eHealth expects outcome_codeable_concept as an array (list) of CodeableConcept objects.
                    $payloadData['outcome_codeable_concept'] = [
                        [
                            'coding' => [
                                [
                                    'system' => 'eHealth/care_plan_activity_outcomes',
                                    'code' => $this->outcomeCode,
                                ],
                            ],
                        ],
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
            $this->dispatch('flashMessage', ['message' => __('care-plan.activity_updated'), 'type' => 'success']);
            $this->showSignatureModal = false;

        } catch (EHealthValidationException $exception) {
            Log::error('CarePlanActivityStatus: eHealth validation error: ' . $exception->getMessage(), [
                'details' => $exception->getDetails()
            ]);
            $this->dispatch('flashMessage', ['message' => $exception->getTranslatedMessage(), 'type' => 'error']);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanActivityStatus: error: ' . $exception->getMessage());
            $this->dispatch('flashMessage', ['message' => $exception->getMessage(), 'type' => 'error']);
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
        $this->currentAuthMethod = collect($this->authMethods)->first(function ($method) use ($methodUuid) {
            return ($method['id'] ?? $method['uuid'] ?? null) === $methodUuid;
        });

        if (is_array($this->currentAuthMethod)) {
            $this->phoneNumber = $this->currentAuthMethod['phone_number']
                ?? $this->currentAuthMethod['phoneNumber']
                ?? null;
        }

        $this->showMethodSelectionModal = false;
        $this->createApproval($methodUuid);
    }

    protected function createApproval(string $methodUuid): void
    {
        try {
            $employeeUuid = Auth::user()?->getCarePlanWriterEmployee($this->carePlan->terms_of_service)?->uuid;

            if (!$employeeUuid) {
                $this->dispatch('flashMessage', [
                    'type' => 'error',
                    'message' => 'Не вдалося визначити лікаря для створення дозволу.',
                ]);

                return;
            }

            $result = app(CarePlanApprovalService::class)->create(
                carePlan: $this->carePlan,
                patientUuid: $this->carePlan->person->uuid,
                employeeUuid: $employeeUuid,
                accessLevel: 'write',
                authorizeWith: $methodUuid ?: null,
            );

            if ($result->isAsync()) {
                $this->approvalId = $result->approvalId;
                $this->pollingLinkId = $result->pollingLinkId;
                $this->isPolling = true;

                return;
            }

            $this->approvalId = $result->approvalId;

            if ($result->requiresOtp()) {
                $this->currentAuthMethod = $result->authMethod ?? $this->currentAuthMethod;
                $this->openAuthModal();

                return;
            }

            $this->syncPlanStatus();
            $this->dispatch('flashMessage', ['message' => 'План лікування успішно активовано.', 'type' => 'success']);
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to create approval: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Не вдалося створити запит на дозвіл: ' . $e->getMessage()]);
        }
    }

    public function checkApprovalJobStatus(): void
    {
        if (!$this->isPolling || !$this->pollingLinkId) {
            return;
        }

        $status = app(CarePlanApprovalService::class)->resolveAsyncJob($this->pollingLinkId);

        if ($status->isPending()) {
            return;
        }

        $this->isPolling = false;
        $this->pollingLinkId = null;

        if ($status->isFailed()) {
            $this->dispatch('flashMessage', [
                'type' => 'error',
                'message' => $status->errorMessage ?: 'Не вдалося обробити запит на дозвіл.',
            ]);

            return;
        }

        if ($status->approvalId) {
            $this->approvalId = $status->approvalId;
        }

        if ($status->requiresOtp()) {
            $this->currentAuthMethod = $status->authMethod ?? $this->currentAuthMethod;
            $this->openAuthModal();

            return;
        }

        $this->syncPlanStatus();
        $this->dispatch('flashMessage', ['message' => 'План лікування успішно активовано.', 'type' => 'success']);
    }

    public function verify(): void
    {
        $this->validate($this->approvalVerificationRules());

        if ($this->isOfflineAuthMethod()) {
            Log::info('CarePlanManager: offline document verification confirmed for approval ID: ' . $this->approvalId);
            $this->closeAuthModal();
            $this->syncPlanStatus();
            $this->dispatch('flashMessage', ['message' => 'План лікування успішно активовано (за документами пацієнта).', 'type' => 'success']);

            return;
        }

        try {
            $response = app(CarePlanApprovalService::class)->verify(
                $this->carePlan->person->uuid,
                $this->approvalId,
                (int) $this->verificationCode,
            );

            if ($response->successful()) {
                $this->closeAuthModal();
                $this->syncPlanStatus();
                $this->dispatch('flashMessage', ['message' => 'План лікування успішно активовано.', 'type' => 'success']);
            }
        } catch (\Exception $e) {
            Log::error('CarePlanLifecycle: failed to verify approval: ' . $e->getMessage());
            $this->addError('verificationCode', 'Невірний код підтвердження або помилка сервісу');
        }
    }

    public function resendSms(): void
    {
        if ($this->smsResent) {
            return;
        }
        try {
            app(CarePlanApprovalService::class)->resendSms($this->carePlan->person->uuid, $this->approvalId);
            $this->smsResent = true;
            $this->dispatch('flashMessage', ['type' => 'success', 'message' => 'SMS надіслано повторно']);
        } catch (\Exception $e) {
            Log::error('CarePlanLifecycle: failed to resend SMS: ' . $e->getMessage());
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
        $planResponse = EHealth::carePlan()->getDetails($this->carePlan->person->uuid, $this->carePlan->uuid);
        $planData = $planResponse->getData();
        if (isset($planData['data']) && is_array($planData['data'])) {
            $planData = $planData['data'];
        }

        if (!$planData || !is_array($planData)) {
            throw new \Exception('Не вдалося отримати актуальний стан плану лікування з ЕСОЗ.');
        }

        $payloadForSign = $planData;
        Log::info('CarePlanShow: fetched care plan from eHealth for signing');

        // Remove local-only fields if they accidentally leaked into the EHealth payload

        // Inject transition reason while keeping the current status from eHealth (e.g. active).
        $payloadForSign['status_reason'] = $statusReasonCodeableConcept;

        return $payloadForSign;
    }

    public function syncPlanStatus(): void
    {
        try {
            $planResponse = EHealth::carePlan()->getDetails($this->carePlan->person->uuid, $this->carePlan->uuid);
            app(CarePlanRepository::class)->syncCarePlans(['data' => [$planResponse->getData()]], $this->carePlan->person_id);

            // Sync approvals as well!
            app(CarePlanApprovalService::class)->syncForCarePlan($this->carePlan);

            $this->refreshCarePlan();
            $this->dispatch('refreshApprovals');
        } catch (\Exception $e) {
            Log::warning('CarePlanShow: failed to sync plan status: ' . $e->getMessage());
        }
    }
}
