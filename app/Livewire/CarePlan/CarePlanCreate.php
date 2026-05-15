<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\CarePlan;
use App\Repositories\CarePlanRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Traits\InteractsWithApprovals;
use App\Models\Person\Person;
use App\Models\LegalEntity;
use Livewire\WithFileUploads;

class CarePlanCreate extends BasePatientComponent
{
    use WithFileUploads;
    use InteractsWithApprovals;

    public bool $showSignatureModal = false;
    public string $patientUuid = '';
    public string $conditionUuid = '';
    public string $medicalRecordType = 'CONDITION';
    public ?string $carePlanUuid = null;

    // Care Plan form data
    public array $form = [
        'patient' => '',
        'medicalNumber' => '',
        'author' => '',
        'coAuthors' => [],
        'category' => '',
        'clinicalProtocol' => '',
        'context' => '',
        'title' => '',
        'intent' => 'order',
        'periodStart' => '',
        'periodEnd' => '',
        'encounter' => '',
        'description' => '',
        'note' => '',
        'informWith' => '',
        'termsOfService' => '',
        'episodes' => [],
        'medicalRecords' => [],
        'knedp' => '',
        'keyContainerUpload' => null,
        'password' => '',
    ];


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

    public function mount(LegalEntity $legalEntity, int $personId): void
    {
        $this->personId = $personId;
        parent::mount($legalEntity, $this->personId);

        $person = Person::find($this->personId);
        if ($person) {
            $this->form['patient'] = trim($person->last_name . ' ' . $person->first_name . ' ' . ($person->second_name ?? ''));

            // Load actual authentication methods from eHealth
            try {
                $this->authMethods = EHealth::person()->getAuthMethods($this->uuid)->getData();
            } catch (\Exception $e) {
                Log::warning('CarePlanCreate: failed to load auth methods: ' . $e->getMessage());
                // Fallback to static cases if eHealth fails
                $this->authMethods = collect(\App\Enums\Person\AuthenticationMethod::cases())->map(fn($m) => [
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
            ->map(fn($e) => [
                'uuid' => $e->uuid,
                'label' => 'Взаємодія #' . $e->id . ' (' . ($e->ehealth_inserted_at ? \Carbon\Carbon::parse($e->ehealth_inserted_at)->format('d.m.Y') : '-') . ')',
            ])
            ->toArray();

        // Try to pick encounter from query param (encounterId, internal DB id) or fallback
        $encounterIdParam = request()->query('encounterId');
        $this->conditionUuid = request()->query('conditionUuid', '');
        $encounterUuid = '';

        if ($encounterIdParam) {
            $encounter = \App\Models\MedicalEvents\Sql\Encounter::where('id', $encounterIdParam)
                ->where('person_id', $this->personId)
                ->whereNotNull('ehealth_inserted_at')
                ->first();
            if ($encounter) {
                $encounterUuid = $encounter->uuid;
                $this->form['encounter'] = $encounterUuid;
            }
        }

        // If no encounter was specified, select the latest eHealth-confirmed one
        if (empty($encounterUuid) && !empty($this->availableEncounters)) {
            $encounterUuid = $this->availableEncounters[0]['uuid'];
            $this->form['encounter'] = $encounterUuid;
        }

        if ($encounterUuid) {
            $encounter = \App\Models\MedicalEvents\Sql\Encounter::where('uuid', $encounterUuid)
                ->with(['diagnoses.condition'])
                ->first();
            if ($encounter) {
                // Pre-fill medical number
                $this->form['medical_number'] = (string) $encounter->id;

                // Pre-fill diagnoses for the UI list
                $this->diagnoses = $encounter->diagnoses->map(function($d) {
                    $conditionUuid = $d->condition?->value;
                    $actualCondition = $conditionUuid
                        ? \App\Models\MedicalEvents\Sql\Condition::where('uuid', $conditionUuid)->with('code.coding')->first()
                        : null;

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
        }
        $this->form['periodStart'] = now()->format('d.m.Y');

        // Pre-fill author from current employee
        $employee = Auth::user()?->getCarePlanWriterEmployee();
        if ($employee) {
            $party = $employee->party;
            $this->form['author'] = implode(' ', array_filter([
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
                ->filter(fn($e) => $e->party !== null)
                ->map(fn($e) => [
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
            \Illuminate\Support\Facades\Log::warning('CarePlanCreate: failed to load dictionaries: ' . $exception->getMessage());
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
            ->map(fn($p) => [
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
        $this->form['patient'] = $name;
        $this->patientSuggestions = [];

        $person = \App\Models\Person\Person::where('uuid', $uuid)->first();
        if ($person) {
            try {
                $this->authMethods = EHealth::person()->getAuthMethods($uuid)->getData();
            } catch (\Exception $e) {
                $this->authMethods = collect(\App\Enums\Person\AuthenticationMethod::cases())->map(fn($m) => [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'type' => $m->value,
                    'label' => $m->label(),
                ])->toArray();
            }
        }
    }

    /**
     * Validation rules for the main form data.
     */
    protected function rules(): array
    {
        return [
            'form.category'         => 'required|string',
            'form.clinicalProtocol' => 'nullable|string',
            'form.context'          => 'nullable|string',
            'form.title'            => 'required|string',
            'form.periodStart'      => 'required|string',
            'form.periodEnd'        => 'nullable|string',
            'form.encounter'        => 'nullable|string',
            'form.description'      => 'nullable|string',
            'form.note'             => 'nullable|string',
            'form.informWith'       => 'nullable|string',
            'form.termsOfService'   => 'required|string',
            'form.episodes'         => 'nullable|array',
            'form.medicalRecords'   => 'nullable|array',
        ];
    }

    /**
     * Human-readable attribute names for validation errors.
     */
    protected function validationAttributes(): array
    {
        return [
            'form.category'          => __('care-plan.category'),
            'form.clinicalProtocol'  => __('care-plan.clinical_protocol'),
            'form.context'           => __('care-plan.context'),
            'form.title'             => __('care-plan.name_care_plan'),
            'form.periodStart'       => __('care-plan.date_and_time_start'),
            'form.periodEnd'         => __('care-plan.date_and_time_end'),
            'form.encounter'         => __('care-plan.encounter'),
            'form.description'       => __('care-plan.extended_description'),
            'form.note'              => __('care-plan.notes'),
            'form.informWith'        => __('care-plan.inform_with'),
            'form.termsOfService'    => __('care-plan.terms_of_service') ?? 'Умови надання послуг',
            'form.knedp'                  => __('forms.knedp') ?? 'КНЕДП',
            'form.keyContainerUpload'     => __('forms.key_container') ?? 'Ключ-контейнер',
            'form.password'               => __('forms.password'),
        ];
    }

    /**
     * Handle validation failure: dispatch flash + scroll events.
     */
    protected function handleValidationFailed(ValidationException $exception, bool $closeModal = false): void
    {
        $errors = collect($exception->validator->errors()->toArray())
            ->map(fn ($msgs) => $msgs[0])
            ->values()
            ->toArray();

        $firstKey = array_key_first($exception->validator->errors()->toArray());

        $this->dispatch('flashMessage', [
            'type'    => 'error',
            'message' => __('validation.failed') ?? 'Форма містить помилки',
            'errors'  => $errors,
        ]);

        $this->dispatch('validation-failed-scroll', firstErrorKey: $firstKey);
        $this->setErrorBag($exception->validator->getMessageBag());

        if ($closeModal) {
            $this->showSignatureModal = false;
        }
    }

    /**
     * Additional validation rules needed before KEP signing.
     */
    protected function rulesForSigning(): array
    {
        return array_merge($this->rules(), [
            'form.knedp' => 'required|string',
            'form.keyContainerUpload' => 'required|file|max:1024',
            'form.password' => 'required|string',
        ]);
    }

    /**
     * Watch period_end and show warning per TZ 3.10.1.2.4.
     */
    public function updatedFormPeriodEnd(): void
    {
        if (!empty($this->form['periodEnd'])) {
            $this->dispatch('flashMessage', [
                'type'    => 'error',
                'message' => __('care-plan.period_end_warning'),
                'errors'  => [],
            ]);
        }
    }

    /**
     * Save as a local draft (without sending to eHealth).
     */
    public function save(CarePlanRepository $repository): void
    {
        if (Auth::user()?->cannot('create', CarePlan::class)) {
            $this->dispatch('flashMessage', [
                'type'    => 'error',
                'message' => __('care-plan.no_permission_create'),
                'errors'  => [],
            ]);
            return;
        }

        try {
            $validated = $this->validate($this->rules());
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
            'status' => 'NEW',
            'category' => $validated['form']['category'],
            'clinical_protocol' => $validated['form']['clinicalProtocol'] ?? null,
            'context' => $validated['form']['context'] ?? null,
            'title' => $validated['form']['title'],
            'period_start' => convertToYmd($validated['form']['periodStart']),
            'period_end' => !empty($validated['form']['periodEnd'])
                ? convertToYmd($validated['form']['periodEnd']) : null,
            'encounter_id' => $encounterData['id'],
            'addresses' => $encounterData['addresses'],
            'supporting_info' => [
                'episodes' => $validated['form']['episodes'],
                'medical_records' => $validated['form']['medicalRecords'],
            ],
            'description' => $validated['form']['description'] ?? null,
            'note' => $validated['form']['note'] ?? null,
            'inform_with' => $validated['form']['informWith'] ?? null,
        ]);

        $this->dispatch('flashMessage', [
            'type'    => 'success',
            'message' => __('care-plan.draft_saved') ?? 'План лікування успішно збережено',
            'errors'  => [],
        ]);
        $this->redirectRoute('care-plan.edit', [legalEntity(), $carePlan->id], navigate: true);
    }

    public function updatedFormEncounter($value): void
    {
        if ($value) {
            $encounter = \App\Models\MedicalEvents\Sql\Encounter::where('uuid', $value)->first();
            if ($encounter && empty($this->form['title'])) {
                $date = $encounter->period_start ? $encounter->period_start->format('d.m.Y') : now()->format('d.m.Y');
                $this->form['title'] = 'План лікування від ' . $date;
            }
        }
    }

    public bool $showMethodSelectionModal = false;

    /**
     * Start the signing process by opening the method selection modal.
     */
    public function startSigningProcess(): void
    {
        try {
            $this->validate($this->rules());
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception);
            return;
        }

        $this->showMethodSelectionModal = true;
    }

    /**
     * Handle the selection of an authentication method.
     */
    public function selectAuthMethod(string $methodUuid): void
    {
        $this->form['informWith'] = $methodUuid;
        $this->showMethodSelectionModal = false;

        $authMethod = collect($this->authMethods)->first(fn($m) => ($m['id'] ?? $m['uuid'] ?? null) === $methodUuid);
        $authType = $authMethod['type'] ?? null;

        if ($authType === \App\Enums\Person\AuthenticationMethod::OTP->value) {
            $this->createApproval();
        } else {
            $this->showSignatureModal = true;
        }
    }

    /**
     * Create an approval request for the Care Plan.
     */
    protected function createApproval(): void
    {
        try {
            if (empty($this->carePlanUuid)) {
                $this->carePlanUuid = \Illuminate\Support\Str::uuid()->toString();
            }

            $payload = [
                'granted_resource_id' => $this->carePlanUuid,
                'granted_resource_type' => 'care_plan',
                'granted_to_id' => legalEntity()->uuid,
                'granted_to_type' => 'legal_entity',
                'authorize_with' => $this->form['informWith'] ?: null,
            ];

            $response = EHealth::approval()->createApproval($this->uuid, $payload);
            $responseData = $response->getData();

            if ($response->getStatusCode() === 201 || $response->getStatusCode() === 200) {
                $this->approvalId = $responseData['id'];
                $this->openAuthModal();
            }
        } catch (\Exception $e) {
            Log::error('CarePlanCreate: failed to create approval: ' . $e->getMessage());
            $this->dispatch('flashMessage', [
                'type' => 'error', 
                'message' => 'Не вдалося надіслати SMS підтвердження: ' . $e->getMessage()
            ]);
        }
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
                $this->showSignatureModal = true;
            }
        } catch (\Exception $e) {
            Log::error('CarePlanCreate: failed to verify approval: ' . $e->getMessage());
            $this->addError('verificationCode', 'Невірний код підтвердження або помилка сервісу');
        }
    }

    /**
     * Resend the SMS code.
     */
    public function resendSms(): void
    {
        if ($this->smsResent) {
            return;
        }

        try {
            EHealth::approval()->resendSms($this->uuid, $this->approvalId);
            $this->smsResent = true;
            $this->dispatch('flashMessage', ['type' => 'success', 'message' => 'SMS надіслано повторно']);
        } catch (\Exception $e) {
            Log::error('CarePlanCreate: failed to resend SMS: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Помилка при повторному надсиланні SMS']);
        }
    }

    /**
     * Sign with KEP and send to eHealth.
     */
    public function sign(CarePlanRepository $repository): void
    {
        $this->validate();

        try {
            $legalEntity = legalEntity();
            $encounterData = $this->resolveEncounterData();
            
            $carePlanPayload = $repository->formatCarePlanRequest(
                $this->form, 
                $this->form['encounter'] ?? null, 
                $encounterData, 
                Auth::user()?->getCarePlanWriterEmployee()?->uuid,
                $this->carePlanUuid ?: null
            );

            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($carePlanPayload),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
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
                $errorMsg = 'Помилка валідації від ЕСОЗ';
                if (!empty($finalResponse['error']['invalid'])) {
                    $errorMsg .= ': ' . json_encode(array_map(fn($e) => $e['rules'][0]['description'] ?? $e['entry'], $finalResponse['error']['invalid']), JSON_UNESCAPED_UNICODE);
                }
                throw new \RuntimeException($errorMsg);
            }

            $carePlanUuid = $finalResponse['id'] ?? null;
            $carePlanStatus = $finalResponse['status'] ?? 'active';
            
            if (isset($finalResponse['result']) && !empty($finalResponse['result'])) {
                $entity = is_array($finalResponse['result']) ? ($finalResponse['result'][0] ?? $finalResponse['result']) : $finalResponse['result'];
                $carePlanUuid = $entity['id'] ?? $carePlanUuid;
                $carePlanStatus = $entity['status'] ?? $carePlanStatus;
            }

            // Create local record
            $repository->create([
                'uuid' => $carePlanUuid,
                'person_id' => $this->personId,
                'author_id' => Auth::user()?->getCarePlanWriterEmployee()?->id,
                'legal_entity_id' => $legalEntity?->id,
                'status' => $carePlanStatus,
                'category' => $this->form['category'],
                'title' => $this->form['title'],
                'period_start' => convertToYmd($this->form['periodStart']),
                'period_end' => !empty($this->form['periodEnd']) ? convertToYmd($this->form['periodEnd']) : null,
                'encounter_id' => $encounterData['id'] ?? null,
            ]);

            $this->dispatch('flashMessage', [
                'type'    => 'success',
                'message' => 'План лікування успішно створено. Ви можете синхронізувати список вручну для оновлення UUID.',
                'errors'  => [],
            ]);

            Session::flash('flash_message', 'План лікування успішно створено.');
            
            $this->redirectRoute('persons.care-plans', [legalEntity(), $this->personId], navigate: true);

        } catch (ConnectionException $exception) {
            Log::error('CarePlan: connection error: ' . $exception->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => __('care-plan.connection_error'), 'errors' => []]);
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            Log::error('CarePlan: eHealth error: ' . $exception->getMessage());
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : 'Помилка від ЕСОЗ: ' . $exception->getMessage();
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => $msg, 'errors' => []]);
            $this->showSignatureModal = false;
        } catch (\RuntimeException $exception) {
            Log::error('CarePlan: runtime error: ' . $exception->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => $exception->getMessage(), 'errors' => []]);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlan: unexpected error: ' . $exception->getMessage(), [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => __('care-plan.unexpected_error'), 'errors' => []]);
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
        if (empty($this->form['encounter'])) {
            return $data;
        }

        $encounter = \App\Models\MedicalEvents\Sql\Encounter::where('uuid', $this->form['encounter'])
            ->whereNotNull('ehealth_inserted_at')
            ->with(['diagnoses.condition', 'period'])
            ->first();

        if ($encounter) {
            $data['id'] = $encounter->id;

            // Get the encounter's period start for date validation
            if ($encounter->period) {
                $data['period_start'] = $encounter->period->start;
            }
            
            // Extract the Codeable Concepts of all conditions (addresses for the care plan)
            $conditionData = $encounter->diagnoses
                ->filter(function ($d) {
                    return empty($this->conditionUuid) || ($d->condition?->uuid === $this->conditionUuid);
                })
                ->map(function ($d) {
                    $conditionUuid = $d->condition?->value;
                    if ($conditionUuid) {
                        $actualCondition = \App\Models\MedicalEvents\Sql\Condition::where('uuid', $conditionUuid)->with('code.coding')->first();
                        $coding = $actualCondition?->code?->coding?->first();
                        
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
        }

        return $data;
    }

    public function render()
    {
        return view('livewire.care-plan.care-plan-create');
    }
}
