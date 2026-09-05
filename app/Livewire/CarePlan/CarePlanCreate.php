<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\CarePlan;
use App\Models\Employee\Employee;
use App\Models\EmployeeRole;
use App\Enums\EmployeeRole\Status as EmployeeRoleStatus;
use App\Repositories\CarePlanRepository;
use App\Services\MedicalEvents\CarePlanApprovalService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Traits\InteractsWithApprovals;
use App\Models\Person\Person;
use App\Models\LegalEntity;
use Livewire\WithFileUploads;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Livewire\CarePlan\Forms\PatientSearchForm;
use App\Enums\CarePlanStatus;

class CarePlanCreate extends BasePatientComponent
{
    use WithFileUploads;
    use InteractsWithApprovals;

    public bool $showSignatureModal = false;
    public bool $showMethodSelectionModal = false;
    public string $patientUuid = '';
    public string $conditionUuid = '';
    public string $medicalRecordType = 'CONDITION';
    public ?string $carePlanUuid = null;

    public CarePlanForm $form;

    public PatientSearchForm $patientSearch;

    public array $categories = [];
    public array $diagnoses = [];
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

    public function mount(LegalEntity $legalEntity, $personId = null, $encounter = null): void
    {
        $routePersonId = request()->route('personId');
        $encounterRouteParam = request()->route('encounter') ?? request()->query('encounter') ?? request()->query('encounterId');
        $this->allowsPatientChange = empty($routePersonId) && empty($encounterRouteParam);

        $resolvedPersonId = null;
        $resolvedEncounter = null;

        // Try to resolve encounter from route parameters, query string or sequence-passed personId

        if ($encounterRouteParam) {
            if (\Illuminate\Support\Str::isUuid((string) $encounterRouteParam)) {
                $resolvedEncounter = \App\Models\MedicalEvents\Sql\Encounter::where('uuid', $encounterRouteParam)->first();
            } elseif (is_numeric($encounterRouteParam)) {
                $resolvedEncounter = \App\Models\MedicalEvents\Sql\Encounter::where('id', (int) $encounterRouteParam)->first();
            }
            if ($resolvedEncounter) {
                $resolvedPersonId = $resolvedEncounter->person_id;
            }
        }

        // If personId was passed, use it, unless it was sequential mapping of encounter
        if (!$resolvedPersonId && $personId) {
            // Check if $personId is actually an encounter ID or UUID
            $possibleEncounter = null;
            if (\Illuminate\Support\Str::isUuid((string) $personId)) {
                $possibleEncounter = \App\Models\MedicalEvents\Sql\Encounter::where('uuid', $personId)->first();
            } elseif (is_numeric($personId)) {
                $possibleEncounter = \App\Models\MedicalEvents\Sql\Encounter::where('id', (int) $personId)->first();
            }

            if ($possibleEncounter) {
                $resolvedEncounter = $possibleEncounter;
                $resolvedPersonId = $possibleEncounter->person_id;
            } else {
                $resolvedPersonId = $personId;
            }
        }

        $this->personId = (int) ($resolvedPersonId ?? 0);

        if ($this->personId > 0) {
            parent::mount($legalEntity);
        } else {
            $this->patientFullName = __('care-plan.new_care_plan');
            $this->verificationStatus = '';
            $this->uuid = '';
            $this->declarationNumber = null;
        }

        $person = Person::find($this->personId);
        if ($person) {
            $name = $person->primary_name;
            $this->form->patient = $name ? trim($name->last_name . ' ' . $name->first_name . ' ' . ($name->second_name ?? '')) : '';
            $this->form->medical_number = (string) ((CarePlan::max('id') ?? 0) + 1);

            $this->availableEpisodes = \App\Models\MedicalEvents\Sql\Episode::forPatient($person)
                ->where('status', 'active')
                ->with('period')
                ->get()
                ->map(fn ($e) => [
                    'uuid' => $e->uuid,
                    'name' => $e->name,
                    'date' => $e->period?->start ? \Carbon\Carbon::parse($e->period->start)->format('d.m.Y') : '',
                ])->toArray();

            $this->loadPatientAuthMethods($this->uuid);
        } else {
            $this->form->medical_number = (string) ((CarePlan::max('id') ?? 0) + 1);
        }

        $this->loadAvailableEncounters();

        $this->conditionUuid = request()->query('conditionUuid', '');

        if ($resolvedEncounter) {
            $this->form->encounter = $resolvedEncounter->uuid;
            $this->form->medical_number = (string) $resolvedEncounter->id;

            // Pre-fill title if empty
            if (empty($this->form->title)) {
                $date = $resolvedEncounter->period?->start ? \Carbon\Carbon::parse($resolvedEncounter->period->start)->format('d.m.Y') : now()->format('d.m.Y');
                $this->form->title = 'План лікування від ' . $date;
            }

            $resolvedEncounter->load(['episode']);
            if ($resolvedEncounter->episode?->value) {
                $episode = \App\Models\MedicalEvents\Sql\Episode::where('uuid', $resolvedEncounter->episode->value)->with('period')->first();
                if ($episode) {
                    $this->form->episodes = [
                        [
                            'uuid' => $episode->uuid,
                            'name' => $episode->name,
                            'date' => $episode->period?->start ? \Carbon\Carbon::parse($episode->period->start)->format('d.m.Y') : '',
                        ]
                    ];
                }
            }

            // Pre-fill diagnoses for the UI list
            $resolvedEncounter->load(['diagnoses.condition']);
            $this->diagnoses = $resolvedEncounter->diagnoses->map(function ($d) {
                $conditionUuid = $d->condition?->value;
                $actualCondition = null;
                if ($conditionUuid) {
                    $actualCondition = \App\Models\MedicalEvents\Sql\Condition::where('uuid', $conditionUuid)->with('code.coding')->first();
                    if (!$actualCondition) {
                        Log::info('CarePlanCreate mount: condition not found in local SQL DB, attempting to fetch from eHealth', [
                            'condition_uuid' => $conditionUuid
                        ]);
                        try {
                            $conditionData = EHealth::condition()->getById($this->uuid, $conditionUuid)->getData();
                            \App\Repositories\MedicalEvents\Repository::condition()->store([Arr::toCamelCase($conditionData)], $person);
                            $actualCondition = \App\Models\MedicalEvents\Sql\Condition::where('uuid', $conditionUuid)->with('code.coding')->first();
                        } catch (\Exception $e) {
                            Log::error('CarePlanCreate mount: failed to fetch condition from eHealth', [
                                'condition_uuid' => $conditionUuid,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                }

                return [
                    'date' => $actualCondition?->asserted_date
                        ? \Carbon\Carbon::parse($actualCondition->asserted_date)->format('d.m.Y')
                        : '-',
                    'name' => ($actualCondition?->code?->text ?: null)
                        ?? ($actualCondition?->code?->coding?->first()?->code ?: null)
                        ?? '-',
                ];
            })->toArray();
        }

        $this->form->periodStart = now()->format('d.m.Y');
        $this->form->periodStartTime = now()->format('H:i');

        $this->refreshAuthorDisplay();
        $this->loadDoctorsAndDictionaries();
    }

    /**
     * Re-resolve the care plan writer employee for the currently selected terms_of_service and
     * refresh the read-only "Автор" display field. eHealth ties the author to a specific
     * healthcare_service.providing_condition, so the effective author can change when the user
     * switches "умови надання послуг" (terms_of_service).
     */
    public function updatedFormTermsOfService(): void
    {
        $this->refreshAuthorDisplay();
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

    private function refreshAuthorDisplay(): void
    {
        $employee = Auth::user()?->getCarePlanWriterEmployee($this->form->termsOfService ?: null);
        if ($employee) {
            $party = $employee->party;
            $this->form->author = implode(' ', array_filter([
                $party?->last_name, $party?->first_name, $party?->second_name,
            ]));
        }
    }

    private function loadDoctorsAndDictionaries(): void
    {
        // Load doctors for co-authors
        $legalEntity = legalEntity();
        if ($legalEntity) {
            $this->doctors = \App\Models\Employee\Employee::where('legal_entity_id', $legalEntity->id)
                ->whereIn('employee_type', [\App\Enums\User\Role::DOCTOR, \App\Enums\User\Role::SPECIALIST])
                ->where('status', \App\Enums\Status::APPROVED)
                ->where('is_active', true)
                ->with('party')
                ->get()
                ->filter(fn ($e) => $e->party !== null)
                ->map(fn ($e) => [
                    'uuid' => $e->uuid,
                    'name' => ($e->party->full_name ?? 'Unknown') . ' (' . ($e->position ?? '') . ')',
                ])
                ->values()
                ->toArray();
        }

        // Load dictionaries via FormTrait pattern
        try {
            $this->getDictionary();
            $this->categories = $this->dictionaries['eHealth/care_plan_categories'] ?? [];
        } catch (\Exception $exception) {
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

        $this->patientSearchResults = Person::query()
            ->where('birth_date', $birthDate)
            ->where(function ($query) use ($firstName, $lastName) {
                $query->whereHas('names', function ($nameQuery) use ($firstName, $lastName) {
                    $nameQuery->where('first_name', $firstName)
                        ->where('last_name', $lastName);
                })->orWhereHas('names', function ($nameQuery) use ($firstName, $lastName) {
                    $nameQuery->where('first_name', $lastName)
                        ->where('last_name', $firstName);
                });
            })
            ->with('names') // Eager load names so fullName works!
            ->get(['id', 'uuid', 'birth_date'])
            ->map(fn (Person $person) => [
                'id' => $person->id,
                'uuid' => $person->uuid,
                'name' => trim(($person->primary_name?->last_name . ' ' . $person->primary_name?->first_name . ' ' . $person->primary_name?->second_name) ?? ''),
                'birthDate' => $person->birth_date
                    ? \Carbon\Carbon::parse($person->birth_date)->format(config('app.date_format'))
                    : '-',
            ])
            ->values()
            ->toArray();

        if ($this->patientSearchResults === []) {
            Session::flash('error', __('patients.nobody_found') . '. ' . __('patients.try_change_search_parameters'));
        }
    }

    /**
     * Select a patient from the search results.
     */
    public function selectPatient(int $personId): void
    {
        $person = Person::query()
            ->with(['declarations' => fn ($declaration) => $declaration->active()->latest()->take(1)])
            ->findOrFail($personId);

        $this->personId = $person->id;
        $this->uuid = $person->uuid;
        $this->patientUuid = $person->uuid;
        $this->patientFullName = $person->fullName;
        $this->verificationStatus = $person->verificationStatus;
        $this->declarationNumber = $person->declarations->first()?->declarationNumber ?? null;

        $birthDate = $person->birth_date
            ? \Carbon\Carbon::parse($person->birth_date)->format(config('app.date_format'))
            : null;
        $this->form->patient = trim($person->fullName . ($birthDate ? ' · ' . $birthDate : ''));
        $this->form->medical_number = (string) ((CarePlan::max('id') ?? 0) + 1);
        $this->form->encounter = '';
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
            $this->authMethods = EHealth::person()->getAuthMethods($personUuid)->getData();
        } catch (\Exception $e) {
            $this->authMethods = [];

            Log::channel('e_health_errors')->error('CarePlanCreate: failed to load patient auth methods', [
                'person_uuid' => $personUuid,
                'exception' => $e->getMessage(),
            ]);

            session()->flash('error', __('care-plan.auth_methods_unavailable'));
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
        $this->availableEncounters = [];
        $this->authMethods = [];
        $this->diagnoses = [];
        $this->patientSearchResults = [];
        $this->form->medical_number = (string) ((CarePlan::max('id') ?? 0) + 1);
    }

    /**
     * Handle validation failure: dispatch flash + scroll events.
     */
    protected function handleValidationFailed(ValidationException $exception, bool $closeModal = false): void
    {
        $message = $exception->validator->errors()->first() ?: (__('validation.failed') ?? 'Форма містить помилки');

        session()->flash('error', $message);
        $this->dispatch('scroll-to-error');
        $this->setErrorBag($exception->validator->getMessageBag());

        if ($closeModal) {
            $this->showSignatureModal = false;
        }
    }

    public function openActivationManually(): void
    {
        // Try to find the latest approval for this care plan
        if (!$this->approvalId && $this->carePlanUuid) {
            try {
                $response = EHealth::approval()->getMany([
                    'granted_resource_type' => 'care_plan',
                    'granted_resource_id' => $this->carePlanUuid,
                ]);
                $approvals = $response->getData();
                if (!empty($approvals)) {
                    $this->approvalId = $approvals[0]['id'] ?? null;
                }
            } catch (\Exception $e) {
                Log::error('CarePlan: Failed to fetch approvals manually: ' . $e->getMessage());
            }
        }

        if ($this->approvalId) {
            $this->showAuthModal = true;
        } else {
            // If there is no approval ID, suggest selecting a verification method (create a new approval request)
            $this->openMethodSelectionModal();
        }
    }

    public function openMethodSelectionModal(): void
    {
        if (!empty($this->form->periodEnd)) {
            session()->flash('error', __('care-plan.period_end_warning'));
        }

        try {
            $this->authMethods = EHealth::person()->getAuthMethods($this->patientUuid)->getData();
            $this->showMethodSelectionModal = true;
        } catch (\Exception $e) {
            Log::error('CarePlanCreate: failed to load auth methods: ' . $e->getMessage());
            session()->flash('error', 'Не вдалося завантажити методи аутентифікації');
        }
    }

    public function selectAuthMethod(string $methodUuid): void
    {
        $this->currentAuthMethod = collect($this->authMethods)->first(function ($method) use ($methodUuid) {
            return ($method['id'] ?? $method['uuid'] ?? null) === $methodUuid;
        });
        $this->showMethodSelectionModal = false;

        $selected = collect($this->authMethods)->first(fn ($m) => ($m['id'] ?? ($m['uuid'] ?? null)) === $methodUuid);
        if ($selected && !empty($selected['phone_number'])) {
            $this->phoneNumber = $selected['phone_number'];
        } elseif ($selected && !empty($selected['phoneNumber'])) {
            $this->phoneNumber = $selected['phoneNumber'];
        }

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
                $carePlan = CarePlan::where('uuid', $this->carePlanUuid)->first();
                session()->flash('info', 'Не вдалося визначити лікаря для створення дозволу. Перейдіть на вкладку "Дозволи" та створіть дозвіл вручну.');
                $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan?->id ?? $this->carePlanUuid], navigate: true);

                return;
            }

            $carePlan = CarePlan::where('uuid', $this->carePlanUuid)->firstOrFail();

            $result = app(CarePlanApprovalService::class)->create(
                carePlan: $carePlan,
                patientUuid: $this->patientUuid,
                employeeUuid: $employeeUuid,
                accessLevel: 'write',
                authorizeWith: $methodUuid ?: null,
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

            if ($result->requiresOtp()) {
                $this->currentAuthMethod = $result->authMethod ?? $this->currentAuthMethod;
                $this->openAuthModal();

                return;
            }

            Session::flash('flash_message', 'План лікування успішно активовано.');
            $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan->id], navigate: true);
        } catch (\Exception $e) {
            Log::error('CarePlanCreate: failed to create approval: ' . $e->getMessage());
            $msg = 'Не вдалося створити запит на дозвіл: ' . $e->getMessage();
            session()->flash('error', $msg);
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
            $msg = $status->errorMessage ?: 'Не вдалося обробити запит на дозвіл. Спробуйте ще раз.';
            session()->flash('error', $msg);

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

        Session::flash('flash_message', 'План лікування успішно активовано.');
        $carePlan = CarePlan::where('uuid', $this->carePlanUuid)->first();
        $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan?->id ?? $this->carePlanUuid], navigate: true);
    }

    /**
     * Save as a local draft (without sending to eHealth).
     */
    public function save(CarePlanRepository $repository): void
    {
        if (Auth::user()?->cannot('create', CarePlan::class)) {
            $msg = __('care-plan.no_permission_create') ?? 'У вас немає прав для створення плану лікування';
            session()->flash('error', $msg);

            return;
        }

        try {
            $this->form->validate();
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception);

            return;
        }

        $legalEntity = legalEntity();

        $encounterData = $this->resolveEncounterData();

        $carePlan = $repository->create([
            'person_id' => $this->resolvePersonId(),
            'author_id' => Auth::user()?->getCarePlanWriterEmployee($this->form->termsOfService ?: null)?->id,
            'legal_entity_id' => $legalEntity?->id,
            'status' => CarePlanStatus::DRAFT->value,
            'category' => $this->form->category,
            'context' => $this->form->context ?: null,
            'title' => $this->form->title,
            'terms_of_service' => $this->form->termsOfService ?: null,
            'period_start' => convertToYmd($this->form->periodStart),
            'period_end' => !empty($this->form->periodEnd)
                ? convertToYmd($this->form->periodEnd) : null,
            'encounter_id' => $encounterData['id'],
            'addresses' => $encounterData['addresses'],
            'supporting_info' => [
                'episodes' => $this->form->episodes,
                'medical_records' => $this->form->medicalRecords,
            ],
            'description' => $this->form->description ?: null,
            'note' => $this->form->note ?: null,
            'inform_with' => $this->form->informWith ?: null,
            'terms_of_service' => $this->form->termsOfService ?: null,
        ]);

        session()->flash('success', __('care-plan.draft_saved') ?? 'План лікування успішно збережено');
        $this->redirectRoute('care-plans.edit', [legalEntity(), $carePlan->id], navigate: true);
    }

    public function cancel(): void
    {
        $encounterUuid = $this->form->encounter;
        if ($encounterUuid) {
            $encounter = \App\Models\MedicalEvents\Sql\Encounter::where('uuid', $encounterUuid)->first();
            if ($encounter) {
                $this->redirectRoute('encounter.edit', [legalEntity(), $this->personId, $encounter->id], navigate: true);

                return;
            }
        }
        if ($this->personId > 0) {
            $this->redirectRoute('persons.care-plans', [legalEntity(), $this->personId], navigate: true);

            return;
        }

        $this->redirectRoute('care-plans.index', legalEntity(), navigate: true);
    }

    /**
     * Load encounters confirmed by eHealth for the current patient.
     */
    protected function loadAvailableEncounters(): void
    {
        if ($this->personId <= 0) {
            $this->availableEncounters = [];

            return;
        }

        $this->availableEncounters = \App\Models\MedicalEvents\Sql\Encounter::where('person_id', $this->personId)
            ->whereNotNull('ehealth_inserted_at')
            ->where('status', 'finished')
            ->orderBy('ehealth_inserted_at', 'desc')
            ->get(['id', 'uuid', 'status', 'ehealth_inserted_at'])
            ->map(fn ($e) => [
                'uuid' => $e->uuid,
                'label' => 'Взаємодія #' . $e->id . ' (' . ($e->ehealth_inserted_at ? \Carbon\Carbon::parse($e->ehealth_inserted_at)->format('d.m.Y') : '-') . ')',
            ])
            ->toArray();
    }

    public function updatedFormEncounter($value): void
    {
        if ($value) {
            $encounter = \App\Models\MedicalEvents\Sql\Encounter::where('uuid', $value)->with(['diagnoses.condition', 'episode'])->first();
            if ($encounter) {
                if (empty($this->form->title)) {
                    $date = $encounter->period?->start ? \Carbon\Carbon::parse($encounter->period->start)->format('d.m.Y') : now()->format('d.m.Y');
                    $this->form->title = 'План лікування від ' . $date;
                }

                if ($encounter->episode?->value) {
                    $episode = \App\Models\MedicalEvents\Sql\Episode::where('uuid', $encounter->episode->value)->with('period')->first();
                    if ($episode) {
                        $this->form->episodes = [
                            [
                                'uuid' => $episode->uuid,
                                'name' => $episode->name,
                                'date' => $episode->period?->start ? \Carbon\Carbon::parse($episode->period->start)->format('d.m.Y') : '',
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
    }

    /**
     * Build the UI list of diagnoses for an encounter. EncounterDiagnose::condition() resolves to
     * the Identifier row (its uuid lives in ->value), not the actual Condition, so we look the
     * Condition up locally and, if missing, fetch and store it from eHealth on the fly.
     *
     * @return array<int, array{date: string, name: string}>
     */
    protected function buildDiagnosesForUi(\App\Models\MedicalEvents\Sql\Encounter $encounter): array
    {
        return $encounter->diagnoses->map(function ($d) {
            $conditionUuid = $d->condition?->value;
            $actualCondition = null;
            if ($conditionUuid) {
                $actualCondition = \App\Models\MedicalEvents\Sql\Condition::where('uuid', $conditionUuid)->with('code.coding')->first();
                if (!$actualCondition) {
                    Log::info('CarePlanCreate: condition not found in local SQL DB, attempting to fetch from eHealth', [
                        'condition_uuid' => $conditionUuid
                    ]);
                    try {
                        $conditionData = EHealth::condition()->getById($this->uuid, $conditionUuid)->getData();
                        \App\Repositories\MedicalEvents\Repository::condition()->store([Arr::toCamelCase($conditionData)], $this->personId);
                        $actualCondition = \App\Models\MedicalEvents\Sql\Condition::where('uuid', $conditionUuid)->with('code.coding')->first();
                    } catch (\Exception $e) {
                        Log::error('CarePlanCreate: failed to fetch condition from eHealth', [
                            'condition_uuid' => $conditionUuid,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            return [
                'date' => $actualCondition?->asserted_date
                    ? \Carbon\Carbon::parse($actualCondition->asserted_date)->format('d.m.Y')
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
            $this->form->validate();
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
        $this->validate($this->approvalVerificationRules());

        if ($this->isOfflineAuthMethod()) {
            Log::info('CarePlanCreate: offline document verification confirmed for approval ID: ' . $this->approvalId);
            $this->closeAuthModal();
            Session::flash('flash_message', 'План лікування успішно активовано (за документами пацієнта).');
            $carePlan = CarePlan::where('uuid', $this->carePlanUuid)->first();
            $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan?->id ?? $this->carePlanUuid], navigate: true);

            return;
        }

        try {
            $response = app(CarePlanApprovalService::class)->verify(
                $this->patientUuid,
                $this->approvalId,
                (int) $this->verificationCode,
            );

            if ($response->successful()) {
                $this->closeAuthModal();
                Session::flash('flash_message', 'План лікування успішно активовано.');
                $carePlan = CarePlan::where('uuid', $this->carePlanUuid)->first();
                $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan?->id ?? $this->carePlanUuid], navigate: true);
            }
        } catch (\Exception $e) {
            Log::error('CarePlanCreate: failed to verify approval: ' . $e->getMessage());
            $this->addError('verificationCode', 'Невірний код підтвердження або помилка сервісу');
        }
    }

    public function resendSms(): void
    {
        if ($this->smsResent) {
            return;
        }

        try {
            app(CarePlanApprovalService::class)->resendSms($this->patientUuid, $this->approvalId);
            $this->smsResent = true;
            session()->flash('success', 'SMS надіслано повторно');
        } catch (\Exception $e) {
            Log::error('CarePlanCreate: failed to resend SMS: ' . $e->getMessage());
            $message = str_contains($e->getMessage(), 'ACL')
                ? __('care-plan.sms_resend_acl_error')
                : ('Не вдалося повторно надіслати SMS: ' . $e->getMessage());
            $this->addError('verificationCode', $message);
            session()->flash('error', $message);
        }
    }

    /**
     * Sign with KEP and send to eHealth.
     */
    public function sign(CarePlanRepository $repository): void
    {
        if (Auth::user()?->cannot('create', CarePlan::class)) {
            $msg = __('care-plan.no_permission_create') ?? 'У вас немає прав для створення плану лікування';
            session()->flash('error', $msg);

            return;
        }

        $generatedUuid = null;
        try {
            $this->form->validate($this->form->rulesForSigning());
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception, closeModal: true);

            return;
        }

        try {
            $legalEntity = legalEntity();
            $encounterData = $this->resolveEncounterData();
            if (empty($encounterData['addresses'])) {
                throw new \RuntimeException('Неможливо створити план лікування: у вибраній взаємодії відсутні діагнози (addresses). Будь ласка, переконайтеся, що взаємодія містить діагнози в ЕСОЗ та вони завантажені в локальну БД.');
            }

            $termsOfService = $this->form->termsOfService;
            $author = Auth::user()?->getCarePlanWriterEmployee($termsOfService);
            $this->logCarePlanAuthorRoleDebug($author, $termsOfService);

            if ($author && !$this->authorHasActiveRoleForTermsOfService($author, $termsOfService)) {
                // Not a hard block: getCarePlanWriterEmployee() already tried its best to find a
                // matching employee and fell back to this one. We still submit so eHealth remains
                // the single source of truth for the "Employee does not have active role..." rule,
                // but we log loudly here so the real cause is obvious without digging through the
                // raw eHealth request/response.
                Log::warning('[CarePlan] submitting with author lacking a matching active role for terms_of_service', [
                    'author_uuid' => $author->uuid,
                    'author_position' => $author->position,
                    'terms_of_service' => $termsOfService,
                ]);
            }

            $carePlanPayload = $repository->formatCarePlanRequest(
                $this->form->toArray(),
                $this->form->encounter ?: null,
                $encounterData,
                $author?->uuid,
                $this->carePlanUuid ?: null
            );
            $generatedUuid = $carePlanPayload['id'];

            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($carePlanPayload),
                $this->form->password,
                $this->form->knedp,
                $this->form->keyContainerUpload,
                Auth::user()->party->taxId
            );

            $finalResponse = app(\App\Services\MedicalEvents\CarePlanLifecycleService::class)
                ->submitSignedCreate($this->uuid, $signedContent);

            $carePlanUuid = $this->carePlanUuid;
            if (!$carePlanUuid && isset($finalResponse['links']) && is_array($finalResponse['links'])) {
                foreach ($finalResponse['links'] as $link) {
                    if (isset($link['entity']) && $link['entity'] === 'care_plan' && isset($link['href'])) {
                        $carePlanUuid = basename($link['href']);
                        break;
                    }
                }
            }

            $entity = $finalResponse['response_data'] ?? $finalResponse['result'] ?? $finalResponse;
            if (is_array($entity) && isset($entity[0])) {
                $entity = $entity[0];
            }

            if (!$carePlanUuid) {
                $carePlanUuid = $entity['id'] ?? ($finalResponse['id'] ?? null);
            }

            // Deep search for approval ID in response_data, result, or root
            $this->approvalId = $finalResponse['response_data']['urgent']['approval_id'] ??
                               $finalResponse['response_data']['urgent']['id'] ??
                               $finalResponse['response_data']['approval_id'] ??
                               $finalResponse['result']['urgent']['approval_id'] ??
                               $finalResponse['result']['urgent']['id'] ??
                               $finalResponse['result']['approval_id'] ??
                               $finalResponse['urgent']['approval_id'] ??
                               $finalResponse['urgent']['id'] ??
                               $finalResponse['approval_id'] ??
                               $entity['urgent']['approval_id'] ??
                               $entity['urgent']['id'] ??
                               $entity['approval_id'] ?? null;

            $carePlanStatus = $entity['status'] ?? $finalResponse['status'] ?? CarePlanStatus::ACTIVE->value;
            if ($carePlanStatus === 'processed') {
                $carePlanStatus = $this->approvalId ? CarePlanStatus::PENDING->value : CarePlanStatus::ACTIVE->value;
            }

            $this->carePlanUuid = $carePlanUuid;

            // Create local record
            $carePlan = $repository->create([
                'uuid' => $carePlanUuid,
                'person_id' => $this->personId,
                'author_id' => $author?->id,
                'legal_entity_id' => $legalEntity?->id,
                'status' => $carePlanStatus,
                'category' => $this->form->category,
                'title' => $this->form->title,
                'terms_of_service' => $termsOfService ?: null,
                'period_start' => convertToYmd($this->form->periodStart),
                'period_end' => !empty($this->form->periodEnd) ? convertToYmd($this->form->periodEnd) : null,
                'encounter_id' => $encounterData['id'] ?? null,
                'context' => $this->form->context ?: null,
                'terms_of_service' => $this->form->termsOfService ?: null,
                'description' => $this->form->description ?: null,
                'note' => $this->form->note ?: null,
                'inform_with' => $this->form->informWith ?: null,
                'addresses' => $encounterData['addresses'],
                'supporting_info' => [
                    'episodes' => $this->form->episodes,
                    'medical_records' => $this->form->medicalRecords,
                ],
            ]);

            if (!empty($carePlanPayload['period'])) {
                \App\Repositories\MedicalEvents\Repository::period()->sync(
                    $carePlan,
                    $carePlanPayload['period'],
                    'effectivePeriod'
                );
            }

            $this->showSignatureModal = false;

            // Query eHealth for the approval associated with this new care plan if not found in finalResponse
            if (!$this->approvalId && $carePlanUuid) {
                try {
                    $response = EHealth::approval()->getMany([
                        'patient_id' => $this->patientUuid ?: $this->uuid,
                        'status' => 'NEW',
                    ]);
                    $approvals = $response->getData();
                    $approvalsData = $approvals['data'] ?? $approvals;
                    if (!empty($approvalsData)) {
                        $matchedApproval = null;
                        foreach ($approvalsData as $appr) {
                            $resources = $appr['granted_resources'] ?? [];
                            foreach ($resources as $res) {
                                if (isset($res['identifier']['value']) && $res['identifier']['value'] === $carePlanUuid) {
                                    $matchedApproval = $appr;
                                    break 2;
                                }
                            }
                        }
                        $this->approvalId = $matchedApproval ? $matchedApproval['id'] : ($approvalsData[0]['id'] ?? null);
                    }
                } catch (\Exception $e) {
                    Log::warning('CarePlanCreate: Failed to fetch approvals on creation: ' . $e->getMessage());
                }
            }

            Log::info('CarePlan: creation result details', [
                'carePlanUuid' => $carePlanUuid,
                'approvalId' => $this->approvalId,
                'finalResponse' => $finalResponse,
            ]);

            session()->flash('success', 'План лікування успішно створено.');

            Log::info('CarePlan: creation job finished', [
                'status' => $carePlanStatus,
                'approvalId' => $this->approvalId
            ]);

            if ($this->approvalId) {
                $authMethod = $finalResponse['response_data']['urgent']['authentication_method_current'] ??
                              $finalResponse['result']['urgent']['authentication_method_current'] ??
                              $finalResponse['urgent']['authentication_method_current'] ??
                              $entity['urgent']['authentication_method_current'] ??
                              ($matchedApproval['authentication_method_current'] ?? null);

                if (empty($authMethod)) {
                    try {
                        $authMethods = EHealth::person()->getAuthMethods($this->patientUuid ?: $this->uuid)->getData();
                        $authMethod = $authMethods[0] ?? null;
                    } catch (\Exception $e) {
                        Log::warning('CarePlanCreate: failed to load auth methods after approval created: ' . $e->getMessage());
                    }
                }

                $this->currentAuthMethod = $authMethod;
                $this->showAuthModal = true;

                $msg = $this->isOfflineAuthMethod($this->currentAuthMethod)
                    ? 'План успішно створено. Пацієнт авторизований за документами (СМС не потрібне, перевірте посвідчення особи).'
                    : 'План успішно створено. Пацієнту надіслано SMS для активації.';
                session()->flash('success', $msg);

                return;
            }

            // If eHealth did not create approval automatically (e.g. due to missing declaration),
            // we immediately request authentication methods and propose to create approval manually.
            try {
                $this->authMethods = EHealth::person()->getAuthMethods($this->uuid)->getData();
                if (!empty($this->authMethods)) {
                    $this->showMethodSelectionModal = true;
                    session()->flash('success', 'План успішно створено. Будь ласка, оберіть метод підтвердження для створення дозволу пацієнта.');

                    return;
                }
            } catch (\Exception $e) {
                Log::warning('CarePlanCreate sign: failed to auto-load auth methods for manual approval request: ' . $e->getMessage());
            }

            Session::flash('flash_message', 'План лікування успішно створено.');
            $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan->id], navigate: true);

        } catch (EHealthConnectionException $exception) {
            $this->carePlanUuid = $generatedUuid ?? $this->carePlanUuid;
            Log::error('CarePlan: connection error: ' . $exception->getMessage());
            $msg = __('care-plan.connection_error') ?? 'Помилка з\'єднання з ЕСОЗ';
            session()->flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            $this->carePlanUuid = $generatedUuid ?? $this->carePlanUuid;
            Log::error('CarePlan: eHealth error: ' . $exception->getMessage());
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : 'Помилка від ЕСОЗ: ' . $exception->getMessage();

            if (
                $generatedUuid && (
                    str_contains($exception->getMessage(), 'Care plan with such id already exists')
                || str_contains($msg, 'Care plan with such id already exists')
                || (isset($exception->details['error']['message']) && str_contains($exception->details['error']['message'], 'Care plan with such id already exists'))
                )
            ) {
                try {
                    $localCarePlan = \App\Models\CarePlan::where('uuid', $generatedUuid)->first();

                    if (!$localCarePlan) {
                        $carePlanData = app(\App\Services\MedicalEvents\CarePlanLifecycleService::class)
                            ->getDetails($this->patientUuid ?: $this->uuid, $generatedUuid);

                        $carePlanStatus = $carePlanData['status'] ?? \App\Enums\CarePlanStatus::PENDING->value;
                        if ($carePlanStatus === 'processed') {
                            $carePlanStatus = \App\Enums\CarePlanStatus::PENDING->value;
                        }

                        if (!isset($encounterData)) {
                            $encounterData = $this->resolveEncounterData();
                        }

                        $localCarePlan = $repository->create([
                            'uuid' => $generatedUuid,
                            'person_id' => $this->personId,
                            'author_id' => Auth::user()?->getCarePlanWriterEmployee()?->id,
                            'legal_entity_id' => legalEntity()?->id,
                            'status' => $carePlanStatus,
                            'category' => $this->form->category,
                            'title' => $this->form->title,
                            'period_start' => convertToYmd($this->form->periodStart),
                            'period_end' => !empty($this->form->periodEnd) ? convertToYmd($this->form->periodEnd) : null,
                            'encounter_id' => $encounterData['id'] ?? null,
                        ]);

                        if (!empty($carePlanData['period'])) {
                            \App\Repositories\MedicalEvents\Repository::period()->sync(
                                $localCarePlan,
                                $carePlanData['period'],
                                'effectivePeriod'
                            );
                        }
                    }

                    session()->flash('success', 'План лікування вже зареєстровано в ЕСОЗ. Ви перенаправлені на сторінку детального перегляду для активації дозволу пацієнта.');
                    $this->redirectRoute('care-plans.show', [legalEntity(), $localCarePlan->id], navigate: true);

                    return;
                } catch (\Throwable $innerEx) {
                    Log::error('CarePlanCreate conflict handling failed: ' . $innerEx->getMessage(), [
                        'trace' => $innerEx->getTraceAsString(),
                    ]);
                }
            }

            session()->flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (\RuntimeException $exception) {
            $this->carePlanUuid = $generatedUuid ?? $this->carePlanUuid;
            Log::error('CarePlan: runtime error: ' . $exception->getMessage());
            session()->flash('error', $exception->getMessage());
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            $this->carePlanUuid = $generatedUuid ?? $this->carePlanUuid;
            Log::error('CarePlan: unexpected error: ' . $exception->getMessage(), [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
            $msg = $exception->getMessage() ?: (__('care-plan.unexpected_error') ?? 'Неочікувана помилка при створенні плану лікування');
            session()->flash('error', $msg);
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

    /**
     * Resolve the local Encounter ID and extract Conditions (addresses) from it.
     */
    protected function resolveEncounterData(): array
    {
        $data = ['id' => null, 'addresses' => [], 'period_start' => null];
        if (empty($this->form->encounter)) {
            Log::warning('CarePlanCreate: encounter form field is empty');

            return $data;
        }

        $encounter = \App\Models\MedicalEvents\Sql\Encounter::where('uuid', $this->form->encounter)
            ->with(['diagnoses.condition', 'period'])
            ->first();

        if ($encounter) {
            $data['id'] = $encounter->id;

            // Use raw UTC value — Period cast returns Kyiv display time, not UTC.
            if ($encounter->period) {
                $data['period_start'] = $encounter->period->getRawOriginal('start');
            }

            Log::info('CarePlanCreate: resolving encounter diagnoses', [
                'encounter_id' => $encounter->id,
                'diagnoses_count' => $encounter->diagnoses->count(),
                'filter_condition_uuid' => $this->conditionUuid ?? 'none'
            ]);

            // Extract the Codeable Concepts of all conditions (addresses for the care plan)
            $conditionData = $encounter->diagnoses
                ->filter(function ($d) use ($encounter) {
                    $conditionUuid = $d->condition?->value;
                    $match = empty($this->conditionUuid) || ($conditionUuid === $this->conditionUuid);
                    Log::info('CarePlanCreate: filter diagnosis', [
                        'encounter_id' => $encounter->id,
                        'condition_uuid' => $conditionUuid,
                        'match' => $match
                    ]);

                    return $match;
                })
                ->map(function ($d) use ($encounter) {
                    $conditionUuid = $d->condition?->value;
                    if ($conditionUuid) {
                        $actualCondition = \App\Models\MedicalEvents\Sql\Condition::where('uuid', $conditionUuid)->with('code.coding')->first();
                        if (!$actualCondition) {
                            Log::warning('CarePlanCreate: condition not found in local SQL DB, attempting to fetch from eHealth', [
                                'condition_uuid' => $conditionUuid
                            ]);
                            try {
                                $conditionData = EHealth::condition()->getById($this->uuid, $conditionUuid)->getData();
                                \App\Repositories\MedicalEvents\Repository::condition()->store([Arr::toCamelCase($conditionData)], $this->personId);
                                $actualCondition = \App\Models\MedicalEvents\Sql\Condition::where('uuid', $conditionUuid)->with('code.coding')->first();
                            } catch (\Exception $e) {
                                Log::error('CarePlanCreate: failed to fetch condition from eHealth', [
                                    'condition_uuid' => $conditionUuid,
                                    'error' => $e->getMessage()
                                ]);
                            }
                        }

                        if ($actualCondition) {
                            $coding = $actualCondition->code?->coding?->first();
                            if ($coding) {
                                return [
                                    'coding' => [
                                        [
                                            'system' => $coding->system,
                                            'code' => $coding->code
                                        ]
                                    ]
                                ];
                            }
                            Log::warning('CarePlanCreate: condition found but has no coding', [
                                'condition_uuid' => $conditionUuid
                            ]);

                        }
                    }

                    return null;
                })
                ->filter()
                ->toArray();

            foreach ($conditionData as $address) {
                if (!in_array($address, $data['addresses'], true)) {
                    $data['addresses'][] = $address;
                }
            }

            Log::info('CarePlanCreate: resolved addresses', [
                'addresses_count' => count($data['addresses']),
                'addresses' => $data['addresses']
            ]);
        } else {
            Log::warning('CarePlanCreate: encounter not found or ehealth_inserted_at is null', [
                'encounter_uuid' => $this->form->encounter
            ]);
        }

        return $data;
    }

    /**
     * eHealth requires the care plan author (SPECIALIST/DOCTOR) to have an active employee_role
     * whose healthcare_service.providing_condition matches care_plan.terms_of_service.
     *
     * @see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/19686719489/AR+Create+Care+Plan
     */
    protected function authorHasActiveRoleForTermsOfService(Employee $author, string $termsOfService): bool
    {
        return EmployeeRole::query()
            ->where('employee_id', $author->id)
            ->where('status', EmployeeRoleStatus::ACTIVE)
            ->where('is_active', true)
            ->whereHas(
                'healthcareService',
                fn ($query) => $query
                    ->where('providing_condition', $termsOfService)
                    ->where('is_active', true)
            )
            ->exists();
    }

    /**
     * Log author employee, submitted terms_of_service, and matching employee_roles for debugging
     * eHealth "Employee does not have active role that correspond to the submitted terms of service".
     */
    protected function logCarePlanAuthorRoleDebug(?Employee $author, string $termsOfService): void
    {
        if ($author === null) {
            logger()->warning('[CarePlan] terms_of_service author check - no care plan writer employee', [
                'user_id' => Auth::id(),
                'legal_entity_id' => legalEntity()->id,
                'terms_of_service' => $termsOfService,
            ]);

            return;
        }

        $author->loadMissing(['party:id,first_name,last_name,second_name', 'specialities']);

        $roles = EmployeeRole::query()
            ->where('employee_id', $author->id)
            ->where('status', EmployeeRoleStatus::ACTIVE)
            ->where('is_active', true)
            ->with('healthcareService:id,speciality_type,providing_condition,uuid,status')
            ->get();

        $partyRoles = EmployeeRole::query()
            ->whereHas('employee', fn ($query) => $query->where('party_id', $author->partyId))
            ->where('status', EmployeeRoleStatus::ACTIVE)
            ->where('is_active', true)
            ->with(['employee:id,uuid,employee_type,position', 'healthcareService:id,speciality_type,providing_condition'])
            ->get();

        logger()->debug('[CarePlan] terms_of_service author role snapshot', [
            'user_id' => Auth::id(),
            'legal_entity_id' => legalEntity()->id,
            'submitted_terms_of_service' => $termsOfService,
            'selected_author' => [
                'employee_uuid' => $author->uuid,
                'employee_type' => $author->employeeType,
                'position' => $author->position,
                'specialities' => $author->specialities->pluck('speciality')->all(),
                'active_roles_count' => $roles->count(),
                'matching_roles_count' => $roles->filter(
                    fn (EmployeeRole $role) => $role->healthcareService?->providing_condition === $termsOfService
                )->count(),
                'active_roles' => $roles->map(fn (EmployeeRole $role) => [
                    'role_uuid' => $role->uuid,
                    'healthcare_service_uuid' => $role->healthcareService?->uuid,
                    'speciality' => $role->healthcareService?->speciality_type,
                    'providing_condition' => $role->healthcareService?->providing_condition,
                ])->values()->all(),
            ],
            'all_party_employee_roles' => $partyRoles->map(fn (EmployeeRole $role) => [
                'employee_uuid' => $role->employee?->uuid,
                'employee_type' => $role->employee?->employee_type,
                'position' => $role->employee?->position,
                'role_uuid' => $role->uuid,
                'speciality' => $role->healthcareService?->speciality_type,
                'providing_condition' => $role->healthcareService?->providing_condition,
            ])->values()->all(),
        ]);
    }

    public function render()
    {
        return view('livewire.care-plan.care-plan-create');
    }
}
