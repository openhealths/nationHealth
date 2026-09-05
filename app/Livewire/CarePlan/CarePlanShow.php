<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Livewire\CarePlan\Concerns\CarePlanManager;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanActivities;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanEPrescription;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanReferrals;
use App\Models\CarePlan;
use App\Repositories\CarePlanActivityRepository;

class CarePlanShow extends CarePlanComponent
{
    use CarePlanManager;
    use ManagesCarePlanActivities;
    use ManagesCarePlanEPrescription;
    use ManagesCarePlanReferrals;

    /**
     * Activity form keeps device/medication unit fields used by drawers on the plan page.
     *
     * @var array<string, mixed>
     */
    public array $activityForm = [
        'id' => null,
        'kind' => 'service_request',
        'program' => '',
        'quantity' => '',
        'quantity_system' => '',
        'quantity_code' => '',
        'daily_amount' => '',
        'daily_amount_system' => '',
        'daily_amount_code' => '',
        'reason_code' => '',
        'reason_reference' => '',
        'goal' => '',
        'description' => '',
        'scheduled_period_start' => '',
        'scheduled_period_end' => '',
        'product_reference' => '',
        'product_codeable_concept' => '',
    ];

    public function mount(CarePlan $carePlan): void
    {
        $this->bootCarePlan($carePlan);

        $editActivityId = request()->query('edit_activity');
        if (is_numeric($editActivityId)) {
            $this->editActivity((int) $editActivityId, app(CarePlanActivityRepository::class));
        }

        $this->activityForm['scheduled_period_end'] = now()->addDays(10)->format('d.m.Y');
    }

    protected function renderCarePlan()
    {
        $this->carePlan->load(['person', 'author.party', 'categoryConcept', 'activities.kindConcept.coding']);

        return view('livewire.care-plan.care-plan-show');
    }
}
