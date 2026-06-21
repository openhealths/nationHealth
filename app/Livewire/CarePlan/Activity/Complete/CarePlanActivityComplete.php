<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Activity\Complete;

use App\Livewire\CarePlan\CarePlanComponent;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanLifecycle;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;

class CarePlanActivityComplete extends CarePlanComponent
{
    use ManagesCarePlanLifecycle;

    public CarePlanActivity $activity;

    public function mount(CarePlan $carePlan, CarePlanActivity $activity): void
    {
        if ($activity->care_plan_id !== $carePlan->id) {
            abort(404);
        }

        $this->bootCarePlan($carePlan);

        $this->activity = $activity;
        $this->openSignatureModal('complete_activity', $activity->id);
    }

    protected function renderCarePlan()
    {
        return view('livewire.care-plan.activity.complete.care-plan-activity-complete');
    }
}
