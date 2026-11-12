<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\Api\Approval;
use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Enums\CarePlanApprovalCreateOutcome;
use App\Enums\CarePlanApprovalJobOutcome;
use App\Enums\CarePlanStatus;
use App\Enums\CarePlanTermsOfService;
use App\Enums\Person\AuthenticationMethod;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Exceptions\MedicalEvents\ClinicalStorageException;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Livewire\CarePlan\CarePlanUpdate;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Livewire\CarePlan\Forms\PatientSearchForm;
use App\Livewire\Concerns\SubmitsClinicalOperations;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\CarePlan;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval as PatientApproval;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\Person\Person;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\MedicalEvents\FhirResource;
use App\Services\MedicalEvents\MedicalRequestOwnership;
use App\Services\MedicalEvents\PatientApprovalService;
use App\Traits\InteractsWithApprovals;
use App\Traits\SynchronizesCarePlans;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

class CarePlanCreate extends BasePatientComponent
{
    use InteractsWithApprovals;

    use SubmitsClinicalOperations;
    use SynchronizesCarePlans;
    use WithFileUploads;

    public bool $showSignatureModal = false;
    public bool $showMethodSelectionModal = false;
    public string $patientUuid = '';
    public string $conditionUuid = '';
    public string $medicalRecordType = 'CONDITION';
    public ?string $carePlanUuid = null;

    public CarePlanForm $form;

    public PatientSearchForm $patientSearch;

    public array $categories = [];

    #[Locked]
    public array $allowedCategoryCodes = [];
    public array $diagnoses = [];
    #[Locked]
    public array $authMethods = [];
    public array $patientSearchResults = [];
    public bool $allowsPatientChange = false;
    public bool $showAdditionalSearchParams = false;
    public ?array $dictionaries = [];
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
    public array $availableEpisodes = [];

    public function mount(LegalEntity $legalEntity, $personId = null, $encounter = null, ?Person $person = null): void
    {
        $routePersonId = $person?->id ?? request()->route('personId');
        $personId ??= $routePersonId;
        $encounterRouteParam = request()->route('encounter') ?? (request()->query('encounter') ?? request()->query('encounterId'));
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
            abort_if(Auth::user()->cannot('view', [$resolvedEncounter, $person]), 404);
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

            $this->availableEpisodes = $this->activeEpisodes($person);

            $this->loadPatientAuthMethods($this->uuid);
        } else {
            $this->form->medical_number = Repository::carePlan()->nextDisplayNumber();
        }

        $this->loadAvailableEncounters();

        $this->conditionUuid = request()->query('conditionUuid', '');

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
                        ?? ($actualCondition?->code?->coding?->first()?->code ?: null)
                        ?? '-',
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

    protected function synchronizeSystemFields(): void
    {
        $this->form->intent = 'order';
        $this->form->termsOfService = Repository::carePlan()->encounterTermsOfService(
            $this->form->encounter,
            (int) $this->personId
        );
        $this->refreshAuthorDisplay();
    }

    protected function refreshCategoryOptions(): void
    {
        $this->resolveEncounterData();
        $this->allowedCategoryCodes = Repository::carePlan()->categoryCodesForEncounter($this->form->encounter, (int) $this->personId);
        $this->resetValidation('form.category');
        if (!in_array($this->form->category, $this->allowedCategoryCodes, true)) {
            $this->form->category = count($this->allowedCategoryCodes) === 1 ? $this->allowedCategoryCodes[0] : '';
        }
    }

    protected function validateCategoryForSigning(): void
    {
        Repository::carePlan()->assertCategoryForEncounter($this->form->category, $this->form->encounter, (int) $this->personId);
    }

    public function updatedFormKeyContainerUpload(): void
    {
        $upload = $this->form->keyContainerUpload ?? null;

        if ($upload && method_exists($upload, 'getClientOriginalName')) {
            $this->form->keyContainerFileName = $upload->getClientOriginalName();
        } elseif ($upload === null) {
            $this->form->keyContainerFileName = '';
        }
    }

    protected function refreshAuthorDisplay(): void
    {
        $this->form->author = '';
        try {
            $employee = Repository::carePlan()->creationAuthorForEncounter($this->form->encounter, $this->personId);
            $this->form->author = $employee->party?->fullName ?? '';
        } catch (ValidationException) {
            // The form keeps its selected encounter so the user sees the validation error.
        }
    }

    protected function validateAuthorAndRelationships(): void
    {
        $repository = Repository::carePlan();
        $repository->creationAuthorForEncounter($this->form->encounter, $this->personId);
        $repository->assertRelatedPlans($this->form->toArray(), $this->personId, $this instanceof CarePlanUpdate ? $this->carePlan->id : null);
    }

    #[Computed]
    public function relatedCarePlanChoices(): array
    {
        return Repository::carePlan()->relatedPlanChoices($this->personId, $this instanceof CarePlanUpdate ? $this->carePlan->id : null);
    }

    private function loadDoctorsAndDictionaries(): void
    {
        // Load doctors for co-authors
        $legalEntity = legalEntity();
        if ($legalEntity) {
            $this->doctors = $this->doctors($legalEntity->id);
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

        $this->patientSearchResults = $this->searchPatients($firstName, $lastName, $birthDate);

        if ($this->patientSearchResults === []) {
            Session::flash('error', __('patients.nobody_found') . '. ' . __('patients.try_change_search_parameters'));
        }
    }

    /**
     * Select a patient from the search results.
     */
    public function selectPatient(int $personId): void
    {
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
     * Handle validation failure: dispatch flash + scroll events.
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
            default => null,
        };

        if ($selector !== null) {
            $this->dispatch('scroll-to-element', selector: $selector);
        } else {
            $this->dispatch('scroll-to-error');
        }

        if ($closeModal) {
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
                $result = $this->pendingCarePlanApprovalConfirmation($this->patientUuid, $this->approvalId);
                if (($result['outcome']->value === 'granted')) {
                    Session::flash('success', __('care-plan.approval_verified'));
                    $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan->id], navigate: true);

                    return;
                }
                $this->currentAuthMethod = $result['authMethod'];
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

            $skipsOtp = $this->shouldSkipPatientAuthUi($carePlan);

            $result = $this->createCarePlanApproval(
                carePlan: $carePlan,
                patientUuid: $this->patientUuid,
                employeeUuid: $employeeUuid,
                accessLevel: 'write',
                authorizeWith: $skipsOtp ? null : ($methodUuid ?: null),
                user: Auth::user(),
                bearerToken: Session::get(config('ehealth.api.oauth.bearer_token')),
            );

            if (($result['outcome']->value === 'async')) {
                $this->approvalId = $result['approvalId'];
                $this->pollingLinkId = $result['pollingLinkId'];
                $this->isPolling = true;

                return;
            }

            $this->approvalId = $result['approvalId'];

            if (in_array($result['outcome']->value, ['otp_required', 'offline_required'], true) && !$skipsOtp) {
                $this->currentAuthMethod = $result['authMethod'] ?? $this->currentAuthMethod;
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

        $status = $this->resolveCarePlanApprovalJob($this->pollingLinkId);

        if (($status['outcome']->value === 'pending')) {
            return;
        }

        $this->isPolling = false;
        $this->pollingLinkId = null;

        if (($status['outcome']->value === 'failed')) {
            $msg = $status['errorMessage'] ?: __('Не вдалося обробити запит на дозвіл. Спробуйте ще раз.');
            Session::flash('error', $msg);

            return;
        }

        if ($status['approvalId']) {
            $this->approvalId = $status['approvalId'];
        }

        $carePlan = Repository::carePlan()->findByUuid($this->carePlanUuid, (int) legalEntity()->id, $this->personId);
        $skipsOtp = $this->shouldSkipPatientAuthUi($carePlan);

        if (in_array($status['outcome']->value, ['otp_required', 'offline_required'], true) && !$skipsOtp) {
            $this->currentAuthMethod = $status['authMethod'] ?? $this->currentAuthMethod;
            $this->openAuthModal();

            return;
        }

        Session::flash('success', $skipsOtp
            ? __('care-plan.approval_inpatient_granted')
            : __('План лікування успішно активовано.'));
        $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan?->id ?? $this->carePlanUuid], navigate: true);
    }

    /**
     * Save as a local draft (without sending to eHealth).
     */
    public function save(): void
    {
        $repository = Repository::carePlan();
        if (Auth::user()?->cannot('create', CarePlan::class)) {
            $msg = __('care-plan.no_permission_create');
            Session::flash('error', $msg);

            return;
        }

        try {
            $this->synchronizeSystemFields();
            $this->form->validate();
            $this->validateAuthorAndRelationships();
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception);

            return;
        }

        $legalEntity = legalEntity();

        $encounterData = $this->resolveEncounterData();

        try {
            $repository->assertFhirStorageReady();
            $fields = [
                'person_id' => $this->resolvePersonId(),
                'author_id' => Repository::carePlan()->creationAuthorForEncounter($this->form->encounter, $this->personId)->id,
                'legal_entity_id' => $legalEntity?->id,
                'status' => CarePlanStatus::DRAFT->value,
                'category' => $this->form->category,
                'co_authors' => $this->form->coAuthors,
                'context' => $this->form->context ?: null,
                'title' => $this->form->title,
                'terms_of_service' => $this->form->termsOfService ?: null,
                'period_start' => $this->periodStartIsoDate(),
                'period_start_time' => $this->form->periodStartTime,
                'period_end_time' => $this->form->periodEndTime,
                'period_end' => $this->periodEndIsoDate(),
                'encounter_id' => $encounterData['id'],
                'addresses' => $encounterData['addresses'],
                'supporting_info' => [
                    'episodes' => $this->form->episodes,
                    'medical_records' => $this->form->medicalRecords,
                ],
                'based_on' => $this->form->basedOn ?: null,
                'part_of' => $this->form->partOf ?: null,
                'description' => $this->form->description ?: null,
                'note' => $this->form->note ?: null,
                'inform_with' => $this->form->informWith ?: null,
            ];
            $carePlan = $this->saveCarePlanDraft($fields, (int) $fields['person_id'], (int) $fields['legal_entity_id']);
        } catch (ClinicalStorageException $exception) {
            Session::flash('error', $exception->getMessage());

            return;
        }

        session()->flash('success', __('care-plan.draft_saved'));
        $this->redirectRoute('care-plans.edit', [legalEntity(), $carePlan->id], navigate: true);
    }

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

    protected function loadAvailableEncounters(): void
    {
        $this->availableEncounters = $this->personId ? $this->carePlanEncounterChoices($this->personId, forCreation: true) : [];
    }

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
                    ?? ($actualCondition?->code?->coding?->first()?->code ?: null)
                    ?? '-',
            ];
        })->toArray();
    }

    /**
     * Start the signing process by opening the signature modal.
     */
    public function startSigningProcess(): void
    {
        try {
            if ($this instanceof CarePlanUpdate) {
                if (Auth::user()->cannot('update', $this->carePlan)) {
                    Session::flash('error', __('care-plan.no_permission_update'));

                    return;
                }
            } else {
                if (Auth::user()->cannot('create', CarePlan::class)) {
                    Session::flash('error', __('care-plan.no_permission_create'));

                    return;
                }
            }
            $this->synchronizeSystemFields();
            $this->form->validate();
            $this->validateAuthorAndRelationships();
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
            $response = $this->verifyCarePlanApproval(
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
                : ($e instanceof EHealthResponseException && EHealth::approval()->errorMessage($e->response) === 'Invalid verification code'
                    ? __('errors.ehealth.messages.invalid_verification_code') : __('care-plan.approval_verify_error'));
            $this->addError('verificationCode', $message);
            Session::flash('error', $message);
        }
    }

    protected function carePlanForSms(): CarePlan
    {
        $plan = Repository::carePlan()->findByUuid((string) $this->carePlanUuid, (int) legalEntity()->id, $this->personId);
        abort_unless($plan instanceof CarePlan, 404);

        return $plan;
    }

    protected function onSmsApprovalAlreadyGranted(): void
    {
        $this->redirectRoute('care-plans.show', [legalEntity(), $this->carePlanForSms()->id], navigate: true);
    }

    /**
     * Sign with KEP and send to eHealth.
     */
    public function sign(): void
    {
        $repository = Repository::carePlan();
        if (Auth::user()?->cannot('create', CarePlan::class)) {
            $msg = __('care-plan.no_permission_create');
            Session::flash('error', $msg);

            return;
        }

        $generatedUuid = null;
        try {
            $this->synchronizeSystemFields();
            $this->form->validate($this->form->rulesForSigning());
            $this->validateAuthorAndRelationships();
            $this->validateCategoryForSigning();
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception);

            return;
        }

        try {
            $legalEntity = legalEntity();
            $encounterData = $this->resolveEncounterData();
            if (empty($encounterData['addresses'])) {
                throw new RuntimeException(__('Неможливо створити план лікування: у вибраній взаємодії відсутні діагнози (addresses). Будь ласка, переконайтеся, що взаємодія містить діагнози в ЕСОЗ та вони завантажені в локальну БД.'));
            }

            $termsOfService = $this->form->termsOfService;
            $author = $repository->creationAuthorForEncounter($this->form->encounter, $this->personId);
            $this->logCarePlanAuthorRoleDebug($author, $termsOfService);

            $repository->assertFhirStorageReady(beforeSubmission: true);
            $mapping = $this->carePlanFormContext(array_replace($this->form->toArray(), [
                'uuid' => $this->carePlanUuid,
                'author' => $author?->uuid,
                'addresses' => $encounterData['addresses'],
            ]));
            $carePlanResource = $this->carePlanResourceFromFields($mapping['data'], $mapping['context']);
            $carePlanPayload = $this->buildCreateCarePlanContent($carePlanResource);
            $generatedUuid = $carePlanPayload['id'];

            $signedContent = signatureService()->signData(
                $carePlanPayload,
                $this->form->password,
                $this->form->knedp,
                $this->form->keyContainerUpload,
                Auth::user()->party->taxId
            );

            $carePlanResource['status'] = CarePlanStatus::DRAFT->value;
            $carePlan = $repository->storeResource($carePlanResource, (int) $this->personId, (int) legalEntity()->id);
            $finalResponse = $this->submitClinicalOperation($carePlan, ['status' => CarePlanStatus::PENDING->value], fn () => EHealth::carePlan()->create($this->uuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']));
            if ($finalResponse === null) {
                $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan->id], navigate: true);

                return;
            }

            $this->showSignatureModal = false;
            Session::flash('success', __('care-plan.submission_confirmed'));
            $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan->id], navigate: true);

        } catch (EHealthConnectionException $exception) {
            $this->carePlanUuid = $generatedUuid ?? $this->carePlanUuid;
            Log::error('CarePlan: connection error: ' . $exception->getMessage());
            $msg = __('care-plan.connection_error');
            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            $this->carePlanUuid = $generatedUuid ?? $this->carePlanUuid;
            Log::error('CarePlan: eHealth error: ' . $exception->getMessage());
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : __('Помилка від ЕСОЗ: ') . $exception->getMessage();

            if (
                $generatedUuid && (
                    str_contains($exception->getMessage(), 'Care plan with such id already exists')
                || str_contains($msg, 'Care plan with such id already exists')
                || (isset($exception->details['error']['message']) && str_contains($exception->details['error']['message'], 'Care plan with such id already exists'))
                )
            ) {
                try {
                    $this->synchronizeResources([['id' => $generatedUuid]], $this->personId, (int) legalEntity()->id, $this->patientUuid ?: $this->uuid);
                    $localCarePlan = $repository->findByUuid($generatedUuid, (int) legalEntity()->id, $this->personId);

                    session()->flash('success', __('План лікування вже зареєстровано в ЕСОЗ. Ви перенаправлені на сторінку детального перегляду для активації дозволу пацієнта.'));
                    $this->redirectRoute('care-plans.show', [legalEntity(), $localCarePlan->id], navigate: true);

                    return;
                } catch (Throwable $innerEx) {
                    Log::error('CarePlanCreate conflict handling failed: ' . $innerEx->getMessage(), [
                        'trace' => $innerEx->getTraceAsString(),
                    ]);
                }
            }

            Session::flash('error', $msg);
            $this->showSignatureModal = false;

            if (!($exception instanceof EHealthValidationException)) {
                return;
            }

            // eHealth reports the rule on encounter, but the user must change care plan category.
            if ($exception->isCarePlanCategoryDiagnosisMismatch()) {
                $this->addError(
                    'form.category',
                    __('errors.ehealth.messages.care_plan_category_diagnosis_mismatch')
                );
                $this->dispatch('scroll-to-element', selector: '#category');

                return;
            }

            if ($exception->isCarePlanPeriodEndBeforeStart()) {
                $this->addError(
                    'form.periodEnd',
                    __('errors.ehealth.messages.period_end_before_start')
                );
                $this->dispatch('scroll-to-element', selector: '#period_end');
            }
        } catch (ClinicalStorageException $exception) {
            Session::flash('error', $exception->getMessage());
            $this->showSignatureModal = false;
        } catch (RuntimeException $exception) {
            $this->carePlanUuid = $generatedUuid ?? $this->carePlanUuid;
            Log::error('CarePlan: runtime error: ' . $exception->getMessage());
            Session::flash('error', $exception->getMessage());
            $this->showSignatureModal = false;
        } catch (Throwable $exception) {
            $this->carePlanUuid = $generatedUuid ?? $this->carePlanUuid;
            Log::error('CarePlan: unexpected error: ' . $exception->getMessage(), [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
            $msg = __('care-plan.unexpected_error');
            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        }
    }

    /**
     * Initialize the component.
     */
    protected function initializeComponent(): void
    {
        // Handled by BasePatientComponent for id, uuid, patientFullName
        $this->patientUuid = $this->uuid;
    }

    /**
     * Resolve the person local ID from uuid.
     */
    protected function resolvePersonId(): ?int
    {
        return $this->personId;
    }

    protected function resolveEncounterData(): array
    {
        if (!$this->form->encounter) {
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
        if ($carePlan && $carePlan->skipsPatientOtp()) {
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

    protected function authorHasActiveRoleForTermsOfService(Employee $author, string $termsOfService): bool
    {
        return Repository::carePlan()->authorHasActiveRoleForTermsOfService($author, $termsOfService);
    }

    protected function logCarePlanAuthorRoleDebug(?Employee $author, string $termsOfService): void
    {
        Log::debug('Care Plan author role validation', ['employeeUuid' => $author?->uuid, 'termsOfService' => $termsOfService, 'hasMatchingRole' => $author !== null && $this->authorHasActiveRoleForTermsOfService($author, $termsOfService)]);
    }

    public function render()
    {
        return view('livewire.care-plan.care-plan-create');
    }

    #[Locked]
    public ?int $smsResendLinkId = null;

    #[Locked]
    public bool $isSmsResending = false;

    public function resendSms(): void
    {
        if (!$this->approvalId || $this->smsResent || $this->isSmsResending
            || AuthenticationMethod::confirmationMethod($this->currentAuthMethod ?? []) !== AuthenticationMethod::OTP) {
            return;
        }
        try {
            $plan = $this->carePlanForSms();
            if (!$this->guardApprovalAccess($plan)) {
                return;
            }
            app(MedicalRequestOwnership::class)->approvalForCarePlan($plan, $this->approvalId);
            $this->resetValidation('verificationCode');
            $this->isSmsResending = true;
            $token = session()->get(config('ehealth.api.oauth.bearer_token'));
            if (!$token || !Auth::user() || !legalEntity()) {
                throw new RuntimeException(__('care-plan.sync_session_expired'));
            }
            $result = $this->requestCarePlanApprovalSmsResend($plan, $this->approvalId, legalEntity(), Auth::user(), $token);
            if ($result['status'] === 'pending') {
                $this->smsResendLinkId = $result['linkId'];
                Session::flash('info', __('care-plan.sms_resend_processing'));

                return;
            }
            $this->finishSmsResend($result['status']);
        } catch (Throwable $exception) {
            $this->isSmsResending = false;
            Log::warning('Care plan approval SMS resend failed', ['exceptionType' => $exception::class]);
            $message = $exception instanceof EHealthValidationException ? $exception->getFormattedMessage()
                : ($exception instanceof RuntimeException ? $exception->getMessage() : __('care-plan.sms_resend_error'));
            $this->addError('verificationCode', $message);
            Session::flash('error', $message);
        }
    }

    public function checkSmsResendStatus(): void
    {
        if ($this->smsResendLinkId === null || !$this->approvalId) {
            return;
        }
        $plan = $this->carePlanForSms();
        if (Auth::user()->cannot('view', $plan)) {
            Session::flash('error', __('care-plan.approval_access_denied'));

            return;
        }
        $state = MedicalEventsRepository::approval()->carePlanSmsResendState($plan, $this->approvalId, $this->smsResendLinkId);
        if (in_array($state['status'], ['PENDING', 'PROCESSING'], true)) {
            return;
        }
        $this->finishSmsResend(strtolower($state['status']));
    }

    protected function finishSmsResend(string $status): void
    {
        $this->smsResendLinkId = null;
        $this->isSmsResending = false;
        if ($status === 'granted') {
            $this->closeAuthModal();
            Session::flash('success', __('care-plan.approval_verified'));
            $this->onSmsApprovalAlreadyGranted();
        } elseif ($status === 'processed') {
            $this->smsResent = true;
            $this->verificationCode = '';
            $this->resetValidation('verificationCode');
            Session::flash('success', __('care-plan.sms_resent'));
        } else {
            $this->smsResent = false;
            $this->addError('verificationCode', __('care-plan.sms_resend_error'));
            Session::flash('error', __('care-plan.sms_resend_error'));
        }
    }

    protected function buildCreateCarePlanContent(array $resource): array
    {
        Validator::make($resource, [
            'id' => ['required', 'uuid'], 'title' => ['required', 'string'],
            'category.coding' => ['required', 'array', 'min:1'],
            'period.start' => ['required', 'date'],
            'encounter.identifier.value' => ['required', 'uuid'],
            'author.identifier.value' => ['required', 'uuid'],
            'addresses' => ['required', 'array', 'min:1'],
            'termsOfService.coding' => ['required', 'array', 'min:1'],
            'basedOn' => ['nullable', 'array'],
            'basedOn.identifier.value' => ['required_with:basedOn', 'uuid'],
            'partOf' => ['nullable', 'array'],
            'partOf.identifier.value' => ['required_with:partOf', 'uuid'],
        ])->validate();
        $content = array_intersect_key($resource, array_flip(['id', 'category', 'title', 'description', 'period', 'supportingInfo', 'note', 'encounter', 'addresses', 'author', 'contributor', 'termsOfService', 'informWith', 'basedOn', 'partOf']));
        $content['intent'] = 'order';
        $content['status'] = 'new';

        return $this->resourcePayload($content);
    }

    protected function buildCancelCarePlanContent(array $snapshot, array $statusReason): array
    {
        Validator::make($snapshot, ['id' => ['required', 'uuid']])->validate();
        unset($snapshot['activities']);
        $snapshot['status_reason'] = $statusReason;

        return $snapshot;
    }

    protected function resourcePayload(array $data): array
    {
        $result = [];
        foreach (Arr::toSnakeCase($data) as $key => $value) {
            if (is_array($value)) {
                $value = $this->resourcePayload($value);
            }
            if ($value !== null && $value !== '' && $value !== []) {
                $result[$key] = $value;
            }
        }

        return array_is_list($data) ? array_values($result) : $result;
    }

    protected function buildCarePlanApprovalPayload(
        CarePlan $carePlan,
        string $employeeUuid,
        string $accessLevel = 'write',
        ?string $authorizeWith = null,
        ?LegalEntity $legalEntity = null,
    ): array {
        $payload = [
            'resources' => [
                [
                    'identifier' => [
                        'type' => [
                            'coding' => [['system' => 'eHealth/resources', 'code' => 'care_plan']],
                        ],
                        'value' => $carePlan->uuid,
                    ],
                ],
            ],
            'granted_to' => [
                'identifier' => [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => 'employee']],
                    ],
                    'value' => $employeeUuid,
                ],
            ],
            'access_level' => $accessLevel,
            'authorize_with' => $authorizeWith ?: null,
        ];

        if ($carePlan->skipsPatientOtp($legalEntity) || empty($payload['authorize_with'])) {
            unset($payload['authorize_with']);
        }

        return $payload;
    }

    protected function createCarePlanApproval(
        CarePlan $carePlan,
        string $patientUuid,
        string $employeeUuid,
        string $accessLevel = 'write',
        ?string $authorizeWith = null,
        ?LegalEntity $legalEntity = null,
        ?User $user = null,
        ?string $bearerToken = null,
    ): array {
        Repository::carePlan()->assertApprovalCreationReady($carePlan);
        $legalEntity ??= legalEntity();
        if (!$legalEntity || !$user || !$bearerToken || $patientUuid !== $carePlan->person?->uuid) {
            throw new RuntimeException(__('care-plan.approval_create_error'));
        }
        $repository = MedicalEventsRepository::approval();
        Repository::carePlan()->assertApprovalRecipient($employeeUuid, $legalEntity->id);
        $confirmWithoutOtp = $carePlan->skipsPatientOtp($legalEntity);
        $lock = Cache::lock('care-plan-approval-create:'.$carePlan->id.':'.$employeeUuid.':'.$accessLevel, 120);
        if (!$lock->get()) {
            throw new RuntimeException(__('care-plan.approval_processing'));
        }
        $link = null;
        $creationConfirmed = false;
        try {
            $existing = $repository->reusableForCarePlan($carePlan, $employeeUuid, $accessLevel);
            if ($existing !== null) {
                if ($existing->getAttribute('is_verified') && $existing->expiresAt?->isFuture() === true) {
                    return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $existing->uuid, 'pollingLinkId' => null, 'authMethod' => null];
                }
                $existingLink = $existing->ehealthLinks->filter(fn ($candidate): bool => data_get($candidate->job?->request_data, 'operation') !== 'resendSms')->sortByDesc('id')->first();
                if ($existingLink !== null && !in_array($existingLink->status, ['PROCESSED', 'FAILED'], true)) {
                    return $this->carePlanApprovalResultForLink($existingLink->id);
                }
                try {
                    $data = $this->requireApprovalResponse(EHealth::approval()->withToken($bearerToken)->getApprovalDetails($patientUuid, $existing->uuid))->validate();
                } catch (EHealthResponseException $exception) {
                    if ($exception->response->status() !== 404) {
                        throw $exception;
                    }
                    $repository->markUnavailable($existing);
                    $data = []; // A missing remote resource expires local access without inventing approval fields.
                }
                if ($data !== []) {
                    $repository->sync($data, $carePlan, $existing);
                }
                $existing->refresh();
                if ($this->carePlanApprovalIsGranted($data)) {
                    return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $existing->uuid, 'pollingLinkId' => null, 'authMethod' => null];
                }
                if (!$existing->getAttribute('is_verified') && $existing->expiresAt?->isFuture() === true
                    && ($confirmWithoutOtp || $authorizeWith === null || $existing->authorizeWith === $authorizeWith)) {
                    if ($confirmWithoutOtp) {
                        $this->confirmCarePlanApprovalWithoutOtp($patientUuid, $existing->uuid, $bearerToken);

                        return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $existing->uuid, 'pollingLinkId' => null, 'authMethod' => null];
                    }

                    return $this->carePlanApprovalResultFromData($data, $patientUuid, $existing->authorizeWith);
                }
            }
            $payload = $this->buildCarePlanApprovalPayload($carePlan, $employeeUuid, $accessLevel, $authorizeWith, $legalEntity);
            $link = $repository->beginCarePlanRequest($carePlan, $payload, $confirmWithoutOtp);
            $response = $this->requireApprovalResponse(EHealth::approval()->withToken($bearerToken)->createApproval($patientUuid, $payload));
            $data = $response->validate();
            if ($response->getStatusCode() === 202) {
                $href = collect($data['links'] ?? [])->first(fn (array $candidate): bool => str_contains((string) ($candidate['href'] ?? ''), '/jobs/'))['href'] ?? null;
                if (!$href) {
                    throw new RuntimeException(__('care-plan.submission_unconfirmed'));
                }
                $repository->trackRequest($link, $href, $data);
                Bus::batch([new RemoteEHealthLinksProcessing($link, $legalEntity, standalone: true)])
                    ->withOption('legal_entity_id', $legalEntity->id)
                    ->withOption('token', Crypt::encryptString($bearerToken))
                    ->withOption('user', $user)
                    ->name(RemoteEHealthLinksProcessing::BATCH_NAME)->onQueue('sync')->dispatch();

                return ['outcome' => CarePlanApprovalCreateOutcome::Async, 'approvalId' => null, 'pollingLinkId' => $link->id, 'authMethod' => null];
            }
            $repository->completeCarePlanRequest($link, $data);
            $creationConfirmed = true;
            if ($confirmWithoutOtp && empty($data['is_verified'])) {
                $this->confirmCarePlanApprovalWithoutOtp($patientUuid, $data['id'], $bearerToken);

                return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $data['id'], 'pollingLinkId' => null, 'authMethod' => null];
            }

            return $this->carePlanApprovalResultFromData($data, $patientUuid, $authorizeWith);
        } catch (Throwable $exception) {
            if ($link !== null && !$creationConfirmed && ($exception instanceof EHealthValidationException
                || ($exception instanceof EHealthResponseException && $exception->getCode() >= 400 && $exception->getCode() < 500 && $exception->getCode() !== 408))) {
                RemoteEHealthLinksProcessing::markSubmissionFailed($link);
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    protected function carePlanApprovalResultForLink(int $linkId): array
    {
        $result = $this->resolveCarePlanApprovalJob($linkId);
        if (($result['outcome']->value === 'failed')) {
            throw new RuntimeException($result['errorMessage'] ?: __('care-plan.approval_result_unavailable'));
        }
        if (($result['outcome']->value === 'granted')) {
            return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $result['approvalId'], 'pollingLinkId' => null, 'authMethod' => null];
        }
        if (in_array($result['outcome']->value, ['otp_required', 'offline_required'], true)) {
            return ['outcome' => ($result['outcome']->value === 'otp_required') ? CarePlanApprovalCreateOutcome::OtpRequired : CarePlanApprovalCreateOutcome::OfflineRequired, 'approvalId' => $result['approvalId'], 'pollingLinkId' => null, 'authMethod' => $result['authMethod']];
        }

        return ['outcome' => CarePlanApprovalCreateOutcome::Async, 'approvalId' => null, 'pollingLinkId' => $linkId, 'authMethod' => null];
    }

    protected function pendingCarePlanApprovalConfirmation(string $patientUuid, string $approvalId, ?string $authorizeWith = null): array
    {
        $response = $this->requireApprovalResponse(EHealth::approval()->getApprovalDetails($patientUuid, $approvalId));
        $data = $response->validate();
        if (($data['id'] ?? null) !== $approvalId) {
            throw new RuntimeException(__('care-plan.approval_result_unavailable'));
        }
        $result = $this->carePlanApprovalResultFromData($data, $patientUuid, $authorizeWith);
        if (($result['outcome']->value === 'granted')) {
            MedicalEventsRepository::approval()->storePatientResponse($patientUuid, $approvalId, $data);
        }

        return $result;
    }

    protected function carePlanApprovalResultFromData(array $data, ?string $patientUuid = null, ?string $authorizeWith = null): array
    {
        if ($this->carePlanApprovalIsGranted($data)) {
            return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $data['id'], 'pollingLinkId' => null, 'authMethod' => null];
        }
        if (($data['is_verified'] ?? null) !== false || empty($data['expires_at'])
            || !CarbonImmutable::parse($data['expires_at'])->isFuture()) {
            throw new RuntimeException(__('care-plan.approval_result_unavailable'));
        }
        $method = $this->extractCarePlanApprovalAuthMethod($data);
        $method = app(PatientApprovalService::class)->resolveConfirmationMethod($method ?? [], $patientUuid, $data['authorize_with'] ?? $authorizeWith);
        if (isset($data['id']) && AuthenticationMethod::confirmationMethod($method ?? []) !== null) {
            $outcome = AuthenticationMethod::confirmationMethod($method) === AuthenticationMethod::OTP
                ? CarePlanApprovalCreateOutcome::OtpRequired : CarePlanApprovalCreateOutcome::OfflineRequired;

            return ['outcome' => $outcome, 'approvalId' => $data['id'], 'pollingLinkId' => null, 'authMethod' => $method];
        }

        throw new RuntimeException(__('care-plan.approval_result_unavailable'));
    }

    protected function carePlanApprovalIsGranted(array $data): bool
    {
        return ($data['is_verified'] ?? false) === true
            && !empty($data['expires_at'])
            && CarbonImmutable::parse($data['expires_at'])->isFuture();
    }

    protected function verifyCarePlanApproval(string $patientUuid, string $approvalId, ?int $code, ?string $bearerToken = null): EHealthResponse
    {
        $response = EHealth::approval()->verifyConfirmed($patientUuid, $approvalId, $code, $bearerToken);
        MedicalEventsRepository::approval()->storePatientResponse($patientUuid, $approvalId, $response->validate());

        return $response;
    }

    protected function confirmCarePlanApprovalWithoutOtp(string $patientUuid, string $approvalId, ?string $bearerToken = null): EHealthResponse
    {
        return $this->verifyCarePlanApproval($patientUuid, $approvalId, null, $bearerToken);
    }

    protected function requireApprovalResponse(EHealthResponse $response): EHealthResponse
    {
        if (!$response->successful()) {
            throw new EHealthResponseException($response);
        }

        return $response;
    }

    protected function resendCarePlanApprovalSms(string $patientUuid, string $approvalId, ?string $bearerToken = null): EHealthResponse
    {
        $key = 'care-plan-otp-resend:'.$patientUuid.':'.$approvalId;

        if (!Cache::add($key, true, now()->addMinutes(10))) {
            throw new RuntimeException(__('validation.sms_already_resent'));
        }

        try {
            $api = EHealth::approval();
            if ($bearerToken !== null) {
                $api->withToken($bearerToken);
            }

            return $this->requireApprovalResponse($api->resendSms($patientUuid, $approvalId));
        } catch (Throwable $exception) {
            if ($exception instanceof EHealthValidationException
                || ($exception instanceof EHealthResponseException && $exception->getCode() >= 400 && $exception->getCode() < 500 && $exception->getCode() !== 408)) {
                Cache::forget($key);
            }
            throw $exception;
        }
    }

    protected function syncCarePlanApprovals(CarePlan $carePlan): void
    {
        // Uses Get approvals filters (granted_resource_type + granted_resources) via syncApprovals.
        MedicalEventsRepository::approval()->syncApprovals($carePlan, 'care_plan', []);
    }

    protected function requestCarePlanApprovalSmsResend(CarePlan $plan, string $approvalId, LegalEntity $legalEntity, User $user, string $bearerToken): array
    {
        $patientUuid = $plan->person->uuid;
        $api = EHealth::approval()->withToken($bearerToken);
        $approval = MedicalEventsRepository::approval()->carePlanApproval($plan, $approvalId);
        if ($approval->getAttribute('is_verified') && $approval->expiresAt?->isFuture() === true) {
            return ['status' => 'granted'];
        }
        if ($approval->expiresAt?->isFuture() !== true) {
            throw new RuntimeException(__('care-plan.approval_result_unavailable'));
        }
        if ($plan->skipsPatientOtp($legalEntity)) {
            throw new RuntimeException(__('care-plan.sms_not_required'));
        }
        try {
            $response = $this->resendCarePlanApprovalSms($patientUuid, $approvalId, $bearerToken);
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() !== 409 || EHealth::approval()->errorMessage($exception->response) !== 'Approval is already verified') {
                throw $exception;
            }
            $details = $this->requireApprovalResponse($api->getApprovalDetails($patientUuid, $approvalId));
            $confirmed = $details->validate();
            if (($confirmed['id'] ?? null) !== $approvalId || !$this->carePlanApprovalIsGranted($confirmed)) {
                throw $exception;
            }
            MedicalEventsRepository::approval()->storePatientResponse($patientUuid, $approvalId, $confirmed);

            return ['status' => 'granted'];
        }
        $link = MedicalEventsRepository::approval()->beginCarePlanSmsResend($plan, $approvalId);
        if ($response->getStatusCode() === 202) {
            $jobData = $response->validate();
            $href = collect($jobData['links'] ?? [])->first(fn (array $candidate): bool => str_contains((string) ($candidate['href'] ?? ''), '/jobs/'))['href'] ?? null;
            if (!$href) {
                MedicalEventsRepository::approval()->finishCarePlanSmsResend($link, ['status' => 'failed']);
                throw new RuntimeException(__('care-plan.submission_unconfirmed'));
            }
            MedicalEventsRepository::approval()->trackRequest($link, $href, $jobData);
            try {
                Bus::batch([new RemoteEHealthLinksProcessing($link, $legalEntity, standalone: true)])
                    ->withOption('legal_entity_id', $legalEntity->id)
                    ->withOption('token', Crypt::encryptString($bearerToken))
                    ->withOption('user', $user)
                    ->name(RemoteEHealthLinksProcessing::BATCH_NAME)->onQueue('sync')->dispatch();
            } catch (Throwable $exception) {
                MedicalEventsRepository::approval()->finishCarePlanSmsResend($link, ['status' => 'failed']);
                throw $exception;
            }

            return ['status' => 'pending', 'linkId' => $link->id];
        }
        MedicalEventsRepository::approval()->finishCarePlanSmsResend($link, ['status' => 'processed', 'response_data' => $response->validate()]);

        return ['status' => 'processed'];
    }

    protected function resolveCarePlanApprovalJob(int $pollingLinkId): array
    {
        $link = MedicalEventsRepository::approval()->requestLink($pollingLinkId);
        if (!$link || !$link->job || !$link->linkable instanceof PatientApproval) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => __('care-plan.approval_result_unavailable')];
        }
        if (strtoupper((string) $link->job->status) === 'FAILED') {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => $this->formatCarePlanApprovalJobError($link->job->response_data ?? [])];
        }
        $approval = $link->linkable;
        if ($approval->getAttribute('is_verified') && $approval->expiresAt?->isFuture() === true) {
            return ['outcome' => CarePlanApprovalJobOutcome::Granted, 'approvalId' => $approval->uuid, 'authMethod' => null, 'errorMessage' => null];
        }
        if ($approval->expiresAt !== null && !$approval->expiresAt->isFuture()) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => __('care-plan.approval_result_unavailable')];
        }
        if (!empty($link->job->response_data['confirmationError'])) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => __('care-plan.approval_verify_error')];
        }
        if (strtoupper((string) $link->job->status) !== 'PROCESSED') {
            return ['outcome' => CarePlanApprovalJobOutcome::Pending, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => null];
        }
        try {
            $data = EHealth::approval()->validateResourceData($link->job->response_data ?? []);
            if (($data['authentication_method_current'] ?? null) === null && $approval->approvable instanceof CarePlan
                && $approval->approvable->skipsPatientOtp()) {
                return ['outcome' => CarePlanApprovalJobOutcome::Pending, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => null];
            }
            $authorizeWith = $approval->authorizeWith ?: data_get($link->job?->request_data, 'authorize_with');
            $result = $this->carePlanApprovalResultFromData($data, $approval->approvable?->person?->uuid, $authorizeWith);
        } catch (ValidationException) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => __('care-plan.approval_result_unavailable')];
        } catch (RuntimeException $exception) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => $exception->getMessage()];
        }

        return ['outcome' => ($result['outcome']->value === 'granted') ? CarePlanApprovalJobOutcome::Granted
                : (($result['outcome']->value === 'otp_required') ? CarePlanApprovalJobOutcome::OtpRequired : CarePlanApprovalJobOutcome::OfflineRequired), 'approvalId' => $result['approvalId'], 'authMethod' => $result['authMethod'], 'errorMessage' => null];
    }

    protected function extractCarePlanApprovalAuthMethod(array $data): ?array
    {
        $method = $data['response_data']['authentication_method_current']
            ?? ($data['data']['authentication_method_current']
                ?? ($data['authentication_method_current']
                    ?? ($data['urgent']['authentication_method_current'] ?? null)));

        return is_array($method) ? $method : null;
    }

    protected function formatCarePlanApprovalJobError(array $jobResult): string
    {
        if (isset($jobResult['error']['invalid']) && is_array($jobResult['error']['invalid'])) {
            $errors = [];

            foreach ($jobResult['error']['invalid'] as $invalid) {
                $entry = $invalid['entry'] ?? '';
                $rules = $invalid['rules'] ?? [];

                foreach ($rules as $rule) {
                    $errors[] = ($entry ? $entry.': ' : '').($rule['description'] ?? '');
                }
            }

            if ($errors !== []) {
                return 'Помилка від ЕСОЗ: '.implode(', ', $errors);
            }
        }

        if (isset($jobResult['error']['message'])) {
            return 'Помилка від ЕСОЗ: '.$jobResult['error']['message'];
        }

        return __('care-plan.approval_create_error');
    }

    protected function carePlanResourceFromFields(array $data, mixed ...$context): array
    {
        $data = Arr::toCamelCase($data);
        $options = $context[0] ?? [];
        $resource = $options['resource'] ?? [];
        $resource['id'] = $data['uuid'] ?? ($options['id'] ?? ($resource['id'] ?? null));

        if (!is_string($resource['id']) || $resource['id'] === '') {
            throw new InvalidArgumentException('Care Plan mapping requires an allocated resource UUID.');
        }

        foreach (['title', 'description', 'note', 'intent', 'status', 'requisition', 'context', 'statusReason'] as $field) {
            if (array_key_exists($field, $data)) {
                $resource[$field] = $data[$field];
            }
        }

        foreach (['category' => 'eHealth/care_plan_categories', 'termsOfService' => 'PROVIDING_CONDITION'] as $field => $system) {
            if (array_key_exists($field, $data)) {
                if (!is_string($data[$field]) || $data[$field] !== data_get($resource, $field.'.coding.0.code')) {
                    $resource[$field] = $this->carePlanConcept($data[$field], $system);
                }
            }
        }

        if (array_key_exists('author', $data) || !array_key_exists('author', $resource)) {
            $value = $data['author'] ?? ($options['authorUuid'] ?? ($options['employeeUuid'] ?? null));
            if ($value === null || $value === '') {
                unset($resource['author']);
            } elseif (!is_string($value) || $value !== data_get($resource, 'author.identifier.value')) {
                $resource['author'] = is_array($value) ? $value : FhirResource::make()
                    ->coding('eHealth/resources', 'employee')
                    ->toIdentifier($value);
            }
        }
        if (array_key_exists('encounter', $data) || !array_key_exists('encounter', $resource)) {
            $value = $data['encounter'] ?? ($options['encounterUuid'] ?? null);
            if ($value === null || $value === '') {
                unset($resource['encounter']);
            } elseif (!is_string($value) || $value !== data_get($resource, 'encounter.identifier.value')) {
                $resource['encounter'] = is_array($value) ? $value : FhirResource::make()
                    ->coding('eHealth/resources', 'encounter')
                    ->toIdentifier($value);
            }
        }
        if (array_key_exists('careManager', $data) || !array_key_exists('careManager', $resource)) {
            $value = $data['careManager'] ?? ($options['careManagerUuid'] ?? null);
            if ($value === null || $value === '') {
                unset($resource['careManager']);
            } elseif (!is_string($value) || $value !== data_get($resource, 'careManager.identifier.value')) {
                $resource['careManager'] = is_array($value) ? $value : FhirResource::make()
                    ->coding('eHealth/resources', 'employee')
                    ->toIdentifier($value);
            }
        }
        if (array_key_exists('managingOrganization', $data) || !array_key_exists('managingOrganization', $resource)) {
            $value = $data['managingOrganization'] ?? ($options['managingOrganizationUuid'] ?? null);
            if ($value === null || $value === '') {
                unset($resource['managingOrganization']);
            } elseif (!is_string($value) || $value !== data_get($resource, 'managingOrganization.identifier.value')) {
                $resource['managingOrganization'] = is_array($value) ? $value : FhirResource::make()
                    ->coding('eHealth/resources', 'legal_entity')
                    ->toIdentifier($value);
            }
        }

        foreach (['addresses', 'supportingInfo', 'contributor'] as $field) {
            if (array_key_exists($field, $data)) {
                $resource[$field] = $data[$field];
            } elseif (array_key_exists($field, $options) && !array_key_exists($field, $resource)) {
                $resource[$field] = $options[$field];
            }
        }

        foreach (['basedOn', 'partOf'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if ($value === null || $value === '') {
                unset($resource[$field]);
            } else {
                // Preserve the original display metadata when an unchanged link is reopened.
                $resource[$field] = $this->carePlanExistingReference($value, 'care_plan', isset($resource[$field]) ? [$resource[$field]] : []);
            }
        }

        if (array_key_exists('coAuthors', $data)) {
            $existing = $resource['contributor'] ?? [];
            $resource['contributor'] = array_map(fn (mixed $value): array => $this->carePlanExistingReference($value, 'employee', $existing), $data['coAuthors']);
        }

        if (array_key_exists('episodes', $data)) {
            $otherInfo = array_values(array_filter($resource['supportingInfo'] ?? [], static fn (array $reference): bool => data_get($reference, 'identifier.type.coding.0.code') !== 'episode_of_care'));
            $existing = $resource['supportingInfo'] ?? [];
            $episodes = array_map(fn (mixed $episode): array => $this->carePlanExistingReference(is_array($episode) && !isset($episode['identifier']) ? ($episode['uuid'] ?? ($episode['id'] ?? '')) : $episode, 'episode_of_care', $existing), $data['episodes']);
            $resource['supportingInfo'] = [...$otherInfo, ...$episodes];
        }

        if (array_key_exists('medicalRecords', $data)) {
            $episodes = array_values(array_filter($resource['supportingInfo'] ?? [], static fn (array $reference): bool => data_get($reference, 'identifier.type.coding.0.code') === 'episode_of_care'));
            $resource['supportingInfo'] = [...$episodes, ...$data['medicalRecords']];
        }

        if (array_key_exists('period', $data)) {
            $resource['period'] = $data['period'];
        } else {
            foreach (['start', 'end'] as $boundary) {
                $field = 'period'.ucfirst($boundary);
                if (array_key_exists($field, $data)) {
                    $resource['period'][$boundary] = $this->carePlanDateTime($data[$field], $data[$field.'Time'] ?? null, $options['timezone'] ?? 'UTC', $resource['period'][$boundary] ?? null, $boundary === 'end', $options['dateFormat'] ?? 'Y-m-d');
                }
            }
        }

        if (array_key_exists('informWith', $data)) {
            $resource['informWith'] = is_array($data['informWith']) || $data['informWith'] === null
                ? $data['informWith']
                : ($data['informWith'] === '' ? null : ['authMethodId' => $data['informWith']]);
        }

        return $resource;
    }

    protected function carePlanFieldsFromResource(array $data, mixed ...$context): array
    {
        $options = $context[0] ?? [];
        $form = array_intersect_key($data, array_flip(['title', 'description', 'note', 'intent', 'status', 'requisition', 'addresses', 'supportingInfo', 'basedOn', 'partOf', 'context']));
        $form['uuid'] = $data['id'];
        foreach (['category', 'termsOfService'] as $field) {
            $form[$field] = data_get($data, $field.'.coding.0.code', '');
        }
        foreach (['author', 'encounter', 'careManager', 'managingOrganization', 'basedOn', 'partOf'] as $field) {
            $form[$field] = data_get($data, $field.'.identifier.value', '');
        }
        $form['coAuthors'] = array_map(static fn (array $reference): string => $reference['identifier']['value'], $data['contributor'] ?? []);
        $form['episodes'] = [];
        $form['medicalRecords'] = [];
        foreach ($data['supportingInfo'] ?? [] as $reference) {
            if (data_get($reference, 'identifier.type.coding.0.code') === 'episode_of_care') {
                $form['episodes'][] = ['uuid' => $reference['identifier']['value']];
            } else {
                $form['medicalRecords'][] = $reference;
            }
        }
        foreach (['start', 'end'] as $boundary) {
            $field = 'period'.ucfirst($boundary);
            $value = data_get($data, 'period.'.$boundary);
            $date = $value ? CarbonImmutable::parse($value)->setTimezone($options['timezone'] ?? 'UTC') : null;
            $form[$field] = $date?->format($options['dateFormat'] ?? 'Y-m-d') ?? '';
            $form[$field.'Time'] = $date?->format('H:i:s') ?? '';
        }
        if (array_key_exists('informWith', $data)) {
            $form['informWith'] = data_get($data, 'informWith.authMethodId', '');
        }

        return $form;
    }

    protected function carePlanConcept(mixed $value, string $system): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_array($value) ? $value : FhirResource::make()->coding($system, (string) $value)->toCodeableConcept();
    }

    protected function carePlanReference(mixed $value, string $type): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('A reference requires a resource UUID.');
        }

        return FhirResource::make()->coding('eHealth/resources', $type)->toIdentifier($value);
    }

    protected function carePlanExistingReference(mixed $value, string $type, array $existing): array
    {
        foreach ($existing as $reference) {
            if (is_string($value) && data_get($reference, 'identifier.value') === $value && data_get($reference, 'identifier.type.coding.0.code') === $type) {
                return $reference;
            }
        }

        return $this->carePlanReference($value, $type);
    }

    protected function carePlanDateTime(?string $date, ?string $time, string $timezone, ?string $original, bool $end, string $dateFormat): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        $hasTime = str_contains($date, 'T') || preg_match('/\d{2}:\d{2}/', $date) === 1;
        $parsed = CarbonImmutable::hasFormat($date, $dateFormat)
            ? CarbonImmutable::createFromFormat('!'.$dateFormat, $date, $timezone)
            : CarbonImmutable::parse($date, $timezone);
        if ($original !== null) {
            $previous = CarbonImmutable::parse($original)->setTimezone($timezone);
            if (($hasTime && $previous->equalTo($parsed)) || (!$hasTime && $previous->toDateString() === $parsed->toDateString() && ($time === null || $time === '' || $previous->format(strlen($time) === 5 ? 'H:i' : 'H:i:s') === $time))) {
                return $original;
            }
        }
        if (!$hasTime) {
            $parsed = $parsed->setTimeFromTimeString($time ?: ($end ? '23:59:59' : '00:00:00'));
        }

        return rtrim(rtrim($parsed->utc()->format('Y-m-d\TH:i:s.u'), '0'), '.').'Z';
    }

    protected function carePlanFormContext(array $form, ?int $localId = null): array
    {
        unset($form['keyContainerUpload'], $form['key_container_upload'], $form['password'], $form['knedp'], $form['keyContainerFileName']);
        $data = Arr::toCamelCase($form);
        $resource = $localId === null ? [] : Repository::carePlan()->getResource($localId);
        $authorUuid = $data['author'] ?? null;
        if (isset($data['authorId'])) {
            $authorUuid = Employee::whereKey($data['authorId'])->value('uuid');
        }
        $encounterUuid = $data['encounter'] ?? null;
        if (isset($data['encounterId'])) {
            $encounterUuid = Encounter::whereKey($data['encounterId'])->value('uuid');
        }
        $data['uuid'] = $data['uuid'] ?? ($resource['id'] ?? Uuid::uuid1()->toString());
        if ($authorUuid !== null) {
            $data['author'] = $authorUuid;
        }
        if ($encounterUuid !== null) {
            $data['encounter'] = $encounterUuid;
        }
        if (isset($data['supportingInfo']['episodes'])) {
            $data['episodes'] = $data['supportingInfo']['episodes'];
            $data['medicalRecords'] = $data['supportingInfo']['medicalRecords'] ?? [];
            unset($data['supportingInfo']);
        }
        foreach ($data['medicalRecords'] ?? [] as $index => $reference) {
            if (isset($reference['identifier'])) {
                continue;
            }
            $type = $reference['type'] ?? null;
            if (!in_array($type, ['procedure', 'diagnostic_report'], true) || !isset($reference['uuid'])) {
                throw new InvalidArgumentException('A medical record requires its resource type and UUID.');
            }
            $data['medicalRecords'][$index] = FhirResource::make()->coding('eHealth/resources', $type)->toIdentifier($reference['uuid']);
        }

        return ['data' => $data, 'context' => ['resource' => $resource, 'timezone' => config('app.timezone'), 'dateFormat' => config('app.date_format')]];
    }

    protected function prepareCarePlanDraft(array $fields, ?int $localId = null): array
    {
        $input = $this->carePlanFormContext($fields, $localId);

        return $this->carePlanResourceFromFields($input['data'], $input['context']);
    }

    protected function resolveEpisodeLabels(array $references, int $personId): array
    {
        $episodes = Episode::wherePersonId($personId)
            ->whereIn('uuid', array_column($references, 'uuid'))->with('period')->get()->keyBy('uuid');

        return array_map(static function (array $reference) use ($episodes): array {
            $episode = $episodes->get($reference['uuid']);

            return array_replace($reference, [
                'name' => $episode?->name ?: $reference['uuid'],
                'date' => $episode?->period?->start ? CarbonImmutable::parse($episode->period->start)->format(config('app.date_format')) : '—',
            ]);
        }, $references);
    }

    protected function saveCarePlanDraft(array $fields, int $personId, int $legalEntityId, ?int $localId = null): CarePlan
    {
        return DB::transaction(function () use ($fields, $personId, $legalEntityId, $localId): CarePlan {
            if ($localId !== null) {
                CarePlan::whereKey($localId)->lockForUpdate()->firstOrFail();
            }
            // Prepare from the locked snapshot so concurrent edits cannot overwrite untouched fields.
            $resource = $this->prepareCarePlanDraft($fields, $localId);

            return Repository::carePlan()->saveDraft($resource, $personId, $legalEntityId, $localId);
        });
    }

    public function periodStartIsoDate(): string
    {
        return CarbonImmutable::createFromFormat('!'.config('app.date_format'), $this->form->periodStart)->format('Y-m-d');
    }

    public function periodEndIsoDate(): ?string
    {
        return $this->form->periodEnd !== ''
            ? CarbonImmutable::createFromFormat('!'.config('app.date_format'), $this->form->periodEnd)->format('Y-m-d')
            : null;
    }

    protected function activeEpisodes(Person $person): array
    {
        return Repository::carePlan()->activeEpisodes($person)
            ->map(fn ($episode): array => ['uuid' => $episode->uuid, 'name' => $episode->name, 'date' => $episode->period?->start ? Carbon::parse($episode->period->start)->format('d.m.Y') : ''])->all();
    }

    protected function doctors(int $legalEntityId): array
    {
        return Repository::carePlan()->doctors($legalEntityId)->map(fn ($employee): array => ['uuid' => $employee->uuid, 'name' => ($employee->party->fullName ?? 'Unknown').' ('.($employee->position ?? '').')'])->values()->all();
    }

    protected function searchPatients(string $firstName, string $lastName, string $birthDate): array
    {
        return Repository::carePlan()->searchPatients($firstName, $lastName, $birthDate)
            ->map(fn (Person $person) => [
                'id' => $person->id,
                'uuid' => $person->uuid,
                'name' => trim(($person->primary_name?->last_name . ' ' . $person->primary_name?->first_name . ' ' . $person->primary_name?->second_name) ?? ''),
                'birthDate' => $person->birth_date
                    ? Carbon::parse($person->birth_date)->format(config('app.date_format'))
                    : '-',
            ])
            ->values()
            ->toArray();
    }

    protected function carePlanEncounterChoices(int $personId, bool $forCreation = false): array
    {
        $choices = Repository::carePlan()->encounterChoices($personId, $forCreation);

        return $choices['encounters']->map(function (Encounter $encounter) use ($choices): array {
            $occurredAt = $encounter->period === null
                ? $encounter->getRawOriginal('ehealth_inserted_at')
                : $encounter->period->getRawOriginal('start');
            $date = $occurredAt
                ? Carbon::parse($occurredAt, 'UTC')->setTimezone(config('app.timezone'))->format('d.m.Y')
                : '-';
            $episodeName = $choices['episodeNames']->get($encounter->episode?->value);
            $label = __('Взаємодія #').$encounter->id.' ('.$date.')';
            if (filled($episodeName)) {
                $label .= ' — '.$episodeName;
            }

            return ['uuid' => $encounter->uuid, 'label' => $label];
        })->all();
    }
}
