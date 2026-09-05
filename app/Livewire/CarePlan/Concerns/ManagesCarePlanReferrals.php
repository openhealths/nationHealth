<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Concerns;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Repositories\CarePlanActivityRepository;
use App\Services\MedicalEvents\CarePlanActivityEHealthGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

trait ManagesCarePlanReferrals
{
    public function initReferralForm(int $activityId, CarePlanActivityRepository $activityRepository): void
    {
        $this->authorizeCarePlanWrite();

        $activity = $this->ownedActivity($activityId);

        $activityStatus = strtolower(is_array($activity->status)
            ? ($activity->status['coding'][0]['code'] ?? ($activity->status['text'] ?? ''))
            : (string) $activity->status);

        $blockedActivityStatuses = ['cancelled', 'completed'];

        if ($this->isTerminalCarePlan) {
            $this->flashOutcome('error', __('care-plan.cannot_mutate_terminal_care_plan', [
                'status' => \App\Enums\CarePlanStatus::labelFor($this->carePlan->status),
            ]));

            return;
        }

        if (in_array($activityStatus, $blockedActivityStatuses)) {
            session()->flash('error', 'Виписування направлення заборонено: це призначення вже завершено або скасовано.');

            return;
        }

        $resolvedKind = $activity->resolvedKind();
        if (!in_array($resolvedKind, ['service_request', 'device_request'], true)) {
            session()->flash('error', __('care-plan.referral_wrong_activity_kind'));

            return;
        }

        try {
            app(CarePlanActivityEHealthGuard::class)->assertRegisteredInEHealth($this->carePlan, $activity);
        } catch (\RuntimeException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        $existingDraft = app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class)->findDraftByActivity($activity);
        if ($existingDraft) {
            if (app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class)->trySyncDraftFromEHealth($this->carePlan, $activity, $existingDraft, $resolvedKind)) {
                if ($activity->status === 'scheduled') {
                    $activity->update(['status' => 'in-progress']);
                }
                $this->refreshCarePlan();
                $documentLabel = $resolvedKind === 'device_request'
                    ? __('care-plan.document_type_device_eprescription')
                    : __('care-plan.document_type_service_referral');
                session()->flash('success', __('care-plan.referral_already_in_ehealth_synced', ['document' => $documentLabel]));

                return;
            }

            $this->referralRequestIdToSign = $existingDraft->uuid;
            $signAction = $resolvedKind === 'service_request'
                ? 'sign_servicerequest'
                : 'sign_devicerequest';
            $documentLabel = $resolvedKind === 'device_request'
                ? __('care-plan.document_type_device_eprescription')
                : __('care-plan.document_type_service_referral');
            session()->flash('info', __('care-plan.referral_unsigned_draft_found', ['document' => $documentLabel]));
            $this->openSignatureModal($signAction);

            return;
        }

        $this->referralWarningMessage = '';
        $this->referralDevicePackageQty = 0;

        // Calculate remaining quantity
        $activityQty = (float) ($activity->quantity ?? 0);
        $issuedQty = app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class)->sumIssuedQuantity($activity);
        $this->referralRemainingQty = $activity->quantity === null
            ? 1.0
            : max(0.0, $activityQty - $issuedQty);

        $defaultQuantity = min($this->referralRemainingQty, 1.0);
        if ($resolvedKind === 'device_request') {
            $this->referralDevicePackageQty = $this->resolveDevicePackageQuantity($activity);
            if ($this->referralDevicePackageQty <= 0) {
                session()->flash('error', __('care-plan.device_package_qty_unknown'));

                return;
            }

            if ($this->referralRemainingQty < $this->referralDevicePackageQty) {
                session()->flash('error', __('care-plan.device_remaining_below_packaging', [ 'remaining' => $this->referralRemainingQty, 'count' => $this->referralDevicePackageQty, ]));

                return;
            }

            // Default to one package; never fall back to 1 piece when package > 1.
            $defaultQuantity = (float) $this->referralDevicePackageQty;
        }

        $code = $activity->productCodeableConcept ?? $activity->productReference ?? 'од.';

        $category = $resolvedKind === 'service_request'
            ? $this->resolveServiceCategory((string) $activity->productReference)
            : null;

        $this->referralServiceCategory = $category ?? 'procedure';

        $occurrenceDates = $this->resolveReferralOccurrenceDates(
            $activity->scheduledPeriodStart,
            $activity->scheduledPeriodEnd
        );

        $reasonReference = [];
        $activity->reasonReferences()->get()->each(function ($identifier) use (&$reasonReference) {
            $typeCode = $identifier->type->first()?->coding?->first()?->code ?? 'condition';
            $reasonReference[] = [
                'type' => $typeCode,
                'uuid' => $identifier->value
            ];
        });

        $informWith = '';
        $this->referralAuthMethods = [];
        try {
            $authMethods = EHealth::person()->getAuthMethods($this->carePlan->person->uuid)->getData();
            if (is_array($authMethods)) {
                $this->referralAuthMethods = collect($authMethods)->map(static function (array $method): array {
                    $uuid = (string) ($method['id'] ?? $method['uuid'] ?? '');
                    $type = (string) ($method['type'] ?? '');
                    $value = (string) ($method['phone_number'] ?? $method['value'] ?? '');

                    return [
                        'uuid' => $uuid,
                        'label' => trim($type.($value !== '' ? ' · '.$value : '')),
                        'raw' => $uuid !== '' ? "{$uuid}|{$type}|{$value}" : '',
                    ];
                })->filter(static fn (array $m): bool => $m['uuid'] !== '')->values()->all();

                if ($this->referralAuthMethods !== []) {
                    $informWith = $this->referralAuthMethods[0]['raw'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('CarePlanShow: failed to load auth methods for referral: '.$e->getMessage());
        }

        $this->referralForm = [
            'activity_id' => $activity->id,
            'kind' => $resolvedKind,
            'code' => $code,
            'quantity' => $defaultQuantity,
            'started_at' => $occurrenceDates['started_at'],
            'ended_at' => $occurrenceDates['ended_at'],
            'priority' => 'routine',
            'intent' => 'order',
            'category' => $this->referralServiceCategory,
            'category_label' => $this->resolveReferralCategoryLabel($this->referralServiceCategory),
            'note' => '',
            'patient_instruction' => '',
            'reason_reference' => $reasonReference,
            'inform_with' => $informWith,
            'program_id' => $activity->program ?? '',
            'supporting_info' => []
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
            $this->flashUserError('Кількість перевищує залишок за призначенням.');

            return;
        }

        if (
            ($this->referralForm['kind'] ?? '') === 'device_request'
            && $this->referralDevicePackageQty <= 0
        ) {
            $message = __('care-plan.device_package_qty_unknown');
            $this->referralWarningMessage = $message;
            $this->flashUserError($message);

            return;
        }

        if (
            ($this->referralForm['kind'] ?? '') === 'device_request'
            && $this->referralDevicePackageQty > 0
            && ((int) $qty % $this->referralDevicePackageQty) !== 0
        ) {
            $message = __('care-plan.device_quantity_packaging', ['count' => $this->referralDevicePackageQty]);
            $this->referralWarningMessage = $message;
            $this->flashUserError($message);

            return;
        }

        // Propose to sign
        $this->showReferralDrawer = false;

        $activity = $this->ownedActivity((int) $this->referralForm['activity_id']);
        if ($activity) {
            $existingDraft = app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class)->findDraftByActivity($activity);
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
            $this->carePlan->loadMissing(['encounter', 'person']);

            $employeeContext = app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class)->resolveEmployeeContext(
                $this->carePlan,
                $activity,
                Auth::user()?->activeDoctorEmployee()?->id
            );

            $this->referralRequestIdToSign = app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class)->createCarePlanDraft(
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
            $this->flashUserError($exception->getTranslatedMessage());
        } catch (\Exception $exception) {
            $this->showReferralDrawer = true;
            Log::error('CarePlanShow: failed to create referral request: ' . $exception->getMessage());
            $kindLabel = ($this->referralForm['kind'] ?? '') === 'device_request'
                ? 'електронний рецепт на медичні вироби'
                : 'заявку на направлення';
            $this->flashUserError('Не вдалося створити '.$kindLabel.': '.$exception->getMessage());
        }
    }

    public function resendReferralSms(string $requestId, string $kind): void
    {
        $this->authorizeCarePlanWrite();
        app(\App\Services\MedicalEvents\MedicalRequestOwnership::class)
            ->referralForPerson($requestId, (int) $this->carePlan->personId);

        try {
            $response = app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class)->resendSms($this->carePlan->person->uuid, $requestId, $kind);

            if ($response->successful()) {
                $this->flashOutcome('success', __('care-plan.referral_sms_resent'));

                return;
            }

            $this->flashOutcome('error', 'Не вдалося повторно надіслати СМС: ' . json_encode($response->getData()));
        } catch (EHealthValidationException $exception) {
            Log::error('CarePlanShow: failed to resend referral SMS validation: ' . $exception->getTranslatedMessage());
            $this->flashOutcome('error', $exception->getTranslatedMessage());
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() === 403) {
                Log::warning('CarePlanShow: referral SMS resend forbidden by eHealth ACL', [
                    'request_id' => $requestId,
                    'person_uuid' => $this->carePlan->person->uuid,
                ]);
                $this->flashOutcome('warning', __('care-plan.referral_sms_forbidden'));

                return;
            }

            Log::error('CarePlanShow: failed to resend referral SMS response: ' . $exception->getMessage());
            $this->flashOutcome('error', 'Помилка надсилання СМС: ' . $exception->getMessage());
        } catch (\Exception $exception) {
            Log::error('CarePlanShow: failed to resend referral SMS: ' . $exception->getMessage());
            $this->flashOutcome('error', 'Помилка надсилання СМС: ' . $exception->getMessage());
        }
    }

    public function signReferral(): void
    {
        if (empty($this->referralRequestIdToSign)) {
            $this->flashUserError('Не вибрано документ для підписання');
            $this->showSignatureModal = false;

            return;
        }

        $this->carePlan->loadMissing(['encounter', 'person']);

        $requestRecord = app(\App\Services\MedicalEvents\MedicalRequestOwnership::class)
            ->referralForPerson((string) $this->referralRequestIdToSign, (int) $this->carePlan->personId);

        $kind = $requestRecord instanceof \App\Models\MedicalEvents\Sql\ServiceRequestRequest
            ? 'service_request'
            : 'device_request';

        try {
            $activity = $this->ownedActivity((int) $requestRecord->basedOnId);
            if (!$activity) {
                throw new \RuntimeException('Призначення для направлення не знайдено');
            }

            $employeeContext = $this->resolveReferralEmployeeContext($requestRecord, $activity);

            $uuids = [
                'person_uuid' => $this->carePlan->person->uuid,
                'encounter_uuid' => $this->carePlan->encounter?->uuid ?? null,
                'episode_uuid' => $this->carePlan->episodeUuid(),
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

            $finalResponse = app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class)
                ->submitSignedCreate($kind, $this->carePlan->person->uuid, $signedContent);

            $dbData = app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class)->persistAfterSignedCreate(
                $dbData,
                $finalResponse,
                $kind,
                (int) $this->carePlan->personId
            );

            $this->finalizeSignedReferral($dbData, $kind, $activity, alreadyPersisted: true);

        } catch (EHealthValidationException $e) {
            if ($e->isDuplicateReferralError()) {
                try {
                    $activity = $this->ownedActivity((int) $requestRecord->basedOnId);
                    if (!$activity) {
                        throw new \RuntimeException('Призначення для направлення не знайдено');
                    }

                    $dbData = $this->buildReferralSignDbData($requestRecord, $activity);
                    $dbData = app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class)->syncReferralFromRemote(
                        $this->carePlan,
                        $activity,
                        $requestRecord,
                        $kind,
                        $dbData
                    );
                    $this->finalizeSignedReferral($dbData, $kind, $activity, true);
                } catch (\Exception $syncException) {
                    Log::error('CarePlanShow: failed to sync referral after duplicate eHealth id: ' . $syncException->getMessage());
                    $this->flashUserError('Документ вже існує в ЕСОЗ, але не вдалося синхронізувати локальні дані: ' . $syncException->getMessage());
                    $this->showSignatureModal = false;
                }

                return;
            }

            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to sign referral validation: ' . $translatedMsg);
            $this->flashUserError($translatedMsg);
            $this->showSignatureModal = false;
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to sign referral: ' . $e->getMessage());
            $this->flashUserError('Не вдалося підписати документ: ' . $e->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function cancelReferral(string $requestId, string $kind): void
    {
        $this->openSignatureModal('cancel_referral', null, $requestId);
    }

    public function recallReferral(string $requestId, string $kind): void
    {
        $this->referralExplanatoryLetter = '';
        $this->openSignatureModal('recall_referral', null, $requestId);
    }

    public function signRecallReferral(): void
    {
        if (empty($this->referralRequestIdToSign)) {
            $this->flashOutcome('error', 'Не вибрано направлення для відкликання');
            $this->showSignatureModal = false;

            return;
        }

        $letter = trim((string) $this->referralExplanatoryLetter);
        if ($letter === '') {
            $this->addError('referralExplanatoryLetter', __('care-plan.referral_recall_letter_required'));

            return;
        }

        $record = app(\App\Services\MedicalEvents\MedicalRequestOwnership::class)
            ->referralForPerson((string) $this->referralRequestIdToSign, (int) $this->carePlan->personId);
        $service = $record instanceof \App\Models\MedicalEvents\Sql\ServiceRequestRequest ? $record : null;
        $device = $service ? null : $record;

        $record = $service ?: $device;

        if (!$service) {
            $this->flashOutcome('error', __('care-plan.referral_recall_service_only'));
            $this->showSignatureModal = false;

            return;
        }

        try {
            $payload = [
                'explanatory_letter' => $letter,
            ];

            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($payload),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            $finalResponse = app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class)
                ->submitSignedRecall($this->carePlan->person->uuid, $record->uuid, [
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64',
                    'explanatory_letter' => $letter,
                ]);

            $this->persistReferralStatusFromJob(
                $finalResponse,
                $record,
                \App\Enums\Person\ServiceRequestStatus::RECALLED
            );
            $this->showSignatureModal = false;
            $this->referralExplanatoryLetter = '';
            $this->refreshCarePlan();
            $this->flashOutcome('success', __('care-plan.referral_recall_success'));
        } catch (EHealthValidationException $e) {
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to recall referral validation: '.$translatedMsg);
            $this->flashOutcome('error', $translatedMsg);
            $this->showSignatureModal = false;
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to recall referral: '.$e->getMessage());
            $this->flashOutcome('error', 'Не вдалося відкликати направлення: '.$e->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function signCancelReferral(): void
    {
        if (empty($this->referralRequestIdToSign)) {
            $this->flashOutcome('error', 'Не вибрано направлення для скасування');
            $this->showSignatureModal = false;

            return;
        }

        $record = app(\App\Services\MedicalEvents\MedicalRequestOwnership::class)
            ->referralForPerson((string) $this->referralRequestIdToSign, (int) $this->carePlan->personId);
        $service = $record instanceof \App\Models\MedicalEvents\Sql\ServiceRequestRequest ? $record : null;
        $device = $service ? null : $record;

        $record = $service ?: $device;

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

            $finalResponse = app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class)
                ->submitSignedCancel($kind, $this->carePlan->person->uuid, $record->uuid, [
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64',
                    'status_reason' => $payload['status_reason'],
                ]);

            $this->persistReferralStatusFromJob(
                $finalResponse,
                $record,
                \App\Enums\Person\ServiceRequestStatus::ENTERED_IN_ERROR
            );
            $this->showSignatureModal = false;
            $this->refreshCarePlan();
            $this->flashOutcome('success', __('care-plan.referral_cancel_success'));
        } catch (EHealthValidationException $e) {
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to cancel referral validation: ' . $translatedMsg);
            $this->flashOutcome('error', $translatedMsg);
            $this->showSignatureModal = false;
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to cancel referral: ' . $e->getMessage());
            $this->flashOutcome('error', 'Не вдалося скасувати направлення: ' . $e->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function loadReferralPrintoutForm(string $requestId): string
    {
        try {
            $html = app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class)->buildPrintoutHtml($this->carePlan, $requestId);
            $this->printableContent = $html;

            return $html;
        } catch (\RuntimeException $exception) {
            $this->flashOutcome('error', $exception->getMessage());

            return '';
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to load referral printout: ' . $e->getMessage());
            $this->flashOutcome('error', 'Не вдалося завантажити друковану форму.');

            return '';
        }
    }

    public function syncReferralFromEHealth(string $requestUuid, string $kind): void
    {
        $this->authorizeCarePlanWrite();

        app(\App\Services\MedicalEvents\MedicalRequestOwnership::class)
            ->referralForPerson($requestUuid, (int) $this->carePlan->personId);

        $requestRecord = $kind === 'service_request'
            ? \App\Repositories\MedicalEvents\Repository::serviceRequest()->findByUuid($requestUuid)
            : \App\Repositories\MedicalEvents\Repository::deviceRequest()->findByUuid($requestUuid);

        if (!$requestRecord) {
            $this->flashOutcome('error', 'Направлення не знайдено.');

            return;
        }

        try {
            $activity = $this->ownedActivity((int) $requestRecord->basedOnId);
            if (!$activity) {
                throw new \RuntimeException('Призначення для направлення не знайдено');
            }

            $before = [
                'status' => (string) $requestRecord->status,
                'request_number' => (string) ($requestRecord->requestNumber ?? ''),
                'quantity' => (string) $requestRecord->quantity,
                'started_at' => $requestRecord->startedAt?->format('Y-m-d'),
                'ended_at' => $requestRecord->endedAt?->format('Y-m-d'),
            ];

            $dbData = $this->buildReferralSignDbData($requestRecord, $activity);
            app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class)->syncReferralFromRemote(
                $this->carePlan,
                $activity,
                $requestRecord,
                $kind,
                $dbData
            );

            $requestRecord->refresh();

            $after = [
                'status' => (string) $requestRecord->status,
                'request_number' => (string) ($requestRecord->requestNumber ?? ''),
                'quantity' => (string) $requestRecord->quantity,
                'started_at' => $requestRecord->startedAt?->format('Y-m-d'),
                'ended_at' => $requestRecord->endedAt?->format('Y-m-d'),
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

            $this->flashOutcome('success', $changes === [] ? __('care-plan.referral_sync_no_changes') : __('care-plan.referral_sync_updated', ['changes' => implode('; ', $changes)]));
        } catch (\Exception $exception) {
            Log::error('CarePlanShow: failed to sync referral from eHealth: ' . $exception->getMessage());
            $this->flashOutcome('error', 'Не вдалося оновити направлення з ЕСОЗ: ' . $exception->getMessage());
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
        $minStart = now();
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
     * Resolve device packaging_count from eHealth device definition (package step for eRx).
     */
    protected function resolveDevicePackageQuantity(\App\Models\CarePlanActivity $activity): int
    {
        $reference = (string) ($activity->productReference ?? '');
        if ($reference === '') {
            return 0;
        }

        try {
            $device = $this->findDeviceDefinitionByReference($reference, $activity->program);

            if (!is_array($device)) {
                return 0;
            }

            $packaging = $device['packaging'] ?? null;
            $count = is_array($packaging)
                ? (int) ($packaging['packaging_count'] ?? $packaging['packagingCount'] ?? 0)
                : 0;

            return max(0, $count);
        } catch (\Throwable $exception) {
            Log::warning('CarePlanShow: failed to resolve device package quantity: '.$exception->getMessage());

            return 0;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findDeviceDefinitionByReference(string $reference, ?string $programId): ?array
    {
        try {
            $data = EHealth::deviceDefinition()->getById($reference)->getData();
            if (is_array($data) && (isset($data['id']) || isset($data['uuid']))) {
                return $data;
            }
            if (isset($data[0]) && is_array($data[0])) {
                return $data[0];
            }
        } catch (\Throwable $exception) {
            Log::debug('CarePlanShow: device definition getById failed: '.$exception->getMessage(), [
                'uuid' => $reference,
            ]);
        }

        $filters = ['page_size' => 100];
        if (!empty($programId)) {
            $filters['medical_program_id'] = $programId;
        }

        $page = 1;
        $maxPages = 10;

        do {
            $filters['page'] = $page;
            $response = EHealth::deviceDefinition()->getMany($filters);
            $device = collect($response->getData())->first(
                static fn (array $item): bool => (string) ($item['id'] ?? $item['uuid'] ?? '') === $reference
            );

            if (is_array($device)) {
                return $device;
            }

            $paging = $response->getPaging();
            $totalPages = (int) ($paging['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages && $page <= $maxPages);

        return null;
    }

    protected function flashUserError(string $message): void
    {
        $this->flashOutcome('error', $message);
    }

    protected function flashUserSuccess(string $message): void
    {
        $this->flashOutcome('success', $message);
    }

    /**
     * @param  array<string, mixed>  $finalResponse
     */
    protected function persistReferralStatusFromJob(
        array $finalResponse,
        \App\Models\MedicalEvents\Sql\ServiceRequestRequest|\App\Models\MedicalEvents\Sql\DeviceRequestRequest $record,
        \App\Enums\Person\ServiceRequestStatus $fallback
    ): void {
        $result = $finalResponse['result'] ?? null;
        $entity = is_array($result) ? ($result[0] ?? $result) : $finalResponse;
        $raw = is_array($entity) ? ($entity['status'] ?? null) : null;
        $resolved = is_string($raw) ? \App\Enums\Person\ServiceRequestStatus::resolve($raw) : null;

        $record->update([
            'status' => ($resolved ?? $fallback)->value,
        ]);
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
        $context = app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class)->resolveEmployeeContext(
            $this->carePlan,
            $activity,
            $requestRecord->employeeId
        );

        return [
            'employee_id' => $requestRecord->employeeId ?? $context['employee_id'],
            'division_id' => $requestRecord->divisionId ?? $context['division_id'],
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

        return app(\App\Services\MedicalEvents\ReferralRequestLifecycleService::class)->buildSignDbData(
            $requestRecord,
            $activity,
            $this->carePlan,
            $employeeContext
        );
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
        $documentLabel = $kind === 'device_request'
            ? 'Електронний рецепт на медичні вироби'
            : 'Електронне направлення';
        $finalStatusCode = strtolower((string) ($dbData['status'] ?? ''));
        if (in_array($finalStatusCode, ['pending', 'processing'], true)) {
            Session::flash('info', $documentLabel.' прийнято в обробку ЕСОЗ. Фінальний статус з’явиться після завершення асинхронної задачі.');
            session()->flash('warning', $documentLabel.' прийнято в обробку ЕСОЗ. Фінальний статус з’явиться після завершення асинхронної задачі.');
        } elseif ($alreadyPersisted) {
            $this->flashUserSuccess($documentLabel.' вже існував у ЕСОЗ. Локальні дані синхронізовано.');
        } else {
            $this->flashUserSuccess($documentLabel.' успішно створено та підписано в ЕСОЗ.');
        }
        $this->refreshCarePlan();
    }

    protected function resolveReferralCategoryLabel(string $category): string
    {
        $key = 'care-plan.referral_category.' . $category;

        return Lang::has($key) ? __($key) : $category;
    }
}
