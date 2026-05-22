<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Repositories\CarePlanRepository;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class CarePlanIndex extends Component
{
    public $carePlans = [];
    public string $searchRequisition = '';
    public string $status = '';
    public string $startDateFrom = '';
    public string $endDateFrom = '';
    public string $isPartOfCarePlan = '';
    public string $includesCarePlan = '';

    public function mount(CarePlanRepository $repository): void
    {
        $legalEntity = legalEntity();

        if ($legalEntity) {
            $this->carePlans = $repository->getByLegalEntity($legalEntity->id);
        }
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
            session()->flash('error', 'Помилка пошуку планів лікування в ЕСОЗ: ' . $e->getMessage());
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
            
            $query = [
                'managing_organization_id' => $legalEntity->uuid,
            ];

            if ($employee) {
                $query['care_manager'] = $employee->uuid;
            }

            $response = EHealth::carePlan()->getMany($query);
            $validatedData = $response->validate();

            app(CarePlanRepository::class)->syncCarePlans($validatedData);

            $this->mount(app(CarePlanRepository::class));
            session()->flash('success', 'Синхронізація планів закладу успішна');
        } catch (\Throwable $e) {
            Log::error('CarePlan index sync error: ' . $e->getMessage());
            session()->flash('error', 'Помилка синхронізації: ' . $e->getMessage());
        }
    }

    public function syncWithEHealth(): void
    {
        $this->sync();
    }

    public function resetFilters(): void
    {
        $this->searchRequisition = '';
        $this->status = '';
        $this->startDateFrom = '';
        $this->endDateFrom = '';
        $this->isPartOfCarePlan = '';
        $this->includesCarePlan = '';
    }

    public function render()
    {
        $legalEntity = legalEntity();
        if ($legalEntity) {
            $query = \App\Models\CarePlan::where('legal_entity_id', $legalEntity->id)
                ->with(['person', 'author.party', 'encounter.diagnoses.condition'])
                ->latest();

            if (!empty($this->searchRequisition)) {
                $query->where('requisition', 'like', '%' . $this->searchRequisition . '%');
            }

            if (!empty($this->status)) {
                $query->where('status', $this->status);
            }

            if (!empty($this->startDateFrom)) {
                try {
                    $query->where('period_start', '>=', \Carbon\Carbon::parse($this->startDateFrom)->startOfDay());
                } catch (\Throwable $e) {}
            }

            if (!empty($this->endDateFrom)) {
                try {
                    $query->where('period_end', '<=', \Carbon\Carbon::parse($this->endDateFrom)->endOfDay());
                } catch (\Throwable $e) {}
            }

            if (!empty($this->isPartOfCarePlan)) {
                $query->where('title', 'like', '%' . $this->isPartOfCarePlan . '%');
            }

            if (!empty($this->includesCarePlan)) {
                $query->where('description', 'like', '%' . $this->includesCarePlan . '%');
            }

            $this->carePlans = $query->get();
        }

        return view('livewire.care-plan.care-plan-index');
    }
}
