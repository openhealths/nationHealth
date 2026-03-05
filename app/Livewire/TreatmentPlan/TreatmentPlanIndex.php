<?php

namespace App\Livewire\TreatmentPlan;

use App\Classes\eHealth\Api\CarePlan;
use App\Repositories\TreatmentPlanRepository;
use Livewire\Component;

class TreatmentPlanIndex extends Component
{
    public $treatmentPlans = [];

    public function mount(TreatmentPlanRepository $repository)
    {
        // TODO: retrieve context for legal entity id
        $legalEntityId = auth()->user()->legal_entity_id ?? null;
        
        if ($legalEntityId) {
             // Loading from local DB
             $this->treatmentPlans = $repository->getByLegalEntity($legalEntityId);
        }
    }
    
    // Additional methods for EHealth API sync (e.g. searching globally by Requisition or ID)
    public function searchEhealthRequisition(string $requisition)
    {
        try {
            $api = new CarePlan();
            $response = $api->getMany(['requisition' => $requisition]);
            // process and merge data to view
            return $response->getData();
        } catch (\Exception $e) {
            $this->dispatch('notify', ['type' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function render()
    {
        return view('livewire.treatment-plan.treatment-plan-index');
    }
}
