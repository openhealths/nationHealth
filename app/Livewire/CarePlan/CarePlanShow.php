<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Exceptions\EHealth\EHealthConnectionException;
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
    private const DEFAULT_MEDICATION_PROGRAM_ID = '1318eabc-1a1a-42f6-8450-61e11c19eede';

    private const DEFAULT_DEVICE_PROGRAM_ID = '85953838-1834-4ed6-8bf4-3f83057380ec';

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

    public string $deviceSelectionWarning = '';

    /** @var list<string> */
    public array $participatingDeviceProgramIds = [];

    public string $deviceParticipationWarning = '';

    // Search and selection parameters
    public string $searchQuery = '';
    public array $searchResults = [];
    public int $searchPage = 1;
    public int $deviceSearchTotalPages = 1;
    public int $deviceSearchTotalEntries = 0;
    public string $deviceSearchModelNumber = '';
    /** @var array<int, array<string, mixed>> */
    public array $deviceSearchCatalog = [];
    public ?array $selectedProduct = null;
    public ?string $selectedProgram = '';

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
        'daily_amount_system' => '',
        'daily_amount_code' => '',
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
        $this->carePlan->load(['person', 'author.party', 'categoryConcept.coding', 'activities.kindConcept.coding']);

        // Fetch patient conditions for outcomeReference selection
        $this->availableConditions = \App\Models\MedicalEvents\Sql\Condition::where('person_id', $this->carePlan->person_id)
            ->with('code.coding')->get()->map(fn ($c) => [
                'uuid' => $c->uuid,
                'name' => ($c->code?->text ?: null) ?? ($c->code?->coding?->first()?->code ?: null) ?? 'Unknown Condition',
                'date' => $c->onset_date ? \Carbon\Carbon::parse($c->onset_date)->format('d.m.Y') : '-',
            ])->toArray();

        // Fetch patient diagnostic reports for justifications (grounds)
        $this->availableReports = \App\Models\MedicalEvents\Sql\DiagnosticReport::where('person_id', $this->carePlan->person_id)
            ->get()->map(fn ($dr) => [
                'uuid' => $dr->uuid,
                'name' => $dr->code?->text ?: 'Diagnostic Report',
                'date' => $dr->issued ? \Carbon\Carbon::parse($dr->issued)->format('d.m.Y') : '-',
            ])->toArray();

        // Fetch patient observations for justifications (grounds)
        $this->availableObservations = \App\Models\MedicalEvents\Sql\Observation::where('person_id', $this->carePlan->person_id)
            ->get()->map(fn ($obs) => [
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

            $this->dictionaries['care_plan_complete_reasons'] = $basics->byName('eHealth/care_plan_complete_reasons')
                ?->asCodeDescription()
                ?->toArray() ?? [];

            $this->dictionaries['care_plan_activity_complete_reasons'] = $basics->byName('eHealth/care_plan_activity_complete_reasons')
                ?->asCodeDescription()
                ?->toArray() ?? [];

            $this->dictionaries['care_plan_activity_cancel_reasons'] = $basics->byName('eHealth/care_plan_activity_cancel_reasons')
                ?->asCodeDescription()
                ?->toArray() ?? [];

            // Load medical programs
            $programs = app(\App\Services\Dictionary\DictionaryManager::class)->medicalPrograms();
            $this->dictionaries['medical_programs'] = $programs
                ->pluck('name', 'id')
                ->toArray() ?? [];

            $activePrograms = $programs->where('is_active', '=', true);
            $this->dictionaries['medical_programs_medication'] = $activePrograms
                ->filter(fn (array $program): bool => strtoupper((string) ($program['type'] ?? '')) === \App\Enums\MedicalProgram\Type::MEDICATION->value)
                ->pluck('name', 'id')
                ->toArray() ?? [];
            $devicePrograms = $activePrograms
                ->filter(fn (array $program): bool => strtoupper((string) ($program['type'] ?? '')) === \App\Enums\MedicalProgram\Type::DEVICE->value);
            $this->loadDeviceProgramParticipationState();
            if ($this->participatingDeviceProgramIds !== []) {
                $devicePrograms = app(\App\Services\MedicalEvents\DeviceProgramParticipationGuard::class)
                    ->filterProgramsForParticipation($devicePrograms, $this->participatingDeviceProgramIds);
            }
            $this->dictionaries['medical_programs_device'] = $devicePrograms
                ->pluck('name', 'id')
                ->toArray() ?? [];
        } catch (\Exception $exception) {
            Log::warning('CarePlanShow: failed to load dictionaries: ' . $exception->getMessage());
        }

        $this->carePlanUuid = $this->carePlan->uuid;
        $this->patientId = $this->carePlan->person->uuid;

        $action = request()->query('action');
        if (in_array($action, ['cancel', 'complete'])) {
            $statusStr = is_array($this->carePlan->status)
                ? ($this->carePlan->status['coding'][0]['code'] ?? ($this->carePlan->status['text'] ?? ''))
                : $this->carePlan->status;

            if (strtolower((string) $statusStr) === 'active') {
                $this->openSignatureModal($action);
            }
        }

        $editActivityId = request()->query('edit_activity');
        if (is_numeric($editActivityId)) {
            $this->editActivity((int) $editActivityId, app(CarePlanActivityRepository::class));
        }

        $this->activityForm['scheduled_period_end'] = now()->addDays(10)->format('d.m.Y');
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

    public function getStatusReasonsProperty(): array
    {
        if ($this->actionType === 'complete') {
            return $this->dictionaries['care_plan_complete_reasons'] ?? [];
        }
        if ($this->actionType === 'complete_activity') {
            return $this->dictionaries['care_plan_activity_complete_reasons'] ?? [];
        }
        if ($this->actionType === 'cancel_activity') {
            return $this->dictionaries['care_plan_activity_cancel_reasons'] ?? [];
        }

        return $this->dictionaries['care_plan_cancel_reasons'] ?? [];
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
        $this->resetActivitySelectionState($kind);

        $this->activityForm = [
            'id' => null,
            'kind' => $kind,
            'program' => $this->selectedProgram,
            'quantity' => '',
            'quantity_system' => '',
            'quantity_code' => '',
            'daily_amount' => '',
            'reason_code' => '',
            'reason_reference' => '',
            'goal' => '',
            'description' => '',
            'scheduled_period_start' => now()->format('d.m.Y'),
            'scheduled_period_end' => now()->addDays(10)->format('d.m.Y'),
            'product_reference' => '',
            'product_codeable_concept' => '',
        ];
    }

    public function editActivity(int $activityId, CarePlanActivityRepository $repository): void
    {
        $activity = $repository->findById($activityId);
        if (!$activity) {
            return;
        }

        $this->activityForm = [
            'id' => $activity->id,
            'kind' => is_array($activity->kind) ? ($activity->kind['coding'][0]['code'] ?? ($activity->kind['text'] ?? '')) : ($activity->kindConcept?->coding?->first()?->code ?? $activity->kind),
            'program' => $activity->program ?? '',
            'quantity' => is_array($activity->quantity) ? ($activity->quantity['value'] ?? '') : $activity->quantity,
            'quantity_system' => is_array($activity->quantity) ? ($activity->quantity['unit'] ?? '') : $activity->quantity_system,
            'quantity_code' => $activity->quantity_code ?? '',
            'daily_amount' => $activity->daily_amount ?? '',
            'daily_amount_system' => $activity->daily_amount_system ?? '',
            'daily_amount_code' => $activity->daily_amount_code ?? '',
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
                    if (!empty($data)) {
                        $this->selectedProduct = $data[0];
                    }
                } elseif (str_contains($kindLower, 'medication')) {
                    $programId = $this->activityForm['program'] ?? $activity->program;
                    $filters = ['innm_dosage_id' => $activity->product_reference];
                    if (!empty($programId)) {
                        $filters['medical_program_id'] = $programId;
                    }
                    $response = EHealth::drug()->getMany($filters);
                    $data = $response->getData();
                    if (empty($data)) {
                        $response = EHealth::drug()->getMany(['innm_id' => $activity->product_reference]);
                        $data = $response->getData();
                    }
                    if (!empty($data)) {
                        $this->selectedProduct = $data[0];
                    }
                } elseif (str_contains($kindLower, 'device')) {
                    $programId = $this->activityForm['program'] ?? $activity->program;
                    $filters = ['page_size' => 50];
                    if (!empty($programId)) {
                        $filters['medical_program_id'] = $programId;
                    }
                    $response = EHealth::deviceDefinition()->getMany($filters);
                    $data = $response->getData();
                    $reference = (string) $activity->product_reference;
                    $this->selectedProduct = collect($data)->first(
                        fn (array $item): bool => (string) ($item['id'] ?? $item['uuid'] ?? '') === $reference
                    );
                    if ($this->selectedProduct === null && $reference !== '') {
                        $this->selectedProduct = [
                            'id' => $reference,
                            'uuid' => $reference,
                            'name' => $reference,
                        ];
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
        $this->selectedProgram = $activity->program ?? '';
        if ($this->selectedProgram === '') {
            $this->selectedProgram = match (true) {
                str_contains($kindLower, 'medication') => $this->resolveMedicationProgramId(),
                str_contains($kindLower, 'device') => $this->resolveDeviceProgramId(),
                default => '',
            };
        }
        $this->activityForm['program'] = $this->selectedProgram;

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

    public function updatedSelectedProgram(): void
    {
        $this->activityForm['program'] = $this->selectedProgram;

        if (!empty($this->activityForm['id'])) {
            $this->selectedProduct = null;
            $this->activityForm['product_reference'] = '';
            $this->activityForm['product_codeable_concept'] = '';
        }

        $this->searchQuery = '';
        $this->searchResults = [];
        $this->searchPage = 1;

        if ($this->showMedicalDeviceSearchDrawer) {
            $this->loadMedicalDeviceSearchResults();
        }
    }

    public function openMedicalDeviceSearch(): void
    {
        $this->showMedicalDeviceDrawer = false;
        $this->showMedicalDeviceSearchDrawer = true;
        $this->searchPage = 1;
        $this->loadMedicalDeviceSearchResults();
    }

    public function resetDeviceSearchFilters(): void
    {
        $this->searchQuery = '';
        $this->deviceSearchModelNumber = '';
        $this->searchPage = 1;
        $this->loadMedicalDeviceSearchResults();
    }

    public function goToDeviceSearchPage(int $page): void
    {
        $this->searchPage = max(1, $page);
        $this->paginateDeviceSearchResults();
    }

    public function updatedSearchQuery(): void
    {
        if (!$this->showMedicalDeviceSearchDrawer) {
            return;
        }

        $this->searchPage = 1;
        $this->loadMedicalDeviceSearchResults();
    }

    public function updatedDeviceSearchModelNumber(): void
    {
        if (!$this->showMedicalDeviceSearchDrawer) {
            return;
        }

        $this->searchPage = 1;
        $this->loadMedicalDeviceSearchResults();
    }

    public function deleteActivity(int $activityId, CarePlanActivityRepository $repository): void
    {
        $activity = $repository->findById($activityId);
        if (!$activity || $activity->care_plan_id !== $this->carePlan->id) {
            Session::flash('error', __('care-plan.activity_not_found'));

            return;
        }

        $activityStatus = strtolower(is_array($activity->status)
            ? ($activity->status['coding'][0]['code'] ?? ($activity->status['text'] ?? ''))
            : (string) $activity->status);

        if (!in_array($activityStatus, ['draft', 'new'], true)) {
            Session::flash('error', __('care-plan.activity_delete_only_draft'));

            return;
        }

        if (!$repository->deleteById($activityId)) {
            Session::flash('error', __('care-plan.activity_delete_has_referrals'));

            return;
        }

        Session::flash('success', __('care-plan.activity_deleted'));
        $this->refreshCarePlan();
    }

    public function saveActivity(CarePlanActivityRepository $repository): void
    {
        $kindLower = strtolower((string) ($this->activityForm['kind'] ?? ''));
        if (str_contains($kindLower, 'medication')) {
            $this->activityForm['program'] = $this->resolveMedicationProgramId();
        } elseif (str_contains($kindLower, 'device')) {
            $this->activityForm['program'] = $this->resolveDeviceProgramId();
        } elseif (!empty($this->selectedProgram)) {
            $this->activityForm['program'] = $this->selectedProgram;
        }

        $this->syncDeviceProductReferenceFromSelection();

        $rules = [
            'activityForm.kind' => 'required|string',
            'activityForm.scheduled_period_start' => 'required|string',
            'activityForm.scheduled_period_end' => 'required|string',
            'activityForm.quantity' => 'nullable|numeric',
            'activityForm.quantity_system' => 'nullable|string',
            'activityForm.quantity_code' => 'nullable|string',
            'activityForm.daily_amount' => 'nullable|numeric',
            'activityForm.daily_amount_system' => 'nullable|string',
            'activityForm.daily_amount_code' => 'nullable|string',
            'activityForm.description' => 'nullable|string',
            'activityForm.product_reference' => 'nullable|string',
            'activityForm.program' => 'nullable|string',
            'activityForm.reason_code' => 'nullable|string',
        ];

        $tos = is_array($this->carePlan->terms_of_service)
            ? ($this->carePlan->terms_of_service['coding'][0]['code'] ?? null)
            : $this->carePlan->terms_of_service;
        $isInpatient = strtoupper((string) $tos) === 'INPATIENT';

        $kindLower = strtolower($this->activityForm['kind']);
        if (str_contains($kindLower, 'device')) {
            $rules['activityForm.quantity'] = 'required|integer|min:1';
            if (!$isInpatient) {
                $rules['activityForm.program'] = 'required|string';
            }
            $rules['activityForm.product_reference'] = 'required|uuid';

            $programId = $this->activityForm['program'] ?: $this->selectedProgram;
            $allowedCodeTypes = $this->resolveDeviceRequestAllowedCodeTypes($programId);
            $requiresClassificationOnly = in_array('CLASSIFICATION_TYPE', $allowedCodeTypes, true)
                && !in_array('DEVICE_DEFINITION', $allowedCodeTypes, true);

            if ($requiresClassificationOnly) {
                $rules['activityForm.product_codeable_concept'] = 'required|string';
            } else {
                $rules['activityForm.product_codeable_concept'] = 'nullable|string';
            }
        }

        if (str_contains($kindLower, 'medication')) {
            $rules['activityForm.daily_amount'] = 'required|numeric|min:0.01';
            $rules['activityForm.quantity_code'] = 'required|string';
        }

        try {
            $validated = $this->validate($rules);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            Session::flash('error', $exception->validator->errors()->first());

            return;
        }

        $activityStart = convertToYmd($validated['activityForm']['scheduled_period_start']);
        $activityEnd = convertToYmd($validated['activityForm']['scheduled_period_end']);
        $periodError = $this->validateActivityPeriodAgainstCarePlan($activityStart, $activityEnd);
        if ($periodError !== null) {
            Session::flash('error', $periodError);
            $this->addError('activityForm.scheduled_period_start', $periodError);

            return;
        }

        if (str_contains($kindLower, 'medication') && !empty($this->selectedProduct)) {
            $expectedUnit = $this->resolveMedicationDenumeratorUnit($this->selectedProduct);
            $quantityCode = strtoupper((string) ($validated['activityForm']['quantity_code'] ?? ''));
            if ($quantityCode !== strtoupper($expectedUnit)) {
                $message = __('care-plan.medication_unit_mismatch', ['unit' => $expectedUnit]);
                Session::flash('error', $message);
                $this->addError('activityForm.quantity_code', $message);

                return;
            }

            $packageStep = (float) ($this->selectedProduct['packages'][0]['package_min_qty'] ?? 0);
            if ($packageStep <= 0) {
                $packageStep = (float) ($this->selectedProduct['packages'][0]['package_qty'] ?? 0);
            }
            $quantity = (float) ($validated['activityForm']['quantity'] ?? 0);
            if ($packageStep > 0) {
                $quotient = $quantity / $packageStep;
                if (abs($quotient - round($quotient)) > 1e-6) {
                    $message = __('care-plan.medication_qty_packaging', ['count' => $packageStep]);
                    Session::flash('error', $message);
                    $this->addError('activityForm.quantity', $message);

                    return;
                }
            }
        }

        if (str_contains($kindLower, 'device') && !empty($this->selectedProduct)) {
            $packagingCount = (int) ($this->selectedProduct['packaging']['packaging_count'] ?? 0);
            $quantity = (int) ($validated['activityForm']['quantity'] ?? 0);
            if ($packagingCount > 0 && $quantity % $packagingCount !== 0) {
                $message = __('care-plan.device_quantity_packaging', ['count' => $packagingCount]);
                Session::flash('error', $message);
                $this->addError('activityForm.quantity', $message);

                return;
            }
        }

        // Compile reason reference identifiers from linked justifications
        $reasonReferences = collect($this->linkedGrounds)->map(fn ($g) => $g['type'] . '/' . $g['uuid'])->toArray();

        $program = !empty($validated['activityForm']['program']) ? $validated['activityForm']['program'] : null;
        if (str_contains(strtolower($validated['activityForm']['kind']), 'medication') && empty($program)) {
            $program = $this->resolveMedicationProgramId();
        } elseif (str_contains(strtolower($validated['activityForm']['kind']), 'device') && empty($program)) {
            $program = $this->resolveDeviceProgramId();
        }

        $medicationUnit = str_contains($kindLower, 'medication')
            ? ($validated['activityForm']['quantity_code'] ?? null)
            : null;

        $activityData = [
            'kind' => $validated['activityForm']['kind'],
            'quantity' => !empty($validated['activityForm']['quantity']) ? $validated['activityForm']['quantity'] : null,
            'quantity_system' => !empty($validated['activityForm']['quantity_system']) ? $validated['activityForm']['quantity_system'] : null,
            'quantity_code' => !empty($validated['activityForm']['quantity_code']) ? $validated['activityForm']['quantity_code'] : null,
            'daily_amount' => !empty($validated['activityForm']['daily_amount']) ? $validated['activityForm']['daily_amount'] : null,
            'daily_amount_system' => $medicationUnit ? 'MEDICATION_UNIT' : null,
            'daily_amount_code' => $medicationUnit,
            'description' => !empty($validated['activityForm']['description']) ? $validated['activityForm']['description'] : null,
            'product_reference' => !empty($validated['activityForm']['product_reference']) ? $validated['activityForm']['product_reference'] : null,
            'product_codeable_concept' => !empty($this->activityForm['product_codeable_concept']) ? $this->activityForm['product_codeable_concept'] : null,
            'program' => $program,
            'reason_code' => !empty($validated['activityForm']['reason_code']) ? $validated['activityForm']['reason_code'] : null,
            'reason_reference' => !empty($reasonReferences) ? $reasonReferences : null,
            'scheduled_period_start' => $activityStart,
            'scheduled_period_end' => $activityEnd,
        ];

        if (!empty($this->activityForm['id'])) {
            $repository->updateById($this->activityForm['id'], $activityData);
            Session::flash('success', __('care-plan.activity_updated'));
        } else {
            $activityData['care_plan_id'] = $this->carePlan->id;
            $activityData['author_id'] = Auth::user()?->activeDoctorEmployee()?->id;
            $activityData['status'] = CarePlanStatus::DRAFT->value;

            $repository->create($activityData);
            Session::flash('success', __('care-plan.activity_draft_saved'));
        }

        $this->refreshCarePlan();

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
            $query = trim($this->searchQuery);
            $params = [
                'page' => $this->searchPage,
                'page_size' => 15,
            ];

            // If the query looks like a code (alphanumeric/hyphens/dots, contains digits, no spaces)
            if (preg_match('/^[\p{L}0-9\-\.]+$/u', $query) && preg_match('/[0-9]/', $query) && !str_contains($query, ' ')) {
                $params['code'] = $query;
            } else {
                $params['name'] = $query;
            }

            $response = EHealth::service()->getMany($params);

            $this->searchResults = $this->flattenServices($response->getData());
        } catch (\Exception $e) {
            Log::error("Failed to search services: " . $e->getMessage());
            $this->searchResults = [];
        }
    }

    private function flattenServices(array $nodes): array
    {
        $services = [];
        foreach ($nodes as $node) {
            if (isset($node['request_allowed']) && $node['request_allowed'] && !empty($node['code'])) {
                $services[$node['id']] = $node;
            }

            if (!empty($node['services'])) {
                foreach ($node['services'] as $service) {
                    if (!empty($service['id'])) {
                        $services[$service['id']] = $service;
                    }
                }
            }

            if (!empty($node['groups'])) {
                $subServices = $this->flattenServices($node['groups']);
                foreach ($subServices as $id => $service) {
                    $services[$id] = $service;
                }
            }
        }

        return array_values($services);
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

            $filters['medical_program_id'] = $this->resolveMedicationProgramId();

            $response = EHealth::drug()->getMany($filters);

            $this->searchResults = $response->getData();
        } catch (\Exception $e) {
            Log::error("Failed to search medications: " . $e->getMessage());
            $this->searchResults = [];
        }
    }

    public function searchMedicalDevices(): void
    {
        $this->searchPage = 1;
        $this->loadMedicalDeviceSearchResults();
    }

    private function loadMedicalDeviceSearchResults(): void
    {
        $programId = $this->resolveDeviceProgramId();
        if ($programId === '') {
            $this->searchResults = [];
            $this->deviceSearchTotalEntries = 0;
            $this->deviceSearchTotalPages = 1;

            return;
        }

        try {
            $query = trim($this->searchQuery);
            $filters = ['medical_program_id' => $programId];

            $modelNumber = trim($this->deviceSearchModelNumber);
            if ($modelNumber !== '') {
                $filters['model_number'] = $modelNumber;
            }

            $isUuidQuery = $query !== '' && preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $query
            ) === 1;

            $devices = $this->fetchAllDeviceDefinitions($filters);

            if ($isUuidQuery) {
                $devices = array_values(array_filter(
                    $devices,
                    fn (array $device): bool => strcasecmp((string) ($device['id'] ?? ''), $query) === 0
                        || strcasecmp((string) ($device['uuid'] ?? ''), $query) === 0
                ));
            } elseif ($query !== '') {
                $devices = $this->filterDevicesByQuery($devices, $query);
            }

            $devices = $this->sortDeviceSearchResults($devices, $query);
            $this->deviceSearchCatalog = array_map(
                fn (array $device): array => $this->enrichDeviceForDisplay($device),
                $devices
            );

            $perPage = 20;
            $this->deviceSearchTotalEntries = count($this->deviceSearchCatalog);
            $this->deviceSearchTotalPages = max(1, (int) ceil($this->deviceSearchTotalEntries / $perPage));

            if ($this->searchPage > $this->deviceSearchTotalPages) {
                $this->searchPage = $this->deviceSearchTotalPages;
            }

            $this->paginateDeviceSearchResults();
        } catch (\Exception $e) {
            Log::error('Failed to search medical devices: ' . $e->getMessage());
            $this->searchResults = [];
            $this->deviceSearchCatalog = [];
            $this->deviceSearchTotalEntries = 0;
            $this->deviceSearchTotalPages = 1;
        }
    }

    private function paginateDeviceSearchResults(): void
    {
        $perPage = 20;
        $offset = ($this->searchPage - 1) * $perPage;
        $this->searchResults = array_slice($this->deviceSearchCatalog, $offset, $perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllDeviceDefinitions(array $filters): array
    {
        $pageSize = (int) config('ehealth.api.page_size', 300);
        $page = 1;
        $all = [];

        do {
            $response = EHealth::deviceDefinition()->getMany(array_merge($filters, [
                'page' => $page,
                'page_size' => $pageSize,
            ]));

            $all = array_merge($all, $response->getData());
            $page++;
            $hasMore = $response->isNotLast();
        } while ($hasMore && $page <= 50);

        $indexed = [];
        foreach ($all as $device) {
            $id = (string) ($device['id'] ?? $device['uuid'] ?? '');
            if ($id !== '') {
                $indexed[$id] = $device;
            }
        }

        return array_values($indexed);
    }

    /**
     * @param  array<int, array<string, mixed>>  $devices
     * @return array<int, array<string, mixed>>
     */
    private function filterDevicesByQuery(array $devices, string $query): array
    {
        $needle = mb_strtolower($query);

        return array_values(array_filter($devices, function (array $device) use ($needle): bool {
            $haystacks = [
                $this->resolveDeviceDisplayName($device),
                (string) ($device['model_number'] ?? ''),
                (string) ($device['id'] ?? ''),
            ];

            foreach ($device['device_names'] ?? [] as $deviceName) {
                if (is_array($deviceName) && !empty($deviceName['name'])) {
                    $haystacks[] = (string) $deviceName['name'];
                }
            }

            foreach ($device['classification_types'] ?? [] as $classificationType) {
                if (is_array($classificationType)) {
                    $haystacks[] = (string) ($classificationType['name'] ?? '');
                    $haystacks[] = (string) ($classificationType['code'] ?? '');
                }
            }

            foreach ($haystacks as $haystack) {
                if ($haystack !== '' && mb_stripos($haystack, $needle) !== false) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param  array<int, array<string, mixed>>  $devices
     * @return array<int, array<string, mixed>>
     */
    private function sortDeviceSearchResults(array $devices, string $query): array
    {
        usort($devices, function (array $left, array $right) use ($query): int {
            if ($query !== '') {
                $leftScore = $this->deviceSearchRelevanceScore($left, $query);
                $rightScore = $this->deviceSearchRelevanceScore($right, $query);

                if ($leftScore !== $rightScore) {
                    return $rightScore <=> $leftScore;
                }
            }

            return strcasecmp(
                $this->resolveDeviceDisplayName($left),
                $this->resolveDeviceDisplayName($right)
            );
        });

        return $devices;
    }

    /**
     * @param  array<string, mixed>  $device
     */
    private function deviceSearchRelevanceScore(array $device, string $query): int
    {
        $needle = mb_strtolower($query);
        $name = mb_strtolower($this->resolveDeviceDisplayName($device));
        $modelNumber = mb_strtolower((string) ($device['model_number'] ?? ''));
        $id = mb_strtolower((string) ($device['id'] ?? ''));

        if ($id === $needle) {
            return 1000;
        }

        if ($name === $needle || $modelNumber === $needle) {
            return 900;
        }

        if (str_starts_with($name, $needle) || str_starts_with($modelNumber, $needle)) {
            return 700;
        }

        if (mb_stripos($name, $needle) !== false) {
            return 500;
        }

        if (mb_stripos($modelNumber, $needle) !== false) {
            return 400;
        }

        return 100;
    }

    /**
     * @param  array<string, mixed>  $device
     * @return array<string, mixed>
     */
    private function enrichDeviceForDisplay(array $device): array
    {
        $device['display_name'] = $this->resolveDeviceDisplayName($device);
        $device['display_packaging'] = $this->formatDevicePackaging($device);
        $device['display_type'] = $this->resolveDeviceTypeName($device);
        $device['display_code'] = $this->resolveDeviceClassificationCode($device) ?? '-';

        return $device;
    }

    /**
     * @param  array<string, mixed>  $device
     */
    private function resolveDeviceDisplayName(array $device): string
    {
        if (!empty($device['name']) && is_string($device['name'])) {
            return $device['name'];
        }

        $deviceNames = $device['device_names'] ?? [];
        if (is_array($deviceNames)) {
            foreach ($deviceNames as $deviceName) {
                if (is_array($deviceName) && !empty($deviceName['name'])) {
                    return (string) $deviceName['name'];
                }
            }
        }

        if (!empty($device['description']) && is_string($device['description'])) {
            return $device['description'];
        }

        return (string) ($device['model_number'] ?? $device['id'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $device
     */
    private function resolveDeviceTypeName(array $device): string
    {
        if (!empty($device['type_name'])) {
            return (string) $device['type_name'];
        }

        if (!empty($device['classification_type_name'])) {
            return (string) $device['classification_type_name'];
        }

        $classificationTypes = $device['classification_types'] ?? [];
        if (is_array($classificationTypes) && !empty($classificationTypes[0]['name'])) {
            return (string) $classificationTypes[0]['name'];
        }

        return '-';
    }

    /**
     * @param  array<string, mixed>  $device
     */
    private function formatDevicePackaging(array $device): string
    {
        $packaging = $device['packaging'] ?? null;
        if (!is_array($packaging)) {
            if (is_string($device['package_description'] ?? null)) {
                return $device['package_description'];
            }

            return '-';
        }

        $parts = array_filter([
            $packaging['packaging_type'] ?? null,
            isset($packaging['packaging_count']) ? (string) $packaging['packaging_count'] : null,
            $packaging['packaging_unit'] ?? null,
        ]);

        return $parts !== [] ? implode(' ', $parts) : '-';
    }

    public function selectProduct(array $product, string $kind): void
    {
        $this->selectedProduct = $product;

        if ($kind !== 'device_request') {
            $this->activityForm['product_reference'] = $product['id'] ?? $product['uuid'] ?? $product['code'] ?? '';
        }

        if ($kind === 'service_request') {
            $this->activityForm['product_codeable_concept'] = $product['code'] ?? '';
            $this->activityForm['quantity_system'] = 'SERVICE_UNIT';
            $this->activityForm['quantity_code'] = 'PIECE';
            $this->showServiceSearchDrawer = false;
            $this->showServiceDrawer = true;
        } elseif ($kind === 'medication_request') {
            $unit = $this->resolveMedicationDenumeratorUnit($product);
            $this->activityForm['quantity_system'] = 'MEDICATION_UNIT';
            $this->activityForm['quantity_code'] = $unit;
            $this->activityForm['daily_amount_system'] = 'MEDICATION_UNIT';
            $this->activityForm['daily_amount_code'] = $unit;
            $this->activityForm['program'] = $this->resolveMedicationProgramId();

            $packageStep = (float) ($product['packages'][0]['package_min_qty'] ?? 0);
            if ($packageStep <= 0) {
                $packageStep = (float) ($product['packages'][0]['package_qty'] ?? 0);
            }
            if ($packageStep > 0) {
                $this->activityForm['quantity'] = (int) $packageStep;
            }

            $this->showMedicationSearchDrawer = false;
            $this->showMedicationFormDrawer = true;
        } elseif ($kind === 'device_request') {
            $this->activityForm['quantity_system'] = 'device_unit';
            $packaging = $product['packaging'] ?? null;
            $this->activityForm['quantity_code'] = is_array($packaging) && !empty($packaging['packaging_unit'])
                ? strtolower((string) $packaging['packaging_unit'])
                : 'piece';
            $this->activityForm['program'] = $this->resolveDeviceProgramId();
            if (is_array($packaging) && !empty($packaging['packaging_count'])) {
                $this->activityForm['quantity'] = (int) $packaging['packaging_count'];
            }

            $this->applyDeviceProductFieldsFromSelection($product);

            $this->deviceSelectionWarning = '';
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
            ->filter(fn ($g) => $g['uuid'] !== $uuid)
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

        $this->carePlan->loadMissing(['encounter', 'encounterIdentifier', 'effectivePeriod', 'author', 'categoryConcept.coding']);

        // Action-specific payload
        $statusMap = [
            'cancel' => CarePlanStatus::ENTERED_IN_ERROR->value,
            'complete' => CarePlanStatus::COMPLETED->value,
        ];

        $systemMap = [
            'cancel' => 'eHealth/care_plan_cancel_reasons',
            'complete' => 'eHealth/care_plan_complete_reasons',
        ];

        $statusReasonCodeableConcept = [
            'coding' => [
                [
                    'system' => $systemMap[$this->actionType] ?? 'eHealth/care_plan_cancel_reasons',
                    'code' => $this->statusReason,
                ]
            ]
        ];

        $categoryCoding = $this->carePlan->categoryConcept?->coding?->first();
        $categorySystem = $categoryCoding?->system ?? 'eHealth/care_plan_categories';
        $categoryCode = $categoryCoding?->code
            ?? (is_array($this->carePlan->category)
                ? ($this->carePlan->category['coding'][0]['code'] ?? null)
                : $this->carePlan->category);

        $encounter = $this->carePlan->encounter;
        if (!$encounter && $this->carePlan->encounterIdentifier?->value) {
            $encounter = \App\Models\MedicalEvents\Sql\Encounter::where('uuid', $this->carePlan->encounterIdentifier->value)
                ->with(['diagnoses.condition'])
                ->first();
        }

        $addresses = [];
        if ($encounter) {
            $encounter->loadMissing(['diagnoses.condition']);
            foreach ($encounter->diagnoses as $d) {
                $conditionUuid = $d->condition?->value;
                if ($conditionUuid) {
                    $actualCondition = \App\Models\MedicalEvents\Sql\Condition::where('uuid', $conditionUuid)->with('code.coding')->first();
                    if ($actualCondition) {
                        $coding = $actualCondition->code?->coding?->first();
                        if ($coding) {
                            $addresses[] = [
                                'coding' => [
                                    [
                                        'system' => $coding->system,
                                        'code' => $coding->code
                                    ]
                                ]
                            ];
                        }
                    }
                }
            }
        }

        if (empty($addresses) && !empty($this->carePlan->addresses)) {
            $addresses = $this->carePlan->addresses;
        }

        $periodStart = null;
        $periodEnd = null;

        if ($this->carePlan->effectivePeriod?->start) {
            $periodStart = convertToEHealthISO8601($this->carePlan->effectivePeriod->start);
        } elseif ($this->carePlan->period_start) {
            $periodStart = convertToEHealthISO8601($this->carePlan->period_start->format('Y-m-d') . ' 00:00:00');
        }

        if ($this->carePlan->effectivePeriod?->end) {
            $periodEnd = convertToEHealthISO8601($this->carePlan->effectivePeriod->end);
        } elseif ($this->carePlan->period_end) {
            $periodEnd = convertToEHealthISO8601($this->carePlan->period_end->format('Y-m-d') . ' 23:59:59');
        }

        $period = array_filter([
            'start' => $periodStart,
            'end' => $periodEnd,
        ]);

        $payload = removeEmptyKeys([
            'id' => $this->carePlan->uuid,
            'intent' => 'order',
            'status' => $statusMap[$this->actionType] ?? 'cancelled',
            'status_reason' => $statusReasonCodeableConcept,
            'category' => [
                'coding' => [
                    [
                        'system' => $categorySystem,
                        'code' => $categoryCode,
                    ]
                ]
            ],
            'title' => $this->carePlan->title,
            'period' => $period,
            'addresses' => !empty($addresses) ? $addresses : null,
            'encounter' => ($encounter?->uuid ?? $this->carePlan->encounterIdentifier?->value) ? [
                'identifier' => [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => 'encounter']]
                    ],
                    'value' => $encounter?->uuid ?? $this->carePlan->encounterIdentifier->value
                ]
            ] : null,
            'author' => [
                'identifier' => [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => 'employee']]
                    ],
                    'value' => $this->carePlan->author?->uuid ?? Auth::user()?->activeDoctorEmployee()?->uuid
                ]
            ],
            'description' => $this->carePlan->description ?: null,
            'note' => $this->carePlan->note ?: null,
            'terms_of_service' => [
                'coding' => [
                    ['system' => 'PROVIDING_CONDITION', 'code' => $this->carePlan->terms_of_service]
                ]
            ]
        ]);

        Log::info('CarePlanShow: Signing status change. actionType=' . $this->actionType, [
            'payload' => $payload,
            'snake_case_payload' => Arr::toSnakeCase($payload)
        ]);

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
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64',
                    'status_reason' => $statusReasonCodeableConcept,
                ]
            );

            $responseData = $eHealthResponse->getData();
            $finalResponse = $responseData;

            // Job Polling
            if (isset($responseData['links'][0]['href']) && str_contains($responseData['links'][0]['href'], '/jobs/')) {
                $jobId = str_replace('/jobs/', '', $responseData['links'][0]['href']);
                $jobApi = EHealth::job();
                $attempts = 0;
                do {
                    sleep(2);
                    $finalResponse = $jobApi->getDetails($jobId)->getData();
                    $attempts++;
                } while ($finalResponse['status'] === 'pending' && $attempts < 15);
            }

            if (($finalResponse['status'] ?? null) === 'failed') {
                throw new EHealthValidationException($finalResponse);
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

            $this->refreshCarePlan();

            Session::flash('success', __('care-plan.care_plan_updated'));
            $this->showSignatureModal = false;

        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlanShow: connection error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.connection_error'));
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            if (method_exists($exception, 'report')) {
                $exception->report();
            }
            Log::error('CarePlanShow: eHealth error: ' . $exception->getMessage(), [
                'details' => method_exists($exception, 'getDetails') ? $exception->getDetails() : null
            ]);
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
            'context' => $this->carePlan->context ? ['identifier' => ['type_code' => $this->carePlan->context]] : null,
            'title' => $this->carePlan->title,
            'period' => array_filter([
                'start' => $this->carePlan->period_start ? $this->carePlan->period_start->format('Y-m-d') : null,
                'end' => $this->carePlan->period_end ? $this->carePlan->period_end->format('Y-m-d') : null,
            ]),
            'addresses' => $this->carePlan->addresses, // Already stored as array of diagnoses
            'supporting_info' => array_merge(
                array_map(fn ($e) => ['display' => $e['name']], $this->carePlan->supporting_info['episodes'] ?? []),
                array_map(fn ($m) => ['display' => $m['name']], $this->carePlan->supporting_info['medical_records'] ?? [])
            ),
            'encounter' => $this->carePlan->encounter?->uuid ? ['identifier' => ['value' => $this->carePlan->encounter->uuid]] : null,
            'care_manager' => ['identifier' => ['value' => Auth::user()?->activeDoctorEmployee()?->uuid]],
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

            $this->refreshCarePlan();

            Session::flash('success', __('care-plan.signed_and_sent'));
            $this->showSignatureModal = false;

        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlanShow: connection error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.connection_error'));
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            if (method_exists($exception, 'report')) {
                $exception->report();
            }
            Log::error('CarePlanShow: eHealth error: ' . $exception->getMessage(), [
                'details' => method_exists($exception, 'getDetails') ? $exception->getDetails() : null
            ]);
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

        if (empty($activity->uuid)) {
            $activity->uuid = \Illuminate\Support\Str::uuid()->toString();
            $activity->save();
        }

        if (str_contains(strtolower((string) $activity->kind), 'device') && empty($activity->program)) {
            Session::flash('error', __('care-plan.device_program_required_before_sign'));
            $this->showSignatureModal = false;

            return;
        }

        if (str_contains(strtolower((string) $activity->kind), 'device')) {
            $deviceWarning = $this->getDeviceSignReadinessWarning($activity);
            if ($deviceWarning !== null) {
                Session::flash('error', $deviceWarning);
                $this->showSignatureModal = false;

                return;
            }
        }

        if (str_contains(strtolower((string) $activity->kind), 'device')) {
            $employeeContext = app(\App\Services\MedicalEvents\CarePlanActivityEHealthGuard::class)
                ->resolveEmployeeContext(
                    $this->carePlan,
                    $activity,
                    Auth::user()?->activeDoctorEmployee()?->id
                );
            $uuids = [
                'person_uuid' => $this->carePlan->person->uuid,
                'encounter_uuid' => $this->carePlan->encounter?->uuid,
                'employee_uuid' => $employeeContext['employee_uuid'],
                'legal_entity_uuid' => $employeeContext['legal_entity_uuid'],
            ];

            try {
                $prequalifyPayload = $activityRepository->buildDevicePrequalifyPayload($activity, $this->carePlan, $uuids);
                $jobResolver = app(\App\Services\MedicalEvents\EHealthJobResolver::class);
                $prequalifyResponse = EHealth::deviceRequest()->prequalify(
                    $this->carePlan->person->uuid,
                    $prequalifyPayload
                );
                $jobResolver->assertPrequalifyValid($jobResolver->resolve($prequalifyResponse->getData()));
            } catch (EHealthValidationException $exception) {
                Session::flash('error', $exception->getTranslatedMessage());
                $this->showSignatureModal = false;

                return;
            }
        }

        $this->ensureCarePlanEffectivePeriodSynced($repository);
        $activity->load('carePlan.effectivePeriod');

        // Build Payload
        $activityPayload = $activityRepository->formatCarePlanActivityRequest($activity);
        Log::info('CarePlanActivity: Signing activity ID=' . $activity->id . ', UUID=' . $activity->uuid, [
            'payload' => $activityPayload,
            'snake_case_payload' => Arr::toSnakeCase($activityPayload)
        ]);

        try {
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($activityPayload),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );
            Log::info('CarePlanActivity: Signing key succeeded');

            $eHealthResponse = EHealth::carePlanActivity()->create(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                [
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64',
                ]
            );

            $responseData = $eHealthResponse->getData();
            Log::info('CarePlanActivity: EHealth response received', ['response' => $responseData]);
            $finalResponse = $responseData;

            // If it is an async job, poll it
            if (isset($responseData['links'][0]['href']) && str_contains($responseData['links'][0]['href'], '/jobs/')) {
                $jobId = str_replace('/jobs/', '', $responseData['links'][0]['href']);
                Log::info('CarePlanActivity: Polling job: ' . $jobId);
                $jobApi = EHealth::job();
                $attempts = 0;
                do {
                    sleep(2);
                    $finalResponse = $jobApi->getDetails($jobId)->getData();
                    $attempts++;
                    Log::info("CarePlanActivity: Job {$jobId} attempt {$attempts} status: " . ($finalResponse['status'] ?? 'unknown'));
                } while ($finalResponse['status'] === 'pending' && $attempts < 15);
            }

            Log::info('CarePlanActivity: Final response from eHealth/Job', ['final_response' => $finalResponse]);

            if (($finalResponse['status'] ?? null) === 'failed') {
                Log::error('CarePlanActivity: Job failed in eHealth. Full details for support:', [
                    'request_payload_unsigned' => Arr::toSnakeCase($activityPayload),
                    'ehealth_response_data' => $finalResponse,
                ]);
                throw new EHealthValidationException($finalResponse);
            }

            // Extract the actual CarePlanActivity data
            $activityUuid = $finalResponse['id'] ?? null;
            $activityStatus = $finalResponse['status'] ?? 'new';

            if (isset($finalResponse['result']) && is_array($finalResponse['result'])) {
                $entity = $finalResponse['result'][0] ?? $finalResponse['result'];
                $activityUuid = $entity['id'] ?? $activityUuid;
                $activityStatus = $entity['status'] ?? 'active';
            }

            // If the job was processed but we didn't find the activity uuid directly, try parsing from links
            if (empty($activityUuid) && isset($finalResponse['links']) && is_array($finalResponse['links'])) {
                foreach ($finalResponse['links'] as $link) {
                    if (isset($link['href']) && str_contains($link['href'], '/activities/')) {
                        $activityUuid = basename($link['href']);
                        break;
                    }
                }
            }

            if ($activityStatus === 'processed') {
                $activityStatus = 'scheduled';
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
                $planResponse = EHealth::carePlan()->getDetails($this->carePlan->person->uuid, $this->carePlan->uuid);
                $repository->syncCarePlans(['data' => [$planResponse->getData()]], $this->carePlan->person_id);
                $activityRepository->syncActivities($this->carePlan->person, $this->carePlan);
            } catch (\Exception $e) {
                Log::warning('CarePlanShow: failed to sync plan status or activities after activity creation: ' . $e->getMessage());
            }

            $this->refreshCarePlan();
            Session::flash('success', __('care-plan.activity_signed'));
            $this->showSignatureModal = false;

        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlanActivity: connection error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.connection_error'));
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            if (method_exists($exception, 'report')) {
                $exception->report();
            }
            Log::error('CarePlanActivity: eHealth request failed with validation/response error. Details for support:', [
                'message' => $exception->getMessage(),
                'errors' => method_exists($exception, 'getErrors') ? $exception->getErrors() : null,
                'request_payload_unsigned' => Arr::toSnakeCase($activityPayload ?? []),
            ]);
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getTranslatedMessage()
                : __('care-plan.ehealth_error_prefix') . $exception->getMessage();
            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (\RuntimeException $exception) {
            Log::error('CarePlanActivity: signature error: ' . $exception->getMessage());
            Session::flash('error', $exception->getMessage());
            $this->showSignatureModal = false;
        } catch (\Throwable $exception) {
            Log::error('CarePlanActivity: unexpected error: ' . $exception->getMessage(), [
                'exception' => $exception
            ]);
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
        if (!$activity) {
            return;
        }

        $statusMap = [
            'cancel_activity' => 'cancelled',
            'complete_activity' => 'completed',
        ];

        $systemMap = [
            'cancel_activity' => 'eHealth/care_plan_activity_cancel_reasons',
            'complete_activity' => 'eHealth/care_plan_activity_complete_reasons',
        ];

        $statusReasonCodeableConcept = [
            'coding' => [
                [
                    'system' => $systemMap[$this->actionType] ?? 'eHealth/care_plan_activity_cancel_reasons',
                    'code' => $this->statusReason,
                ]
            ]
        ];

        if ($this->actionType === 'cancel_activity') {
            $creationPayload = $activityRepository->resolveActivityCreationPayloadForCancelSigning($activity);
            $payload = $activityRepository->buildActivityCancelSignPayload($creationPayload);
            $basePayload = $creationPayload;
        } else {
            $basePayload = $activityRepository->resolveActivityPayloadBase(
                $activity,
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
            );
            $payload = $activityRepository->buildActivityCompleteSignPayload(
                $basePayload,
                $this->outcomeCode ?: null,
                $this->outcomeReferences,
            );
        }

        // Log the full JSON string to prevent Monolog from truncating the output in laravel.log
        Log::info('CarePlanActivityStatus: Full JSON payload to sign: ' . json_encode(Arr::toSnakeCase($payload), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // Dump and die to inspect the payload in the browser network tab/modal as requested
        // dd(Arr::toSnakeCase($payload));

        try {
            $signedContent = signatureService()->signData(
                Arr::toSnakeCase($payload),
                $this->form['password'],
                $this->form['knedp'],
                $this->form['keyContainerUpload'],
                Auth::user()->party->taxId
            );
            Log::info('CarePlanActivityStatus: Signing key succeeded');

            $apiMethod = $this->actionType === 'complete_activity' ? 'complete' : 'cancel';

            $payloadData = [
                'signed_data' => $signedContent,
                'signed_data_encoding' => 'base64',
            ];

            if ($this->actionType === 'cancel_activity') {
                $payloadData['detail'] = $activityRepository->buildActivityCancelPatchDetail(
                    $basePayload,
                    $statusReasonCodeableConcept,
                );
            } elseif ($this->actionType === 'complete_activity') {
                if ($this->outcomeCode) {
                    $payloadData['outcome_codeable_concept'] = [
                        'coding' => [
                            [
                                'system' => 'eHealth/care_plan_activity_outcomes',
                                'code' => $this->outcomeCode,
                            ]
                        ]
                    ];
                }

                if (!empty($this->outcomeReferences)) {
                    $payloadData['outcome_reference'] = collect($this->outcomeReferences)->map(fn ($id) => [
                        'identifier' => [
                            'value' => $id,
                        ]
                    ])->toArray();
                }
            }

            $eHealthResponse = EHealth::carePlanActivity()->{$apiMethod}(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                $activity->uuid,
                $payloadData
            );

            $responseData = $eHealthResponse->getData();
            Log::info('CarePlanActivityStatus: EHealth response received', ['response' => $responseData]);
            $finalResponse = $responseData;

            if (isset($responseData['links'][0]['href']) && str_contains($responseData['links'][0]['href'], '/jobs/')) {
                $jobId = str_replace('/jobs/', '', $responseData['links'][0]['href']);
                Log::info('CarePlanActivityStatus: Polling job: ' . $jobId);
                $jobApi = EHealth::job();
                $attempts = 0;
                do {
                    sleep(2);
                    $finalResponse = $jobApi->getDetails($jobId)->getData();
                    $attempts++;
                    Log::info("CarePlanActivityStatus: Job {$jobId} attempt {$attempts} status: " . ($finalResponse['status'] ?? 'unknown'));
                } while ($finalResponse['status'] === 'pending' && $attempts < 15);
            }

            Log::info('CarePlanActivityStatus: Final response from eHealth/Job', ['final_response' => $finalResponse]);

            if (($finalResponse['status'] ?? null) === 'failed') {
                Log::error('CarePlanActivityStatus: Job failed in eHealth', ['final_response' => $finalResponse]);
                throw new EHealthValidationException($finalResponse);
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

            $this->refreshCarePlan();
            Session::flash('success', __('care-plan.activity_updated'));
            $this->showSignatureModal = false;

        } catch (EHealthValidationException $exception) {
            Log::error('CarePlanActivityStatus: eHealth validation error: ' . $exception->getMessage(), [
                'details' => $exception->getDetails()
            ]);
            Session::flash('error', $exception->getTranslatedMessage());
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
                        'value' => Auth::user()?->getCarePlanWriterEmployee($this->carePlan->terms_of_service)?->uuid,
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

            if ($response->successful()) {
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
        if ($this->smsResent) {
            return;
        }
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
            $planResponse = EHealth::carePlan()->getDetails($this->carePlan->person->uuid, $this->carePlan->uuid);
            app(CarePlanRepository::class)->syncCarePlans(['data' => [$planResponse->getData()]], $this->carePlan->person_id);

            // Sync approvals as well!
            app(\App\Repositories\ApprovalRepository::class)->syncApprovals($this->carePlan, 'care_plan');

            $this->refreshCarePlan();
            $this->dispatch('refreshApprovals');
        } catch (\Exception $e) {
            Log::warning('CarePlanShow: failed to sync plan status: ' . $e->getMessage());
        }
    }

    private function refreshCarePlan(): void
    {
        $this->carePlan->refresh();
        $this->carePlan->load(['person', 'author.party', 'categoryConcept', 'activities.kindConcept.coding']);
    }

    private function cleanActivityPayload(array $payload): array
    {
        $excludeKeys = [
            'remaining_quantity',
            'remaining_quantity_type',
            'inserted_at',
            'inserted_by',
            'updated_at',
            'updated_by',
            'status_history',
            'database_id',
        ];

        $cleaned = [];
        foreach ($payload as $key => $value) {
            $snakeKey = \Illuminate\Support\Str::snake($key);
            if (in_array($snakeKey, $excludeKeys, true)) {
                continue;
            }

            if ($snakeKey === 'author' && is_array($value)) {
                if (isset($value[0])) {
                    $value = $value[0];
                }
            }

            if (is_array($value)) {
                $cleaned[$key] = $this->cleanActivityPayload($value);
            } else {
                $cleaned[$key] = $value;
            }
        }

        return $cleaned;
    }

    private function validateActivityPeriodAgainstCarePlan(string $activityStart, string $activityEnd): ?string
    {
        if ($activityStart > $activityEnd) {
            return __('care-plan.activity_period_end_before_start');
        }

        $bounds = app(CarePlanRepository::class)->resolveEHealthPeriodBounds($this->carePlan);
        $planStart = $bounds['start'];
        $planEnd = $bounds['end'];

        if ($planStart) {
            $planStartDate = $planStart->copy()->setTimezone(config('app.timezone', 'Europe/Kyiv'))->format('Y-m-d');
            if ($activityStart < $planStartDate) {
                return __('care-plan.activity_period_before_plan_start');
            }
        } elseif ($this->carePlan->period_start) {
            $planStartDate = $this->carePlan->period_start->format('Y-m-d');
            if ($activityStart < $planStartDate) {
                return __('care-plan.activity_period_before_plan_start');
            }
        }

        if ($planEnd) {
            $planEndDate = $planEnd->copy()->setTimezone(config('app.timezone', 'Europe/Kyiv'))->format('Y-m-d');
            if ($activityEnd > $planEndDate) {
                return __('care-plan.activity_period_after_plan_end');
            }
        } elseif ($this->carePlan->period_end) {
            $planEndDate = $this->carePlan->period_end->format('Y-m-d');
            if ($activityEnd > $planEndDate) {
                return __('care-plan.activity_period_after_plan_end');
            }
        }

        return null;
    }

    private function ensureCarePlanEffectivePeriodSynced(CarePlanRepository $repository): void
    {
        $this->carePlan->loadMissing('effectivePeriod');

        if ($this->carePlan->effectivePeriod && $repository->resolveEHealthPeriodBounds($this->carePlan)['start']) {
            return;
        }

        try {
            $planResponse = EHealth::carePlan()->getDetails($this->carePlan->person->uuid, $this->carePlan->uuid);
            $repository->syncCarePlans(['data' => [$planResponse->getData()]], $this->carePlan->person_id);
            $this->carePlan->refresh()->load('effectivePeriod');
        } catch (\Exception $e) {
            Log::warning('CarePlanShow: failed to sync effective period before activity sign: ' . $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function resolveMedicationDenumeratorUnit(array $product): string
    {
        $ingredients = $product['ingredients'] ?? [];
        if (is_array($ingredients)) {
            foreach ($ingredients as $ingredient) {
                $unit = $ingredient['dosage']['denumerator_unit'] ?? null;
                if (!empty($unit)) {
                    return (string) $unit;
                }
            }
        }

        return (string) ($product['innm_dosage_form'] ?? 'PIECE');
    }

    private function syncDeviceProductReferenceFromSelection(): void
    {
        if (empty($this->selectedProduct)) {
            return;
        }

        $kindLower = strtolower($this->activityForm['kind'] ?? '');
        if (!str_contains($kindLower, 'device')) {
            return;
        }

        $this->applyDeviceProductFieldsFromSelection($this->selectedProduct);
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function applyDeviceProductFieldsFromSelection(array $product): void
    {
        $programId = $this->resolveDeviceProgramId();
        $allowedTypes = $this->resolveDeviceRequestAllowedCodeTypes($programId);
        $allowsDeviceDefinition = in_array('DEVICE_DEFINITION', $allowedTypes, true);
        $allowsClassification = in_array('CLASSIFICATION_TYPE', $allowedTypes, true);

        $deviceId = (string) ($product['id'] ?? $product['uuid'] ?? '');
        $classificationCode = $this->resolveDeviceClassificationCode($product);

        if ($allowsDeviceDefinition && $deviceId !== '') {
            $this->activityForm['product_reference'] = $deviceId;
            $this->activityForm['product_codeable_concept'] = '';

            return;
        }

        if ($allowsClassification && $classificationCode !== null && $classificationCode !== '') {
            $this->activityForm['product_codeable_concept'] = $classificationCode;
            $this->activityForm['product_reference'] = '';

            return;
        }

        if ($deviceId !== '') {
            $this->activityForm['product_reference'] = $deviceId;
        }

        if ($classificationCode !== null && $classificationCode !== '') {
            $this->activityForm['product_codeable_concept'] = $classificationCode;
        }
    }

    protected function resolveMedicationProgramId(): ?string
    {
        if ($this->selectedProgram !== '') {
            return $this->selectedProgram;
        }
        $tos = is_array($this->carePlan->terms_of_service)
            ? ($this->carePlan->terms_of_service['coding'][0]['code'] ?? null)
            : $this->carePlan->terms_of_service;
        if (strtoupper((string) $tos) === 'INPATIENT') {
            return null;
        }

        return self::DEFAULT_MEDICATION_PROGRAM_ID;
    }

    protected function resolveDeviceProgramId(): ?string
    {
        if ($this->selectedProgram !== '') {
            return $this->selectedProgram;
        }
        $tos = is_array($this->carePlan->terms_of_service)
            ? ($this->carePlan->terms_of_service['coding'][0]['code'] ?? null)
            : $this->carePlan->terms_of_service;
        if (strtoupper((string) $tos) === 'INPATIENT') {
            return null;
        }
        $devicePrograms = array_keys($this->dictionaries['medical_programs_device'] ?? []);
        if (in_array(self::DEFAULT_DEVICE_PROGRAM_ID, $devicePrograms, true)) {
            return self::DEFAULT_DEVICE_PROGRAM_ID;
        }

        return $devicePrograms[0] ?? self::DEFAULT_DEVICE_PROGRAM_ID;
    }

    protected function resetActivitySelectionState(string $kind): void
    {
        $this->searchQuery = '';
        $this->searchResults = [];
        $this->searchPage = 1;
        $this->deviceSearchTotalPages = 1;
        $this->deviceSearchTotalEntries = 0;
        $this->deviceSearchModelNumber = '';
        $this->deviceSearchCatalog = [];
        $this->selectedProduct = null;
        $this->linkedGrounds = [];
        $this->deviceSelectionWarning = '';

        $kindLower = strtolower($kind);
        $this->selectedProgram = match (true) {
            str_contains($kindLower, 'medication') => $this->resolveMedicationProgramId(),
            str_contains($kindLower, 'device') => $this->resolveDeviceProgramId(),
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $device
     */
    private function resolveDeviceClassificationCode(array $device): ?string
    {
        if (!empty($device['classification_type_code'])) {
            return (string) $device['classification_type_code'];
        }

        if (!empty($device['code']) && !preg_match('/^[0-9a-f]{8}-/i', (string) $device['code'])) {
            return (string) $device['code'];
        }

        $classificationTypes = $device['classification_types'] ?? [];
        if (is_array($classificationTypes) && !empty($classificationTypes[0]['code'])) {
            return (string) $classificationTypes[0]['code'];
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function resolveDeviceRequestAllowedCodeTypes(?string $programId): array
    {
        if (empty($programId)) {
            return [];
        }

        try {
            $program = dictionary()->medicalPrograms()->firstWhere('id', $programId);
            $types = $program['medical_program_settings']['device_request_allowed_code_types'] ?? [];

            return is_array($types) ? $types : [];
        } catch (\Exception) {
            return [];
        }
    }

    protected function loadDeviceProgramParticipationState(): void
    {
        $guard = app(\App\Services\MedicalEvents\DeviceProgramParticipationGuard::class);
        $this->participatingDeviceProgramIds = $guard->resolveParticipatingProgramIds(legalEntity());
        $this->deviceParticipationWarning = $this->participatingDeviceProgramIds === []
            ? __('care-plan.device_program_participation_sync_hint')
            : '';
    }

    protected function getDeviceSignReadinessWarning(CarePlanActivity $activity): ?string
    {
        $assessment = app(\App\Services\MedicalEvents\DeviceProgramParticipationGuard::class)
            ->assess($this->carePlan, $activity, legalEntity());

        if ($assessment->warnings !== []) {
            $this->deviceParticipationWarning = implode(' ', $assessment->warnings);
        }

        return $assessment->blockingMessage();
    }

    public function render()
    {
        $this->carePlan->load(['person', 'author.party', 'categoryConcept', 'activities.kindConcept.coding']);

        return view('livewire.care-plan.care-plan-show');
    }
}
