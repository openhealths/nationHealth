<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Enums\CarePlanStatus;
use App\Livewire\CarePlan\Concerns\CarePlanManager;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Repositories\CarePlanActivityRepository;
use App\Repositories\CarePlanRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CarePlanShow extends CarePlanComponent
{
    use CarePlanManager;

    private const DEFAULT_MEDICATION_PROGRAM_ID = '1318eabc-1a1a-42f6-8450-61e11c19eede';

    private const DEFAULT_DEVICE_PROGRAM_ID = '85953838-1834-4ed6-8bf4-3f83057380ec';

    public bool $confirmingActivityDeletion = false;

    public ?int $activityToDelete = null;

    public string $deviceSelectionWarning = '';

    public int $deviceSearchTotalPages = 1;

    public int $deviceSearchTotalEntries = 0;

    public string $deviceSearchModelNumber = '';

    /** @var array<int, array<string, mixed>> */
    public array $deviceSearchCatalog = [];

    /**
     * Activity form keeps device/medication unit fields used by drawers on the plan page.
     *
     * @var array<string, mixed>
     */
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

    public function mount(CarePlan $carePlan): void
    {
        $this->bootCarePlan($carePlan);

        $editActivityId = request()->query('edit_activity');
        if (is_numeric($editActivityId)) {
            $this->editActivity((int) $editActivityId, app(CarePlanActivityRepository::class));
        }

        $this->activityForm['scheduled_period_end'] = now()->addDays(10)->format('d.m.Y');
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

    public function openMedicalDeviceSearch(): void
    {
        if (!filled($this->selectedProgram)) {
            $this->selectedProgram = $this->resolveDeviceProgramId() ?? '';
            $this->activityForm['program'] = $this->selectedProgram;
        }

        if (!filled($this->selectedProgram)) {
            $this->dispatch('flashMessage', ['message' => __('care-plan.select_program_first'), 'type' => 'error']);

            return;
        }

        $this->activityForm['program'] = $this->selectedProgram;
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

    public function confirmDeleteActivity(int $activityId): void
    {
        $this->activityToDelete = $activityId;
        $this->confirmingActivityDeletion = true;
    }

    public function cancelDeleteActivity(): void
    {
        $this->confirmingActivityDeletion = false;
        $this->activityToDelete = null;
    }

    public function deleteActivity(int $activityId, CarePlanActivityRepository $repository): void
    {
        $activity = $repository->findById($activityId);
        if (!$activity || $activity->care_plan_id !== $this->carePlan->id) {
            $this->dispatch('flashMessage', ['message' => __('care-plan.activity_not_found'), 'type' => 'error']);
            $this->cancelDeleteActivity();

            return;
        }

        $statusVal = $activity->status instanceof \UnitEnum ? $activity->status->value : $activity->status;
        $activityStatus = strtolower(is_array($statusVal)
            ? ($statusVal['coding'][0]['code'] ?? ($statusVal['text'] ?? ''))
            : (string) $statusVal);

        if (!in_array($activityStatus, ['draft', 'new'], true)) {
            $this->dispatch('flashMessage', ['message' => __('care-plan.activity_delete_only_draft'), 'type' => 'error']);
            $this->cancelDeleteActivity();

            return;
        }

        if (!$repository->deleteById($activityId)) {
            $this->dispatch('flashMessage', ['message' => __('care-plan.activity_delete_has_referrals'), 'type' => 'error']);
            $this->cancelDeleteActivity();

            return;
        }

        $this->cancelDeleteActivity();
        $this->dispatch('flashMessage', ['message' => __('care-plan.activity_deleted'), 'type' => 'success']);
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

        $programId = $this->activityForm['program'] ?? $this->selectedProgram ?? null;
        $periodRule = !empty($programId) ? 'required|string' : 'nullable|string';

        $rules = [
            'activityForm.kind' => 'required|string',
            'activityForm.scheduled_period_start' => $periodRule,
            'activityForm.scheduled_period_end' => $periodRule,
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

        if (!empty($programId) && !empty($this->dictionaries['medical_programs'][$programId])) {
            $program = $this->dictionaries['medical_programs'][$programId];
            $allowedIcd10 = \Illuminate\Support\Arr::get($program, 'medical_program_settings.conditions_icd10_am_allowed', []);
            $allowedIcpc2 = \Illuminate\Support\Arr::get($program, 'medical_program_settings.conditions_icpc2_allowed', []);

            if (!empty($allowedIcd10) || !empty($allowedIcpc2)) {
                $addresses = $this->carePlan->addresses ?? [];
                $hasValidDiagnosis = false;

                foreach ($addresses as $address) {
                    $codings = $address['coding'] ?? [];
                    foreach ($codings as $coding) {
                        $system = $coding['system'] ?? '';
                        $code = $coding['code'] ?? '';

                        if (str_contains($system, 'ICD10_AM') && in_array($code, $allowedIcd10, true)) {
                            $hasValidDiagnosis = true;
                            break 2;
                        }

                        if (str_contains($system, 'ICPC2') && in_array($code, $allowedIcpc2, true)) {
                            $hasValidDiagnosis = true;
                            break 2;
                        }
                    }
                }

                if (!$hasValidDiagnosis) {
                    $message = __('care-plan.medical_program_diagnosis_mismatch');
                    $this->dispatch('flashMessage', ['message' => $message, 'type' => 'error']);
                    $this->addError('activityForm.program', $message);

                    return;
                }
            }
        }

        try {
            $validated = $this->validate($rules);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->dispatch('flashMessage', ['message' => $exception->validator->errors()->first(), 'type' => 'error']);

            return;
        }

        $activityStart = convertToYmd($validated['activityForm']['scheduled_period_start']);
        $activityEnd = convertToYmd($validated['activityForm']['scheduled_period_end']);
        $periodError = $this->validateActivityPeriodAgainstCarePlan($activityStart, $activityEnd);
        if ($periodError !== null) {
            $this->dispatch('flashMessage', ['message' => $periodError, 'type' => 'error']);
            $this->addError('activityForm.scheduled_period_start', $periodError);

            return;
        }

        if (str_contains($kindLower, 'medication')) {
            $product = $this->selectedProduct;

            if (empty($product) && !empty($this->activityForm['product_reference'])) {
                try {
                    $response = EHealth::drug()->getMany(['innm_dosage_id' => $this->activityForm['product_reference']]);
                    $data = $response->getData();
                    if (empty($data)) {
                        $response = EHealth::drug()->getMany(['innm_id' => $this->activityForm['product_reference']]);
                        $data = $response->getData();
                    }
                    if (!empty($data)) {
                        $product = $data[0];
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            if (!empty($product)) {
                $expectedUnit = $this->resolveMedicationDenumeratorUnit($product);
                $quantityCode = strtoupper((string) ($validated['activityForm']['quantity_code'] ?? ''));
                if ($quantityCode !== strtoupper($expectedUnit)) {
                    $message = __('care-plan.medication_unit_mismatch', ['unit' => $expectedUnit]);
                    $this->dispatch('flashMessage', ['message' => $message, 'type' => 'error']);
                    $this->addError('activityForm.quantity_code', $message);

                    return;
                }

                $packageStep = (float) ($product['packages'][0]['package_min_qty'] ?? 0);
                if ($packageStep <= 0) {
                    $packageStep = (float) ($product['packages'][0]['package_qty'] ?? 0);
                }
                $quantity = (float) ($validated['activityForm']['quantity'] ?? 0);
                if ($packageStep > 0) {
                    $quotient = $quantity / $packageStep;
                    if (abs($quotient - round($quotient)) > 1e-6) {
                        $message = __('care-plan.medication_qty_packaging', ['count' => $packageStep]);
                        $this->dispatch('flashMessage', ['message' => $message, 'type' => 'error']);
                        $this->addError('activityForm.quantity', $message);

                        return;
                    }
                }
            } elseif (!empty($this->activityForm['product_reference'])) {
                $message = 'Не вдалося перевірити одиниці виміру препарату. Будь ласка, знайдіть і оберіть препарат зі списку ще раз.';
                $this->dispatch('flashMessage', ['message' => $message, 'type' => 'error']);
                $this->addError('activityForm.quantity_code', $message);

                return;
            }
        }

        if (str_contains($kindLower, 'device') && !empty($this->selectedProduct)) {
            $guard = app(\App\Services\MedicalEvents\DeviceProgramParticipationGuard::class);
            $programForDevice = $validated['activityForm']['program']
                ?? $this->activityForm['program']
                ?? $this->resolveDeviceProgramId();
            if (!$guard->deviceAllowsCarePlanActivity($this->selectedProduct, $programForDevice)) {
                $message = __('care-plan.device_care_plan_activity_not_allowed');
                $this->dispatch('flashMessage', ['message' => $message, 'type' => 'error']);
                $this->addError('activityForm.product_reference', $message);

                return;
            }

            $packaging = $this->selectedProduct['packaging'] ?? [];
            $packagingCount = (int) ($packaging['packaging_count'] ?? 0);
            $packagingUnit = isset($packaging['packaging_unit'])
                ? $this->normalizeDeviceUnitCode((string) $packaging['packaging_unit'])
                : null;
            $quantity = (int) ($validated['activityForm']['quantity'] ?? 0);
            $quantityCode = $this->normalizeDeviceUnitCode((string) ($validated['activityForm']['quantity_code'] ?? ''));

            if ($packagingUnit !== null && $quantityCode !== '' && strcasecmp($packagingUnit, $quantityCode) !== 0) {
                $message = __('care-plan.device_quantity_unit_mismatch', ['unit' => $packagingUnit]);
                $this->dispatch('flashMessage', ['message' => $message, 'type' => 'error']);
                $this->addError('activityForm.quantity_code', $message);

                return;
            }

            if ($packagingCount > 0 && $quantity % $packagingCount !== 0) {
                $message = __('care-plan.device_quantity_packaging', ['count' => $packagingCount]);
                $this->dispatch('flashMessage', ['message' => $message, 'type' => 'error']);
                $this->addError('activityForm.quantity', $message);

                return;
            }

            // Persist dictionary-normalized unit code for the eHealth payload.
            if ($packagingUnit !== null) {
                $this->activityForm['quantity_code'] = $packagingUnit;
                $validated['activityForm']['quantity_code'] = $packagingUnit;
            }
            $this->activityForm['quantity_system'] = 'device_unit';
            $validated['activityForm']['quantity_system'] = 'device_unit';
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
            $this->dispatch('flashMessage', ['message' => __('care-plan.activity_updated'), 'type' => 'success']);
        } else {
            $activityData['care_plan_id'] = $this->carePlan->id;
            $activityData['author_id'] = Auth::user()?->activeDoctorEmployee()?->id;
            $activityData['status'] = CarePlanStatus::DRAFT->value;

            $repository->create($activityData);
            $this->dispatch('flashMessage', ['message' => __('care-plan.activity_draft_saved'), 'type' => 'success']);
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
        if (!filled($programId)) {
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
            $guard = app(\App\Services\MedicalEvents\DeviceProgramParticipationGuard::class);
            $this->deviceSearchCatalog = array_values(array_filter(
                array_map(
                    fn (array $device): array => $this->enrichDeviceForDisplay($device),
                    $devices
                ),
                function (array $device) use ($guard, $programId): bool {
                    return $guard->deviceAllowsCarePlanActivity($device, $programId);
                }
            ));

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

    private function enrichDeviceForDisplay(array $device): array
    {
        $device['display_name'] = $this->resolveDeviceDisplayName($device);
        $device['display_packaging'] = $this->formatDevicePackaging($device);
        $device['display_type'] = $this->resolveDeviceTypeName($device);
        $device['display_code'] = $this->resolveDeviceClassificationCode($device) ?? '-';

        return $device;
    }

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
            $packagingUnit = is_array($packaging) && !empty($packaging['packaging_unit'])
                ? (string) $packaging['packaging_unit']
                : 'piece';
            $this->activityForm['quantity_code'] = $this->normalizeDeviceUnitCode($packagingUnit);
            $this->activityForm['program'] = $this->resolveDeviceProgramId();
            if (is_array($packaging) && !empty($packaging['packaging_count'])) {
                $this->activityForm['quantity'] = (int) $packaging['packaging_count'];
            }

            $this->applyDeviceProductFieldsFromSelection($product);

            $programDevice = app(\App\Services\MedicalEvents\DeviceProgramParticipationGuard::class)
                ->resolveProgramDevice($product, $this->activityForm['program'] ?: null);
            $maxDaily = isset($programDevice['max_daily_count']) ? (int) $programDevice['max_daily_count'] : null;
            $this->deviceSelectionWarning = $maxDaily !== null && $maxDaily > 0
                ? __('care-plan.device_max_daily_count_hint', ['count' => $maxDaily])
                : '';
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

        // Program settings missing/unknown: prefer device definition UUID alone.
        if ($deviceId !== '') {
            $this->activityForm['product_reference'] = $deviceId;
            $this->activityForm['product_codeable_concept'] = '';

            return;
        }

        if ($classificationCode !== null && $classificationCode !== '') {
            $this->activityForm['product_codeable_concept'] = $classificationCode;
            $this->activityForm['product_reference'] = '';
        }
    }

    protected function resolveMedicationProgramId(): ?string
    {
        if (filled($this->selectedProgram)) {
            return $this->selectedProgram;
        }

        return self::DEFAULT_MEDICATION_PROGRAM_ID;
    }

    protected function resolveDeviceProgramId(): ?string
    {
        if (filled($this->selectedProgram)) {
            return $this->selectedProgram;
        }
        $devicePrograms = array_keys($this->dictionaries['medical_programs_device'] ?? []);
        if ($devicePrograms === []) {
            return self::DEFAULT_DEVICE_PROGRAM_ID;
        }
        if (in_array(self::DEFAULT_DEVICE_PROGRAM_ID, $devicePrograms, true)) {
            return self::DEFAULT_DEVICE_PROGRAM_ID;
        }

        return $devicePrograms[0];
    }

    private function basicDictionaryCodes(\App\Services\Dictionary\Collections\BasicDictionaryCollection $basics, array $names): array
    {
        foreach ($names as $name) {
            try {
                return $basics->byName($name)->asCodeDescription()->toArray();
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return [];
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
        $this->selectedProgram = '';

        $kindLower = strtolower($kind);
        $this->selectedProgram = match (true) {
            str_contains($kindLower, 'medication') => $this->resolveMedicationProgramId() ?? '',
            str_contains($kindLower, 'device') => $this->resolveDeviceProgramId() ?? '',
            default => '',
        };
    }

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

    private function normalizeDeviceUnitCode(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return $code;
        }

        $dictionary = $this->dictionaries['device_unit'] ?? [];
        if (is_array($dictionary) && $dictionary !== []) {
            foreach (array_keys($dictionary) as $key) {
                if (strcasecmp((string) $key, $code) === 0) {
                    return (string) $key;
                }
            }
        }

        return strtolower($code);
    }

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

    protected function getDeviceSignReadinessWarning(CarePlanActivity $activity): ?string
    {
        $assessment = app(\App\Services\MedicalEvents\DeviceProgramParticipationGuard::class)
            ->assess($this->carePlan, $activity, legalEntity());

        if ($assessment->warnings !== []) {
            $this->deviceParticipationWarning = implode(' ', $assessment->warnings);
        }

        return $assessment->blockingMessage();
    }

    protected function renderCarePlan()
    {
        $this->carePlan->load(['person', 'author.party', 'categoryConcept', 'activities.kindConcept.coding']);

        return view('livewire.care-plan.care-plan-show');
    }
}
