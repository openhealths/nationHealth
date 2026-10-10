<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Livewire\CarePlan\Concerns\CarePlanManager;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanActivities;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanEPrescription;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanReferrals;
use App\Models\CarePlan;
use App\Repositories\CarePlanActivityRepository;
use App\Services\MedicalEvents\CarePlanLifecycleService;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Locked;
use Throwable;

class CarePlanShow extends CarePlanComponent
{
    use CarePlanManager;
    use ManagesCarePlanActivities;
    use ManagesCarePlanEPrescription;
    use ManagesCarePlanReferrals;

    #[Locked]
    public array $remotePlanDetails = [];

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
        $this->refreshDisplayedDetails();

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

    public function refreshDisplayedDetails(): void
    {
        $this->authorize('view', $this->carePlan);
        $this->remotePlanDetails = [];
        if (!$this->carePlan->uuid || in_array(strtolower($this->carePlan->status), ['new', 'draft'], true)) {
            return;
        }
        try {
            $this->remotePlanDetails = app(CarePlanLifecycleService::class)->getDetails($this->carePlan->person->uuid, $this->carePlan->uuid);
            foreach ($this->remotePlanDetails['category']['coding'] ?? [] as $index => $coding) {
                if (isset($this->dictionaries['care_plan_categories'][$coding['code']])) {
                    $this->remotePlanDetails['category']['coding'][$index]['display'] = $this->dictionaries['care_plan_categories'][$coding['code']];
                }
            }
        } catch (Throwable $exception) {
            report($exception);
            Session::flash('error', __('device-requests.messages.plan_details_unavailable'));
        }
    }

    protected function refreshCarePlan(): void
    {
        parent::refreshCarePlan();
        $this->refreshDisplayedDetails();
    }
}
