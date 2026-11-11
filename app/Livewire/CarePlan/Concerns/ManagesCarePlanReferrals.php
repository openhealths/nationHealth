<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Concerns;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealth as SubmissionApi;
use App\Core\Arr;
use App\Enums\CarePlanStatus;
use App\Enums\Person\ServiceRequestStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\Concerns\SubmitsClinicalOperations;
use App\Livewire\Referral\Forms\ServiceRequestForm;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\MedicalEvents\CarePlanActivityEHealthGuard;
use App\Services\MedicalEvents\Fhir;
use App\Services\MedicalEvents\Mappers\DeviceRequestMapper;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use App\Services\MedicalEvents\MedicalRequestOwnership;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use RuntimeException;
use Throwable;

trait ManagesCarePlanReferrals
{
    use SubmitsClinicalOperations;

    #[Computed]
    public function referralPriorityOptions(): array
    {
        $name = ($this->referralForm['kind'] ?? '') === 'device_request'
            ? 'device_request_priority'
            : 'SERVICE_REQUEST_PRIORITY';

        return dictionary()->basics()->byName($name)->asCodeDescription()->all();
    }

    public function initReferralForm(int $activityId): void
    {
        if ($this->authorizeCarePlanActivityWrite() === false) {
            return;
        }

        try {
            $activity = $this->ownedActivity($activityId);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        }

        $activityStatus = strtolower(is_array($activity->status)
            ? ($activity->status['coding'][0]['code'] ?? ($activity->status['text'] ?? ''))
            : (string) $activity->status);

        $blockedActivityStatuses = ['cancelled', 'completed'];

        if ($this->isTerminalCarePlan) {
            Session::flash('error', __('care-plan.cannot_mutate_terminal_care_plan', [
                'status' => CarePlanStatus::labelFor($this->carePlan->status),
            ]));

            return;
        }

        if (in_array($activityStatus, $blockedActivityStatuses)) {
            Session::flash('error', __('Виписування направлення заборонено: це призначення вже завершено або скасовано.'));

            return;
        }

        $resolvedKind = $activity->resolvedKind();
        if (!in_array($resolvedKind, ['service_request', 'device_request'], true)) {
            Session::flash('error', __('care-plan.referral_wrong_activity_kind'));

            return;
        }

        try {
            app(CarePlanActivityEHealthGuard::class)->assertRegisteredInEHealth($this->carePlan, $activity);
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());

            return;
        }

        if ($resolvedKind === 'device_request') {
            $this->redirectRoute('persons.device-requests.create', [
                'legalEntity' => legalEntity(),
                'person' => $this->carePlan->person,
                'carePlan' => $this->carePlan->id,
                'activity' => $activity->id,
            ]);

            return;
        }

        $existingDraft = app(ReferralRequestLifecycleService::class)->findDraftByActivity($activity);
        if ($existingDraft) {
            if (app(ReferralRequestLifecycleService::class)->trySyncDraftFromEHealth($this->carePlan, $activity, $existingDraft, $resolvedKind)) {
                if ($activity->status === 'scheduled') {
                    Repository::carePlanActivity()->updateById($activity->id, ['status' => 'in-progress']);
                }
                $this->refreshCarePlan();
                $documentLabel = $resolvedKind === 'device_request'
                    ? __('care-plan.document_type_device_eprescription')
                    : __('care-plan.document_type_service_referral');
                Session::flash('success', __('care-plan.referral_already_in_ehealth_synced', ['document' => $documentLabel]));

                return;
            }

            $this->referralRequestIdToSign = $existingDraft->uuid;
            $documentLabel = $resolvedKind === 'device_request'
                ? __('care-plan.document_type_device_eprescription')
                : __('care-plan.document_type_service_referral');
            Session::flash('info', __('care-plan.referral_unsigned_draft_found', ['document' => $documentLabel]));
        }

        $this->showSignatureModal = false;
        $this->resetValidation();
        $this->referralWarningMessage = '';
        $this->referralDevicePackageQty = 0;

        // Calculate remaining quantity
        $activityQty = (float) ($activity->quantity ?? 0);
        $issuedQty = app(ReferralRequestLifecycleService::class)->sumIssuedQuantity($activity);
        $this->referralRemainingQty = $activity->quantity === null
            ? 1.0
            : max(0.0, $activityQty - $issuedQty);

        $defaultQuantity = min($this->referralRemainingQty, 1.0);
        if ($resolvedKind === 'device_request') {
            $this->referralDevicePackageQty = $this->resolveDevicePackageQuantity($activity);
            if ($this->referralDevicePackageQty <= 0) {
                Session::flash('error', __('care-plan.device_package_qty_unknown'));

                return;
            }

            if ($this->referralRemainingQty < $this->referralDevicePackageQty) {
                Session::flash('error', __('care-plan.device_remaining_below_packaging', [ 'remaining' => $this->referralRemainingQty, 'count' => $this->referralDevicePackageQty, ]));

                return;
            }

            // Default to one package; never fall back to 1 piece when package > 1.
            $defaultQuantity = (float) $this->referralDevicePackageQty;
        }

        $activityResource = Repository::carePlanActivity()->getResource($activity);
        $code = data_get($activityResource, 'detail.productReference.identifier.value')
            ?? ($activity->productCodeableConcept ?? ($activity->productReference ?? 'од.'));
        $this->referralServiceType = data_get($activityResource, 'detail.productReference.identifier.type.coding.0.code', 'service');
        $this->referralCategoryOptions = [];
        if ($this->referralServiceType === 'service_group') {
            try {
                $group = dictionary()->services()->flattened()->firstWhere('id', $code);
                if (($group['type'] ?? '') !== 'service_group' || empty($group['request_allowed'])) {
                    Session::flash('error', __('care-plan.service_group_referral_not_allowed'));

                    return;
                }
                $this->referralCategoryOptions = dictionary()->basics()
                    ->byName('eHealth/SNOMED/service_request_categories')->asCodeDescription()
                    ->except('transfer_of_care')->all(); // Transfers use the encounter discharge flow.
                if ($this->referralCategoryOptions === []) {
                    Session::flash('error', __('care-plan.referral_categories_unavailable'));

                    return;
                }
            } catch (Throwable $exception) {
                report($exception);
                Session::flash('error', __('care-plan.referral_categories_unavailable'));

                return;
            }
        }
        $category = $resolvedKind === 'service_request' && $this->referralServiceType !== 'service_group'
            ? $this->resolveServiceCategory((string) $code)
            : null;

        $this->referralServiceCategory = $category ?? ($this->referralServiceType === 'service_group' ? '' : 'procedure');

        $schedule = $activityResource['detail']['scheduledPeriod'] ?? [];
        $draftData = $existingDraft ? MedicalEventsRepository::serviceRequest()->formData($existingDraft) : [];
        if ($this->referralServiceType === 'service_group') {
            $this->referralServiceCategory = $draftData['category'] ?? '';
        }
        $occurrenceDates = $this->resolveReferralOccurrenceDates(
            isset($schedule['start']) ? Carbon::parse($schedule['start'], 'UTC')->setTimezone(config('app.timezone')) : null,
            isset($schedule['end']) ? Carbon::parse($schedule['end'], 'UTC')->setTimezone(config('app.timezone')) : null
        );
        if ($existingDraft) {
            foreach (['started_at' => 'start', 'ended_at' => 'end'] as $field => $boundary) {
                if (!empty($draftData[$field])) {
                    $instant = CarbonImmutable::parse($draftData[$field], 'UTC')->setTimezone(config('app.timezone'));
                    $occurrenceDates[$field] = $instant->format('d.m.Y');
                    $occurrenceDates[$boundary.'_time'] = $instant->format('H:i');
                }
            }
        }

        $reasonReference = [];
        $grounds = collect($this->availableConditions)->concat($this->availableReports)->concat($this->availableObservations)->keyBy('uuid');
        $activity->reasonReferences()->with('type.coding')->get()->each(function ($identifier) use (&$reasonReference, $grounds) {
            $typeCode = $identifier->type->first()?->coding?->first()?->code ?? 'condition';
            $reasonReference[] = [
                'type' => $typeCode,
                'uuid' => $identifier->value,
                'name' => $grounds->get($identifier->value)['name'] ?? ($identifier->displayValue ?? __('care-plan.ground_name_unavailable')),
            ];
        });

        $informWith = '';
        $this->referralAuthMethods = [];
        try {
            $authMethods = EHealth::person()->getAuthMethods($this->carePlan->person->uuid)->validate();
            if (is_array($authMethods)) {
                $this->referralAuthMethods = collect($authMethods)->map(static function (array $method): array {
                    $uuid = (string) ($method['id'] ?? ($method['uuid'] ?? ''));
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
        } catch (Throwable $e) {
            Log::warning('CarePlanShow: failed to load auth methods for referral: '.$e->getMessage());
        }

        $this->referralForm = [
            'activity_id' => $activity->id,
            'kind' => $resolvedKind,
            'code' => $code,
            'code_label' => $this->resolveReferralServiceLabel($activity),
            'quantity' => $defaultQuantity,
            'started_at' => $occurrenceDates['started_at'],
            'ended_at' => $occurrenceDates['ended_at'],
            'start_time' => $occurrenceDates['start_time'],
            'end_time' => $occurrenceDates['end_time'],
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

        if ($existingDraft) {
            $this->referralForm = array_replace($this->referralForm, Arr::only($draftData, [
                'quantity', 'priority', 'intent', 'note', 'patient_instruction', 'inform_with', 'supporting_info',
            ]));
        }

        $this->referralShowRemainingQtyWarning = false;
        $this->referralSelectedActivity = $activity->toArray();
        $this->showReferralDrawer = true;
    }

    public function saveReferralDraft(): void
    {
        $this->validateReferral(false);
    }

    public function validateReferral(bool $sign = true): void
    {
        $plan = Repository::carePlan()->findById($this->carePlan->id);
        if (Auth::user()->cannot('manageActivities', $plan)) {
            Session::flash('error', __('care-plan.policy.manage_activities_denied'));

            return;
        }
        if (!$sign && ($this->referralForm['kind'] ?? null) !== 'service_request') {
            Session::flash('error', __('care-plan.referral_wrong_activity_kind'));

            return;
        }

        $this->referralWarningMessage = '';
        $this->referralShowRemainingQtyWarning = false;

        if ($this->referralForm['kind'] === 'service_request' && $this->referralServiceType !== 'service_group') {
            $this->referralForm['category'] = $this->referralServiceCategory ?: ($this->referralForm['category'] ?? 'procedure');
        }

        $rules = [
            'referralForm.started_at' => 'required|date_format:d.m.Y',
            'referralForm.ended_at' => 'required|date_format:d.m.Y',
            'referralForm.start_time' => 'required|date_format:H:i',
            'referralForm.end_time' => 'required|date_format:H:i',
            'referralForm.quantity' => 'required|numeric|min:0.01',
            'referralForm.priority' => ['required', Rule::in(array_keys($this->referralPriorityOptions()))],
        ];

        if ($this->referralForm['kind'] === 'service_request') {
            $rules['referralForm.category'] = $this->referralServiceType === 'service_group'
                ? ['required', 'string', Rule::in(array_keys($this->referralCategoryOptions))]
                : 'required|string';
        }

        $this->validate($rules);
        $period = $this->referralOccurrencePeriod();

        if ($this->referralForm['kind'] === 'service_request') {
            $activityProgram = $this->referralSelectedActivity['program'] ?? null;
            $this->referralForm['program_id'] = !empty($activityProgram) ? $activityProgram : null;
        }

        $qty = (float) $this->referralForm['quantity'];
        if ($qty > $this->referralRemainingQty) {
            $this->referralShowRemainingQtyWarning = true;
            $this->referralWarningMessage = __('Кількість перевищує залишок за призначенням (') . $this->referralRemainingQty . ')';
            $this->flashUserError(__('Кількість перевищує залишок за призначенням.'));

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

        try {
            $activity = $this->ownedActivity((int) $this->referralForm['activity_id']);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        }
        if ($sign && $activity) {
            $existingDraft = app(ReferralRequestLifecycleService::class)->findDraftByActivity($activity);
            if ($existingDraft && app(ReferralRequestLifecycleService::class)->trySyncDraftFromEHealth(
                $this->carePlan,
                $activity,
                $existingDraft,
                $this->referralForm['kind']
            )) {
                $this->refreshCarePlan();
                Session::flash('success', __('care-plan.referral_already_in_ehealth_synced', [
                    'document' => __('care-plan.document_type_service_referral'),
                ]));

                return;
            }
        }

        try {
            $this->carePlan = Repository::carePlan()->findById($this->carePlan->id);

            $employeeContext = app(ReferralRequestLifecycleService::class)->resolveEmployeeContext(
                $this->carePlan,
                $activity,
                Auth::user()?->activeDoctorEmployee()?->id
            );

            $this->referralRequestIdToSign = app(ReferralRequestLifecycleService::class)->createCarePlanDraft(
                $this->carePlan,
                array_replace($this->referralForm, $period),
                $qty,
                $employeeContext,
                prequalify: $sign
            );
            if (!$sign) {
                $this->showReferralDrawer = true;
                $this->showSignatureModal = false;
                $this->actionType = '';
                Session::flash('success', __('referrals.draft_saved'));

                return;
            }
            $this->assertReferralOccurrencePeriod($period);
            $signAction = $this->referralForm['kind'] === 'service_request'
                ? 'sign_servicerequest'
                : 'sign_devicerequest';
            $this->openSignatureModal($signAction);
            Session::flash('success', __('care-plan.referral_request_created'));
        } catch (ValidationException $exception) {
            $this->showReferralDrawer = true;
            $this->showSignatureModal = false;
            $this->actionType = '';
            $this->flashUserError($exception->validator->errors()->first());

            throw $exception;
        } catch (EHealthValidationException $exception) {
            $exception->report();
            $this->showReferralDrawer = true;
            $this->flashUserError($exception->getTranslatedMessage());
        } catch (Exception $exception) {
            $this->showReferralDrawer = true;
            Log::error('CarePlanShow: failed to create referral request: ' . $exception->getMessage());
            $kindLabel = ($this->referralForm['kind'] ?? '') === 'device_request'
                ? __('електронний рецепт на медичні вироби')
                : __('заявку на направлення');
            $this->flashUserError(__('Не вдалося створити ').$kindLabel.': '.$exception->getMessage());
        }
    }

    public function resendReferralSms(string $requestId, string $kind): void
    {
        if ($this->authorizeCarePlanActivityWrite() === false) {
            return;
        }
        if ($kind === 'device_request') {
            $this->redirectRoute('persons.device-requests', ['legalEntity' => legalEntity(), 'person' => $this->carePlan->person]);

            return;
        }
        try {
            app(MedicalRequestOwnership::class)
                ->referralForPerson($requestId, (int) $this->carePlan->personId);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        }

        try {
            $response = app(ReferralRequestLifecycleService::class)->resendSms($this->carePlan->person->uuid, $requestId, $kind);

            if ($response->successful()) {
                Session::flash('success', __('care-plan.referral_sms_resent'));

                return;
            }

            Session::flash('error', __('referrals.sms_failed'));
        } catch (EHealthValidationException $exception) {
            Log::error('CarePlanShow: failed to resend referral SMS validation: ' . $exception->getTranslatedMessage());
            Session::flash('error', $exception->getTranslatedMessage());
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() === 403) {
                Log::warning('CarePlanShow: referral SMS resend forbidden by eHealth ACL', [
                    'request_id' => $requestId,
                    'person_uuid' => $this->carePlan->person->uuid,
                ]);
                Session::flash('warning', __('care-plan.referral_sms_forbidden'));

                return;
            }

            Log::error('CarePlanShow: failed to resend referral SMS response: ' . $exception->getMessage());
            Session::flash('error', __('Помилка надсилання СМС: ') . $exception->getMessage());
        } catch (Exception $exception) {
            Log::error('CarePlanShow: failed to resend referral SMS: ' . $exception->getMessage());
            Session::flash('error', __('Помилка надсилання СМС: ') . $exception->getMessage());
        }
    }

    public function signReferral(): void
    {
        if (empty($this->referralRequestIdToSign)) {
            $this->flashUserError(__('Не вибрано документ для підписання'));
            $this->showSignatureModal = false;

            return;
        }

        $this->carePlan = Repository::carePlan()->findById($this->carePlan->id);

        try {
            $requestRecord = app(MedicalRequestOwnership::class)
                ->referralForPerson((string) $this->referralRequestIdToSign, (int) $this->carePlan->personId);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        }

        $kind = $requestRecord instanceof ServiceRequestRequest
            ? 'service_request'
            : 'device_request';

        if ($kind === 'device_request') {
            $this->redirectRoute('persons.device-requests.create', ['legalEntity' => legalEntity(), 'person' => $this->carePlan->person, 'draft' => $requestRecord->uuid]);

            return;
        }

        try {
            $activity = $this->ownedActivityByBasedOnUuid($requestRecord->basedOn?->value);

            $employeeContext = $this->resolveReferralEmployeeContext($requestRecord, $activity);

            $uuids = [
                'person_uuid' => $this->carePlan->person->uuid,
                'encounter_uuid' => $this->carePlan->encounter?->uuid ?? null,
                'episode_uuid' => $this->carePlan->episodeUuid(),
                'employee_uuid' => $employeeContext['employee_uuid'],
                'legal_entity_uuid' => $employeeContext['legal_entity_uuid'],
            ];

            $dbData = $this->buildReferralSignDbData($requestRecord, $activity);
            $this->assertReferralOccurrencePeriod($dbData);

            if ($kind === 'service_request') {
                $resource = Fhir::serviceRequest()->toFhir($dbData, $uuids + ['carePlanUuid' => $this->carePlan->uuid, 'resource' => MedicalEventsRepository::serviceRequest()->getResource($requestRecord)]);
                $signPayload = ServiceRequestForm::buildCreateContent($resource);
            } else {
                $signPayload = (new DeviceRequestMapper())->toCreateSignedContent($dbData, $uuids, (string) $this->carePlan->uuid, (string) $activity->uuid);
            }

            $signedContent = signatureService()->signData(
                $signPayload,
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            $this->assertReferralOccurrencePeriod($dbData);
            $requestRecord = MedicalEventsRepository::serviceRequest()->storeResource($resource, $this->carePlan->personId, $requestRecord->employeeId, $requestRecord->divisionId);
            $finalResponse = $this->submitClinicalOperation($requestRecord, ['status' => 'active'], fn () => SubmissionApi::serviceRequest()->createSigned($this->carePlan->person->uuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']));
            if ($finalResponse === null) {
                $this->redirectRoute('care-plans.activities.show', [legalEntity(), $this->carePlan->id, $activity->id], navigate: true);

                return;
            }

            $dbData = app(ReferralRequestLifecycleService::class)->persistAfterSignedCreate(
                $dbData,
                $finalResponse,
                $kind,
                (int) $this->carePlan->personId
            );

            $this->finalizeSignedReferral($dbData, $kind, $activity, alreadyPersisted: true);

        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

        } catch (ValidationException $exception) {
            if (collect($exception->errors())->keys()->contains(static fn (string $key): bool => in_array($key, ['form.password', 'form.knedp', 'form.keyContainerUpload'], true))) {
                throw $exception;
            }
            $this->showSignatureModal = false;
            $this->initReferralForm($activity->id);
            $this->setErrorBag($exception->validator->errors());
            $this->flashUserError($exception->validator->errors()->first());
        } catch (EHealthValidationException $e) {
            if ($e->isDuplicateReferralError()) {
                try {
                    $activity = $this->ownedActivityByBasedOnUuid($requestRecord->basedOn?->value);

                    $dbData = $this->buildReferralSignDbData($requestRecord, $activity);
                    $dbData = app(ReferralRequestLifecycleService::class)->syncReferralFromRemote(
                        $this->carePlan,
                        $activity,
                        $requestRecord,
                        $kind,
                        $dbData
                    );
                    $this->finalizeSignedReferral($dbData, $kind, $activity, alreadyPersisted: true, alreadyExists: true);
                } catch (Exception $syncException) {
                    Log::error('CarePlanShow: failed to sync referral after duplicate eHealth id: ' . $syncException->getMessage());
                    $this->flashUserError(__('Документ вже існує в ЕСОЗ, але не вдалося синхронізувати локальні дані: ') . $syncException->getMessage());
                    $this->showSignatureModal = false;
                }

                return;
            }

            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to sign referral validation: ' . $translatedMsg);
            $this->flashUserError($translatedMsg);
            $this->showSignatureModal = false;
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to sign referral: ' . $e->getMessage());
            $this->flashUserError(__('Не вдалося підписати документ: ') . $e->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function cancelReferral(string $requestId, string $kind): void
    {
        if ($kind === 'device_request') {
            $this->redirectRoute('persons.device-requests', ['legalEntity' => legalEntity(), 'person' => $this->carePlan->person]);

            return;
        }
        try {
            $record = app(MedicalRequestOwnership::class)->serviceForPerson($requestId, (int) $this->carePlan->personId);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));

            return;
        }
        if (Auth::user()->cannot('cancel', $record)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }
        $this->openSignatureModal('cancel_referral', null, $requestId);
    }

    public function recallReferral(string $requestId, string $kind): void
    {
        try {
            $record = app(MedicalRequestOwnership::class)->serviceForPerson($requestId, (int) $this->carePlan->personId);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));

            return;
        }
        if (Auth::user()->cannot('recall', $record)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }
        $this->referralExplanatoryLetter = '';
        $this->openSignatureModal('recall_referral', null, $requestId);
    }

    public function signRecallReferral(): void
    {
        if (empty($this->referralRequestIdToSign)) {
            Session::flash('error', __('Не вибрано направлення для відкликання'));
            $this->showSignatureModal = false;

            return;
        }

        $letter = trim((string) $this->referralExplanatoryLetter);
        if ($letter === '') {
            $this->addError('referralExplanatoryLetter', __('care-plan.referral_recall_letter_required'));

            return;
        }

        try {
            $record = app(MedicalRequestOwnership::class)
                ->referralForPerson((string) $this->referralRequestIdToSign, (int) $this->carePlan->personId);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        }
        $service = $record instanceof ServiceRequestRequest ? $record : null;
        $device = $service ? null : $record;

        $record = $service ?: $device;

        if (!$service) {
            Session::flash('error', __('care-plan.referral_recall_service_only'));
            $this->showSignatureModal = false;

            return;
        }

        if (Auth::user()->cannot('recall', $service)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }

        $this->validate(['statusReason' => ['required', 'string']]);
        try {
            $api = EHealth::serviceRequest();
            $snapshot = $this->fetchServiceRequestSnapshot($this->carePlan->person->uuid, $record->uuid);
            if (Auth::user()->cannot('registeredAction', [ServiceRequestRequest::class, $snapshot, 'recall'])) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return;
            }
            $record = MedicalEventsRepository::serviceRequest()->storeResource((new ServiceRequestMapper())->normalize($snapshot), (int) $this->carePlan->personId, $record->employeeId, $record->divisionId);
            $payload = ServiceRequestForm::buildStatusContent($snapshot, $this->statusReason, $letter, 'eHealth/service_request_recall_reasons');

            $signedContent = signatureService()->signData(
                $payload,
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            // Eligibility and pending operations can change during KEP; reload before creating submission tracking.
            try {
                $record = app(MedicalRequestOwnership::class)->serviceForPerson($record->uuid, (int) $this->carePlan->personId);
            } catch (ModelNotFoundException) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return;
            }
            if (Auth::user()->cannot('recall', $record)
                || Auth::user()->cannot('registeredAction', [ServiceRequestRequest::class, $snapshot, 'recall'])) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return;
            }

            $finalResponse = $this->submitClinicalOperation($record, ['status' => 'recalled', 'statusReason' => $payload['status_reason'], 'explanatoryLetter' => $letter], fn () => SubmissionApi::serviceRequest()->recall($this->carePlan->person->uuid, $record->uuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']), 'service_request:recall');
            if ($finalResponse === null) {
                return;
            }

            $this->persistReferralStatusFromJob(
                $finalResponse,
                $record,
                ServiceRequestStatus::RECALLED
            );
            $this->showSignatureModal = false;
            $this->referralExplanatoryLetter = '';
            $this->refreshCarePlan();
            Session::flash('success', __('care-plan.referral_recall_success'));
        } catch (EHealthValidationException $e) {
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to recall referral validation: '.$translatedMsg);
            Session::flash('error', $translatedMsg);
            $this->showSignatureModal = false;
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to recall referral: '.$e->getMessage());
            Session::flash('error', __('Не вдалося відкликати направлення: ').$e->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function signCancelReferral(): void
    {
        if (empty($this->referralRequestIdToSign)) {
            Session::flash('error', __('Не вибрано направлення для скасування'));
            $this->showSignatureModal = false;

            return;
        }

        try {
            $record = app(MedicalRequestOwnership::class)
                ->referralForPerson((string) $this->referralRequestIdToSign, (int) $this->carePlan->personId);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        }
        $service = $record instanceof ServiceRequestRequest ? $record : null;
        $device = $service ? null : $record;

        $record = $service ?: $device;

        $kind = $service ? 'service_request' : 'device_request';
        if ($service) {
            if (Auth::user()->cannot('cancel', $service)) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return;
            }
            $this->validate(['statusReason' => ['required', 'string'], 'referralExplanatoryLetter' => ['required', 'string']]);
        }

        if ($kind === 'device_request') {
            $this->redirectRoute('persons.device-requests', ['legalEntity' => legalEntity(), 'person' => $this->carePlan->person]);

            return;
        }

        try {
            $payload = [
                'status_reason' => $this->statusReason ?: 'entered-in-error'
            ];

            if ($kind === 'service_request') {
                $api = EHealth::serviceRequest();
                $snapshot = $this->fetchServiceRequestSnapshot($this->carePlan->person->uuid, $record->uuid);
                if (Auth::user()->cannot('registeredAction', [ServiceRequestRequest::class, $snapshot, 'error'])) {
                    Session::flash('error', __('referrals.messages.action_unavailable'));

                    return;
                }
                $record = MedicalEventsRepository::serviceRequest()->storeResource((new ServiceRequestMapper())->normalize($snapshot), (int) $this->carePlan->personId, $record->employeeId, $record->divisionId);
                $payload = ServiceRequestForm::buildStatusContent($snapshot, $this->statusReason, trim($this->referralExplanatoryLetter), 'eHealth/service_request_cancel_reasons');
            }

            $signedContent = signatureService()->signData(
                $kind === 'service_request' ? $payload : Arr::toSnakeCase($payload),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            // Eligibility and pending operations can change during KEP; reload before creating submission tracking.
            try {
                $record = app(MedicalRequestOwnership::class)->serviceForPerson($record->uuid, (int) $this->carePlan->personId);
            } catch (ModelNotFoundException) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return;
            }
            if (Auth::user()->cannot('cancel', $record)
                || Auth::user()->cannot('registeredAction', [ServiceRequestRequest::class, $snapshot, 'error'])) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return;
            }

            $finalResponse = $this->submitClinicalOperation($record, ['status' => 'entered-in-error', 'statusReason' => $payload['status_reason'], 'explanatoryLetter' => trim($this->referralExplanatoryLetter)], fn () => SubmissionApi::serviceRequest()->cancel($this->carePlan->person->uuid, $record->uuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']), 'service_request:cancel');
            if ($finalResponse === null) {
                return;
            }

            $this->persistReferralStatusFromJob(
                $finalResponse,
                $record,
                ServiceRequestStatus::ENTERED_IN_ERROR
            );
            $this->showSignatureModal = false;
            $this->refreshCarePlan();
            Session::flash('success', __('care-plan.referral_cancel_success'));
        } catch (EHealthValidationException $e) {
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to cancel referral validation: ' . $translatedMsg);
            Session::flash('error', $translatedMsg);
            $this->showSignatureModal = false;
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to cancel referral: ' . $e->getMessage());
            Session::flash('error', __('Не вдалося скасувати направлення: ') . $e->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function loadReferralPrintoutForm(string $requestId): string
    {
        try {
            $record = app(MedicalRequestOwnership::class)->serviceForPerson($requestId, (int) $this->carePlan->personId);
            if (Auth::user()->cannot('viewRecord', $record)) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return '';
            }
            $html = app(ReferralRequestLifecycleService::class)->buildPrintoutHtml($record, $requestId);
            $this->printableContent = $html;

            return $html;
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());

            return '';
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to load referral printout: ' . $e->getMessage());
            Session::flash('error', __('Не вдалося завантажити друковану форму.'));

            return '';
        }
    }

    public function syncReferralFromEHealth(string $requestUuid, string $kind): void
    {
        $this->authorizeCarePlanWrite();

        try {
            $ownership = app(MedicalRequestOwnership::class);
            $requestRecord = match ($kind) {
                'service_request' => $ownership->serviceForPerson($requestUuid, (int) $this->carePlan->personId),
                'device_request' => $ownership->deviceForPerson($requestUuid, (int) $this->carePlan->personId),
                default => throw new ModelNotFoundException(),
            };
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        }

        try {
            $activity = $this->ownedActivityByBasedOnUuid($requestRecord->basedOn?->value);

            $before = [
                'status' => (string) $requestRecord->status,
                'request_number' => (string) ($requestRecord->requestNumber ?? ''),
                'quantity' => (string) $requestRecord->quantity,
                'started_at' => $requestRecord->startedAt?->format('Y-m-d'),
                'ended_at' => $requestRecord->endedAt?->format('Y-m-d'),
            ];

            $dbData = $this->buildReferralSignDbData($requestRecord, $activity);
            app(ReferralRequestLifecycleService::class)->syncReferralFromRemote(
                $this->carePlan,
                $activity,
                $requestRecord,
                $kind,
                $dbData
            );

            ($requestRecord instanceof ServiceRequestRequest ? MedicalEventsRepository::serviceRequest() : MedicalEventsRepository::deviceRequest())->refreshRecord($requestRecord);

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
                Repository::carePlanActivity()->updateById($activity->id, ['status' => 'in-progress']);
            }

            $this->refreshCarePlan();

            $changes = [];
            foreach ($before as $field => $value) {
                if (($after[$field] ?? null) !== $value) {
                    $changes[] = match ($field) {
                        'status' => __('статус: ') . $this->resolveReferralStatusLabel($value) . ' → ' . $this->resolveReferralStatusLabel((string) $after[$field]),
                        'request_number' => __('номер: ') . ($value ?: '—') . ' → ' . ($after[$field] ?: '—'),
                        'quantity' => __('кількість: ') . $value . ' → ' . $after[$field],
                        'started_at' => __('початок: ') . ($value ?: '—') . ' → ' . ($after[$field] ?: '—'),
                        'ended_at' => __('кінець: ') . ($value ?: '—') . ' → ' . ($after[$field] ?: '—'),
                        default => $field,
                    };
                }
            }

            Session::flash('success', $changes === [] ? __('care-plan.referral_sync_no_changes') : __('care-plan.referral_sync_updated', ['changes' => implode('; ', $changes)]));
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

        } catch (Exception $exception) {
            Log::error('CarePlanShow: failed to sync referral from eHealth: ' . $exception->getMessage());
            Session::flash('error', __('Не вдалося оновити направлення з ЕСОЗ: ') . $exception->getMessage());
        }
    }

    protected function resolveReferralServiceLabel(CarePlanActivity $activity): string
    {
        $display = $activity->productReferenceIdentifier?->displayValue;
        if (filled($display)) {
            return $display;
        }
        try {
            $service = $this->findServiceInCatalog(dictionary()->services()->all(), (string) ($activity->productReferenceIdentifier?->value ?? $activity->productReference));
            if (filled($service['name'] ?? null)) {
                return trim(($service['code'] ?? '').' '.$service['name']);
            }
        } catch (Throwable $exception) {
            Log::warning('Care plan service label unavailable', ['exceptionType' => $exception::class]);
        }

        return __('care-plan.service_name_unavailable');
    }

    protected function resolveServiceCategory(string $serviceId): ?string
    {
        try {
            $catalog = dictionary()->services()->all();

            if (!is_array($catalog)) {
                return null;
            }

            $category = $this->findServiceCategoryInCatalog($catalog, $serviceId);

            return $category !== null ? (string) $category : null;
        } catch (Exception $exception) {
            Log::warning('CarePlanShow: failed to resolve service category: ' . $exception->getMessage());
        }

        return null;
    }

    /**
     * @param  array<mixed>  $nodes
     */
    protected function findServiceCategoryInCatalog(array $nodes, string $serviceId): ?string
    {
        return $this->findServiceInCatalog($nodes, $serviceId)['category'] ?? null;
    }

    protected function findServiceInCatalog(array $nodes, string $serviceId): ?array
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            if (($node['id'] ?? null) === $serviceId) {
                return $node;
            }

            foreach (['services', 'groups'] as $childKey) {
                if (!empty($node[$childKey]) && is_array($node[$childKey])) {
                    $service = $this->findServiceInCatalog($node[$childKey], $serviceId);
                    if ($service !== null) {
                        return $service;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array{started_at: string, ended_at: string}
     */
    protected function resolveReferralOccurrenceDates(?Carbon $scheduledStart, ?Carbon $scheduledEnd): array
    {
        $minStart = now()->addMinutes(30)->startOfMinute();
        $start = $scheduledStart && $scheduledStart->greaterThan($minStart)
            ? $scheduledStart->copy()
            : $minStart->copy();
        $end = $scheduledEnd?->copy() ?? $start->copy()->addMonths(3);

        return [
            'started_at' => $start->format('d.m.Y'),
            'ended_at' => $end->format('d.m.Y'),
            'start_time' => $start->format('H:i'),
            'end_time' => $end->format('H:i'),
        ];
    }

    protected function referralOccurrencePeriod(): array
    {
        $start = CarbonImmutable::createFromFormat(
            '!d.m.Y H:i',
            $this->referralForm['started_at'].' '.$this->referralForm['start_time'],
            config('app.timezone')
        );
        $end = CarbonImmutable::createFromFormat(
            '!d.m.Y H:i',
            $this->referralForm['ended_at'].' '.$this->referralForm['end_time'],
            config('app.timezone')
        );
        foreach (['start_time' => $start, 'end_time' => $end] as $field => $date) {
            if ($date->format('H:i') !== $this->referralForm[$field]) {
                throw ValidationException::withMessages([
                    'referralForm.'.$field => __('referrals.period_fields.nonexistent_time'),
                ]);
            }
        }
        $period = ['started_at' => $start->toIso8601String(), 'ended_at' => $end->toIso8601String()];
        $this->assertReferralOccurrencePeriod($period);

        return $period;
    }

    protected function assertReferralOccurrencePeriod(array $period): void
    {
        $serverNow = CarbonImmutable::now();
        $rules = [
            'referralForm.start_time' => ['required', 'date', 'after_or_equal:'.$serverNow->toIso8601String()],
            'referralForm.ended_at' => ['required', 'date', 'after_or_equal:referralForm.start_time'],
        ];
        if (array_key_exists('priority', $period)) {
            $rules['referralForm.priority'] = ['required', Rule::in(array_keys($this->referralPriorityOptions()))];
        }
        $validator = Validator::make(['referralForm' => [
            'start_time' => $period['started_at'] ?? null,
            'ended_at' => $period['ended_at'] ?? null,
            'priority' => $period['priority'] ?? null,
        ]], $rules, [
            'referralForm.start_time.after_or_equal' => __('errors.ehealth.messages.occurrence_start_in_past').' '.__('referrals.period_fields.comparison_context', [
                'start' => $period['started_at'] ?? '',
                'now' => $serverNow->toIso8601String(),
            ]),
            'referralForm.ended_at.after_or_equal' => __('errors.ehealth.messages.period_end_before_start'),
        ]);
        if ($validator->fails() && isset($validator->failed()['referralForm.start_time']['AfterOrEqual'])) {
            Log::notice('Care plan referral start is in the past', [
                'draft_uuid' => $this->referralRequestIdToSign,
                'start' => $period['started_at'] ?? null,
                'end' => $period['ended_at'] ?? null,
                'server_now' => $serverNow->toIso8601String(),
                'timezone' => config('app.timezone'),
            ]);
        }
        $validator->validate();
    }

    /**
     * Resolve device packaging_count from eHealth device definition (package step for eRx).
     */
    protected function resolveDevicePackageQuantity(CarePlanActivity $activity): int
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
                ? (int) ($packaging['packaging_count'] ?? ($packaging['packagingCount'] ?? 0))
                : 0;

            return max(0, $count);
        } catch (Throwable $exception) {
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
            return EHealth::deviceDefinition()->getById($reference)->validate();
        } catch (ValidationException $exception) {
            // A successful but malformed detail cannot become a packaging rule
            // or be hidden by switching to a different catalogue response.
            Log::warning('CarePlanShow: invalid device definition detail: '.$exception->getMessage(), ['uuid' => $reference]);

            return null;
        } catch (Throwable $exception) {
            Log::debug('CarePlanShow: device definition getById failed: '.$exception->getMessage(), [
                'uuid' => $reference,
            ]);
        }

        $filters = ['page_size' => 100];
        if (!empty($programId)) {
            $filters['medical_program_id'] = $programId;
        }

        $page = 1;
        $totalPages = null;

        do {
            $filters['page'] = $page;
            $response = EHealth::deviceDefinition()->getMany($filters);
            $definitions = $response->validate();
            $paging = $response->getPaging();
            if ($paging['page_number'] !== $page
                || ($totalPages !== null && $paging['total_pages'] !== $totalPages)) {
                throw ValidationException::withMessages(['paging' => __('device-requests.validation.configuration_incomplete')]);
            }
            $totalPages = $paging['total_pages'];
            $device = collect($definitions)->first(
                static fn (array $item): bool => (string) ($item['id'] ?? ($item['uuid'] ?? '')) === $reference
            );

            if (is_array($device)) {
                return $device;
            }

            $page++;
        } while ($page <= $totalPages);

        return null;
    }

    protected function flashUserError(string $message): void
    {
        Session::flash('error', $message);
    }

    protected function flashUserSuccess(string $message): void
    {
        Session::flash('success', $message);
    }

    /**
     * @param  array<string, mixed>  $finalResponse
     */
    protected function persistReferralStatusFromJob(
        array $finalResponse,
        ServiceRequestRequest|DeviceRequestRequest $record,
        ServiceRequestStatus $fallback
    ): void {
        $result = $finalResponse['result'] ?? null;
        $entity = is_array($result) ? ($result[0] ?? $result) : $finalResponse;
        $raw = is_array($entity) ? ($entity['status'] ?? null) : null;
        $resolved = is_string($raw) ? ServiceRequestStatus::resolve($raw) : null;

        if ($record instanceof ServiceRequestRequest) {
            MedicalEventsRepository::serviceRequest()->updateClinicalStatus($record, ($resolved ?? $fallback)->value);
        } else {
            MedicalEventsRepository::deviceRequest()->saveStatus($record, ($resolved ?? $fallback)->value);
        }
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
        ServiceRequestRequest|DeviceRequestRequest $requestRecord,
        CarePlanActivity $activity
    ): array {
        $context = app(ReferralRequestLifecycleService::class)->resolveEmployeeContext(
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
        ServiceRequestRequest|DeviceRequestRequest $requestRecord,
        CarePlanActivity $activity
    ): array {
        $employeeContext = $this->resolveReferralEmployeeContext($requestRecord, $activity);

        return app(ReferralRequestLifecycleService::class)->buildSignDbData(
            $requestRecord,
            $activity,
            $this->carePlan,
            $employeeContext
        );
    }

    /**
     * @param  array<string, mixed>  $dbData
     */
    protected function finalizeSignedReferral(array $dbData, string $kind, CarePlanActivity $activity, bool $alreadyPersisted = false, bool $alreadyExists = false): void
    {
        if (!$alreadyPersisted) {
            if ($kind === 'service_request') {
                app(ReferralRequestLifecycleService::class)->saveServiceRequest($dbData, $this->carePlan->personId);
            } else {
                MedicalEventsRepository::deviceRequest()->store($dbData, $this->carePlan->person_id);
            }
        }

        if ($activity->status === 'scheduled') {
            Repository::carePlanActivity()->updateById($activity->id, ['status' => 'in-progress']);
        }

        $this->showSignatureModal = false;
        $documentLabel = $kind === 'device_request'
            ? __('Електронний рецепт на медичні вироби')
            : __('Електронне направлення');
        $finalStatusCode = strtolower((string) ($dbData['status'] ?? ''));
        if (in_array($finalStatusCode, ['pending', 'processing'], true)) {

            Session::flash('warning', $documentLabel.__(' прийнято в обробку ЕСОЗ. Фінальний статус з’явиться після завершення асинхронної задачі.'));
        } elseif ($alreadyExists) {
            $this->flashUserSuccess($documentLabel.__(' вже існував у ЕСОЗ. Локальні дані синхронізовано.'));
        } else {
            $message = __('care-plan.referral_submission_confirmed');
            if (filled($dbData['request_number'] ?? null)) {
                $message .= ' '.__('care-plan.document_number', ['number' => $dbData['request_number']]);
            }
            $this->flashUserSuccess($message);
        }
        $this->refreshCarePlan();
    }

    protected function resolveReferralCategoryLabel(string $category): string
    {
        $key = 'care-plan.referral_category.' . $category;

        return Lang::has($key) ? __($key) : $category;
    }
}
