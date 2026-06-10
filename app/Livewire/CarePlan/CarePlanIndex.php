<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Repositories\CarePlanRepository;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use App\Models\Declaration;
use App\Models\Person\Person;
use App\Models\CarePlan;
use App\Enums\User\Role;
use App\Repositories\DeclarationRepository;

class CarePlanIndex extends Component
{
    public $carePlans = [];
    public string $searchRequisition = '';

    public string $filterName = '';
    public string $filterEncounterId = '';
    public string $filterStatus = '';
    public string $filterStartDateRange = '';
    public string $filterEndDateRange = '';
    public string $filterIsPartOf = '';
    public string $filterIncludes = '';
    public bool $showAdditionalParams = false;

    public function mount(CarePlanRepository $repository): void
    {
        $legalEntity = legalEntity();

        if ($legalEntity) {
            $this->carePlans = $repository->getByLegalEntity($legalEntity->id);
        }
    }

    public function search(): void
    {
        $this->searchByRequisition();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'searchRequisition',
            'filterName',
            'filterEncounterId',
            'filterStatus',
            'filterStartDateRange',
            'filterEndDateRange',
            'filterIsPartOf',
            'filterIncludes',
        ]);
    }

    /**
     * Search eHealth by public requisition number (per TZ 3.10.3.2.1).
     */
    public function searchByRequisition(): void
    {
        if (empty($this->searchRequisition)) {
            return;
        }

        try {
            $response = EHealth::carePlan()->getMany(['requisition' => $this->searchRequisition]);
            $data = $response->validate();

            // Sync with local DB if found
            app(CarePlanRepository::class)->syncCarePlans($data);

            $this->mount(app(CarePlanRepository::class));
        } catch (\Throwable $e) {
            Log::error('CarePlan search error: ' . $e->getMessage());
            session()->flash('error', __('care-plan.search_error') . ': ' . $e->getMessage());
        }
    }

    public function sync(): void
    {
        try {
            $legalEntity = legalEntity();
            if (!$legalEntity) {
                return;
            }

            $employee = auth()->user()->activeEmployee();
            if (!$employee) {
                return;
            }

            // Find all patient IDs from active declarations for this doctor/legal entity
            $query = Declaration::query()
                ->active()
                ->filterByLegalEntityId($legalEntity->id);

            // Filter by employees of the logged-in user if they are not OWNER
            if (!auth()->user()->hasAllowedRole(Role::OWNER)) {
                $employeeIds = auth()->user()->party->employees()
                    ->where('legal_entity_id', $legalEntity->id)
                    ->pluck('id')
                    ->all();
                $query->forEmployees($employeeIds);
            }

            $personIds = $query->pluck('person_id')->unique()->all();

            // Also include patients of existing care plans in the database
            $existingCarePlanPersonIds = CarePlan::where('legal_entity_id', $legalEntity->id)
                ->pluck('person_id')
                ->unique()
                ->all();

            $allPersonIds = array_unique(array_merge($personIds, $existingCarePlanPersonIds));

            $persons = Person::whereIn('id', $allPersonIds)->get();

            $allValidatedData = [];
            foreach ($persons as $person) {
                try {
                    // Sync patient's declarations first to support auto-activation logic (like in PersonCarePlans)
                    try {
                        $decResponse = EHealth::declaration()->getMany(
                            ['person_id' => $person->uuid],
                            groupByEntities: true
                        );
                        $decValidated = $decResponse->validate();
                        app(DeclarationRepository::class)->storeMany($decValidated, $legalEntity);
                    } catch (\Throwable $exception) {
                        Log::warning('CarePlanIndex: failed to sync declarations for patient during care plan sync', [
                            'patient_uuid' => $person->uuid,
                            'error' => $exception->getMessage()
                        ]);
                    }

                    $response = EHealth::carePlan()->getBySearchParams($person->uuid, []);
                    $validated = $response->validate();
                    if (!empty($validated)) {
                        $allValidatedData = array_merge($allValidatedData, $validated);
                    }
                } catch (\Throwable $ex) {
                    Log::warning("Failed to sync care plans for patient {$person->uuid}: " . $ex->getMessage());
                }
            }

            if (!empty($allValidatedData)) {
                app(CarePlanRepository::class)->syncCarePlans($allValidatedData);
            }

            $this->mount(app(CarePlanRepository::class));
            session()->flash('success', __('care-plan.sync_success'));
        } catch (\Throwable $e) {
            Log::error('CarePlan index sync error: ' . $e->getMessage());
            session()->flash('error', __('care-plan.sync_error') . ': ' . $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.care-plan.care-plan-index');
    }
}
