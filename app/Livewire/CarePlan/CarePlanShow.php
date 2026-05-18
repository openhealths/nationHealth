<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;
 
use App\Traits\InteractsWithApprovals;
use App\Enums\CarePlanStatus;
use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Repositories\CarePlanRepository;
use App\Repositories\CarePlanActivityRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

class CarePlanShow extends Component
{
    use WithFileUploads;
    use InteractsWithApprovals;

    public CarePlan $carePlan;

    public bool $showSignatureModal = false;
    public string $actionType = ''; // 'cancel', 'complete', 'sign_activity', 'complete_activity', 'cancel_activity'
    public string $statusReason = ''; // Used when cancelling or completing
    public ?int $activityToSign = null;
    public array $dictionaries = [];
    public array $authMethods = [];
    public bool $showMethodSelectionModal = false;
    public ?string $carePlanUuid = null;

    // Drawer visibility controls (entangled with Alpine)
    public bool $showServiceDrawer = false;
    public bool $showServiceSearchDrawer = false;
    public bool $showMedicationDrawer = false;
    public bool $showMedicationSearchDrawer = false;
    public bool $showMedicationFormDrawer = false;
    public bool $showMedicalDeviceDrawer = false;
    public bool $showMedicalDeviceSearchDrawer = false;
    public bool $showMedicalDeviceFormDrawer = false;

    // Search and selection parameters
    public string $searchQuery = '';
    public array $searchResults = [];
    public int $searchPage = 1;
    public ?array $selectedProduct = null;
    public string $selectedProgram = '';

    // Linked justification references (grounds)
    public array $linkedGrounds = [];
    public array $availableReports = [];
    public array $availableObservations = [];

    // Activity Form state
    public array $activityForm = [
        'id' => null,
        'kind' => 'service_request',
        'program' => '',
        'quantity' => '',
        'quantity_system' => '',
        'quantity_code' => '',
        'daily_amount' => '',
        'reason_code' => '',
        'reason_reference' => '',
        'goal' => '',
        'description' => '',
        'scheduled_period_start' => '',
        'scheduled_period_end' => '',
        'product_reference' => '',
        'product_codeable_concept' => '',
    ];

    public array $form = [
        'knedp' => '',
        'keyContainerUpload' => null,
        'password' => '',
    ];

    public string $outcomeCode = ''; // For outcomeCodeableConcept
    public array $outcomeReferences = []; // For outcomeReference (IDs of identifiers)

    public array $availableConditions = [];

    public function mount(CarePlan $carePlan): void
    {
        $this->carePlan = $carePlan;
        
        // Fetch patient conditions for outcomeReference selection
        $this->availableConditions = \App\Models\MedicalEvents\Sql\Condition::whereHas('context', function ($query) {
            $query->where('person_id', $this->carePlan->person_id);
        })->with('code.coding')->get()->map(fn($c) => [
            'uuid' => $c->uuid,
            'name' => ($c->code?->text ?: null) ?? ($c->code?->coding?->first()?->code ?: null) ?? 'Unknown Condition',
            'date' => $c->onset_date ? \Carbon\Carbon::parse($c->onset_date)->format('d.m.Y') : '-',
        ])->toArray();

        // Fetch patient diagnostic reports for justifications (grounds)
        $this->availableReports = \App\Models\MedicalEvents\Sql\DiagnosticReport::where('person_id', $this->carePlan->person_id)
            ->get()->map(fn($dr) => [
                'uuid' => $dr->uuid,
                'name' => $dr->code?->text ?: 'Diagnostic Report',
                'date' => $dr->issued ? \Carbon\Carbon::parse($dr->issued)->format('d.m.Y') : '-',
            ])->toArray();

        // Fetch patient observations for justifications (grounds)
        $this->availableObservations = \App\Models\MedicalEvents\Sql\Observation::where('person_id', $this->carePlan->person_id)
            ->get()->map(fn($obs) => [
                'uuid' => $obs->uuid,
                'name' => $obs->code?->text ?: 'Observation',
                'date' => $obs->issued ? \Carbon\Carbon::parse($obs->issued)->format('d.m.Y') : '-',
            ])->toArray();

        try {
            $basics = app(\App\Services\Dictionary\DictionaryManager::class)->basics();
            $this->dictionaries['care_plan_categories'] = $basics->byName('eHealth/care_plan_categories')
                ?->asCodeDescription()
                ?->toArray() ?? [];
            
            $this->dictionaries['care_plan_activity_outcomes'] = $basics->byName('eHealth/care_plan_activity_outcomes')
                ?->asCodeDescription()
                ?->toArray() ?? [];

            $this->dictionaries['care_plan_cancel_reasons'] = $basics->byName('eHealth/care_plan_cancel_reasons')
                ?->asCodeDescription()
                ?->toArray() ?? [];

            // Load medical programs
            $this->dictionaries['medical_programs'] = app(\App\Services\Dictionary\DictionaryManager::class)
                ->medicalPrograms()
                ->pluck('name', 'id')
                ->toArray() ?? [];
        } catch (\Exception $exception) {
            Log::warning('CarePlanShow: failed to load dictionaries: ' . $exception->getMessage());
        }

        $this->carePlanUuid = $this->carePlan->uuid;
        $this->patientId = $this->carePlan->person->uuid;
    }

    protected function rulesForSigning(): array
    {
        $statusReasonRule = in_array($this->actionType, ['sign_activity', 'sign_plan']) 
            ? 'nullable|string' 
            : 'required|string';

        return [
            'statusReason' => $statusReasonRule,
            'form.knedp' => 'required|string',
            'form.keyContainerUpload' => 'required|file|max:1024',
            'form.password' => 'required|string',
        ];
    }

    public function openSignatureModal(string $actionType, ?int $activityId = null): void
    {
        $this->actionType = $actionType;
        $this->activityToSign = $activityId;
        $this->statusReason = ''; // Reset reason
        $this->outcomeCode = ''; // Reset outcome
        $this->outcomeReferences = []; // Reset references
        $this->showSignatureModal = true;
    }

    public function initActivityForm(string $kind): void
    {
        $this->activityForm = [
            'id' => null,
            'kind' => $kind,
            'program' => '',
            'quantity' => '',
            'quantity_system' => '',
            'quantity_code' => '',
            'daily_amount' => '',
            'reason_code' => '',
            'reason_reference' => '',
            'goal' => '',
            'description' => '',
            'scheduled_period_start' => now()->format('d.m.Y'),
            'scheduled_period_end' => '',
            'product_reference' => '',
            'product_codeable_concept' => '',
        ];
    }

    public function editActivity(int $activityId, CarePlanActivityRepository $repository): void
    {
        $activity = $repository->findById($activityId);
        if (!$activity) return;

        $this->activityForm = [
            'id' => $activity->id,
            'kind' => is_array($activity->kind) ? ($activity->kind['coding'][0]['code'] ?? ($activity->kind['text'] ?? '')) : ($activity->kindConcept?->coding?->first()?->code ?? $activity->kind),
            'program' => $activity->program ?? '',
            'quantity' => is_array($activity->quantity) ? ($activity->quantity['value'] ?? '') : $activity->quantity,
            'quantity_system' => is_array($activity->quantity) ? ($activity->quantity['unit'] ?? '') : $activity->quantity_system,
            'quantity_code' => $activity->quantity_code ?? '',
            'daily_amount' => $activity->daily_amount ?? '',
            'reason_code' => $activity->reason_code ?? '',
            'reason_reference' => $activity->reason_reference ?? '',
            'goal' => $activity->goal ?? '',
            'description' => $activity->description ?? '',
            'scheduled_period_start' => $activity->scheduled_period_start?->format('d.m.Y') ?? '',
            'scheduled_period_end' => $activity->scheduled_period_end?->format('d.m.Y') ?? '',
            'product_reference' => $activity->product_reference ?? '',
            'product_codeable_concept' => $activity->product_codeable_concept ?? '',
        ];

        // Load pre-selected product info
        $this->selectedProduct = null;
        if (!empty($activity->product_reference)) {
            try {
                $kindLower = strtolower($this->activityForm['kind']);
                if (str_contains($kindLower, 'service')) {
                    $response = EHealth::service()->getMany(['code' => $activity->product_reference]);
                    $data = $response->getData();
                    if (!empty($data['data'])) {
                        $this->selectedProduct = $data['data'][0];
                    }
                } elseif (str_contains($kindLower, 'medication')) {
                    $response = EHealth::drug()->getMany(['innm_id' => $activity->product_reference]);
                    $data = $response->getData();
                    if (!empty($data['data'])) {
                        $this->selectedProduct = $data['data'][0];
                    }
                } elseif (str_contains($kindLower, 'device')) {
                    $response = EHealth::deviceDefinition()->getMany(['classification_type_code' => $activity->product_reference]);
                    $data = $response->getData();
                    if (!empty($data['data'])) {
                        $this->selectedProduct = $data['data'][0];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('CarePlanShow: failed to preload product reference: ' . $e->getMessage());
            }
        }

        // Initialize linked justification grounds
        $this->linkedGrounds = [];
        if (!empty($activity->reason_reference)) {
            foreach ($activity->reason_reference as $ref) {
                $parts = explode('/', $ref);
                if (count($parts) === 2) {
                    $this->addLinkedGround($parts[0], $parts[1]);
                } else {
                    $uuid = $ref;
                    if (collect($this->availableConditions)->contains('uuid', $uuid)) {
                        $this->addLinkedGround('Condition', $uuid);
                    } elseif (collect($this->availableReports)->contains('uuid', $uuid)) {
                        $this->addLinkedGround('DiagnosticReport', $uuid);
                    } elseif (collect($this->availableObservations)->contains('uuid', $uuid)) {
                        $this->addLinkedGround('Observation', $uuid);
                    } else {
                        $this->addLinkedGround('Condition', $uuid);
                    }
                }
            }
        }

        $kindLower = strtolower($this->activityForm['kind']);
        if (str_contains($kindLower, 'service')) {
            $this->showServiceDrawer = true;
        } elseif (str_contains($kindLower, 'medication')) {
            $this->showMedicationFormDrawer = true;
        } elseif (str_contains($kindLower, 'device')) {
            $this->showMedicalDeviceFormDrawer = true;
        } else {
            $this->showServiceDrawer = true;
        }
    }

    public function saveActivity(CarePlanActivityRepository $repository): void
    {
        $rules = [
            'activityForm.kind' => 'required|string',
            'activityForm.scheduled_period_start' => 'required|string',
            'activityForm.quantity' => 'nullable|numeric',
            'activityForm.quantity_system' => 'nullable|string',
            'activityForm.quantity_code' => 'nullable|string',
            'activityForm.daily_amount' => 'nullable|numeric',
            'activityForm.description' => 'nullable|string',
            'activityForm.product_reference' => 'nullable|string',
            'activityForm.program' => 'nullable|string',
            'activityForm.reason_code' => 'nullable|string',
        ];

        // Apply strict validation for device request positive integer quantities
        $kindLower = strtolower($this->activityForm['kind']);
        if (str_contains($kindLower, 'device')) {
            $rules['activityForm.quantity'] = 'required|integer|min:1';
        }

        try {
            $validated = $this->validate($rules);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            return;
        }

        // Compile reason reference identifiers from linked justifications
        $reasonReferences = collect($this->linkedGrounds)->map(fn($g) => $g['type'] . '/' . $g['uuid'])->toArray();

        $activityData = [
            'kind' => $validated['activityForm']['kind'],
            'quantity' => !empty($validated['activityForm']['quantity']) ? $validated['activityForm']['quantity'] : null,
            'quantity_system' => !empty($validated['activityForm']['quantity_system']) ? $validated['activityForm']['quantity_system'] : null,
            'quantity_code' => !empty($validated['activityForm']['quantity_code']) ? $validated['activityForm']['quantity_code'] : null,
            'daily_amount' => !empty($validated['activityForm']['daily_amount']) ? $validated['activityForm']['daily_amount'] : null,
            'description' => !empty($validated['activityForm']['description']) ? $validated['activityForm']['description'] : null,
            'product_reference' => !empty($validated['activityForm']['product_reference']) ? $validated['activityForm']['product_reference'] : null,
            'program' => !empty($validated['activityForm']['program']) ? $validated['activityForm']['program'] : null,
            'reason_code' => !empty($validated['activityForm']['reason_code']) ? $validated['activityForm']['reason_code'] : null,
            'reason_reference' => !empty($reasonReferences) ? $reasonReferences : null,
            'scheduled_period_start' => convertToYmd($validated['activityForm']['scheduled_period_start']),
            'scheduled_period_end' => !empty($this->activityForm['scheduled_period_end'])
                ? convertToYmd($this->activityForm['scheduled_period_end']) : null,
        ];

        if (!empty($this->activityForm['id'])) {
            $repository->updateById($this->activityForm['id'], $activityData);
            Session::flash('success', __('care-plan.activity_updated'));
        } else {
            $activityData['care_plan_id'] = $this->carePlan->id;
            $activityData['author_id'] = Auth::user()?->activeEmployee()?->id;
            $activityData['status'] = CarePlanStatus::DRAFT->value;

            $repository->create($activityData);
            Session::flash('success', __('care-plan.activity_draft_saved'));
        }

        $this->carePlan->refresh();

        // Close drawers
        $this->dispatch('close-drawers');
    }

    public function searchServices(): void
    {
        if (empty($this->searchQuery)) {
            $this->searchResults = [];
            return;
        }

        try {
            $response = EHealth::service()->getMany([
                'name' => $this->searchQuery,
                'page' => $this->searchPage,
                'page_size' => 15,
            ]);

            $this->searchResults = $response->getData()['data'] ?? [];
        } catch (\Exception $e) {
            Log::error("Failed to search services: " . $e->getMessage());
            $this->searchResults = [];
        }
    }

    public function searchMedications(): void
    {
        if (empty($this->searchQuery)) {
            $this->searchResults = [];
            return;
        }

        try {
            $filters = [
                'innm_name' => $this->searchQuery,
                'page' => $this->searchPage,
                'page_size' => 15,
            ];

            if (!empty($this->selectedProgram)) {
                $filters['medical_program_id'] = $this->selectedProgram;
            }

            $response = EHealth::drug()->getMany($filters);

            $this->searchResults = $response->getData()['data'] ?? [];
        } catch (\Exception $e) {
            Log::error("Failed to search medications: " . $e->getMessage());
            $this->searchResults = [];
        }
    }

    public function searchMedicalDevices(): void
    {
        if (empty($this->searchQuery)) {
            $this->searchResults = [];
            return;
        }

        try {
            $filters = [
                'name' => $this->searchQuery,
                'page' => $this->searchPage,
                'page_size' => 15,
            ];

            if (!empty($this->selectedProgram)) {
                $filters['medical_program_id'] = $this->selectedProgram;
            }

            $response = EHealth::deviceDefinition()->getMany($filters);

            $this->searchResults = $response->getData()['data'] ?? [];
        } catch (\Exception $e) {
            Log::error("Failed to search medical devices: " . $e->getMessage());
            $this->searchResults = [];
        }
    }

    public function selectProduct(array $product, string $kind): void
    {
        $this->selectedProduct = $product;
        $this->activityForm['product_reference'] = $product['id'] ?? $product['uuid'] ?? $product['code'] ?? '';

        if ($kind === 'service_request') {
            $this->activityForm['product_codeable_concept'] = $product['code'] ?? '';
            $this->activityForm['quantity_system'] = 'SERVICE_UNIT';
            $this->activityForm['quantity_code'] = 'units';
            $this->showServiceSearchDrawer = false;
            $this->showServiceDrawer = true;
        } elseif ($kind === 'medication_request') {
            $this->activityForm['quantity_system'] = 'MEDICATION_UNIT';
            $this->activityForm['quantity_code'] = $product['innm_dosage_form'] ?? 'ml';
            $this->activityForm['program'] = $this->selectedProgram;
            $this->showMedicationSearchDrawer = false;
            $this->showMedicationFormDrawer = true;
        } elseif ($kind === 'device_request') {
            $this->activityForm['quantity_system'] = 'device_unit';
            $this->activityForm['quantity_code'] = 'units';
            $this->activityForm['program'] = $this->selectedProgram;
            $this->showMedicalDeviceSearchDrawer = false;
            $this->showMedicalDeviceFormDrawer = true;
        }
    }

    public function addLinkedGround(string $type, string $uuid): void
    {
        $exists = collect($this->linkedGrounds)->contains('uuid', $uuid);
        if ($exists) {
            return;
        }

        $name = 'Unknown Record';
        $date = '-';
        if ($type === 'Condition') {
            $item = collect($this->availableConditions)->firstWhere('uuid', $uuid);
            if ($item) {
                $name = $item['name'];
                $date = $item['date'];
            }
        } elseif ($type === 'DiagnosticReport') {
            $item = collect($this->availableReports)->firstWhere('uuid', $uuid);
            if ($item) {
                $name = $item['name'];
                $date = $item['date'];
            }
        } elseif ($type === 'Observation') {
            $item = collect($this->availableObservations)->firstWhere('uuid', $uuid);
            if ($item) {
                $name = $item['name'];
                $date = $item['date'];
            }
        }

        $this->linkedGrounds[] = [
            'type' => $type,
            'uuid' => $uuid,
            'name' => $name,
            'date' => $date,
        ];
    }

    public function removeLinkedGround(string $uuid): void
    {
        $this->linkedGrounds = collect($this->linkedGrounds)
            ->filter(fn($g) => $g['uuid'] !== $uuid)
            ->values()
            ->toArray();
    }

    public function sign(CarePlanRepository $repository, CarePlanActivityRepository $activityRepository): void
    {
        try {
            $validated = $this->validate($this->rulesForSigning());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());
            $this->showSignatureModal = false;
            return;
        }

        if ($this->actionType === 'sign_activity') {
            $this->signActivity($repository, $activityRepository);
            return;
        }

        if (in_array($this->actionType, ['complete_activity', 'cancel_activity'])) {
            $this->signStatusActivity($activityRepository);
            return;
        }

        if (empty($this->carePlan->uuid)) {
            if ($this->actionType === 'sign_plan') {
                $this->signPlan($repository);
                return;
            }
            Session::flash('error', __('care-plan.care_plan_not_synced'));
            $this->showSignatureModal = false;
            return;
        }

        // Action-specific payload
        $statusMap = [
            'cancel' => CarePlanStatus::ENTERED_IN_ERROR->value,
            'complete' => CarePlanStatus::COMPLETED->value,
        ];

        $payload = [
            'status' => $statusMap[$this->actionType] ?? 'cancelled',
            'status_reason' => $this->statusReason,
        ];

        try {
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($payload),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            // Send to eHealth based on action type
            $apiMethod = $this->actionType === 'complete' ? 'complete' : 'cancel';
            
            $eHealthResponse = EHealth::carePlan()->{$apiMethod}(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                [
                    'signed_data'          => $signedContent,
                    'signed_data_encoding' => 'base64',
                ]
            );

            $responseData = $eHealthResponse->getData();
            $finalResponse = $responseData;

            // Job Polling
            if (isset($responseData['links'][0]['href']) && str_contains($responseData['links'][0]['href'], '/jobs/')) {
                $jobId = str_replace('/jobs/', '', $responseData['links'][0]['href']);
                $jobApi = new \App\Classes\eHealth\Api\Job();
                $attempts = 0;
                do {
                    sleep(2);
                    $finalResponse = $jobApi->getDetails($jobId)->getData();
                    $attempts++;
                } while ($finalResponse['status'] === 'pending' && $attempts < 15);
            }

            // Extract status
            $carePlanStatus = $finalResponse['status'] ?? $payload['status'];
            if (isset($finalResponse['result']) && is_array($finalResponse['result'])) {
                $entity = $finalResponse['result'][0] ?? $finalResponse['result'];
                $carePlanStatus = $entity['status'] ?? $carePlanStatus;
            }

            // Update local state
            $repository->updateById($this->carePlan->id, [
                'status' => $carePlanStatus,
            ]);

            $this->carePlan->refresh();

            Session::flash('success', __('care-plan.care_plan_updated'));
            $this->showSignatureModal = false;

        } catch (ConnectionException $exception) {
            Log::error('CarePlanShow: connection error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.connection_error'));
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            if (method_exists($exception, 'report')) {
                $exception->report();
            }
            Log::error('CarePlanShow: eHealth error: ' . $exception->getMessage());
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : __('care-plan.ehealth_error_prefix') . $exception->getMessage();
            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanShow: unexpected error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.unexpected_error'));
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
            'instantiates_protocol' => $this->carePlan->clinical_protocol ? [['display' => $this->carePlan->clinical_protocol]] : null,
            'context' => $this->carePlan->context ? ['identifier' => ['type_code' => $this->carePlan->context]] : null,
            'title' => $this->carePlan->title,
            'period' => array_filter([
                'start' => $this->carePlan->period_start ? $this->carePlan->period_start->format('Y-m-d') : null,
                'end' => $this->carePlan->period_end ? $this->carePlan->period_end->format('Y-m-d') : null,
            ]),
            'addresses' => $this->carePlan->addresses, // Already stored as array of diagnoses
            'supporting_info' => array_merge(
                array_map(fn($e) => ['display' => $e['name']], $this->carePlan->supporting_info['episodes'] ?? []),
                array_map(fn($m) => ['display' => $m['name']], $this->carePlan->supporting_info['medical_records'] ?? [])
            ),
            'encounter' => $this->carePlan->encounter?->uuid ? ['identifier' => ['value' => $this->carePlan->encounter->uuid]] : null,
            'care_manager' => ['identifier' => ['value' => Auth::user()?->activeEmployee()?->uuid]],
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

            $eHealthResponse = EHealth::carePlan()->create($this->carePlan->person->uuid, [
                'signed_data' => $signedContent,
                'signed_data_encoding' => 'base64',
            ]);

            $responseData = $eHealthResponse->getData();

            // Update local model
            $repository->updateById($this->carePlan->id, [
                'uuid' => $responseData['id'] ?? null,
                'status' => $responseData['status'] ?? 'new',
                'requisition' => $responseData['requisition'] ?? null,
            ]);

            $this->carePlan->refresh();

            Session::flash('success', __('care-plan.signed_and_sent'));
            $this->showSignatureModal = false;

        } catch (ConnectionException $exception) {
            Log::error('CarePlanShow: connection error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.connection_error'));
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            if (method_exists($exception, 'report')) {
                $exception->report();
            }
            Log::error('CarePlanShow: eHealth error: ' . $exception->getMessage());
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : __('care-plan.ehealth_error_prefix') . $exception->getMessage();
            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanShow: unexpected error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.unexpected_error'));
            $this->showSignatureModal = false;
        }
    }

    private function signActivity(CarePlanRepository $repository, CarePlanActivityRepository $activityRepository): void
    {
        if (!$this->activityToSign) {
            Session::flash('error', __('care-plan.no_activity_selected'));
            $this->showSignatureModal = false;
            return;
        }

        $activity = $activityRepository->findById($this->activityToSign);
        if (!$activity) {
            Session::flash('error', __('care-plan.activity_not_found'));
            $this->showSignatureModal = false;
            return;
        }

        // Build Payload
        $activityPayload = $activityRepository->formatCarePlanActivityRequest($activity);

        try {
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($activityPayload),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            $eHealthResponse = EHealth::carePlanActivity()->create(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                [
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64',
                ]
            );

            $responseData = $eHealthResponse->getData();
            $finalResponse = $responseData;

            // If it is an async job, poll it
            if (isset($responseData['links'][0]['href']) && str_contains($responseData['links'][0]['href'], '/jobs/')) {
                $jobId = str_replace('/jobs/', '', $responseData['links'][0]['href']);
                $jobApi = new \App\Classes\eHealth\Api\Job();
                $attempts = 0;
                do {
                    sleep(2);
                    $finalResponse = $jobApi->getDetails($jobId)->getData();
                    $attempts++;
                } while ($finalResponse['status'] === 'pending' && $attempts < 15);
            }

            // Extract the actual CarePlanActivity data
            $activityUuid = $finalResponse['id'] ?? null;
            $activityStatus = $finalResponse['status'] ?? 'new';
            
            if (isset($finalResponse['result']) && is_array($finalResponse['result'])) {
                $entity = $finalResponse['result'][0] ?? $finalResponse['result'];
                $activityUuid = $entity['id'] ?? $activityUuid;
                $activityStatus = $entity['status'] ?? 'active';
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
                $planResponse = EHealth::carePlan()->getBySearchParams($this->carePlan->person->uuid, ['id' => $this->carePlan->uuid]);
                $repository->syncCarePlans($planResponse->getData(), $this->carePlan->person_id);
            } catch (\Exception $e) {
                Log::warning('CarePlanShow: failed to sync plan status after activity: ' . $e->getMessage());
            }

            $this->carePlan->refresh();
            Session::flash('success', __('care-plan.activity_signed'));
            $this->showSignatureModal = false;

        } catch (ConnectionException $exception) {
            Log::error('CarePlanActivity: connection error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.connection_error'));
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            if (method_exists($exception, 'report')) {
                $exception->report();
            }
            Log::error('CarePlanActivity: eHealth error: ' . $exception->getMessage());
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : __('care-plan.ehealth_error_prefix') . $exception->getMessage();
            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanActivity: unexpected error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.unexpected_error'));
            $this->showSignatureModal = false;
        }
    }

    private function signStatusActivity(CarePlanActivityRepository $activityRepository): void
    {
        if (!$this->activityToSign) {
            Session::flash('error', __('care-plan.no_activity_selected'));
            $this->showSignatureModal = false;
            return;
        }

        $activity = $activityRepository->findById($this->activityToSign);
        if (!$activity) return;

        $payload = [
            'status' => $statusMap[$this->actionType] ?? 'cancelled',
            'status_reason' => $this->statusReason,
        ];

        if ($this->actionType === 'complete_activity') {
            if ($this->outcomeCode) {
                $payload['outcome_codeable_concept'] = [
                    'coding' => [
                        [
                            'system' => 'eHealth/care_plan_activity_outcomes',
                            'code' => $this->outcomeCode,
                        ]
                    ]
                ];
            }
            
            if (!empty($this->outcomeReferences)) {
                $payload['outcome_reference'] = collect($this->outcomeReferences)->map(fn($id) => [
                    'identifier' => [
                        'value' => $id, // Assuming these are UUIDs of clinical documents
                    ]
                ])->toArray();
            }
        }

        try {
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($payload),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );

            $apiMethod = $this->actionType === 'complete_activity' ? 'complete' : 'cancel';
            
            $eHealthResponse = EHealth::carePlanActivity()->{$apiMethod}(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                $activity->uuid,
                [
                    'signed_data'          => $signedContent,
                    'signed_data_encoding' => 'base64',
                ]
            );

            $responseData = $eHealthResponse->getData();
            $finalResponse = $responseData;

            if (isset($responseData['links'][0]['href']) && str_contains($responseData['links'][0]['href'], '/jobs/')) {
                $jobId = str_replace('/jobs/', '', $responseData['links'][0]['href']);
                $jobApi = new \App\Classes\eHealth\Api\Job();
                $attempts = 0;
                do {
                    sleep(2);
                    $finalResponse = $jobApi->getDetails($jobId)->getData();
                    $attempts++;
                } while ($finalResponse['status'] === 'pending' && $attempts < 15);
            }

            $activityStatus = $finalResponse['status'] ?? $payload['status'];
            if (isset($finalResponse['result']) && is_array($finalResponse['result'])) {
                $entity = $finalResponse['result'][0] ?? $finalResponse['result'];
                $activityStatus = $entity['status'] ?? $activityStatus;
            }

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

            $this->carePlan->refresh();
            Session::flash('success', __('care-plan.activity_updated'));
            $this->showSignatureModal = false;

        } catch (\Throwable $exception) {
            Log::error('CarePlanActivityStatus: error: ' . $exception->getMessage());
            Session::flash('error', $exception->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function openMethodSelectionModal(): void
    {
        if (empty($this->carePlan->uuid)) {
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'План лікування ще не синхронізовано з ЕСОЗ.']);
            return;
        }

        try {
            $this->authMethods = EHealth::person()->getAuthMethods($this->carePlan->person->uuid)->getData();
            $this->showMethodSelectionModal = true;
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to load auth methods: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Не вдалося завантажити методи аутентифікації']);
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
                            'value' => $this->carePlan->uuid,
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

            $response = EHealth::approval()->createApproval($this->carePlan->person->uuid, $payload);
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
                    $this->syncPlanStatus();
                    Session::flash('success', 'План лікування успішно активовано.');
                }
            }
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to create approval: ' . $e->getMessage());
            $this->dispatch('flashMessage', ['type' => 'error', 'message' => 'Не вдалося створити запит на дозвіл: ' . $e->getMessage()]);
        }
    }

    public function verify(): void
    {
        $this->validate($this->approvalVerificationRules());

        try {
            $response = EHealth::approval()->verify($this->carePlan->person->uuid, $this->approvalId, [
                'code' => (int) $this->verificationCode,
            ]);

            if ($response->getStatusCode() === 200) {
                $this->closeAuthModal();
                $this->syncPlanStatus();
                Session::flash('success', 'План лікування успішно активовано.');
            }
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to verify approval: ' . $e->getMessage());
            $this->addError('verificationCode', 'Невірний код підтвердження або помилка сервісу');
        }
    }

    public function resendSms(): void
    {
        if ($this->smsResent) return;
        try {
            EHealth::approval()->resendSms($this->carePlan->person->uuid, $this->approvalId);
            $this->smsResent = true;
            $this->dispatch('flashMessage', ['type' => 'success', 'message' => 'SMS надіслано повторно']);
        } catch (\Exception $e) {
            Log::error('CarePlanShow: failed to resend SMS: ' . $e->getMessage());
        }
    }

    public function sync(): void
    {
        $this->syncPlanStatus();
        $this->dispatch('flashMessage', ['type' => 'success', 'message' => 'Дані успішно синхронізовано з ЕСОЗ']);
    }

    public function syncPlanStatus(): void
    {
        try {
            $planResponse = EHealth::carePlan()->getBySearchParams($this->carePlan->person->uuid, ['id' => $this->carePlan->uuid]);
            app(CarePlanRepository::class)->syncCarePlans($planResponse->getData(), $this->carePlan->person_id);
            $this->carePlan->refresh();
        } catch (\Exception $e) {
            Log::warning('CarePlanShow: failed to sync plan status: ' . $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.care-plan.care-plan-show');
    }
}
