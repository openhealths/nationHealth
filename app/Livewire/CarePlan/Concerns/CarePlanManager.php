<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Concerns;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\CarePlanStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthJobTimeoutException;
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
            $this->flashOutcome('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

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
                $this->flashOutcome('error', __('care-plan.unexpected_error'));
                $this->showSignatureModal = false;

                return;
            }

            $this->signEPrescription();

            return;
        }

        if ($this->actionType === 'sign_servicerequest' || $this->actionType === 'sign_devicerequest') {
            if (!method_exists($this, 'signReferral')) {
                $this->flashOutcome('error', __('care-plan.unexpected_error'));
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
                $this->flashOutcome('error', __('care-plan.unexpected_error'));
                $this->showSignatureModal = false;

                return;
            }

            // eHealth medication-request flow in this app uses reject (no separate cancel signer).
            $this->signRejectPrescription();

            return;
        }

        if ($this->actionType === 'cancel_referral') {
            if (!method_exists($this, 'signCancelReferral')) {
                $this->flashOutcome('error', __('care-plan.unexpected_error'));
                $this->showSignatureModal = false;

                return;
            }

            $this->signCancelReferral();

            return;
        }

        if ($this->actionType === 'recall_referral') {
            if (!method_exists($this, 'signRecallReferral')) {
                $this->flashOutcome('error', __('care-plan.unexpected_error'));
                $this->showSignatureModal = false;

                return;
            }

            $this->signRecallReferral();

            return;
        }

        if (empty($this->carePlan->uuid)) {
            if ($this->actionType === 'sign_plan') {
                $this->signPlan($repository);

                return;
            }
            $this->flashOutcome('error', __('care-plan.care_plan_not_synced'));
            $this->showSignatureModal = false;

            return;
        }

        if ($this->actionType === 'cancel') {
            $planCancelBlock = app(\App\Services\MedicalEvents\CarePlanLifecycleGateService::class)
                ->planCancelBlockReason($this->carePlan);

            if ($planCancelBlock !== null) {
                $this->flashOutcome('error', $planCancelBlock);
                $this->showSignatureModal = false;

                return;
            }
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

        try {
            $payloadForSign = $this->buildCarePlanStatusChangePayload($statusReasonCodeableConcept);

            Log::info('CarePlanShow: signing care-plan status change', [
                'actionType' => $this->actionType,
                'care_plan_id' => $this->carePlan->id,
            ]);

            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($payloadForSign),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            $finalResponse = app(\App\Services\MedicalEvents\CarePlanLifecycleService::class)->cancel(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                [
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64',
                    'status_reason' => $statusReasonCodeableConcept,
                ]
            );

            $repository->updateById($this->carePlan->id, [
                'status' => CarePlanStatus::fromJobResponse($finalResponse, CarePlanStatus::CANCELLED)->value,
            ]);

            $this->refreshCarePlan();

            $this->flashOutcome('success', __('care-plan.care_plan_cancelled'));
            $this->showSignatureModal = false;
            $this->redirectAfterCarePlanClosed();

        } catch (EHealthJobTimeoutException $exception) {
            $exception->report();
            $this->flashOutcome('error', $exception->getMessage());
            $this->showSignatureModal = false;
        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlanShow: connection error: ' . $exception->getMessage());
            $this->flashOutcome('error', __('care-plan.connection_error'));
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

            if ($this->actionType === 'cancel' && $this->isCarePlanAlreadyCancelledError($exception)) {
                $repository->updateById($this->carePlan->id, [
                    'status' => CarePlanStatus::CANCELLED->value,
                ]);
                $this->refreshCarePlan();
            }

            $this->flashOutcome('error', $msg);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanShow: unexpected error: ' . $exception->getMessage(), [
                'exception' => $exception,
                'actionType' => $this->actionType,
            ]);
            $this->flashOutcome(
                'error',
                __('care-plan.unexpected_error') . ' ' . $exception->getMessage()
            );
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

            $finalResponse = app(\App\Services\MedicalEvents\CarePlanLifecycleService::class)
                ->submitSignedCreate($this->carePlan->person->uuid, $signedContent);

            $entity = $finalResponse['result'][0] ?? ($finalResponse['result'] ?? $finalResponse);
            if (!is_array($entity)) {
                $entity = $finalResponse;
            }

            $repository->updateById($this->carePlan->id, [
                'uuid' => $entity['id'] ?? $finalResponse['id'] ?? null,
                'status' => $entity['status'] ?? $finalResponse['status'] ?? 'new',
                'requisition' => $entity['requisition'] ?? $finalResponse['requisition'] ?? null,
            ]);

            $this->refreshCarePlan();

            $this->flashOutcome('success', __('care-plan.signed_and_sent'));
            $this->showSignatureModal = false;

        } catch (EHealthJobTimeoutException $exception) {
            $exception->report();
            $this->flashOutcome('error', $exception->getMessage());
            $this->showSignatureModal = false;
        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlanShow: connection error: ' . $exception->getMessage());
            $this->flashOutcome('error', __('care-plan.connection_error'));
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
            $this->flashOutcome('error', $msg);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanShow: unexpected error: ' . $exception->getMessage());
            $this->flashOutcome('error', __('care-plan.unexpected_error'));
            $this->showSignatureModal = false;
        }
    }

    public function completePlan(CarePlanRepository $repository): void
    {
        $this->validate([
            'statusReason' => 'required|string'
        ]);

        if (empty($this->carePlan->uuid)) {
            $this->flashOutcome('error', __('care-plan.care_plan_not_synced'));
            $this->showSignatureModal = false;

            return;
        }

        $blockReason = app(\App\Services\MedicalEvents\CarePlanLifecycleGateService::class)
            ->planCompleteBlockReason($this->carePlan);

        if ($blockReason !== null) {
            $this->flashOutcome('error', $blockReason);
            $this->showSignatureModal = false;

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
            $finalResponse = app(\App\Services\MedicalEvents\CarePlanLifecycleService::class)->complete(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                [
                    'status_reason' => $statusReasonCodeableConcept,
                ]
            );

            $repository->updateById($this->carePlan->id, [
                'status' => CarePlanStatus::fromJobResponse($finalResponse, CarePlanStatus::COMPLETED)->value,
            ]);

            $this->refreshCarePlan();

            $this->flashOutcome('success', __('care-plan.care_plan_completed'));
            $this->showSignatureModal = false;
            $this->redirectAfterCarePlanClosed();

        } catch (EHealthJobTimeoutException $exception) {
            $exception->report();
            $this->flashOutcome('error', $exception->getMessage());
            $this->showSignatureModal = false;
        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlanShow: connection error: ' . $exception->getMessage());
            $this->flashOutcome('error', __('care-plan.connection_error'));
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
            $this->flashOutcome('error', $msg);
        } catch (\Throwable $exception) {
            Log::error('CarePlanShow: unexpected error: ' . $exception->getMessage());
            $this->flashOutcome('error', __('care-plan.unexpected_error'));
        }
    }

    private function signActivity(CarePlanRepository $repository, CarePlanActivityRepository $activityRepository): void
    {
        if (!$this->activityToSign) {
            $this->flashOutcome('error', __('care-plan.no_activity_selected'));
            $this->showSignatureModal = false;

            return;
        }

        $activity = $activityRepository->findById($this->activityToSign);
        if (!$activity) {
            $this->flashOutcome('error', __('care-plan.activity_not_found'));
            $this->showSignatureModal = false;

            return;
        }

        if (empty($activity->uuid)) {
            $activity->uuid = \Illuminate\Support\Str::uuid()->toString();
            $activity->save();
        }

        if (str_contains(strtolower((string) $activity->kind), 'device') && empty($activity->program)) {
            $this->flashOutcome('error', __('care-plan.device_program_required_before_sign'));
            $this->showSignatureModal = false;

            return;
        }

        if (method_exists($this, 'getDeviceSignReadinessWarning')) {
            $deviceWarning = $this->getDeviceSignReadinessWarning($activity);
            if ($deviceWarning !== null) {
                $this->flashOutcome('error', $deviceWarning);
                $this->showSignatureModal = false;

                return;
            }
        }

        // Build Payload
        $activityPayload = $activityRepository->formatCarePlanActivityRequest($activity);
        Log::info('CarePlanActivity: signing activity', [
            'activity_id' => $activity->id,
            'activity_uuid' => $activity->uuid,
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

            $finalResponse = app(\App\Services\MedicalEvents\CarePlanActivityLifecycleService::class)
                ->submitSignedCreate(
                    $this->carePlan->person->uuid,
                    $this->carePlan->uuid,
                    $signedContent
                );

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
                $planData = app(\App\Services\MedicalEvents\CarePlanLifecycleService::class)
                    ->getDetails($this->carePlan->person->uuid, $this->carePlan->uuid);
                $repository->syncCarePlans(
                    ['data' => [$planData]],
                    $this->carePlan->person_id,
                    Auth::user()?->getCarePlanWriterEmployee($this->carePlan->terms_of_service)?->id
                );
                $activityRepository->syncActivities($this->carePlan->person, $this->carePlan);
            } catch (\Exception $e) {
                Log::warning('CarePlanShow: failed to sync plan status or activities after activity creation: ' . $e->getMessage());
            }

            $this->refreshCarePlan();
            $this->flashOutcome('success', __('care-plan.activity_signed'));
            $this->showSignatureModal = false;

        } catch (EHealthJobTimeoutException $exception) {
            $exception->report();
            $this->flashOutcome('error', $exception->getMessage());
            $this->showSignatureModal = false;
        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlanActivity: connection error: ' . $exception->getMessage());
            $this->flashOutcome('error', __('care-plan.connection_error'));
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
            $this->flashOutcome('error', $msg);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanActivity: unexpected error: ' . $exception->getMessage(), [
                'exception' => $exception
            ]);
            $this->flashOutcome('error', __('care-plan.unexpected_error'));
            $this->showSignatureModal = false;
        }
    }

    private function signStatusActivity(CarePlanActivityRepository $activityRepository): void
    {
        if (!$this->activityToSign) {
            $this->flashOutcome('error', __('care-plan.no_activity_selected'));
            $this->showSignatureModal = false;

            return;
        }

        $activity = $activityRepository->findById($this->activityToSign);
        if (!$activity) {
            return;
        }

        $openDocsBlock = app(\App\Services\MedicalEvents\CarePlanLifecycleGateService::class)
            ->activityStatusChangeBlockReason($activity, (string) $this->actionType);

        if ($openDocsBlock !== null) {
            $this->flashOutcome('error', $openDocsBlock);
            $this->showSignatureModal = false;

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

        $payloadForSign = [];

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
        }

        try {
            if ($this->actionType === 'cancel_activity') {
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
            } else {
                // Complete (API-007-006-0006) takes status_reason and the outcome in the PATCH
                // body and is not signed at all.
                $payloadData = [
                    'detail' => $activityRepository->buildActivityCompletePatchDetail(
                        $statusReasonCodeableConcept,
                    ),
                ];

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

            $activityLifecycle = app(\App\Services\MedicalEvents\CarePlanActivityLifecycleService::class);
            $finalResponse = $this->actionType === 'complete_activity'
                ? $activityLifecycle->complete(
                    $this->carePlan->person->uuid,
                    $this->carePlan->uuid,
                    $activity->uuid,
                    $payloadData
                )
                : $activityLifecycle->cancel(
                    $this->carePlan->person->uuid,
                    $this->carePlan->uuid,
                    $activity->uuid,
                    $payloadData
                );

            $fallbackStatus = $this->actionType === 'complete_activity'
                ? CarePlanStatus::COMPLETED
                : CarePlanStatus::CANCELLED;
            $activityStatus = CarePlanStatus::fromJobResponse($finalResponse, $fallbackStatus)->value;

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
            $this->flashOutcome(
                'success',
                $this->actionType === 'complete_activity'
                    ? __('care-plan.activity_completed')
                    : __('care-plan.activity_cancelled')
            );
            $this->showSignatureModal = false;

        } catch (EHealthValidationException $exception) {
            Log::error('CarePlanActivityStatus: eHealth validation error: ' . $exception->getMessage(), [
                'details' => $exception->getDetails()
            ]);
            $this->flashOutcome('error', $exception->getTranslatedMessage());
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanActivityStatus: error: ' . $exception->getMessage());
            $this->flashOutcome('error', $exception->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function openMethodSelectionModal(): void
    {
        if ($this->guardTerminalCarePlanMutation()) {
            return;
        }

        if (empty($this->carePlan->uuid)) {
            $this->flashOutcome('error', 'План лікування ще не синхронізовано з ЕСОЗ.');

            return;
        }

        try {
            $this->authMethods = EHealth::person()->getAuthMethods($this->carePlan->person->uuid)->getData();
            $this->showMethodSelectionModal = true;
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to load auth methods: ' . $e->getMessage());
            $this->flashOutcome('error', 'Не вдалося завантажити методи аутентифікації');
        }
    }

    protected function isCarePlanAlreadyCancelledError(\Throwable $exception): bool
    {
        if ($exception instanceof EHealthValidationException) {
            return $exception->isCarePlanAlreadyCancelled();
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'cannot be cancelled')
            && str_contains($message, 'cancelled');
    }

    protected function redirectAfterCarePlanClosed(): void
    {
        $this->carePlan->loadMissing(['encounter', 'person']);

        $personId = $this->carePlan->personId;
        $encounterId = $this->carePlan->encounterId;

        if ($personId && $encounterId) {
            $this->redirectRoute('encounter.edit', [legalEntity(), $personId, $encounterId], navigate: true);

            return;
        }

        if ($personId) {
            $this->redirectRoute('persons.care-plans', [legalEntity(), $personId], navigate: true);
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
                $this->flashOutcome('error', 'Не вдалося визначити лікаря для створення дозволу.');

                return;
            }

            $result = app(CarePlanApprovalService::class)->create(
                carePlan: $this->carePlan,
                patientUuid: $this->carePlan->person->uuid,
                employeeUuid: $employeeUuid,
                accessLevel: 'write',
                authorizeWith: $methodUuid ?: null,
                user: Auth::user(),
                bearerToken: session()->get(config('ehealth.api.oauth.bearer_token')),
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
            $this->flashOutcome('success', 'План лікування успішно активовано.');
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to create approval: ' . $e->getMessage());
            $this->flashOutcome('error', 'Не вдалося створити запит на дозвіл: ' . $e->getMessage());
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
            $this->flashOutcome('error', $status->errorMessage ?: 'Не вдалося обробити запит на дозвіл.');

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
        $this->flashOutcome('success', 'План лікування успішно активовано.');
    }

    public function verify(): void
    {
        $this->validate($this->approvalVerificationRules());

        if ($this->isOfflineAuthMethod()) {
            Log::info('CarePlanManager: offline document verification confirmed for approval ID: ' . $this->approvalId);
            $this->closeAuthModal();
            $this->syncPlanStatus();
            $this->flashOutcome('success', 'План лікування успішно активовано (за документами пацієнта).');

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
                $this->flashOutcome('success', 'План лікування успішно активовано.');
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
            $this->flashOutcome('success', 'SMS надіслано повторно');
        } catch (\Exception $e) {
            Log::error('CarePlanLifecycle: failed to resend SMS: ' . $e->getMessage());
            $message = str_contains($e->getMessage(), 'ACL')
                ? __('care-plan.sms_resend_acl_error')
                : __('care-plan.sms_resend_error');
            $this->addError('verificationCode', $message);
            $this->flashOutcome('error', $message);
        }
    }

    public function sync(): void
    {
        if (empty($this->carePlan->uuid)) {
            $this->flashOutcome('error', __('care-plan.care_plan_not_synced'));

            return;
        }

        if (!$this->syncPlanStatus()) {
            $this->flashOutcome('error', __('care-plan.sync_error'));

            return;
        }

        $this->flashOutcome('success', __('care-plan.data_synced'));
    }

    private function buildCarePlanStatusChangePayload(array $statusReasonCodeableConcept): array
    {
        // Fetch the original care plan from eHealth GET details endpoint.
        // Signing the exact returned payload ensures cryptographic match with the server database state.
        $planData = app(\App\Services\MedicalEvents\CarePlanLifecycleService::class)
            ->getDetails($this->carePlan->person->uuid, $this->carePlan->uuid);
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

    public function syncPlanStatus(): bool
    {
        try {
            $planData = app(\App\Services\MedicalEvents\CarePlanLifecycleService::class)
                ->getDetails($this->carePlan->person->uuid, $this->carePlan->uuid);
            app(CarePlanRepository::class)->syncCarePlans(
                ['data' => [$planData]],
                $this->carePlan->person_id,
                Auth::user()?->getCarePlanWriterEmployee($this->carePlan->terms_of_service)?->id
            );

            app(CarePlanActivityRepository::class)->syncActivities($this->carePlan->person, $this->carePlan);

            app(CarePlanApprovalService::class)->syncForCarePlan($this->carePlan);

            $this->refreshCarePlan();
            $this->dispatch('refreshApprovals');

            return true;
        } catch (\Exception $e) {
            Log::warning('CarePlanShow: failed to sync plan status: ' . $e->getMessage());

            return false;
        }
    }
}
