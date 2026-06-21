<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Activity\Edit;

use App\Livewire\CarePlan\CarePlanComponent;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanActivities;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Repositories\CarePlanActivityRepository;

class CarePlanActivityEdit extends CarePlanComponent
{
    use ManagesCarePlanActivities;

    public CarePlanActivity $activity;

    public function mount(CarePlan $carePlan, CarePlanActivity $activity, CarePlanActivityRepository $repository): void
    {
        if ($activity->care_plan_id !== $carePlan->id) {
            abort(404);
        }

        $this->bootCarePlan($carePlan);

        $this->activity = $activity;
        $this->editActivity($activity->id, $repository);
    }

    protected function afterActivitySaved(?CarePlanActivity $activity = null): void
    {
        $target = $activity ?? $this->activity;

        $this->redirectRoute(
            'care-plans.activities.show',
            [legalEntity(), $this->carePlan->id, $target->id],
            navigate: true
        );
    }

    protected function renderCarePlan()
    {
        return view('livewire.care-plan.activity.edit.care-plan-activity-edit');
    }
}
