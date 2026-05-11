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

    public function render()
    {
        return view('livewire.care-plan.care-plan-index');
    }
}
