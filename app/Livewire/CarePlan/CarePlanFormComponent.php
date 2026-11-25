<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\Api\Approval;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Enums\CarePlanStatus;
use App\Enums\CarePlanTermsOfService;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\CarePlan\Concerns\HandlesCarePlanApprovalSms;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Livewire\CarePlan\Forms\PatientSearchForm;
use App\Livewire\Concerns\SubmitsClinicalOperations;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\CarePlan;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\MedicalEvents\CarePlanApprovalService;
use App\Services\MedicalEvents\MedicalRequestOwnership;
use App\Traits\InteractsWithApprovals;
use App\Traits\SynchronizesCarePlans;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

/**
 * Shared patient, encounter, form and approval behavior for the care plan editor pages.
 */
abstract class CarePlanFormComponent extends BasePatientComponent
{
    use SynchronizesCarePlans;
    use SubmitsClinicalOperations;

    use WithFileUploads;
    use InteractsWithApprovals;
    use HandlesCarePlanApprovalSms;

    /** Whether the shared KEP modal is open. */
    public bool $showSignatureModal = false;

    /** Whether the patient authentication-method selector is open. */
    public bool $showMethodSelectionModal = false;

    /** eHealth patient identifier used by the consent workflow. */
    public string $patientUuid = '';

    /** Diagnosis UUID supplied by the entry point, when it preselects a condition. */
    #[Locked]
    public string $conditionUuid = '';

    /** Type of medical record supplied by the editor context. */
    public string $medicalRecordType = 'CONDITION';

    /** eHealth identifier used by the existing signing and consent workflows. */
    public ?string $carePlanUuid = null;

    /** Shared editor fields for creation, editing and signing. */
    public CarePlanForm $form;

    /** Criteria for selecting a patient from the local registry. */
    public PatientSearchForm $patientSearch;

    /** Category dictionary entries used as labels in the editor. */
    public array $categories = [];

    /** Category codes allowed by the selected encounter's diagnoses. */
    #[Locked]
    public array $allowedCategoryCodes = [];

    /** Diagnosis labels and dates displayed for the selected encounter. */
    public array $diagnoses = [];

    /** Authentication methods returned by eHealth for the selected patient. */
    #[Locked]
    public array $authMethods = [];

    /** List of patients matching the current search. */
    public array $patientSearchResults = [];

    /** Whether this entry point starts without a fixed patient or encounter. */
    #[Locked]
    public bool $allowsPatientChange = false;

    /** Whether the extra criteria in the patient search are visible. */
    public bool $showAdditionalSearchParams = false;

    /** Dictionary entries used by the shared editor. */
    public ?array $dictionaries = [];

    /** Clinicians available as co-authors in the current facility. */
    public array $doctors = [];

    /**
     * Dictionaries to load via FormTrait::getDictionary().
     */
    protected array $dictionaryNames = [
        'eHealth/care_plan_categories',
        'eHealth/encounter_classes',
        'PROVIDING_CONDITION',
    ];

    /**
     * Available encounters that have been confirmed by eHealth (for the encounter selector).
     */
    public array $availableEncounters = [];

    /** Active episodes available as supporting information for the patient. */
    public array $availableEpisodes = [];

    /** Resolve the route context and populate the shared editor and selection lists. */
    public function mount(LegalEntity $legalEntity, $personId = null, $encounter = null, ?Person $person = null): void
    {
        $routePersonId = $person?->id ?? request()->route('personId');
        $personId ??= $routePersonId;
        $encounterRouteParam = request()->route('encounter') ?? request()->query('encounter') ?? request()->query('encounterId');
        $this->allowsPatientChange = empty($routePersonId) && empty($encounterRouteParam);

        $resolvedPersonId = null;
        $resolvedEncounter = null;

        // Try to resolve encounter from route parameters, query string or sequence-passed personId

        if ($encounterRouteParam) {
            if (Str::isUuid((string) $encounterRouteParam)) {
                $resolvedEncounter = Repository::carePlan()->findEncounter($encounterRouteParam);
            } elseif (is_numeric($encounterRouteParam)) {
                $resolvedEncounter = Repository::carePlan()->findEncounter((int) $encounterRouteParam);
            }
            if ($resolvedEncounter) {
                $resolvedPersonId = $resolvedEncounter->person_id;
            }
        }

        if ($person !== null && $encounterRouteParam) {
            abort_unless($resolvedEncounter !== null && (int) $resolvedEncounter->person_id === $person->id, 404);
            $this->authorize('view', [$resolvedEncounter, $person]);
        }

        // If personId was passed, use it, unless it was sequential mapping of encounter
        if (!$resolvedPersonId && $personId && (!ctype_digit((string) $personId) || !Repository::carePlan()->findPatient((int) $personId))) {
            // Check if $personId is actually an encounter ID or UUID
            $possibleEncounter = null;
            if (Str::isUuid((string) $personId)) {
                $possibleEncounter = Repository::carePlan()->findEncounter($personId);
            } elseif (is_numeric($personId)) {
                $possibleEncounter = Repository::carePlan()->findEncounter((int) $personId);
            }

            if ($possibleEncounter) {
                $resolvedEncounter = $possibleEncounter;
                $resolvedPersonId = $possibleEncounter->person_id;
            } else {
                $resolvedPersonId = ctype_digit((string) $personId) ? (int) $personId : null;
            }
        }

        $patientId = $resolvedPersonId ?? $personId;
        $this->personId = ctype_digit((string) $patientId) ? (int) $patientId : 0;

        if ($this->personId > 0) {
            parent::mount($legalEntity);
        } else {
            $this->patientFullName = __('care-plan.new_care_plan');
            $this->verificationStatus = '';
            $this->uuid = '';
            $this->declarationNumber = null;
        }

        $person = Repository::carePlan()->findPatient($this->personId);
        if ($person) {
            $name = $person->primary_name;
            $this->form->patient = $name ? trim($name->last_name . ' ' . $name->first_name . ' ' . ($name->second_name ?? '')) : '';
            $this->form->medical_number = Repository::carePlan()->nextDisplayNumber();

            $this->availableEpisodes = Repository::carePlan()->activeEpisodes($person);

            $this->loadPatientAuthMethods($this->uuid);
        } else {
            $this->form->medical_number = Repository::carePlan()->nextDisplayNumber();
        }

        $this->loadAvailableEncounters();

        $conditionUuid = request()->query('conditionUuid');
        $this->conditionUuid = is_string($conditionUuid) && Str::isUuid($conditionUuid) ? $conditionUuid : '';

        if ($resolvedEncounter) {
            $this->form->encounter = $resolvedEncounter->uuid;
            $this->form->medical_number = (string) $resolvedEncounter->id;

            // Pre-fill title if empty
            if (empty($this->form->title)) {
                $date = $resolvedEncounter->period?->start ? Carbon::parse($resolvedEncounter->period->start)->format('d.m.Y') : now()->format('d.m.Y');
                $this->form->title = __('План лікування від ') . $date;
            }

            Repository::carePlan()->loadEncounter($resolvedEncounter);
            if ($resolvedEncounter->episode?->value) {
                $episode = Repository::carePlan()->findEpisode($resolvedEncounter->episode->value);
                if ($episode) {
                    $this->form->episodes = [
                        [
                            'uuid' => $episode->uuid,
                            'name' => $episode->name,
                            'date' => $episode->period?->start ? Carbon::parse($episode->period->start)->format('d.m.Y') : '',
                        ]
                    ];
                }
            }

            // Pre-fill diagnoses for the UI list
            Repository::carePlan()->loadEncounter($resolvedEncounter);
            $this->diagnoses = $resolvedEncounter->diagnoses->map(function ($d) {
                $conditionUuid = $d->condition?->value;
                $actualCondition = null;
                if ($conditionUuid) {
                    $actualCondition = Repository::carePlan()->findCondition($conditionUuid, $this->personId);
                    if (!$actualCondition) {
                        Log::info('CarePlanCreate mount: condition not found in local SQL DB, attempting to fetch from eHealth', [
                            'condition_uuid' => $conditionUuid
                        ]);
                        try {
                            $conditionData = EHealth::condition()->getById($this->uuid, $conditionUuid)->validate();
                            MedicalEventsRepository::condition()->store([Arr::toCamelCase($conditionData)], $person);
                            $actualCondition = Repository::carePlan()->findCondition($conditionUuid, $this->personId);
                        } catch (Exception $e) {
                            Log::error('CarePlanCreate mount: failed to fetch condition from eHealth', [
                                'condition_uuid' => $conditionUuid,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                }

                return [
                    'date' => $actualCondition?->asserted_date
                        ? Carbon::parse($actualCondition->asserted_date)->format('d.m.Y')
                        : '-',
                    'name' => ($actualCondition?->code?->text ?: null)
                        ?? (($actualCondition?->code?->coding?->first()?->code ?: null) ?? '-'),
                ];
            })->toArray();
        }

        $this->form->periodStart = now()->format(config('app.date_format'));
        $this->form->periodStartTime = now()->format('H:i');

        $this->synchronizeSystemFields();
        $this->loadDoctorsAndDictionaries();
        $this->refreshCategoryOptions();
    }

    /**
     * Re-resolve the care plan writer employee for the currently selected terms_of_service and
     * refresh the read-only "Автор" display field. eHealth ties the author to a specific
     * healthcare_service.providing_condition, so the effective author can change when the user
     * switches "умови надання послуг" (terms_of_service).
     */
    public function updatedFormTermsOfService(): void
    {
        $this->synchronizeSystemFields();
    }

    /** Populate intent, terms of service and the displayed author from the selected encounter. */
    protected function synchronizeSystemFields(): void
    {
        $this->form->intent = 'order';
        $this->form->termsOfService = Repository::carePlan()->encounterTermsOfService(
            $this->form->encounter ?? '',
            (int) $this->personId
        );
        $this->refreshAuthorDisplay();
    }

    /** Refresh the category choices and clear a selection that no longer applies. */
    protected function refreshCategoryOptions(): void
    {
        $this->resolveEncounterData();
        $this->allowedCategoryCodes = Repository::carePlan()->categoryCodesForEncounter($this->form->encounter ?? '', (int) $this->personId);
        $this->resetValidation('form.category');
        if (!in_array($this->form->category ?? '', $this->allowedCategoryCodes, true)) {
            $this->form->category = count($this->allowedCategoryCodes) === 1 ? $this->allowedCategoryCodes[0] : '';
        }
    }

    /** Recheck the selected category against the encounter before opening or submitting KEP. */
    protected function validateCategoryForSigning(): void
    {
        Repository::carePlan()->assertCategoryForEncounter($this->form->category, $this->form->encounter, (int) $this->personId);
    }

    /** Show the filename of the current KEP key container in the signing modal. */
    public function updatedFormKeyContainerUpload(): void
    {
        $upload = $this->form->keyContainerUpload ?? null;

        if ($upload && method_exists($upload, 'getClientOriginalName')) {
            $this->form->keyContainerFileName = $upload->getClientOriginalName();
        } elseif ($upload === null) {
            $this->form->keyContainerFileName = '';
        }
    }

    /** Display the employee resolved for the form's terms of service. */
    protected function refreshAuthorDisplay(): void
    {
        $employee = Auth::user()?->getCarePlanWriterEmployee($this->form->termsOfService ?: null);
        if ($employee) {
            $party = $employee->party;
            $this->form->author = implode(' ', array_filter([
                $party?->last_name, $party?->first_name, $party?->second_name,
            ]));
        }
    }

    /** Load the facility's co-author choices and the dictionaries used by the editor. */
    private function loadDoctorsAndDictionaries(): void
    {
        // Load doctors for co-authors
        $legalEntity = legalEntity();
        if ($legalEntity) {
            $this->doctors = Repository::carePlan()->doctors($legalEntity->id);
        }

        // Load dictionaries via FormTrait pattern
        try {
            $this->getDictionary();
            $this->categories = $this->dictionaries['eHealth/care_plan_categories'] ?? [];
        } catch (Exception $exception) {
            report($exception);
            Log::warning('CarePlanCreate: failed to load dictionaries: ' . $exception->getMessage());
        }
    }

    /**
     * Search for a patient in the local registry (same criteria as the patients page).
     */
    public function searchForPatient(): void
    {
        try {
            $validated = $this->patientSearch->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $firstName = $validated['firstName'];
        $lastName = $validated['lastName'];
        $birthDate = convertToYmd($validated['birthDate']);

        $this->patientSearchResults = Repository::carePlan()->searchPatients($firstName, $lastName, $birthDate);

        if ($this->patientSearchResults === []) {
            Session::flash('error', __('patients.nobody_found') . '. ' . __('patients.try_change_search_parameters'));
        }
    }

    /**
     * Select a patient from the search results.
     */
    public function selectPatient(int $personId): void
    {
        if (!$this->allowsPatientChange) {
            return;
        }

        $person = Repository::carePlan()->findPatient($personId) ?? abort(404);

        $this->personId = $person->id;
        $this->uuid = $person->uuid;
        $this->patientUuid = $person->uuid;
        $this->patientFullName = $person->fullName;
        $this->verificationStatus = $person->verificationStatus;
        $this->declarationNumber = $person->declarations->first()?->declarationNumber ?? null;

        $birthDate = $person->birth_date
            ? Carbon::parse($person->birth_date)->format(config('app.date_format'))
            : null;
        $this->form->patient = trim($person->fullName . ($birthDate ? ' · ' . $birthDate : ''));
        $this->form->medical_number = Repository::carePlan()->nextDisplayNumber();
        $this->form->encounter = '';
        $this->form->category = '';
        $this->allowedCategoryCodes = [];
        $this->diagnoses = [];
        $this->patientSearchResults = [];
        $this->loadAvailableEncounters();

        $this->loadPatientAuthMethods((string) $person->uuid);
    }

    /**
     * Load the patient's authentication methods from eHealth.
     *
     * These identify how the patient confirms consent, so they may only come from the
     * registry — a local fallback would fabricate legally significant data.
     */
    private function loadPatientAuthMethods(string $personUuid): void
    {
        try {
            $this->authMethods = EHealth::person()->getAuthMethods($personUuid)->validate();
        } catch (Exception $e) {
            $this->authMethods = [];

            Log::channel('e_health_errors')->error('CarePlanCreate: failed to load patient auth methods', [
                'person_uuid' => $personUuid,
                'exception' => $e->getMessage(),
            ]);

            Session::flash('error', __('care-plan.auth_methods_unavailable'));
        }
    }

    /**
     * Reset patient selection and return to search mode.
     */
    public function clearSelectedPatient(): void
    {
        if (!$this->allowsPatientChange) {
            return;
        }

        $this->personId = 0;
        $this->patientUuid = '';
        $this->uuid = '';
        $this->patientFullName = __('care-plan.new_care_plan');
        $this->verificationStatus = '';
        $this->declarationNumber = null;
        $this->form->patient = '';
        $this->form->encounter = '';
        $this->form->category = '';
        $this->allowedCategoryCodes = [];
        $this->availableEncounters = [];
        $this->authMethods = [];
        $this->diagnoses = [];
        $this->patientSearchResults = [];
        $this->form->medical_number = Repository::carePlan()->nextDisplayNumber();
    }

    /**
     * Expose field errors and scroll the editor to the first correction.
     *
     * @param  bool  $closeModal  Close KEP only when the error belongs to the editor.
     */
    protected function handleValidationFailed(ValidationException $exception, bool $closeModal = false): void
    {
        $message = $exception->validator->errors()->first() ?: (__('validation.failed'));

        Session::flash('error', $message);
        $this->setErrorBag($exception->validator->getMessageBag());

        $firstKey = (string) array_key_first($exception->validator->errors()->messages());
        $selector = match ($firstKey) {
            'form.category', 'category' => '#category',
            'form.periodEnd', 'periodEnd' => '#period_end',
            'form.periodStart', 'periodStart' => '#period_start',
            'form.encounter', 'encounter' => '#encounter_select',
            default => null,
        };

        if ($selector !== null) {
            $this->dispatch('scroll-to-element', selector: $selector);
        } else {
            $this->dispatch('scroll-to-error');
        }

        if ($closeModal && !$exception->validator->errors()->hasAny(['form.knedp', 'form.keyContainerUpload', 'form.password'])) {
            $this->showSignatureModal = false;
        }
    }

    /**
     * Look up a NEW care_plan approval on the patient-scoped eHealth endpoint.
     *
     * GET /api/approvals is rejected by Cloudflare WAF; list by patient instead.
     *
     * @return array<string, mixed>|null
     */
    private function findNewCarePlanApproval(string $carePlanUuid): ?array
    {
        $patientUuid = $this->patientUuid ?: $this->uuid;
        if ($patientUuid === '' || $carePlanUuid === '') {
            return null;
        }

        try {
            // GET /api/patients/{id}/approvals rejects `status` (422 additional properties).
            $response = EHealth::approval()->getPatientApprovals($patientUuid, [
                'granted_resource_type' => 'care_plan',
                'granted_resources' => $carePlanUuid,
            ]);
            $approvals = $response->validate();
            $approvalsData = is_array($approvals) ? ($approvals['data'] ?? $approvals) : [];
            if (!is_array($approvalsData) || $approvalsData === []) {
                return null;
            }

            foreach ($approvalsData as $approval) {
                if (!is_array($approval)) {
                    continue;
                }

                foreach ($approval['granted_resources'] ?? [] as $resource) {
                    if (($resource['identifier']['value'] ?? null) === $carePlanUuid) {
                        return $approval;
                    }
                }
            }

            $first = $approvalsData[0] ?? null;

            return is_array($first) ? $first : null;
        } catch (Exception $exception) {
            Log::warning('CarePlanCreate: Failed to fetch approvals: '.$exception->getMessage());

            return null;
        }
    }

    public function openActivationManually(): void
    {
        $carePlan = $this->carePlanUuid
            ? Repository::carePlan()->findByUuid($this->carePlanUuid, (int) legalEntity()->id, $this->personId)
            : null;

        if ($carePlan === null || !$this->guardApprovalAccess($carePlan)) {
            return;
        }

        if ($this->shouldSkipPatientAuthUi($carePlan)) {
            $this->createApproval('');

            return;
        }

        if (!$this->approvalId && $this->carePlanUuid) {
            $matchedApproval = $this->findNewCarePlanApproval($this->carePlanUuid);
            $this->approvalId = $matchedApproval['id'] ?? null;
            if (is_array($matchedApproval)) {
                $this->currentAuthMethod = $matchedApproval['authentication_method_current'] ?? $this->currentAuthMethod;
            }
        }

        if ($this->approvalId) {
            try {
                $result = app(CarePlanApprovalService::class)->pendingConfirmation($this->patientUuid, $this->approvalId);
                if ($result->isGranted()) {
                    Session::flash('success', __('care-plan.approval_verified'));
                    $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan->id], navigate: true);

                    return;
                }
                $this->currentAuthMethod = $result->authMethod;
                $this->openAuthModal();
            } catch (Throwable $exception) {
                Session::flash('error', __('care-plan.approval_result_unavailable'));
            }
        } else {
            $this->openMethodSelectionModal();
        }
    }

    public function openMethodSelectionModal(): void
    {
        $carePlan = $this->carePlanUuid
            ? Repository::carePlan()->findByUuid($this->carePlanUuid, (int) legalEntity()->id, $this->personId)
            : null;

        if ($carePlan === null || !$this->guardApprovalAccess($carePlan)) {
            return;
        }

        if ($this->shouldSkipPatientAuthUi($carePlan)) {
            $this->createApproval('');

            return;
        }

        if (!empty($this->form->periodEnd)) {
            Session::flash('error', __('care-plan.period_end_warning'));
        }

        try {
            $this->authMethods = EHealth::person()->getAuthMethods($this->patientUuid)->validate();
            $this->showMethodSelectionModal = true;
        } catch (Exception $e) {
            Log::error('CarePlanCreate: failed to load auth methods: ' . $e->getMessage());
            Session::flash('error', __('Не вдалося завантажити методи аутентифікації'));
        }
    }

    public function selectAuthMethod(string $methodUuid): void
    {
        $carePlan = $this->carePlanForSms();
        if (!$this->guardApprovalAccess($carePlan)) {
            return;
        }
        $this->currentAuthMethod = $this->selectedApprovalAuthMethod($methodUuid);
        $this->showMethodSelectionModal = false;
        $this->createApproval($methodUuid);
    }

    protected function createApproval(string $methodUuid): void
    {
        try {
            // Resolve the employee UUID that will be granted access.
            // Priority: explicitly selected doctor on the form → authenticated user's DOCTOR/SPECIALIST employee.
            // If neither resolves, skip approval creation and instruct the user to create it manually.
            $employeeUuid = (!empty($this->form->doctor) ? $this->form->doctor : null)
                ?? Auth::user()?->getCarePlanWriterEmployee($this->form->termsOfService ?: null)?->uuid;

            if (!$employeeUuid) {
                $carePlan = Repository::carePlan()->findByUuid($this->carePlanUuid, (int) legalEntity()->id, $this->personId);
                session()->flash('info', __('Не вдалося визначити лікаря для створення дозволу. Перейдіть на вкладку "Дозволи" та створіть дозвіл вручну.'));
                $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan?->id ?? $this->carePlanUuid], navigate: true);

                return;
            }

            $carePlan = Repository::carePlan()->findByUuid($this->carePlanUuid, (int) legalEntity()->id, $this->personId) ?? abort(404);
            $approvalService = app(CarePlanApprovalService::class);
            $skipsOtp = $this->shouldSkipPatientAuthUi($carePlan);

            $result = $approvalService->create(
                carePlan: $carePlan,
                patientUuid: $this->patientUuid,
                employeeUuid: $employeeUuid,
                accessLevel: 'write',
                authorizeWith: $skipsOtp ? null : ($methodUuid ?: null),
                user: Auth::user(),
                bearerToken: Session::get(config('ehealth.api.oauth.bearer_token')),
            );

            if ($result->isAsync()) {
                $this->approvalId = $result->approvalId;
                $this->pollingLinkId = $result->pollingLinkId;
                $this->isPolling = true;

                return;
            }

            $this->approvalId = $result->approvalId;

            if ($result->requiresConfirmation() && !$skipsOtp) {
                $this->currentAuthMethod = $result->authMethod ?? $this->currentAuthMethod;
                $this->openAuthModal();

                return;
            }

            Session::flash('success', $skipsOtp
                ? __('care-plan.approval_inpatient_granted')
                : __('План лікування успішно активовано.'));
            $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan->id], navigate: true);
        } catch (Exception $e) {
            Log::error('CarePlanCreate: failed to create approval: ' . $e->getMessage());
            $msg = __('Не вдалося створити запит на дозвіл: ') . $e->getMessage();
            Session::flash('error', $msg);
        }
    }

    /**
     * Called by wire:poll.2s while $isPolling === true.
     */
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
            $msg = $status->errorMessage ?: __('Не вдалося обробити запит на дозвіл. Спробуйте ще раз.');
            Session::flash('error', $msg);

            return;
        }

        if ($status->approvalId) {
            $this->approvalId = $status->approvalId;
        }

        $carePlan = Repository::carePlan()->findByUuid($this->carePlanUuid, (int) legalEntity()->id, $this->personId);
        $skipsOtp = $this->shouldSkipPatientAuthUi($carePlan);

        if ($status->requiresConfirmation() && !$skipsOtp) {
            $this->currentAuthMethod = $status->authMethod ?? $this->currentAuthMethod;
            $this->openAuthModal();

            return;
        }

        Session::flash('success', $skipsOtp
            ? __('care-plan.approval_inpatient_granted')
            : __('План лікування успішно активовано.'));
        $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan?->id ?? $this->carePlanUuid], navigate: true);
    }

    /** Return to the selected encounter, patient record or care plan list. */
    public function cancel(): void
    {
        $encounterUuid = $this->form->encounter;
        if ($encounterUuid) {
            $encounter = Repository::carePlan()->findEncounter($encounterUuid, $this->personId);
            if ($encounter) {
                $this->redirectRoute('persons.encounters.package.show', [legalEntity(), $this->personId, $encounter->id], navigate: true);

                return;
            }
        }
        if ($this->personId > 0) {
            $this->redirectRoute('persons.care-plans', [legalEntity(), $this->personId], navigate: true);

            return;
        }

        $this->redirectRoute('care-plans.index', legalEntity(), navigate: true);
    }

    /** Load the encounters available for the selected patient in this facility. */
    protected function loadAvailableEncounters(): void
    {
        $this->availableEncounters = $this->personId ? Repository::carePlan()->encounterChoices($this->personId) : [];
    }

    /** Refresh the author, diagnosis, episode and category choices after changing the encounter. */
    public function updatedFormEncounter($value): void
    {
        $this->synchronizeSystemFields();
        if ($value) {
            $encounter = Repository::carePlan()->findEncounter($value, $this->personId);
            if ($encounter) {
                if (empty($this->form->title)) {
                    $date = $encounter->period?->start ? Carbon::parse($encounter->period->start)->format('d.m.Y') : now()->format('d.m.Y');
                    $this->form->title = __('План лікування від ') . $date;
                }

                if ($encounter->episode?->value) {
                    $episode = Repository::carePlan()->findEpisode($encounter->episode->value);
                    if ($episode) {
                        $this->form->episodes = [
                            [
                                'uuid' => $episode->uuid,
                                'name' => $episode->name,
                                'date' => $episode->period?->start ? Carbon::parse($episode->period->start)->format('d.m.Y') : '',
                            ]
                        ];
                    }
                } else {
                    $this->form->episodes = [];
                }

                $this->diagnoses = $this->buildDiagnosesForUi($encounter);
            }
        } else {
            $this->diagnoses = [];
            $this->form->episodes = [];
        }
        $this->refreshCategoryOptions();
    }

    /**
     * Build the UI list of diagnoses for an encounter. EncounterDiagnose::condition() resolves to
     * the Identifier row (its uuid lives in ->value), not the actual Condition, so we look the
     * Condition up locally and, if missing, fetch and store it from eHealth on the fly.
     *
     * @return array<int, array{date: string, name: string}>
     */
    protected function buildDiagnosesForUi(Encounter $encounter): array
    {
        return $encounter->diagnoses->map(function ($d) {
            $conditionUuid = $d->condition?->value;
            $actualCondition = null;
            if ($conditionUuid) {
                $actualCondition = Repository::carePlan()->findCondition($conditionUuid, $this->personId);
                if (!$actualCondition) {
                    Log::info('CarePlanCreate: condition not found in local SQL DB, attempting to fetch from eHealth', [
                        'condition_uuid' => $conditionUuid
                    ]);
                    try {
                        $conditionData = EHealth::condition()->getById($this->uuid, $conditionUuid)->validate();
                        MedicalEventsRepository::condition()->store([Arr::toCamelCase($conditionData)], Repository::carePlan()->findPatient($this->personId));
                        $actualCondition = Repository::carePlan()->findCondition($conditionUuid, $this->personId);
                    } catch (Exception $e) {
                        Log::error('CarePlanCreate: failed to fetch condition from eHealth', [
                            'condition_uuid' => $conditionUuid,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            return [
                'date' => $actualCondition?->asserted_date
                    ? Carbon::parse($actualCondition->asserted_date)->format('d.m.Y')
                    : '-',
                'name' => ($actualCondition?->code?->text ?: null)
                    ?? (($actualCondition?->code?->coding?->first()?->code ?: null) ?? '-'),
            ];
        })->toArray();
    }

    /**
     * Start the signing process by opening the signature modal.
     */
    public function startSigningProcess(): void
    {
        $user = Auth::user();
        $editing = $this instanceof CarePlanUpdate;
        if ($user === null || ($editing ? $user->cannot('update', $this->carePlan) : $user->cannot('create', CarePlan::class))) {
            Session::flash('error', __($editing ? 'care-plan.no_permission_update' : 'care-plan.no_permission_create'));

            return;
        }

        try {
            $this->synchronizeSystemFields();
            $this->form->validate();
            $this->validateCategoryForSigning();
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception);

            return;
        }

        $this->showSignatureModal = true;
    }

    /**
     * Verify the SMS code or confirm offline document verification.
     */
    public function verify(): void
    {
        if ($this->isSmsResending) {
            Session::flash('info', __('care-plan.sms_resend_processing'));

            return;
        }
        $plan = $this->carePlanForSms();
        if (!$this->guardApprovalAccess($plan)) {
            return;
        }
        app(MedicalRequestOwnership::class)->approvalForCarePlan($plan, $this->approvalId ?? '');
        $this->validate($this->approvalVerificationRules(), $this->approvalVerificationMessages());

        try {
            $response = app(CarePlanApprovalService::class)->verify(
                $this->patientUuid,
                $this->approvalId,
                $this->isOfflineAuthMethod() ? null : (int) $this->verificationCode,
            );

            if ($response->successful()) {
                $this->closeAuthModal();
                Session::flash('success', __('План лікування успішно активовано.'));
                $carePlan = Repository::carePlan()->findByUuid($this->carePlanUuid, (int) legalEntity()->id, $this->personId);
                $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan?->id ?? $this->carePlanUuid], navigate: true);
            }
        } catch (Exception $e) {
            Log::warning('Care plan approval confirmation failed', ['exceptionType' => $e::class, 'statusCode' => $e->getCode()]);
            $message = $e instanceof EHealthValidationException ? $e->getFormattedMessage()
                : ($e instanceof EHealthResponseException && Approval::errorMessage($e->response) === 'Invalid verification code'
                    ? __('errors.ehealth.messages.invalid_verification_code') : __('care-plan.approval_verify_error'));
            $this->addError('verificationCode', $message);
            Session::flash('error', $message);
        }
    }

    /** Resolve the plan under the current patient and facility for SMS approval. */
    protected function carePlanForSms(): CarePlan
    {
        $plan = Repository::carePlan()->findByUuid((string) $this->carePlanUuid, (int) legalEntity()->id, $this->personId);
        abort_unless($plan instanceof CarePlan, 404);

        return $plan;
    }

    /** Return to the plan details when the patient has already granted approval. */
    protected function onSmsApprovalAlreadyGranted(): void
    {
        $this->redirectRoute('care-plans.show', [legalEntity(), $this->carePlanForSms()->id], navigate: true);
    }

    /**
     * Initialize the consent workflow with the patient resolved by the base component.
     */
    protected function initializeComponent(): void
    {
        // Handled by BasePatientComponent for id, uuid, patientFullName
        $this->patientUuid = $this->uuid;
    }

    /**
     * Resolve encounter context and diagnosis concepts for the existing signed plan workflow.
     *
     * @return array{id: int|null, addresses: list<array>, period_start: string|null}
     */
    protected function resolveEncounterData(): array
    {
        if (empty($this->form->encounter)) {
            return ['id' => null, 'addresses' => [], 'period_start' => null];
        }
        $encounter = Repository::carePlan()->findEncounter($this->form->encounter, $this->personId);
        if ($encounter) {
            $this->buildDiagnosesForUi($encounter);
        }

        return Repository::carePlan()->encounterData($this->form->encounter, $this->personId, $this->conditionUuid ?: null);
    }

    /**
     * INPATIENT plans at this facility skip the SMS / method-selection step.
     * OUTPATIENT (typical PMD) still requires patient OTP.
     */
    protected function shouldSkipPatientAuthUi(?CarePlan $carePlan): bool
    {
        if ($carePlan && app(CarePlanApprovalService::class)->skipsPatientOtp($carePlan)) {
            return true;
        }

        if (strtoupper((string) $this->form->termsOfService) !== CarePlanTermsOfService::INPATIENT->value) {
            return false;
        }

        $legalEntity = legalEntity();
        if ($legalEntity === null) {
            return false;
        }

        if ($carePlan === null || $carePlan->legalEntityId === null) {
            return true;
        }

        return (int) $carePlan->legalEntityId === (int) $legalEntity->id;
    }

    /** Check whether the author has an active role matching the encounter's terms of service. */
    protected function authorHasActiveRoleForTermsOfService(Employee $author, string $termsOfService): bool
    {
        return Repository::carePlan()->authorHasActiveRoleForTermsOfService($author, $termsOfService);
    }

    /** Log the resolved author and role match for the existing eHealth signing flow. */
    protected function logCarePlanAuthorRoleDebug(?Employee $author, string $termsOfService): void
    {
        Log::debug('Care Plan author role validation', ['employeeUuid' => $author?->uuid, 'termsOfService' => $termsOfService, 'hasMatchingRole' => $author !== null && $this->authorHasActiveRoleForTermsOfService($author, $termsOfService)]);
    }

    /** Render the editor shared by creation and editing. */
    public function render(): View
    {
        return view('livewire.care-plan.care-plan-create');
    }
}
