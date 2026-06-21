<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Cancel;

use App\Livewire\CarePlan\CarePlanComponent;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanLifecycle;
use App\Models\CarePlan;

class CarePlanCancel extends CarePlanComponent
{
    use ManagesCarePlanLifecycle;

    public function mount(CarePlan $carePlan): void
    {
        $this->bootCarePlan($carePlan);

        $this->openSignatureModal('cancel');
    }

    protected function renderCarePlan()
    {
        return view('livewire.care-plan.cancel.care-plan-cancel');
    }
}
