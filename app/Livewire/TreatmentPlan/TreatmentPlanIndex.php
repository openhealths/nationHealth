<?php

declare(strict_types=1);

namespace App\Livewire\TreatmentPlan;

use App\Classes\eHealth\EHealth;
use App\Repositories\TreatmentPlanRepository;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class TreatmentPlanIndex extends Component
{
    public $treatmentPlans = [];
    public string $searchRequisition = '';

    public function mount(TreatmentPlanRepository $repository): void
    {
        $legalEntity = legalEntity();

        if ($legalEntity) {
            $this->treatmentPlans = $repository->getByLegalEntity($legalEntity->id);
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
            $this->treatmentPlans = collect($data)->toArray();
        } catch (\Throwable $e) {
            Log::error('CarePlan search error: ' . $e->getMessage());
            session()->flash('error', 'Помилка пошуку планів лікування в ЕСОЗ: ' . $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.treatment-plan.treatment-plan-index');
    }
}
