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
            $data = $response->getData();
            // Merge eHealth results with local list
            $this->carePlans = collect($data)->toArray();
        } catch (\Throwable $e) {
            Log::error('CarePlan search error: ' . $e->getMessage());
            session()->flash('error', 'Помилка пошуку планів лікування в ЕСОЗ: ' . $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.care-plan.care-plan-index');
    }
}
