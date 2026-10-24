<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Repositories\CarePlanRepository;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use App\Repositories\DeclarationRepository;

class CarePlanIndex extends Component
{
    public string $searchRequisition = '';

    public string $filterName = '';
    public string $filterEncounterId = '';
    public string $filterStatus = '';
    public string $filterStartDateRange = '';
    public string $filterEndDateRange = '';
    public string $filterIsPartOf = '';
    public string $filterIncludes = '';
    public bool $showAdditionalParams = false;

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
            EHealth::carePlan()->synchronizeSearch(['requisition' => $this->searchRequisition], (int) legalEntity()->id);
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

            $persons = app(CarePlanRepository::class)->patientsForSync($legalEntity->id, auth()->user());

            $failedPatients = [];
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

                    EHealth::carePlan()->synchronizePatient($person->uuid, $person->id, $legalEntity->id);
                } catch (\Throwable $ex) {
                    $failedPatients[] = $person->uuid;
                    Log::warning("Failed to sync care plans for patient {$person->uuid}: " . $ex->getMessage());
                }
            }

            if ($failedPatients !== []) {
                session()->flash('error', __('care-plan.sync_error').': '.implode(', ', $failedPatients));

                return;
            }

            session()->flash('success', __('care-plan.sync_success'));
        } catch (\Throwable $e) {
            Log::error('CarePlan index sync error: ' . $e->getMessage());
            session()->flash('error', __('care-plan.sync_error') . ': ' . $e->getMessage());
        }
    }

    public function render()
    {
        $carePlans = legalEntity() ? app(CarePlanRepository::class)->listing(legalEntity()->id, ['status' => $this->filterStatus, 'name' => $this->filterName, 'startDate' => $this->filterStartDateRange, 'endDate' => $this->filterEndDateRange, 'encounterId' => $this->filterEncounterId, 'requisition' => $this->searchRequisition]) : collect();

        return view('livewire.care-plan.care-plan-index', ['carePlans' => $carePlans]);
    }
}
