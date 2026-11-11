<?php

declare(strict_types=1);

namespace App\Livewire\EncounterPackage\Concerns;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealth as SubmissionApi;
use App\Enums\JobStatus;
use App\Enums\MedicalProgram\Type as MedicalProgramType;
use App\Enums\Person\EncounterStatus;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\Concerns\SubmitsClinicalOperations;
use App\Livewire\Referral\Forms\ServiceRequestForm;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\Dictionary\ServiceSearch;
use App\Services\MedicalEvents\InformWith;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use App\Services\MedicalEvents\MedicalRequestOwnership;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use RuntimeException;
use Throwable;

trait ManagesEncounterReferrals
{
    use SubmitsClinicalOperations;

    // Uses ResolvesEncounterStandaloneContext via EncounterEdit.

    public bool $showEncounterReferralDrawer = false;

    /** @var array<string, mixed> */
    public array $encounterReferralForm = [];

    /** @var list<array{uuid: string, type: string, label: string, raw: string}> */
    public array $encounterReferralAuthMethods = [];

    #[Locked]
    public ?string $encounterReferralRequestIdToSign = null;

    public string $encounterReferralWarningMessage = '';

    public string $encounterReferralPlanSearch = '';

    #[Computed]
    public function encounterReferralPlanChoices(): array
    {
        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null || $encounter->getAttribute('person_id') === null) {
            return [];
        }
        $actor = $this->encounterReferralEmployeeContext($encounter);

        return Repository::carePlan()->documentPlanChoices($encounter->getAttribute('person_id'), $encounter->uuid, Auth::user(), $this->encounterReferralPlanSearch, $actor['employee_uuid']);
    }

    #[Computed]
    public function encounterReferralActivityChoices(): array
    {
        $encounter = $this->resolveEncounterModelForStandalone();
        $planId = (int) ($this->encounterReferralForm['care_plan_id'] ?? 0);
        if ($encounter === null || $encounter->getAttribute('person_id') === null || $planId === 0) {
            return [];
        }
        $actor = $this->encounterReferralEmployeeContext($encounter);

        return Repository::carePlan()->documentActivityChoices($planId, $encounter->getAttribute('person_id'), $encounter->uuid, Auth::user(), 'service_request', $actor['employee_uuid']);
    }

    #[Computed]
    public function encounterReferralApprovalContext(): array
    {
        $planId = (int) ($this->encounterReferralForm['care_plan_id'] ?? 0);
        $choice = collect($this->encounterReferralPlanChoices)->firstWhere('id', $planId);
        $encounter = $this->resolveEncounterModelForStandalone();
        if ($choice === null || !$choice['approvalRequired'] || $encounter === null) {
            return [];
        }

        return ['carePlan' => Repository::carePlan()->findById($planId), 'actingEmployeeUuid' => $this->encounterReferralEmployeeContext($encounter)['employee_uuid']];
    }

    #[On('care-plan-approvals-changed')]
    public function refreshEncounterReferralPlanAccess(): void
    {
        // A pending approval does not grant access; the provider rechecks its verified status for the selected actor.
        unset($this->encounterReferralPlanChoices, $this->encounterReferralActivityChoices, $this->encounterReferralApprovalContext);
    }

    private function encounterReferralEmployeeContext(Encounter $encounter): array
    {
        $draft = $this->encounterReferralRequestIdToSign === null ? null : MedicalEventsRepository::serviceRequest()->findByUuid($this->encounterReferralRequestIdToSign);

        return app(ReferralRequestLifecycleService::class)->resolveEncounterEmployeeContext($encounter, $draft?->employeeId ?? Auth::user()->activeDoctorEmployee()?->id);
    }

    #[Locked]
    public array $encounterReferralSubmission = [];

    public string $encounterReferralServiceSearch = '';

    public bool $encounterReferralHasSearched = false;

    /** @var list<array<string, mixed>> */
    public array $encounterReferralServiceResults = [];

    /** @var array<string, mixed>|null */
    public ?array $encounterReferralSelectedService = null;

    #[Locked]
    public string $encounterReferralServiceType = 'service';

    /** @var list<array{id: string, name: string}> */
    public array $encounterReferralPrograms = [];

    public bool $encounterReferralIsTransfer = false;

    public string $encounterReferralPerformerName = '';

    /** @var list<array{id: string, name: string, type: string}> */
    public array $encounterReferralDivisions = [];

    public string $encounterReferralDestinationUuid = '';

    #[Locked]
    public ?string $encounterReferralVerifiedDestinationUuid = null;

    /**
     * Division UUIDs loaded for the destination legal entity.
     * Locked so the client cannot inject a division that does not belong to the performer.
     *
     * @var list<string>
     */
    #[Locked]
    public array $encounterReferralAllowedDivisionIds = [];

    /** @var array<string, string> */
    #[Locked]
    public array $encounterReferralSpecialities = [];

    /** @var list<string> */
    private const array TRANSFER_DIVISION_TYPES = ['CLINIC', 'LICENSED_UNIT', 'AMBULANT_CLINIC', 'FAP'];

    #[Computed]
    public function encounterReferralPriorityOptions(): array
    {
        return dictionary()->basics()->byName('SERVICE_REQUEST_PRIORITY')->asCodeDescription()->all();
    }

    public function openEncounterReferralDrawer(): void
    {
        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null) {
            return;
        }

        $status = $encounter->status instanceof EncounterStatus
            ? $encounter->status
            : EncounterStatus::tryFrom((string) $encounter->status);

        if ($status !== EncounterStatus::FINISHED) {
            Session::flash('error', __('Виписати направлення можна лише для завершеної взаємодії.'));

            return;
        }

        $this->encounterReferralRequestIdToSign = null;
        $this->afterEncounterReferralDraftChanged();
        $this->encounterReferralSubmission = [];
        $this->showSignatureModal = false;
        $this->actionType = null;

        $this->loadEncounterReferralAuthMethods($encounter);
        $this->loadEncounterReferralPrograms();
        $this->loadEncounterReferralSpecialities();

        $transfer = $this->resolveEncounterReferralTransfer($encounter);
        $this->encounterReferralIsTransfer = $transfer !== null;
        $this->encounterReferralPerformerName = $transfer['name'] ?? '';
        $this->encounterReferralDestinationUuid = $transfer['uuid'] ?? '';
        $this->encounterReferralVerifiedDestinationUuid = $transfer['uuid'] ?? null;
        $this->encounterReferralDivisions = [];
        $this->encounterReferralAllowedDivisionIds = [];
        $this->encounterReferralWarningMessage = '';

        $start = now()->addMinutes(30)->startOfMinute();
        $this->encounterReferralForm = [
            'category' => $transfer !== null ? 'transfer_of_care' : 'diagnostic_procedure',
            'service_id' => '',
            'priority' => 'routine',
            'quantity' => 1,
            'started_at' => $start->format('d.m.Y'),
            'start_time' => $start->format('H:i'),
            'end_time' => $start->format('H:i'),
            'ended_at' => $start->copy()->addMonths(3)->format('d.m.Y'),
            'program_id' => $this->resolveDefaultEncounterReferralProgramId(),
            'note' => '',
            'patient_instruction' => '',
            'inform_with' => InformWith::formValue($this->encounterReferralAuthMethods[0] ?? []),
            'reason_reference' => [],
            'performer' => $transfer['uuid'] ?? '',
            'location_reference' => '',
            'performer_type' => '',
            'care_plan_id' => 0,
            'care_plan_activity_id' => 0,
            'based_on_uuid' => null,
        ];

        if ($transfer !== null) {
            $this->loadEncounterReferralDivisions($transfer['uuid']);
            $this->loadEncounterReferralSpecialities();
        }

        $divisionWarning = $this->encounterReferralWarningMessage;

        $this->encounterReferralServiceSearch = '';
        $this->encounterReferralHasSearched = false;
        $this->encounterReferralServiceResults = [];
        $this->encounterReferralSelectedService = null;
        $this->encounterReferralServiceType = 'service';
        $this->encounterReferralWarningMessage = $divisionWarning;
        $this->resetEncounterReferralValidation();
        $this->showEncounterReferralDrawer = true;
    }

    public function closeEncounterReferralDrawer(): void
    {
        $this->showEncounterReferralDrawer = false;
        $this->encounterReferralWarningMessage = '';
        $this->encounterReferralServiceResults = [];
        $this->encounterReferralHasSearched = false;
        $this->resetEncounterReferralValidation();
    }

    public function updatedEncounterReferralForm(mixed $value, ?string $key): void
    {
        if ($key !== null) {
            $this->resetValidation('encounterReferralForm.'.$key);
        }
        $this->encounterReferralWarningMessage = '';
        if ($key === 'care_plan_id') {
            $this->encounterReferralForm['care_plan_activity_id'] = 0;
            $this->encounterReferralForm['based_on_uuid'] = null;
        }
        if ($key === 'care_plan_activity_id' && (int) $value > 0) {
            $activity = collect($this->encounterReferralActivityChoices)->firstWhere('id', (int) $value);
            if ($activity !== null) {
                $this->encounterReferralForm['based_on_uuid'] = $activity['uuid'];
                $serviceId = data_get($activity, 'productReference.identifier.value');
                if (is_string($serviceId)) {
                    $service = $this->resolveEncounterReferralServiceChoice(['id' => $serviceId]);
                    if ($service !== null) {
                        $this->encounterReferralForm['service_id'] = $serviceId;
                        $this->encounterReferralSelectedService = $service;
                        $this->applyEncounterReferralServiceCategory($service);
                    }
                }
            }
        }
        if ($key === 'care_plan_activity_id' && (int) $value === 0) {
            $this->encounterReferralForm['based_on_uuid'] = null;
        }
    }

    public function searchEncounterReferralServices(): void
    {
        $query = trim((string) $this->encounterReferralServiceSearch);
        if ($query === '') {
            $this->encounterReferralServiceResults = [];
            $this->encounterReferralHasSearched = false;

            return;
        }
        $this->encounterReferralHasSearched = true;

        try {
            $this->encounterReferralServiceResults = ServiceSearch::search(
                $query,
                static fn (array $params): array => EHealth::service()->getMany($params)->validate()
            );
            $this->encounterReferralWarningMessage = '';
        } catch (Throwable $exception) {
            Log::error('EncounterEdit: service search failed for standalone referral: '.$exception->getMessage());
            $this->encounterReferralServiceResults = [];
            $this->encounterReferralWarningMessage = __('Не вдалося виконати пошук послуг. Спробуйте ще раз.');
            Session::flash('error', $this->encounterReferralWarningMessage);
        }
    }

    public function selectEncounterReferralService(string $serviceId): void
    {
        $selected = collect($this->encounterReferralServiceResults)
            ->first(static fn (array $service): bool => (string) ($service['id'] ?? '') === $serviceId);

        if (!is_array($selected)) {
            $this->encounterReferralWarningMessage = __('Не вдалося обрати послугу. Спробуйте пошукати ще раз.');
            Session::flash('error', $this->encounterReferralWarningMessage);

            return;
        }

        $selected = $this->resolveEncounterReferralServiceChoice($selected);
        if ($selected === null) {
            return;
        }

        $this->encounterReferralForm['service_id'] = $serviceId;
        $this->encounterReferralSelectedService = $selected;
        $this->resetValidation('encounterReferralForm.service_id');
        $this->applyEncounterReferralServiceCategory($selected);

        // Hide the result list after pick — same UX as standalone eRx (readable in dark mode)
        $this->encounterReferralServiceSearch = '';
        $this->encounterReferralServiceResults = [];
        $this->encounterReferralHasSearched = false;
        $this->encounterReferralWarningMessage = '';
    }

    #[On('encounter-referral-service-selected')]
    public function selectEncounterReferralServiceFromCatalog(array $service): void
    {
        $service = $this->resolveEncounterReferralServiceChoice($service);
        if ($service === null) {
            return;
        }
        $this->encounterReferralForm['service_id'] = $service['id'];
        $this->encounterReferralSelectedService = $service;
        $this->resetValidation('encounterReferralForm.service_id');
        $this->applyEncounterReferralServiceCategory($service);

        $this->encounterReferralServiceSearch = '';
        $this->encounterReferralServiceResults = [];
        $this->encounterReferralHasSearched = false;
        $this->encounterReferralWarningMessage = '';

        $this->dispatch('encounter-referral-service-catalog-close');
    }

    /** Resolve group identity and referral eligibility from the server catalog. */
    protected function resolveEncounterReferralServiceChoice(array $service): ?array
    {
        try {
            $choice = dictionary()->services()->flattened()->firstWhere('id', $service['id'] ?? null);
        } catch (Throwable $exception) {
            report($exception);
            $choice = null;
        }
        if ($choice === null || empty($choice['request_allowed'])) {
            Session::flash('error', __('care-plan.service_group_referral_not_allowed'));

            return null;
        }
        if (Auth::user()->isAssistantOnly()) {
            $allowed = config('ehealth.referrals.assistant_allowed_categories', []);
            $categories = ($choice['type'] ?? '') === 'service_group'
                ? dictionary()->services()->flattened()->whereIn('id', ServiceSearch::memberServiceIds($choice['id'], dictionary()->services()->toArray()))->pluck('category')->unique()->all()
                : [$choice['category'] ?? null];
            if ($categories === [] || array_diff($categories, $allowed) !== []) {
                Session::flash('error', __('referrals.messages.category_forbidden'));

                return null;
            }
        }

        return $choice;
    }

    public function saveEncounterReferralDraft(): void
    {
        $this->validateEncounterReferral(false);
    }

    public function validateEncounterReferral(bool $sign = true): void
    {
        if ($this->referralReadOnly ?? false) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }
        if ($this->encounterReferralRequestIdToSign !== null) {
            $this->checkEncounterReferralSubmission();
            if (($this->encounterReferralSubmission['status'] ?? null) === JobStatus::PENDING->value || $this->encounterReferralRequestIdToSign === null) {
                return;
            }
        }
        $this->encounterReferralWarningMessage = '';

        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null) {
            return;
        }

        if ($this->rejectInvalidEncounterReferralTransfer($encounter)) {
            return;
        }

        $this->validate([
            'encounterReferralForm.service_id' => 'required|string|uuid',
            'encounterReferralForm.category' => 'required|string',
            'encounterReferralForm.quantity' => 'required|numeric|min:0.01',
            'encounterReferralForm.priority' => ['required', Rule::in(array_keys($this->encounterReferralPriorityOptions()))],
        ], [], [
            'encounterReferralForm.service_id' => __('код послуги'),
            'encounterReferralForm.category' => __('категорія'),
            'encounterReferralForm.quantity' => __('кількість'),
            'encounterReferralForm.priority' => __('пріоритет'),
            'encounterReferralForm.started_at' => __('дата початку'),
            'encounterReferralForm.ended_at' => __('дата закінчення'),
        ]);

        $period = $this->encounterReferralPeriod();

        if ($this->resolveEncounterReferralServiceChoice(['id' => $this->encounterReferralForm['service_id']]) === null) {
            return;
        }

        try {
            $employeeContext = $this->encounterReferralEmployeeContext($encounter);

            $employee = MedicalEventsRepository::serviceRequest()->authorForDraft($employeeContext['employee_id'], $employeeContext['employee_uuid']);
            if ($employee === null || Auth::user()->cannot('create', [ServiceRequestRequest::class, $employee, $this->encounterReferralForm['category'], $encounter])) {
                $this->failEncounterReferral(__('referrals.messages.category_forbidden'));

                return;
            }

            $formData = array_replace($this->encounterReferralForm, $period);
            $formData['program_id'] = $formData['program_id'] !== '' ? $formData['program_id'] : null;
            $formData['kind'] = 'service_request';
            $formData['service_type'] = $this->encounterReferralServiceType;
            $existingDraft = $this->encounterReferralRequestIdToSign !== null;

            $lifecycle = app(ReferralRequestLifecycleService::class);
            $this->encounterReferralRequestIdToSign = $lifecycle->createEncounterDraft(
                $encounter,
                $formData,
                $employeeContext,
                $this->encounterReferralRequestIdToSign
            );
            $this->afterEncounterReferralDraftChanged();
            $this->showSignatureModal = false;
            $this->actionType = null;
            if (!$sign) {
                Session::flash('success', __('referrals.draft_saved'));
            }
            if ($this->afterEncounterReferralDraftSaved($sign)) {
                return;
            }

            if (!$sign) {
                return;
            }

            $lifecycle->prepareEncounterDraftForSigning($encounter, $formData, $employeeContext, $this->encounterReferralRequestIdToSign);
            $this->assertEncounterReferralPeriod($period);
            $this->showEncounterReferralDrawer = false;
            $this->actionType = 'sign_referral';
            Session::flash(
                $existingDraft ? 'info' : 'success',
                __($existingDraft ? 'referrals.draft_found_for_signing' : 'referrals.draft_created_for_signing')
            );
            $this->showSignatureModal = true;
        } catch (ValidationException $exception) {
            $this->showSignatureModal = false;
            $this->actionType = null;
            $this->showEncounterReferralDrawer = true;
            $this->failEncounterReferral($exception->validator->errors()->first());

            throw $exception;
        } catch (EHealthValidationException $exception) {
            $exception->report();
            $this->showSignatureModal = false;
            $this->actionType = null;
            $this->failEncounterReferral($exception->getFormattedMessage());
        } catch (Throwable $exception) {
            Log::error('EncounterEdit: failed to create encounter referral: '.$exception->getMessage());
            $this->showSignatureModal = false;
            $this->actionType = null;
            $this->failEncounterReferral(__('Не вдалося створити направлення: ').$exception->getMessage());
        }
    }

    /** @return array{started_at: string, ended_at: string} */
    protected function encounterReferralPeriod(): array
    {
        $this->validate([
            'encounterReferralForm.started_at' => ['required', 'date_format:d.m.Y'],
            'encounterReferralForm.ended_at' => ['required', 'date_format:d.m.Y'],
            'encounterReferralForm.start_time' => ['required', 'date_format:H:i'],
            'encounterReferralForm.end_time' => ['required', 'date_format:H:i'],
        ], [], [
            'encounterReferralForm.started_at' => __('forms.start_date'),
            'encounterReferralForm.ended_at' => __('forms.end_date'),
            'encounterReferralForm.start_time' => __('referrals.period_fields.start_time'),
            'encounterReferralForm.end_time' => __('referrals.period_fields.end_time'),
        ]);

        $start = CarbonImmutable::createFromFormat(
            '!d.m.Y H:i',
            $this->encounterReferralForm['started_at'].' '.$this->encounterReferralForm['start_time'],
            config('app.timezone')
        );
        $end = CarbonImmutable::createFromFormat(
            '!d.m.Y H:i',
            $this->encounterReferralForm['ended_at'].' '.$this->encounterReferralForm['end_time'],
            config('app.timezone')
        );

        foreach (['start_time' => $start, 'end_time' => $end] as $field => $date) {
            if ($date->format('H:i') !== $this->encounterReferralForm[$field]) {
                throw ValidationException::withMessages([
                    'encounterReferralForm.'.$field => __('referrals.period_fields.nonexistent_time'),
                ]);
            }
        }

        $period = ['started_at' => $start->toIso8601String(), 'ended_at' => $end->toIso8601String()];
        $this->assertEncounterReferralPeriod($period);

        return $period;
    }

    protected function assertEncounterReferralPeriod(array $period): void
    {
        $serverNow = CarbonImmutable::now();
        $rules = [
            'encounterReferralForm.start_time' => ['required', 'date', 'after_or_equal:'.$serverNow->toIso8601String()],
            'encounterReferralForm.ended_at' => ['required', 'date', 'after_or_equal:encounterReferralForm.start_time'],
        ];
        if (array_key_exists('priority', $period)) {
            $rules['encounterReferralForm.priority'] = ['required', Rule::in(array_keys($this->encounterReferralPriorityOptions()))];
        }
        $validator = Validator::make(['encounterReferralForm' => [
            'start_time' => $period['started_at'] ?? null,
            'ended_at' => $period['ended_at'] ?? null,
            'priority' => $period['priority'] ?? null,
        ]], $rules, [
            'encounterReferralForm.start_time.after_or_equal' => __('errors.ehealth.messages.occurrence_start_in_past').' '.__('referrals.period_fields.comparison_context', [
                'start' => $period['started_at'] ?? '',
                'now' => $serverNow->toIso8601String(),
            ]),
            'encounterReferralForm.ended_at.after_or_equal' => __('errors.ehealth.messages.period_end_before_start'),
        ]);
        if ($validator->fails() && isset($validator->failed()['encounterReferralForm.start_time']['AfterOrEqual'])) {
            Log::notice('Encounter referral start is in the past', [
                'draft_uuid' => $this->encounterReferralRequestIdToSign,
                'start' => $period['started_at'] ?? null,
                'end' => $period['ended_at'] ?? null,
                'server_now' => $serverNow->toIso8601String(),
                'timezone' => config('app.timezone'),
            ]);
        }
        $validator->validate();
    }

    public function signEncounterReferral(): void
    {
        if ($this->referralReadOnly ?? false) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }
        if (empty($this->encounterReferralRequestIdToSign)) {
            Session::flash('error', __('Не вибрано направлення для підписання'));
            $this->showSignatureModal = false;
            $this->actionType = null;

            return;
        }

        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null) {
            $this->showSignatureModal = false;
            $this->actionType = null;

            return;
        }

        try {
            $requestRecord = app(MedicalRequestOwnership::class)
                ->serviceForEncounter(
                    (string) $this->encounterReferralRequestIdToSign,
                    $encounter
                );

            if (MedicalEventsRepository::serviceRequest()->hasPendingSubmission($requestRecord)) {
                $this->checkEncounterReferralSubmission();
                $this->showSignatureModal = false;

                return;
            }
            if ($requestRecord->status === 'active') {
                $this->completeEncounterReferralSubmission($requestRecord);

                return;
            }
            $person = MedicalEventsRepository::serviceRequest()->patientForEncounter($encounter);
            if ($person === null || empty($person->uuid)) {
                throw new RuntimeException(__('Пацієнта не знайдено'));
            }

            $lifecycle = app(ReferralRequestLifecycleService::class);
            $employeeContext = $lifecycle->resolveEncounterEmployeeContext(
                $encounter,
                $requestRecord->employeeId ?? Auth::user()?->activeDoctorEmployee()?->id
            );

            if (Auth::user()->cannot('create', [ServiceRequestRequest::class, MedicalEventsRepository::serviceRequest()->authorForDraft($employeeContext['employee_id'], $employeeContext['employee_uuid']), (string) ($this->encounterReferralForm['category'] ?? ''), $encounter])) {
                Session::flash('error', __('referrals.messages.category_forbidden'));

                return;
            }
            $dbData = $lifecycle->buildSignDbData($requestRecord, null, $encounter, $employeeContext);
            // Approval, product and remaining quantity may change while the KEP modal is open.
            $dbData = MedicalEventsRepository::serviceRequest()->encounterActivityContext($encounter, $dbData, $employeeContext['employee_uuid']);

            $this->assertEncounterReferralPeriod($dbData);
            $validated = $this->form->validate($this->form->signingRules());

            $uuids = [
                'person_uuid' => $person->uuid,
                'encounter_uuid' => $encounter->uuid,
                'episode_uuid' => $encounter->episode?->value ?? null,
                'employee_uuid' => $employeeContext['employee_uuid'],
                'legal_entity_uuid' => $employeeContext['legal_entity_uuid'],
            ];

            $resource = (new ServiceRequestMapper())->toFhir($dbData, $uuids + ['resource' => MedicalEventsRepository::serviceRequest()->getResource($requestRecord), 'carePlanUuid' => $dbData['care_plan_uuid'] ?? null]);
            $signPayload = ServiceRequestForm::buildCreateContent($resource);

            $signedContent = signatureService()->signData(
                $signPayload,
                $validated['password'],
                $validated['knedp'],
                $validated['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            $this->assertEncounterReferralPeriod($dbData);
            // Repeat the source's existing policy checks after KEP and use the saved category, not mutable form state.
            $encounter = $this->resolveEncounterModelForStandalone();
            $author = MedicalEventsRepository::serviceRequest()->authorForDraft($employeeContext['employee_id'], $employeeContext['employee_uuid']);
            if ($encounter === null || $author === null
                || Auth::user()->cannot('create', [ServiceRequestRequest::class, $author, (string) ($dbData['category'] ?? ''), $encounter])) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return;
            }
            $freshContext = MedicalEventsRepository::serviceRequest()->encounterActivityContext($encounter, $dbData, $employeeContext['employee_uuid']);
            if ($freshContext !== $dbData) {
                throw ValidationException::withMessages(['encounterReferralForm.care_plan_activity_id' => __('referrals.messages.activity_unavailable')]);
            }
            $requestRecord = MedicalEventsRepository::serviceRequest()->storeResource($resource, $person, $requestRecord->employeeId, $requestRecord->divisionId);
            $finalResponse = $this->submitClinicalOperation($requestRecord, ['status' => 'active'], fn () => SubmissionApi::serviceRequest()->createSigned($person->uuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']));
            if ($finalResponse === null) {
                return;
            }

            $dbData = $lifecycle->persistAfterSignedCreate(
                $dbData,
                $finalResponse,
                'service_request',
                $person
            );

            $requestRecord->requestNumber = $dbData['request_number'] ?? null;
            $this->completeEncounterReferralSubmission($requestRecord);
        } catch (ValidationException $exception) {
            if (array_intersect(array_keys($exception->errors()), ['form.knedp', 'form.password', 'form.keyContainerUpload']) === []) {
                $this->showSignatureModal = false;
                $this->actionType = null;
            }

            throw $exception;
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = null;
        } catch (EHealthValidationException $exception) {
            $exception->report();
            $this->encounterReferralWarningMessage = $exception->getFormattedMessage();
            Session::flash('error', $this->encounterReferralWarningMessage);
            $this->showSignatureModal = false;
            $this->actionType = null;
        } catch (Throwable $exception) {
            Log::error('EncounterEdit: failed to sign encounter referral: '.$exception->getMessage());
            Session::flash('error', __('Не вдалося підписати направлення: ').$exception->getMessage());
            $this->showSignatureModal = false;
            $this->actionType = null;
        } finally {
            if (isset($requestRecord) && $this->encounterReferralRequestIdToSign !== null) {
                $this->encounterReferralSubmission = MedicalEventsRepository::serviceRequest()->submissionState($requestRecord);
                if (($this->encounterReferralSubmission['status'] ?? null) === JobStatus::PENDING->value) {
                    $this->form->resetSigningFields();
                    $this->afterEncounterReferralSubmitted();
                }
            }
        }
    }

    protected function afterEncounterReferralDraftChanged(): void
    {
    }

    protected function afterEncounterReferralDraftSaved(bool $sign): bool
    {
        return false;
    }

    protected function afterEncounterReferralSubmitted(): void
    {
    }

    public function checkEncounterReferralSubmission(bool $synchronize = false): void
    {
        if ($this->encounterReferralRequestIdToSign === null) {
            return;
        }
        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null) {
            return;
        }
        $record = app(MedicalRequestOwnership::class)->serviceForEncounter($this->encounterReferralRequestIdToSign, $encounter);
        $repository = MedicalEventsRepository::serviceRequest();
        $previousStatus = $this->encounterReferralSubmission['status'] ?? null;
        if ($synchronize && $record->status !== 'active') {
            try {
                $person = $repository->patientForEncounter($encounter);
                $remote = app(ReferralRequestLifecycleService::class)->fetchRemoteReferral($person->uuid, $record->uuid, 'service_request');
                $record = $repository->importResource((new ServiceRequestMapper())->normalize($remote), $person, $record->employeeId, $record->divisionId);
            } catch (Throwable $exception) {
                report($exception);
                Session::flash('warning', __('referrals.submission_refresh_failed'));
            }
        }
        $this->encounterReferralSubmission = $repository->submissionState($record);
        $status = $this->encounterReferralSubmission['status'];
        if ($record->status === 'active') {
            $this->completeEncounterReferralSubmission($record);
        } elseif ($status === JobStatus::FAILED->value && $status !== $previousStatus) {
            $this->encounterReferralWarningMessage = $this->encounterReferralSubmission['error'] ?: __('referrals.submission_failed');
            Session::flash('error', $this->encounterReferralWarningMessage);
        }
    }

    protected function completeEncounterReferralSubmission(ServiceRequestRequest $record): void
    {
        if (blank($record->requestNumber)) {
            try {
                $repository = MedicalEventsRepository::serviceRequest();
                $person = $repository->patientForRequest($record);
                $remote = app(ReferralRequestLifecycleService::class)->fetchRemoteReferral($person->uuid, $record->uuid, 'service_request');
                $number = $remote['requisition'] ?? ($remote['request_number'] ?? null);
                if (is_string($number) && $number !== '') {
                    $repository->updateRequestNumber($record->uuid, $number);
                    $record->requestNumber = $number;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $this->showSignatureModal = false;
        $this->actionType = null;
        $this->encounterReferralRequestIdToSign = null;
        $this->afterEncounterReferralDraftChanged();
        $this->encounterReferralSubmission = [];
        $this->form->resetSigningFields();
        Session::flash('success', filled($record->requestNumber)
            ? __('referrals.submission_created', ['number' => $record->requestNumber])
            : __('referrals.submission_created_without_number'));
        $this->afterEncounterReferralSigned($record);
    }

    /**
     * Keep transfer_of_care when the encounter is a transfer discharge.
     * eHealth does not require the catalog category to match for this category.
     *
     * @param  array<string, mixed>  $service
     */
    protected function applyEncounterReferralServiceCategory(array $service): void
    {
        $this->encounterReferralServiceType = ($service['type'] ?? '') === 'service_group' ? 'service_group' : 'service';
        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter !== null && $this->resolveEncounterReferralTransfer($encounter) !== null) {
            $this->encounterReferralForm['category'] = 'transfer_of_care';
            $this->encounterReferralIsTransfer = true;
            $this->resetValidation('encounterReferralForm.category');

            return;
        }

        if ($this->encounterReferralServiceType === 'service_group') {
            $this->encounterReferralForm['category'] = '';
            $this->resetValidation('encounterReferralForm.category');

            return;
        }

        $category = ServiceSearch::requestCategory($service);
        if ($category !== null) {
            $this->encounterReferralForm['category'] = $category;
            if ($category === 'hospitalization') {
                $this->loadEncounterReferralSpecialities();
            }
            $this->resetValidation('encounterReferralForm.category');
        }
    }

    /**
     * A finished inpatient discharge to another facility.
     *
     * @return array{uuid: string, name: string}|null
     */
    protected function resolveEncounterReferralTransfer(Encounter $encounter): ?array
    {
        $status = $encounter->status instanceof EncounterStatus
            ? $encounter->status
            : EncounterStatus::tryFrom((string) $encounter->status);

        if ($status !== EncounterStatus::FINISHED) {
            return null;
        }

        $encounter->loadMissing([
            'class',
            'hospitalization.dischargeDisposition',
            'hospitalization.destination',
        ]);

        if ($encounter->class?->code !== 'INPATIENT') {
            return null;
        }

        if ($encounter->hospitalization?->dischargeDisposition?->code !== 'transfer_general') {
            return null;
        }

        $destination = (string) ($encounter->hospitalization?->destination?->value ?? '');
        if (!Str::isUuid($destination)) {
            return null;
        }

        return [
            'uuid' => $destination,
            'name' => Repository::legalEntity()->displayNameByUuid($destination),
        ];
    }

    /**
     * Block transfer_of_care outside a transfer discharge, and require a destination division inside it.
     * Returns true when the draft must not be created.
     */
    protected function rejectInvalidEncounterReferralTransfer(Encounter $encounter): bool
    {
        $transfer = $this->resolveEncounterReferralTransfer($encounter);
        $category = (string) ($this->encounterReferralForm['category'] ?? '');

        if ($category === 'transfer_of_care' && $transfer === null && ($encounter->class?->code !== 'INPATIENT' || !in_array($encounter->type?->coding?->first()?->code, ['service_delivery_location', 'discharge'], true))) {
            $this->failEncounterReferral(__('referrals.messages.transfer_encounter_required'));

            return true;
        }

        if ($transfer === null || $category !== 'transfer_of_care') {
            if ($category === 'transfer_of_care' && blank($this->encounterReferralForm['performer'] ?? null)) {
                $this->failEncounterReferral(__('referrals.messages.transfer_destination_required'));

                return true;
            }
            $performer = (string) ($this->encounterReferralForm['performer'] ?? '');
            $location = (string) ($this->encounterReferralForm['location_reference'] ?? '');
            if (($performer !== '' && $performer !== $this->encounterReferralVerifiedDestinationUuid)
                || ($location !== '' && !in_array($location, $this->encounterReferralAllowedDivisionIds, true))
                || ($category === 'transfer_of_care' && $location === '')) {
                $this->failEncounterReferral(__('referrals.messages.transfer_destination_required'));

                return true;
            }
            // Optional destination fields survive; hospitalization always supplies a specialty.
            if ($category === 'hospitalization') {
                $this->loadEncounterReferralSpecialities();
                $speciality = (string) ($this->encounterReferralForm['performer_type'] ?? '');
                if ($speciality === '' || !array_key_exists($speciality, $this->encounterReferralSpecialities)) {
                    $this->failEncounterReferral(__('referrals.messages.hospitalization_speciality_required'));

                    return true;
                }
            }

            return false;
        }

        $this->encounterReferralForm['category'] = 'transfer_of_care';
        $this->encounterReferralForm['performer'] = $transfer['uuid'];

        $location = (string) ($this->encounterReferralForm['location_reference'] ?? '');
        if (!$this->loadEncounterReferralDivisions($transfer['uuid'])) {
            return true;
        }
        $this->loadEncounterReferralSpecialities();
        if ($location === '' || !in_array($location, $this->encounterReferralAllowedDivisionIds, true)) {
            $message = $this->encounterReferralDivisions === []
                ? __('Немає активного підрозділу закладу, до якого переводять пацієнта.')
                : __('Оберіть підрозділ закладу, до якого переводять пацієнта.');
            $this->failEncounterReferral($message);

            return true;
        }

        $performerType = (string) ($this->encounterReferralForm['performer_type'] ?? '');
        if (
            $performerType !== ''
            && !array_key_exists($performerType, $this->encounterReferralSpecialities)
        ) {
            $this->failEncounterReferral(__('Обрана спеціальність виконавця недоступна.'));

            return true;
        }

        return false;
    }

    protected function failEncounterReferral(string $message): void
    {
        $this->encounterReferralWarningMessage = $message;
        Session::flash('error', $message);
    }

    /** Resolve a selected destination before exposing its divisions as choices. */
    public function selectEncounterReferralDestination(): void
    {
        if (Auth::user()->cannot('service_request:write')) {
            Session::flash('error', __('referrals.messages.category_forbidden'));

            return;
        }
        $this->validate(['encounterReferralDestinationUuid' => ['required', 'uuid']]);
        try {
            $destination = EHealth::legalEntity()->getDetails(uuid: $this->encounterReferralDestinationUuid)->validate();
            $destination = array_is_list($destination) ? $destination[0] : $destination;
            $id = $destination['uuid'] ?? ($destination['id'] ?? null);
            if ($id !== $this->encounterReferralDestinationUuid || ($destination['status'] ?? '') !== 'ACTIVE') {
                throw new RuntimeException(__('referrals.messages.transfer_destination_required'));
            }
            if (!$this->loadEncounterReferralDivisions($id)) {
                return;
            }
            $this->encounterReferralVerifiedDestinationUuid = $id;
            $this->encounterReferralForm['performer'] = $id;
            $this->encounterReferralForm['location_reference'] = '';
            $this->encounterReferralPerformerName = $destination['name'] ?? '';
        } catch (Throwable $exception) {
            report($exception);
            $this->failEncounterReferral(__('referrals.messages.transfer_destination_required'));
        }
    }

    protected function loadEncounterReferralDivisions(string $legalEntityUuid): bool
    {
        $this->encounterReferralWarningMessage = '';
        $this->encounterReferralDivisions = [];
        $this->encounterReferralAllowedDivisionIds = [];

        try {
            $rows = [];
            $page = 1;
            do {
                $response = EHealth::division()->search($legalEntityUuid, $page);
                $data = $response->validate();
                $entries = $data['entries'] ?? $data;
                if (!is_array($entries) || !array_is_list($entries)) {
                    throw new RuntimeException(__('encounters.referral_divisions_invalid_response'));
                }
                $rows = [...$rows, ...$entries];
                $totalPages = (int) ($data['total_pages'] ?? ($response->getPaging()['total_pages'] ?? $page));
                $page++;
            } while ($page <= $totalPages);
        } catch (Throwable $exception) {
            Log::warning('EncounterEdit: failed to load destination divisions for transfer referral: '.$exception->getMessage());
            $this->failEncounterReferral(__('Не вдалося завантажити підрозділи закладу, до якого переводять пацієнта.'));

            return false;
        }

        $divisions = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (string) ($row['id'] ?? ($row['uuid'] ?? ''));
            // The public registry filters by destination LE; some rows only expose its name.
            $owner = (string) ($row['legal_entity_id'] ?? ($row['legal_entity_uuid'] ?? (data_get($row, 'legal_entity.id') ?? $legalEntityUuid)));
            $type = (string) ($row['type'] ?? '');
            $status = strtoupper((string) ($row['status'] ?? ''));
            $active = $row['is_active'] ?? ($row['isActive'] ?? true);

            // Registry rows may omit status. eHealth prequalify validates the chosen division before persistence.
            if (!Str::isUuid($id) || $owner !== $legalEntityUuid || ($status !== '' && $status !== 'ACTIVE') || $active === false) {
                continue;
            }

            if (!in_array($type, self::TRANSFER_DIVISION_TYPES, true)) {
                continue;
            }

            $divisions[] = [
                'id' => $id,
                'name' => (string) ($row['name'] ?? $id),
                'type' => $type,
            ];
        }

        $this->encounterReferralDivisions = $divisions;
        $this->encounterReferralAllowedDivisionIds = array_column($divisions, 'id');
        Log::debug('Transfer referral division lookup completed', [
            'returnedCount' => count($rows),
            'selectableCount' => count($divisions),
            'returnedTypes' => array_values(array_unique(array_column($rows, 'type'))),
        ]);
        if ($divisions === []) {
            $this->encounterReferralWarningMessage = __('encounters.referral_no_eligible_divisions');
            Session::flash('warning', $this->encounterReferralWarningMessage);
        }

        return true;
    }

    protected function loadEncounterReferralSpecialities(): void
    {
        try {
            $this->encounterReferralSpecialities = dictionary()
                ->basics()
                ->byName('SPECIALITY_TYPE')
                ->asCodeDescription()
                ->all();
        } catch (Throwable $exception) {
            Log::warning('EncounterEdit: failed to load SPECIALITY_TYPE for transfer referral: '.$exception->getMessage());
            $this->encounterReferralSpecialities = [];
        }
    }

    protected function loadEncounterReferralAuthMethods(Encounter $encounter): void
    {
        $this->encounterReferralAuthMethods = [];
        $person = MedicalEventsRepository::serviceRequest()->patientForEncounter($encounter);
        if (!$person instanceof Person || empty($person->uuid)) {
            return;
        }

        try {
            $authMethods = EHealth::person()->getAuthMethods($person->uuid)->validate();
            if (!is_array($authMethods)) {
                return;
            }

            $this->encounterReferralAuthMethods = collect($authMethods)->map(static function (array $method): array {
                $uuid = (string) ($method['id'] ?? ($method['uuid'] ?? ''));
                $type = (string) ($method['type'] ?? '');
                $phone = (string) ($method['phone_number'] ?? ($method['value'] ?? ''));

                return [
                    'uuid' => $uuid,
                    'type' => $type,
                    'label' => trim($type.($phone !== '' ? ' · '.$phone : '')),
                    'raw' => $uuid !== '' ? "{$uuid}|{$type}|{$phone}" : '',
                ];
            })->filter(static fn (array $m): bool => $m['uuid'] !== '')->values()->all();
        } catch (Throwable $exception) {
            Log::warning('EncounterEdit: failed to load auth methods for referral: '.$exception->getMessage());
        }
    }

    protected function loadEncounterReferralPrograms(): void
    {
        try {
            $this->encounterReferralPrograms = dictionary()->medicalPrograms()
                ->where('is_active', true)
                ->where('type', MedicalProgramType::SERVICE->value)
                ->map(static fn (array $program): array => [
                    'id' => (string) ($program['id'] ?? ''),
                    'name' => (string) ($program['name'] ?? ''),
                ])
                ->filter(static fn (array $program): bool => $program['id'] !== '' && $program['name'] !== '')
                ->values()
                ->all();
        } catch (Throwable $exception) {
            Log::warning('EncounterEdit: failed to load service programs for standalone referral: '.$exception->getMessage());
            $this->encounterReferralPrograms = [];
        }
    }

    /**
     * Prefer PMG (state guarantees) when present; otherwise first loaded SERVICE program.
     */
    protected function resolveDefaultEncounterReferralProgramId(): string
    {
        foreach ($this->encounterReferralPrograms as $program) {
            $name = mb_strtolower((string) ($program['name'] ?? ''));
            if (
                str_contains($name, 'державних фінансових гарантій')
                || str_contains($name, 'пмг')
            ) {
                return (string) ($program['id'] ?? '');
            }
        }

        return (string) ($this->encounterReferralPrograms[0]['id'] ?? '');
    }

    private function resetEncounterReferralValidation(): void
    {
        foreach ($this->getErrorBag()->keys() as $key) {
            if (str_starts_with($key, 'encounterReferralForm.')) {
                $this->resetValidation($key);
            }
        }
    }

    protected function afterEncounterReferralSigned(ServiceRequestRequest $record): void
    {
    }

}
