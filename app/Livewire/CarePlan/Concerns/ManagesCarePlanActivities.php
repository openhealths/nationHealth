<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Concerns;

use App\Classes\eHealth\EHealth;
use App\Enums\CarePlanStatus;
use App\Repositories\CarePlanActivityRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

trait ManagesCarePlanActivities
{
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
                    $response = EHealth::drug()->getMany(['innm_id' => $activity->product_reference]);
                    $data = $response->getData();
                    if (!empty($data)) {
                        $this->selectedProduct = $data[0];
                    }
                } elseif (str_contains($kindLower, 'device')) {
                    $response = EHealth::deviceDefinition()->getMany(['classification_type_code' => $activity->product_reference]);
                    $data = $response->getData();
                    if (!empty($data)) {
                        $this->selectedProduct = $data[0];
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
            'activityForm.scheduled_period_end' => 'required|string',
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
            $this->setErrorBag($exception->validator->errors());
            Session::flash('error', $exception->validator->errors()->first());

            return;
        }

        // Compile reason reference identifiers from linked justifications
        $reasonReferences = collect($this->linkedGrounds)->map(fn ($g) => $g['type'] . '/' . $g['uuid'])->toArray();

        $program = !empty($validated['activityForm']['program']) ? $validated['activityForm']['program'] : null;
        if (str_contains(strtolower($validated['activityForm']['kind']), 'medication') && empty($program)) {
            $program = '1318eabc-1a1a-42f6-8450-61e11c19eede'; // Default to "Prescription medical products"
        }

        $activityData = [
            'kind' => $validated['activityForm']['kind'],
            'quantity' => !empty($validated['activityForm']['quantity']) ? $validated['activityForm']['quantity'] : null,
            'quantity_system' => !empty($validated['activityForm']['quantity_system']) ? $validated['activityForm']['quantity_system'] : null,
            'quantity_code' => !empty($validated['activityForm']['quantity_code']) ? $validated['activityForm']['quantity_code'] : null,
            'daily_amount' => !empty($validated['activityForm']['daily_amount']) ? $validated['activityForm']['daily_amount'] : null,
            'description' => !empty($validated['activityForm']['description']) ? $validated['activityForm']['description'] : null,
            'product_reference' => !empty($validated['activityForm']['product_reference']) ? $validated['activityForm']['product_reference'] : null,
            'program' => $program,
            'reason_code' => !empty($validated['activityForm']['reason_code']) ? $validated['activityForm']['reason_code'] : null,
            'reason_reference' => !empty($reasonReferences) ? $reasonReferences : null,
            'scheduled_period_start' => convertToYmd($validated['activityForm']['scheduled_period_start']),
            'scheduled_period_end' => convertToYmd($validated['activityForm']['scheduled_period_end']),
        ];

        if (!empty($this->activityForm['id'])) {
            $repository->updateById($this->activityForm['id'], $activityData);
            Session::flash('success', __('care-plan.activity_updated'));
            $createdActivity = $repository->findById($this->activityForm['id']);
        } else {
            $activityData['care_plan_id'] = $this->carePlan->id;
            $activityData['author_id'] = Auth::user()?->activeDoctorEmployee()?->id;
            $activityData['status'] = CarePlanStatus::DRAFT->value;

            $createdActivity = $repository->create($activityData);
            Session::flash('success', __('care-plan.activity_draft_saved'));
        }

        $this->refreshCarePlan();

        // Close drawers
        $this->dispatch('close-drawers');

        $this->afterActivitySaved($createdActivity ?? null);
    }

    protected function afterActivitySaved(?\App\Models\CarePlanActivity $activity = null): void
    {
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

            if (!empty($this->selectedProgram)) {
                $filters['medical_program_id'] = $this->selectedProgram;
            }

            $response = EHealth::drug()->getMany($filters);

            $this->searchResults = $response->getData();
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

            $this->searchResults = $response->getData();
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
            $this->activityForm['quantity_code'] = 'PIECE';
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
            $this->activityForm['quantity_code'] = 'PIECE';
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
            ->filter(fn ($g) => $g['uuid'] !== $uuid)
            ->values()
            ->toArray();
    }
}
