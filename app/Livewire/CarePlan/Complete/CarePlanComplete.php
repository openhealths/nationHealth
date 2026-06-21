<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Complete;

use App\Livewire\CarePlan\CarePlanComponent;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanLifecycle;
use App\Models\CarePlan;

class CarePlanComplete extends CarePlanComponent
{
    use ManagesCarePlanLifecycle;

    public function mount(CarePlan $carePlan): void
    {
        $this->bootCarePlan($carePlan);

        $this->openSignatureModal('complete');
    }

    protected function renderCarePlan()
    {
        return view('livewire.care-plan.complete.care-plan-complete');
    }
}
