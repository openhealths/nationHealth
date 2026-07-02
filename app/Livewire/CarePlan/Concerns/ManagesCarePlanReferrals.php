<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Concerns;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Repositories\CarePlanActivityRepository;
use App\Services\MedicalEvents\CarePlanActivityEHealthGuard;
use App\Services\MedicalEvents\EHealthJobResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

trait ManagesCarePlanReferrals
{
    public function initReferralForm(int $activityId, CarePlanActivityRepository $activityRepository): void
    {
        $activity = $activityRepository->findById($activityId);
        if (!$activity) {
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Призначення не знайдено']);

            return;
        }

        $planStatus = strtolower(is_array($this->carePlan->status)
            ? ($this->carePlan->status['coding'][0]['code'] ?? ($this->carePlan->status['text'] ?? ''))
            : (string) $this->carePlan->status);

        $activityStatus = strtolower(is_array($activity->status)
            ? ($activity->status['coding'][0]['code'] ?? ($activity->status['text'] ?? ''))
            : (string) $activity->status);

        $blockedPlanStatuses = ['cancelled', 'completed', 'terminated', 'entered-in-error'];
        $blockedActivityStatuses = ['cancelled', 'completed'];

        if (in_array($planStatus, $blockedPlanStatuses)) {
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Виписування направлення заборонено: план лікування завершено, скасовано або відмінено.']);

            return;
        }

        if (in_array($activityStatus, $blockedActivityStatuses)) {
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Виписування направлення заборонено: це призначення вже завершено або скасовано.']);

            return;
        }

        $resolvedKind = $activity->resolvedKind();
        if (!in_array($resolvedKind, ['service_request', 'device_request'], true)) {
            $this->dispatch('flashMessage', [
                'type' => 'error',
                'message' => __('care-plan.referral_wrong_activity_kind'),
            ]);

            return;
        }

        try {
            app(CarePlanActivityEHealthGuard::class)->assertRegisteredInEHealth($this->carePlan, $activity);
        } catch (\RuntimeException $exception) {
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => $exception->getMessage()]);

            return;
        }

        $existingDraft = $this->referralLifecycle->findDraftByActivity($activity);
        if ($existingDraft) {
            if ($this->referralLifecycle->trySyncDraftFromEHealth($this->carePlan, $activity, $existingDraft, $resolvedKind)) {
                if ($activity->status === 'scheduled') {
                    $activity->update(['status' => 'in-progress']);
                }
                $this->refreshCarePlan();
                $this->dispatch('flashMessage', [
                    'type' => 'success',
                    'message' => 'Направлення вже створено в ЕСОЗ. Локальні дані синхронізовано.',
                ]);

                return;
            }

            $this->referralRequestIdToSign = $existingDraft->uuid;
            $signAction = $resolvedKind === 'service_request'
                ? 'sign_servicerequest'
                : 'sign_devicerequest';
            $this->dispatch('flashMessage', [
                'type' => 'info',
                'message' => 'Знайдено непідписане направлення. Продовжіть підписання.',
            ]);
            $this->openSignatureModal($signAction);

            return;
        }

        $this->referralWarningMessage = '';

        // Calculate remaining quantity
        $activityQty = (float) ($activity->quantity ?? 0);
        $issuedQty = $this->referralLifecycle->sumIssuedQuantity($activity);
        $this->referralRemainingQty = $activity->quantity === null
            ? 1.0
            : max(0.0, $activityQty - $issuedQty);

        $code = $activity->product_codeable_concept ?? $activity->product_reference ?? 'од.';

        $category = $resolvedKind === 'service_request'
            ? $this->resolveServiceCategory((string) $activity->product_reference)
            : null;

        $this->referralServiceCategory = $category ?? 'procedure';

        $occurrenceDates = $this->resolveReferralOccurrenceDates(
            $activity->scheduled_period_start,
            $activity->scheduled_period_end
        );

        $supportingInfo = [];
        $activity->reasonReferences()->get()->each(function ($identifier) use (&$supportingInfo) {
            $typeCode = $identifier->type->first()?->coding?->first()?->code ?? 'condition';
            $supportingInfo[] = [
                'type' => $typeCode,
                'uuid' => $identifier->value
            ];
        });

        $this->referralForm = [
            'activity_id' => $activity->id,
            'kind' => $resolvedKind,
            'code' => $code,
            'quantity' => min($this->referralRemainingQty, 1.0),
            'started_at' => $occurrenceDates['started_at'],
            'ended_at' => $occurrenceDates['ended_at'],
            'priority' => 'routine',
            'intent' => 'order',
            'category' => $this->referralServiceCategory,
            'category_label' => $this->resolveReferralCategoryLabel($this->referralServiceCategory),
            'note' => '',
            'program_id' => $activity->program ?? '',
            'supporting_info' => $supportingInfo
        ];

        $this->referralShowRemainingQtyWarning = false;
        $this->referralSelectedActivity = $activity->toArray();
        $this->showReferralDrawer = true;
    }

    public function validateReferral(): void
    {
        $this->referralWarningMessage = '';
        $this->referralShowRemainingQtyWarning = false;

        if ($this->referralForm['kind'] === 'service_request') {
            $this->referralForm['category'] = $this->referralServiceCategory ?: ($this->referralForm['category'] ?? 'procedure');
        }

        $rules = [
            'referralForm.started_at' => 'required|date_format:d.m.Y',
            'referralForm.ended_at' => 'required|date_format:d.m.Y|after_or_equal:referralForm.started_at',
            'referralForm.quantity' => 'required|numeric|min:0.01',
            'referralForm.priority' => 'required|in:routine,urgent,asap,stat',
        ];

        if ($this->referralForm['kind'] === 'service_request') {
            $rules['referralForm.category'] = 'required|string';
        }

        $this->validate($rules);

        if ($this->referralForm['kind'] === 'service_request') {
            $activityProgram = $this->referralSelectedActivity['program'] ?? null;
            $this->referralForm['program_id'] = !empty($activityProgram) ? $activityProgram : null;
        }

        $qty = (float) $this->referralForm['quantity'];
        if ($qty > $this->referralRemainingQty) {
            $this->referralShowRemainingQtyWarning = true;
            $this->referralWarningMessage = 'Кількість перевищує залишок за призначенням (' . $this->referralRemainingQty . ')';
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Кількість перевищує залишок за призначенням.']);

            return;
        }

        // Propose to sign
        $this->showReferralDrawer = false;

        $activity = \App\Models\CarePlanActivity::find($this->referralForm['activity_id']);
        if ($activity) {
            $existingDraft = $this->referralLifecycle->findDraftByActivity($activity);
            if ($existingDraft) {
                $this->referralRequestIdToSign = $existingDraft->uuid;
                $signAction = $this->referralForm['kind'] === 'service_request'
                    ? 'sign_servicerequest'
                    : 'sign_devicerequest';
                $this->openSignatureModal($signAction);

                return;
            }
        }

        try {
            $employeeContext = $this->referralLifecycle->resolveEmployeeContext(
                $this->carePlan,
                $activity,
                Auth::user()?->activeDoctorEmployee()?->id
            );

            $this->referralRequestIdToSign = $this->referralLifecycle->createDraft(
                $this->carePlan,
                $this->referralForm,
                $qty,
                $employeeContext
            );
            $signAction = $this->referralForm['kind'] === 'service_request'
                ? 'sign_servicerequest'
                : 'sign_devicerequest';
            $this->openSignatureModal($signAction);
        } catch (EHealthValidationException $exception) {
            $exception->report();
            $this->showReferralDrawer = true;
            Session::flash('error', $exception->getFormattedMessage());
        } catch (\Exception $exception) {
            $this->showReferralDrawer = true;
            Log::error('CarePlanShow: failed to create referral request: ' . $exception->getMessage());
            Session::flash('error', 'Не вдалося створити заявку на направлення: ' . $exception->getMessage());
        }
    }

    public function resendReferralSms(string $requestId, string $kind): void
    {
        try {
            $response = $this->referralLifecycle->resendSms($this->carePlan->person->uuid, $requestId, $kind);

            if ($response->successful()) {
                $this->dispatch('flashMessage', [
                    'type' => 'success',
                    'message' => 'СМС з кодом підтвердження успішно надіслано повторно пацієнту.',
                ]);

                return;
            }

            $this->dispatch('flashMessage', [
                'type' => 'error',
                'message' => 'Не вдалося повторно надіслати СМС: ' . json_encode($response->getData()),
            ]);
        } catch (EHealthValidationException $exception) {
            Log::error('CarePlanShow: failed to resend referral SMS validation: ' . $exception->getTranslatedMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => $exception->getTranslatedMessage()]);
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() === 403) {
                Log::warning('CarePlanShow: referral SMS resend forbidden by eHealth ACL', [
                    'request_id' => $requestId,
                    'person_uuid' => $this->carePlan->person->uuid,
                ]);
                $this->dispatch('flashMessage', [
                    'type' => 'warning',
                    'message' => __('care-plan.referral_sms_forbidden'),
                ]);

                return;
            }

            Log::error('CarePlanShow: failed to resend referral SMS response: ' . $exception->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Помилка надсилання СМС: ' . $exception->getMessage()]);
        } catch (\Exception $exception) {
            Log::error('CarePlanShow: failed to resend referral SMS: ' . $exception->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Помилка надсилання СМС: ' . $exception->getMessage()]);
        }
    }

    public function signReferral(): void
    {
        if (empty($this->referralRequestIdToSign)) {
            Session::flash('error', 'Не вибрано направлення для підписання');
            $this->showSignatureModal = false;

            return;
        }

        $requestRecord = \App\Models\MedicalEvents\Sql\ServiceRequestRequest::where('uuid', $this->referralRequestIdToSign)->first()
            ?? \App\Models\MedicalEvents\Sql\DeviceRequestRequest::where('uuid', $this->referralRequestIdToSign)->first();

        $kind = $requestRecord instanceof \App\Models\MedicalEvents\Sql\ServiceRequestRequest
            ? 'service_request'
            : 'device_request';

        if (!$requestRecord) {
            Session::flash('error', 'Направлення не знайдено');
            $this->showSignatureModal = false;

            return;
        }

        try {
            $activity = \App\Models\CarePlanActivity::find($requestRecord->based_on_id);
            if (!$activity) {
                throw new \RuntimeException('Призначення для направлення не знайдено');
            }

            $employeeContext = $this->resolveReferralEmployeeContext($requestRecord, $activity);

            $uuids = [
                'person_uuid' => $this->carePlan->person->uuid,
                'encounter_uuid' => $this->carePlan->encounter?->uuid ?? null,
                'employee_uuid' => $employeeContext['employee_uuid'],
                'legal_entity_uuid' => $employeeContext['legal_entity_uuid'],
            ];

            $dbData = $this->buildReferralSignDbData($requestRecord, $activity);

            $mapper = $kind === 'service_request'
                ? new \App\Services\MedicalEvents\Mappers\ServiceRequestMapper()
                : new \App\Services\MedicalEvents\Mappers\DeviceRequestMapper();

            $signPayload = $mapper->toCreateSignedContent(
                $dbData,
                $uuids,
                (string) $this->carePlan->uuid,
                (string) $activity->uuid
            );

            $signedContent = signatureService()->signData(
                $signPayload,
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            if ($kind === 'service_request') {
                $eHealthResponse = EHealth::serviceRequest()->createSigned(
                    $this->carePlan->person->uuid,
                    [
                        'signed_data' => $signedContent,
                        'signed_data_encoding' => 'base64',
                    ]
                );
            } else {
                $eHealthResponse = EHealth::deviceRequest()->createSigned(
                    $this->carePlan->person->uuid,
                    [
                        'signed_data' => $signedContent,
                        'signed_data_encoding' => 'base64',
                    ]
                );
            }

            $responseData = $eHealthResponse->getData();
            $finalResponse = app(EHealthJobResolver::class)->resolve($responseData);
            app(EHealthJobResolver::class)->assertSuccessful($finalResponse);

            $entity = isset($finalResponse['result'][0])
                ? $finalResponse['result'][0]
                : ($finalResponse['result'] ?? $finalResponse);

            $dbData['status'] = $entity['status'] ?? ($finalResponse['status'] ?? 'active');
            $dbData['request_number'] = $entity['request_number'] ?? $entity['requisition'] ?? $dbData['request_number'] ?? null;
            $dbData['uuid'] = $entity['id'] ?? $dbData['uuid'];

            $this->finalizeSignedReferral($dbData, $kind, $activity);

        } catch (EHealthValidationException $e) {
            if ($e->isDuplicateReferralError()) {
                try {
                    $activity = \App\Models\CarePlanActivity::find($requestRecord->based_on_id);
                    if (!$activity) {
                        throw new \RuntimeException('Призначення для направлення не знайдено');
                    }

                    $dbData = $this->buildReferralSignDbData($requestRecord, $activity);
                    $dbData = $this->referralLifecycle->syncReferralFromRemote(
                        $this->carePlan,
                        $activity,
                        $requestRecord,
                        $kind,
                        $dbData
                    );
                    $this->finalizeSignedReferral($dbData, $kind, $activity, true);
                } catch (\Exception $syncException) {
                    Log::error('CarePlanShow: failed to sync referral after duplicate eHealth id: ' . $syncException->getMessage());
                    Session::flash('error', 'Направлення вже існує в ЕСОЗ, але не вдалося синхронізувати локальні дані: ' . $syncException->getMessage());
                    $this->showSignatureModal = false;
                }

                return;
            }

            $translatedMsg = $e->getFormattedMessage();
            Log::error('CarePlanShow: failed to sign referral validation: ' . $translatedMsg);
            Session::flash('error', $translatedMsg);
            $this->showSignatureModal = false;
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to sign referral: ' . $e->getMessage());
            Session::flash('error', 'Не вдалося підписати направлення: ' . $e->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function cancelReferral(string $requestId, string $kind): void
    {
        $this->openSignatureModal('cancel_referral', null, $requestId);
    }

    public function signCancelReferral(): void
    {
        if (empty($this->referralRequestIdToSign)) {
            Session::flash('error', 'Не вибрано направлення для скасування');
            $this->showSignatureModal = false;

            return;
        }

        $service = \App\Models\MedicalEvents\Sql\ServiceRequestRequest::where('uuid', $this->referralRequestIdToSign)->first();
        $device = null;
        if (!$service) {
            $device = \App\Models\MedicalEvents\Sql\DeviceRequestRequest::where('uuid', $this->referralRequestIdToSign)->first();
        }

        $record = $service ?: $device;
        if (!$record) {
            Session::flash('error', 'Направлення не знайдено');
            $this->showSignatureModal = false;

            return;
        }

        $kind = $service ? 'service_request' : 'device_request';

        try {
            $payload = [
                'status_reason' => $this->statusReason ?: 'entered-in-error'
            ];

            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($payload),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            if ($kind === 'service_request') {
                $response = EHealth::serviceRequest()->cancel($this->carePlan->person->uuid, $record->uuid, [
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64',
                    'status_reason' => $payload['status_reason'],
                ]);
            } else {
                $response = EHealth::deviceRequest()->cancel($this->carePlan->person->uuid, $record->uuid, [
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64',
                    'status_reason' => $payload['status_reason'],
                ]);
            }

            if ($response->successful()) {
                $record->update(['status' => 'entered-in-error']);
                $this->showSignatureModal = false;
                $this->refreshCarePlan();
                $this->dispatch('flashMessage', ['type' => 'success', 'message' => 'Направлення скасовано в eHealth.']);
            } else {
                throw new \Exception(json_encode($response->getData()));
            }
        } catch (EHealthValidationException $e) {
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to cancel referral validation: ' . $translatedMsg);
            Session::flash('error', $translatedMsg);
            $this->showSignatureModal = false;
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to cancel referral: ' . $e->getMessage());
            Session::flash('error', 'Не вдалося скасувати направлення: ' . $e->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function loadReferralPrintoutForm(string $requestId): string
    {
        try {
            $html = $this->referralLifecycle->buildPrintoutHtml($this->carePlan, $requestId);
            $this->printableContent = $html;

            return $html;
        } catch (\RuntimeException $exception) {
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => $exception->getMessage()]);

            return '';
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to load referral printout: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Не вдалося завантажити друковану форму.']);

            return '';
        }
    }

    public function syncReferralFromEHealth(string $requestUuid, string $kind): void
    {
        $requestRecord = $kind === 'service_request'
            ? \App\Repositories\MedicalEvents\Repository::serviceRequest()->findByUuid($requestUuid)
            : \App\Repositories\MedicalEvents\Repository::deviceRequest()->findByUuid($requestUuid);

        if (!$requestRecord) {
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Направлення не знайдено.']);

            return;
        }

        try {
            $activity = \App\Models\CarePlanActivity::find($requestRecord->based_on_id);
            if (!$activity) {
                throw new \RuntimeException('Призначення для направлення не знайдено');
            }

            $before = [
                'status' => (string) $requestRecord->status,
                'request_number' => (string) ($requestRecord->request_number ?? ''),
                'quantity' => (string) $requestRecord->quantity,
                'started_at' => $requestRecord->started_at?->format('Y-m-d'),
                'ended_at' => $requestRecord->ended_at?->format('Y-m-d'),
            ];

            $dbData = $this->buildReferralSignDbData($requestRecord, $activity);
            $this->referralLifecycle->syncReferralFromRemote(
                $this->carePlan,
                $activity,
                $requestRecord,
                $kind,
                $dbData
            );

            $requestRecord->refresh();

            $after = [
                'status' => (string) $requestRecord->status,
                'request_number' => (string) ($requestRecord->request_number ?? ''),
                'quantity' => (string) $requestRecord->quantity,
                'started_at' => $requestRecord->started_at?->format('Y-m-d'),
                'ended_at' => $requestRecord->ended_at?->format('Y-m-d'),
            ];

            Log::info('CarePlanShow: referral synced from eHealth', [
                'request_uuid' => $requestUuid,
                'person_uuid' => $this->carePlan->person->uuid,
                'kind' => $kind,
                'before' => $before,
                'after' => $after,
            ]);

            if ($activity->status === 'scheduled') {
                $activity->update(['status' => 'in-progress']);
            }

            $this->refreshCarePlan();

            $changes = [];
            foreach ($before as $field => $value) {
                if (($after[$field] ?? null) !== $value) {
                    $changes[] = match ($field) {
                        'status' => 'статус: ' . $this->resolveReferralStatusLabel($value) . ' → ' . $this->resolveReferralStatusLabel((string) $after[$field]),
                        'request_number' => 'номер: ' . ($value ?: '—') . ' → ' . ($after[$field] ?: '—'),
                        'quantity' => 'кількість: ' . $value . ' → ' . $after[$field],
                        'started_at' => 'початок: ' . ($value ?: '—') . ' → ' . ($after[$field] ?: '—'),
                        'ended_at' => 'кінець: ' . ($value ?: '—') . ' → ' . ($after[$field] ?: '—'),
                        default => $field,
                    };
                }
            }

            $this->dispatch('flashMessage', [
                'type' => 'success',
                'message' => $changes === []
                    ? __('care-plan.referral_sync_no_changes')
                    : __('care-plan.referral_sync_updated', ['changes' => implode('; ', $changes)]),
            ]);
        } catch (\Exception $exception) {
            Log::error('CarePlanShow: failed to sync referral from eHealth: ' . $exception->getMessage());
            $this->dispatch('flashMessage', [
                'type' => 'error',
                'message' => 'Не вдалося оновити направлення з ЕСОЗ: ' . $exception->getMessage(),
            ]);
        }
    }

    protected function resolveServiceCategory(string $serviceId): ?string
    {
        try {
            $response = EHealth::service()->getMany(['id' => $serviceId]);
            $catalog = $response->getData();

            if (!is_array($catalog)) {
                return null;
            }

            $category = $this->findServiceCategoryInCatalog($catalog, $serviceId);

            return $category !== null ? (string) $category : null;
        } catch (\Exception $exception) {
            Log::warning('CarePlanShow: failed to resolve service category: ' . $exception->getMessage());
        }

        return null;
    }

    /**
     * @param  array<mixed>  $nodes
     */
    protected function findServiceCategoryInCatalog(array $nodes, string $serviceId): ?string
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            if (($node['id'] ?? null) === $serviceId && !empty($node['category'])) {
                return (string) $node['category'];
            }

            foreach (['services', 'groups'] as $childKey) {
                if (!empty($node[$childKey]) && is_array($node[$childKey])) {
                    $category = $this->findServiceCategoryInCatalog($node[$childKey], $serviceId);
                    if ($category !== null) {
                        return $category;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array{started_at: string, ended_at: string}
     */
    protected function resolveReferralOccurrenceDates(?\Carbon\Carbon $scheduledStart, ?\Carbon\Carbon $scheduledEnd): array
    {
        $minStart = now()->addHour();
        $start = $scheduledStart && $scheduledStart->greaterThan($minStart)
            ? $scheduledStart->copy()
            : $minStart->copy();

        $end = $scheduledEnd && $scheduledEnd->greaterThan($start)
            ? $scheduledEnd->copy()
            : $start->copy()->addMonths(3);

        return [
            'started_at' => $start->format('d.m.Y'),
            'ended_at' => $end->format('d.m.Y'),
        ];
    }

    /**
     * @return array{
     *     employee_id: int|null,
     *     division_id: int|null,
     *     employee_uuid: string|null,
     *     legal_entity_uuid: string|null
     * }
     */
    protected function resolveReferralEmployeeContext(
        \App\Models\MedicalEvents\Sql\ServiceRequestRequest|\App\Models\MedicalEvents\Sql\DeviceRequestRequest $requestRecord,
        \App\Models\CarePlanActivity $activity
    ): array {
        $context = $this->referralLifecycle->resolveEmployeeContext(
            $this->carePlan,
            $activity,
            $requestRecord->employee_id
        );

        return [
            'employee_id' => $requestRecord->employee_id ?? $context['employee_id'],
            'division_id' => $requestRecord->division_id ?? $context['division_id'],
            'employee_uuid' => $context['employee_uuid'],
            'legal_entity_uuid' => $context['legal_entity_uuid'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildReferralSignDbData(
        \App\Models\MedicalEvents\Sql\ServiceRequestRequest|\App\Models\MedicalEvents\Sql\DeviceRequestRequest $requestRecord,
        \App\Models\CarePlanActivity $activity
    ): array {
        $employeeContext = $this->resolveReferralEmployeeContext($requestRecord, $activity);
        $startedAt = $requestRecord->started_at ?? $activity->scheduled_period_start;
        $endedAt = $requestRecord->ended_at ?? $activity->scheduled_period_end;

        $dbData = [
            'uuid' => $requestRecord->uuid,
            'employee_id' => $employeeContext['employee_id'],
            'division_id' => $employeeContext['division_id'],
            'based_on_id' => $requestRecord->based_on_id ?? $activity->id,
            'context_id' => $requestRecord->context_id ?? $this->carePlan->encounter?->id,
            'quantity' => $requestRecord->quantity,
            'quantity_system' => $activity->quantity_system ?: 'SERVICE_UNIT',
            'quantity_code' => $activity->quantity_code ?: 'PIECE',
            'intent' => $requestRecord->intent ?? 'order',
            'category' => $requestRecord->category,
            'program_id' => $requestRecord->program_id,
            'priority' => $requestRecord->priority ?? 'routine',
            'note' => $requestRecord->note,
            'supporting_info' => $requestRecord->supporting_info,
            'started_at' => $startedAt instanceof \DateTimeInterface
                ? $startedAt->format('Y-m-d')
                : (string) $startedAt,
            'ended_at' => $endedAt instanceof \DateTimeInterface
                ? $endedAt->format('Y-m-d')
                : (string) $endedAt,
            'based_on_uuid' => $activity->uuid,
        ];

        if ($requestRecord instanceof \App\Models\MedicalEvents\Sql\ServiceRequestRequest) {
            $dbData['service_id'] = $requestRecord->service_id ?: $activity->product_reference;
        } else {
            if (!empty($activity->product_reference)) {
                $dbData['device_id'] = $requestRecord->device_id ?: $activity->product_reference;
                $dbData['device_code_type'] = 'DEVICE_DEFINITION';
            } else {
                $dbData['device_id'] = $requestRecord->device_id ?: $activity->product_codeable_concept;
                $dbData['device_code_type'] = 'CLASSIFICATION_TYPE';
            }

            $dbData['quantity_system'] = $activity->quantity_system ?: 'device_unit';
            $dbData['quantity_code'] = strtolower($activity->quantity_code ?: 'piece');
        }

        return $dbData;
    }

    /**
     * @param  array<string, mixed>  $dbData
     */
    protected function finalizeSignedReferral(array $dbData, string $kind, \App\Models\CarePlanActivity $activity, bool $alreadyPersisted = false): void
    {
        if (!$alreadyPersisted) {
            if ($kind === 'service_request') {
                \App\Repositories\MedicalEvents\Repository::serviceRequest()->store($dbData, $this->carePlan->person_id);
            } else {
                \App\Repositories\MedicalEvents\Repository::deviceRequest()->store($dbData, $this->carePlan->person_id);
            }
        }

        if ($activity->status === 'scheduled') {
            $activity->update(['status' => 'in-progress']);
        }

        $this->showSignatureModal = false;
        $finalStatusCode = strtolower((string) ($dbData['status'] ?? ''));
        if (in_array($finalStatusCode, ['pending', 'processing'], true)) {
            $this->dispatch('flashMessage', [
                'type' => 'warning',
                'message' => 'Запит на направлення прийнято в обробку ЕСОЗ. Фінальний статус з’явиться після завершення асинхронної задачі.',
            ]);
        } elseif ($alreadyPersisted) {
            $this->dispatch('flashMessage', [
                'type' => 'success',
                'message' => 'Направлення вже існувало в ЕСОЗ. Локальні дані синхронізовано.',
            ]);
        } else {
            $this->dispatch('flashMessage', ['type' => 'success', 'message' => 'Направлення успішно створено та підписано в eHealth.']);
        }
        $this->refreshCarePlan();
    }

    protected function resolveReferralCategoryLabel(string $category): string
    {
        $key = 'care-plan.referral_category.' . $category;

        return Lang::has($key) ? __($key) : $category;
    }
}
