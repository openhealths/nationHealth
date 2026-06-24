<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Activity\Show;

use App\Livewire\CarePlan\CarePlanComponent;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanEPrescription;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanLifecycle;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanReferrals;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;

class CarePlanActivityShow extends CarePlanComponent
{
    use ManagesCarePlanEPrescription;
    use ManagesCarePlanLifecycle;
    use ManagesCarePlanReferrals;

    public CarePlanActivity $activity;

    public function mount(CarePlan $carePlan, CarePlanActivity $activity): void
    {
        if ($activity->care_plan_id !== $carePlan->id) {
            abort(404);
        }

        $this->bootCarePlan($carePlan);

        $this->activity = $activity->load(['kindConcept.coding', 'reasonReferences']);
        $this->scopeDocumentsToActivity($activity->id);
    }

    protected function renderCarePlan()
    {
        $this->carePlan->load(['person', 'author.party', 'categoryConcept']);

        return view('livewire.care-plan.activity.show.care-plan-activity-show');
    }
}
