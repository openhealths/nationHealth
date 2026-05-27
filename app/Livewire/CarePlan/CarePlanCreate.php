<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\CarePlan;
use App\Repositories\CarePlanRepository;
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

    // Care Plan form data
    public CarePlanForm $form;

    public array $categories = [];
    public array $diagnoses = [];
    public array $authMethods = [];
    public array $patientSuggestions = [];
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

    protected function loadPatientData(): void
    {
        if (empty($this->personId)) {
            $this->patientFullName = '';
            $this->verificationStatus = '';
            $this->uuid = '';
            $this->declarationNumber = null;

            return;
        }

        parent::loadPatientData();
    }

    public function mount(LegalEntity $legalEntity, ?int $personId = null, $encounter = null): void
    {
        $encounterModel = null;
        if ($encounter) {
            if ($encounter instanceof \App\Models\MedicalEvents\Sql\Encounter) {
                $encounterModel = $encounter;
            } else {
                $query = \App\Models\MedicalEvents\Sql\Encounter::query();
                if (is_numeric($encounter)) {
                    $query->where('id', (int) $encounter);
                } elseif (\Illuminate\Support\Str::isUuid((string) $encounter)) {
                    $query->where('uuid', $encounter);
                } else {
                    $query->where('id', -1);
                }
                $encounterModel = $query->first();
            }

            if ($encounterModel) {
                $personId = $encounterModel->person_id;
            }
        }

        $this->personId = $personId ?? 0;
        parent::mount($legalEntity, $this->personId);

        $person = Person::find($this->personId);
        if ($person) {
            $this->form->patient = trim($person->last_name . ' ' . $person->first_name . ' ' . ($person->second_name ?? ''));
            $this->form->medical_number = (string) ((CarePlan::max('id') ?? 0) + 1);

            // Load actual authentication methods from eHealth
            try {
                $this->authMethods = EHealth::person()->getAuthMethods($this->uuid)->getData();
            } catch (\Exception $e) {
                Log::warning('CarePlanCreate: failed to load auth methods: ' . $e->getMessage());
                // Fallback to static cases if eHealth fails
                $this->authMethods = collect(\App\Enums\Person\AuthenticationMethod::cases())->map(fn ($m) => [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'type' => $m->value,
                    'label' => $m->label(),
                ])->toArray();
            }
        }

        // Load only encounters that have been confirmed by eHealth (have ehealth_inserted_at)
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

        // Try to pick encounter from query param (encounterId, internal DB id) or fallback to encounter (UUID)
        $encounterIdParam = request()->query('encounterId');
        $encounterUuidParam = request()->query('encounter');
        $this->conditionUuid = request()->query('conditionUuid', '');

        if (!$encounterModel) {
            if ($encounterIdParam && is_numeric($encounterIdParam)) {
                $encounterModel = \App\Models\MedicalEvents\Sql\Encounter::where('id', (int) $encounterIdParam)
                    ->where('person_id', $this->personId)
                    ->first();
            } elseif ($encounterUuidParam && \Illuminate\Support\Str::isUuid((string) $encounterUuidParam)) {
                $encounterModel = \App\Models\MedicalEvents\Sql\Encounter::where('uuid', $encounterUuidParam)
                    ->where('person_id', $this->personId)
                    ->first();
            }
        }

        if ($encounterModel) {
            $this->form->encounter = $encounterModel->uuid;
            $this->form->medical_number = (string) $encounterModel->id;

            // Pre-fill title if empty
            if (empty($this->form->title)) {
                $date = $encounterModel->period?->start ? \Carbon\Carbon::parse($encounterModel->period->start)->format('d.m.Y') : now()->format('d.m.Y');
                $this->form->title = 'План лікування від ' . $date;
            }

            // Ensure it is in availableEncounters so it can be pre-selected in the UI
            $exists = collect($this->availableEncounters)->contains('uuid', $encounterModel->uuid);
            if (!$exists) {
                array_unshift($this->availableEncounters, [
                    'uuid' => $encounterModel->uuid,
                    'label' => 'Взаємодія #' . $encounterModel->id . ' (' . ($encounterModel->ehealth_inserted_at ? \Carbon\Carbon::parse($encounterModel->ehealth_inserted_at)->format('d.m.Y') : 'Локальна') . ')',
                ]);
            }

            // Pre-fill diagnoses for the UI list
            $encounterModel->load(['diagnoses.condition']);
            $this->diagnoses = $encounterModel->diagnoses->map(function ($d) {
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
                            \App\Repositories\MedicalEvents\Repository::condition()->store([Arr::toCamelCase($conditionData)], $this->personId);
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

        // Pre-fill author from current employee
        $employee = Auth::user()?->getCarePlanWriterEmployee();
        if ($employee) {
            $party = $employee->party;
            $this->form->author = implode(' ', array_filter([
                $party?->last_name, $party?->first_name, $party?->second_name,
            ]));
        }

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
     * Search for patients as the user types.
     */
    public function updatedFormPatient(string $value): void
    {
        if (strlen($value) < 3) {
            $this->patientSuggestions = [];

            return;
        }

        $this->patientSuggestions = \App\Models\Person\Person::query()
            ->where('last_name', 'like', "%{$value}%")
            ->orWhere('first_name', 'like', "%{$value}%")
            ->orWhere('tax_id', 'like', "%{$value}%")
            ->limit(5)
            ->get()
            ->map(fn ($p) => [
                'uuid' => $p->uuid,
                'name' => trim($p->last_name . ' ' . $p->first_name . ' ' . ($p->second_name ?? '')),
                'tax_id' => $p->tax_id,
            ])
            ->toArray();
    }

    /**
     * Select a patient from the suggestions.
     */
    public function selectPatient(string $uuid, string $name): void
    {
        $this->patientUuid = $uuid;
        $this->form->patient = $name;
        $this->patientSuggestions = [];

        $person = \App\Models\Person\Person::where('uuid', $uuid)
            ->with(['declarations' => fn ($d) => $d->active()->latest()->take(1)])
            ->first();

        if ($person) {
            $this->personId = $person->id;
            $this->uuid = $uuid;
            $this->patientFullName = $person->fullName;
            $this->verificationStatus = $person->verification_status;
            $this->declarationNumber = $person->declarations->first()?->declarationNumber ?? null;

            $this->form->medical_number = (string) ((CarePlan::max('id') ?? 0) + 1);

            // Load only encounters that have been confirmed by eHealth (have ehealth_inserted_at)
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

            try {
                $this->authMethods = EHealth::person()->getAuthMethods($uuid)->getData();
            } catch (\Exception $e) {
                Log::warning('CarePlanCreate: failed to load auth methods: ' . $e->getMessage());
                $this->authMethods = collect(\App\Enums\Person\AuthenticationMethod::cases())->map(fn ($m) => [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'type' => $m->value,
                    'label' => $m->label(),
                ])->toArray();
            }
        }
    }

    /**
     * Handle validation failure: dispatch flash + scroll events.
     */
    protected function handleValidationFailed(ValidationException $exception, bool $closeModal = false): void
    {
        $firstKey = array_key_first($exception->validator->errors()->toArray());

        session()->flash('error', __('validation.failed') ?? 'Форма містить помилки');

        $this->dispatch('validation-failed-scroll', firstErrorKey: $firstKey);
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
            $this->authMethods = EHealth::person()->getAuthMethods($this->uuid)->getData();
            $this->showMethodSelectionModal = true;
        } catch (\Exception $e) {
            Log::error('CarePlanCreate: failed to load auth methods: ' . $e->getMessage());
            session()->flash('error', 'Не вдалося завантажити методи аутентифікації');
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
                            'value' => $this->carePlanUuid,
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

            $response = EHealth::approval()->createApproval($this->uuid, $payload);
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
                    Session::flash('flash_message', 'План лікування успішно активовано.');
                    $carePlan = CarePlan::where('uuid', $this->carePlanUuid)->first();
                    $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan?->id ?? $this->carePlanUuid], navigate: true);
                }
            }
        } catch (\Exception $e) {
            Log::error('CarePlanCreate: failed to create approval: ' . $e->getMessage());
            session()->flash('error', 'Не вдалося створити запит на дозвіл: ' . $e->getMessage());
        }
    }

    /**
     * Save as a local draft (without sending to eHealth).
     */
    public function save(CarePlanRepository $repository): void
    {
        if (Auth::user()?->cannot('create', CarePlan::class)) {
            session()->flash('error', __('care-plan.no_permission_create'));

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
            'author_id' => Auth::user()?->getCarePlanWriterEmployee()?->id,
            'legal_entity_id' => $legalEntity?->id,
            'status' => CarePlanStatus::DRAFT->value,
            'category' => $this->form->category,
            'clinical_protocol' => $this->form->clinicalProtocol ?: null,
            'context' => $this->form->context ?: null,
            'title' => $this->form->title,
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
        ]);

        session()->flash('success', __('care-plan.draft_saved') ?? 'План лікування успішно збережено');
        $this->redirectRoute('care-plans.edit', [legalEntity(), $carePlan->id], navigate: true);
    }

    public function delete(CarePlanRepository $repository): void
    {
        if (isset($this->carePlan) && $this->carePlan->exists) {
            if ($this->carePlan->status === 'draft') {
                $this->carePlan->delete();
                session()->flash('success', 'Чернетку плану лікування успішно видалено.');
            } else {
                session()->flash('error', 'Можна видаляти лише чернетки планів лікування.');
            }
        }
        $this->redirectRoute('persons.care-plans', [legalEntity(), $this->personId], navigate: true);
    }

    public function updatedFormEncounter($value): void
    {
        if ($value) {
            $encounter = \App\Models\MedicalEvents\Sql\Encounter::where('uuid', $value)->with(['diagnoses.condition'])->first();
            if ($encounter) {
                if (empty($this->form->title)) {
                    $date = $encounter->period?->start ? \Carbon\Carbon::parse($encounter->period->start)->format('d.m.Y') : now()->format('d.m.Y');
                    $this->form->title = 'План лікування від ' . $date;
                }

                // Pre-fill diagnoses for the UI list
                $this->diagnoses = $encounter->diagnoses->map(function ($d) {
                    $conditionUuid = $d->condition?->value;
                    $actualCondition = null;
                    if ($conditionUuid) {
                        $actualCondition = \App\Models\MedicalEvents\Sql\Condition::where('uuid', $conditionUuid)->with('code.coding')->first();
                        if (!$actualCondition) {
                            Log::info('CarePlanCreate updatedFormEncounter: condition not found in local SQL DB, attempting to fetch from eHealth', [
                                'condition_uuid' => $conditionUuid
                            ]);
                            try {
                                $conditionData = EHealth::condition()->getById($this->uuid, $conditionUuid)->getData();
                                \App\Repositories\MedicalEvents\Repository::condition()->store([Arr::toCamelCase($conditionData)], $this->personId);
                                $actualCondition = \App\Models\MedicalEvents\Sql\Condition::where('uuid', $conditionUuid)->with('code.coding')->first();
                            } catch (\Exception $e) {
                                Log::error('CarePlanCreate updatedFormEncounter: failed to fetch condition from eHealth', [
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
        } else {
            $this->diagnoses = [];
        }
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
     * Verify the SMS code.
     */
    public function verify(): void
    {
        $this->validate($this->approvalVerificationRules());

        try {
            $response = EHealth::approval()->verify($this->uuid, $this->approvalId, [
                'code' => (int) $this->verificationCode,
            ]);

            if ($response->getStatusCode() === 200) {
                $this->closeAuthModal();
                Session::flash('flash_message', 'План лікування успішно активовано.');
                $carePlan = CarePlan::where('uuid', $this->carePlanUuid)->first();
                $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan?->id ?? $this->carePlanUuid], navigate: true);
            }
        } catch (EHealthValidationException $e) {
            Log::error('CarePlanCreate: failed to verify approval (validation): ' . $e->getFormattedMessage(), $e->getDetails());
            $this->addError('verificationCode', $e->getFormattedMessage());
        } catch (\Exception $e) {
            Log::error('CarePlanCreate: failed to verify approval: ' . $e->getMessage());
            $this->addError('verificationCode', 'Невірний код підтвердження або помилка сервісу: ' . $e->getMessage());
        }
    }

    public function resendSms(): void
    {
        if ($this->smsResent) {
            return;
        }

        try {
            EHealth::approval()->resendSms($this->uuid, $this->approvalId);
            $this->smsResent = true;
            session()->flash('success', 'SMS надіслано повторно');
        } catch (\Exception $e) {
            Log::error('CarePlanCreate: failed to resend SMS: ' . $e->getMessage());
            $this->addError('verificationCode', 'Не вдалося повторно надіслати SMS: ' . $e->getMessage());
        }
    }

    /**
     * Sign with KEP and send to eHealth.
     */
    public function sign(CarePlanRepository $repository): void
    {
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

            $carePlanPayload = $repository->formatCarePlanRequest(
                $this->form->toArray(),
                $this->form->encounter ?: null,
                $encounterData,
                Auth::user()?->getCarePlanWriterEmployee()?->uuid,
                $this->carePlanUuid ?: null
            );

            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($carePlanPayload),
                $this->form->password,
                $this->form->knedp,
                $this->form->keyContainerUpload,
                Auth::user()->party->taxId
            );

            $response = EHealth::carePlan()->create($this->uuid, [
                'signed_data' => $signedContent,
                'signed_data_encoding' => 'base64',
            ]);

            $jobId = $response->getData()['job_id'] ?? null;
            if (!$jobId && isset($response->getData()['links'][0]['href'])) {
                $jobId = basename($response->getData()['links'][0]['href']);
            }

            $jobApi = EHealth::job();
            $attempts = 0;
            do {
                sleep(2);
                $finalResponse = $jobApi->getDetails($jobId)->getData();
                $attempts++;
            } while (($finalResponse['status'] === 'pending' || $finalResponse['status'] === 'accepted') && $attempts < 15);

            if ($finalResponse['status'] !== 'processed' && $finalResponse['status'] !== 'active') {
                Log::error('CarePlanCreate eHealth job failed:', $finalResponse);
                $errorMsg = 'Помилка валідації від ЕСОЗ';
                if (!empty($finalResponse['error']['invalid'])) {
                    $errorMsg .= ': ' . json_encode(array_map(fn ($e) => ($e['entry'] ?? '') . ': ' . ($e['rules'][0]['description'] ?? ''), $finalResponse['error']['invalid']), JSON_UNESCAPED_UNICODE);
                }
                throw new \RuntimeException($errorMsg);
            }

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
                'author_id' => Auth::user()?->getCarePlanWriterEmployee()?->id,
                'legal_entity_id' => $legalEntity?->id,
                'status' => $carePlanStatus,
                'category' => $this->form->category,
                'title' => $this->form->title,
                'period_start' => convertToYmd($this->form->periodStart),
                'period_end' => !empty($this->form->periodEnd) ? convertToYmd($this->form->periodEnd) : null,
                'encounter_id' => $encounterData['id'] ?? null,
            ]);

            // Sync with eHealth to fetch the official full record (including category codes, periods, and status)
            if ($carePlanUuid) {
                try {
                    $detailsResponse = EHealth::carePlan()->getDetails($this->patientUuid ?: $this->uuid, $carePlanUuid);
                    $repository->syncCarePlans($detailsResponse->validate(), $this->personId);
                    $carePlan = $repository->findByUuid($carePlanUuid) ?? $carePlan;
                } catch (\Throwable $e) {
                    Log::warning('CarePlanCreate: failed to sync newly created care plan from eHealth: ' . $e->getMessage());
                }
            }

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
                $this->showAuthModal = true;
                session()->flash('success', 'План успішно створено. Пацієнту надіслано SMS для активації.');

                return;
            }

            Session::flash('flash_message', 'План лікування успішно створено.');
            $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan->id], navigate: true);

        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlan: connection error: ' . $exception->getMessage());
            session()->flash('error', __('care-plan.connection_error'));
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            Log::error('CarePlan: eHealth error: ' . $exception->getMessage());
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : 'Помилка від ЕСОЗ: ' . $exception->getMessage();
            session()->flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (\RuntimeException $exception) {
            Log::error('CarePlan: runtime error: ' . $exception->getMessage());
            session()->flash('error', $exception->getMessage());
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlan: unexpected error: ' . $exception->getMessage(), [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
            session()->flash('error', __('care-plan.unexpected_error'));
            $this->showSignatureModal = false;
        }
    }

    /**
     * Initialize the component.
     */
    protected function initializeComponent(): void
    {
        // Handled by BasePatientComponent for id, uuid, patientFullName
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

            // Get the encounter's period start for date validation
            if ($encounter->period) {
                $data['period_start'] = $encounter->period->start;
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

    public function render()
    {
        return view('livewire.care-plan.care-plan-create');
    }
}
