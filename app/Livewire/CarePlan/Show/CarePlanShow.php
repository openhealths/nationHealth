<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Show;

use App\Livewire\CarePlan\CarePlanComponent;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanActivities;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanLifecycle;
use App\Models\CarePlan;

class CarePlanShow extends CarePlanComponent
{
    use ManagesCarePlanActivities;
    use ManagesCarePlanLifecycle;

    public function mount(CarePlan $carePlan): void
    {
        $this->bootCarePlan($carePlan);
    }

    protected function renderCarePlan()
    {
        $this->carePlan->load(['person', 'author.party', 'categoryConcept', 'activities.kindConcept.coding']);

        return view('livewire.care-plan.show.care-plan-show');
    }
}
