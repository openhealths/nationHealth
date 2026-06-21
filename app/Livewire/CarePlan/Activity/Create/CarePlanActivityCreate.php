<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Activity\Create;

use App\Livewire\CarePlan\CarePlanComponent;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanActivities;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;

class CarePlanActivityCreate extends CarePlanComponent
{
    use ManagesCarePlanActivities;

    public string $kind = 'service_request';

    public function mount(CarePlan $carePlan): void
    {
        $this->bootCarePlan($carePlan);

        $kind = request()->query('kind', 'service_request');
        $allowed = ['service_request', 'medication_request', 'device_request'];

        $this->kind = in_array($kind, $allowed, true) ? $kind : 'service_request';
        $this->initActivityForm($this->kind);
        $this->openActivityDrawerForKind($this->kind);
    }

    protected function openActivityDrawerForKind(string $kind): void
    {
        $kindLower = strtolower($kind);

        if (str_contains($kindLower, 'service')) {
            $this->showServiceDrawer = true;
        } elseif (str_contains($kindLower, 'medication')) {
            $this->showMedicationDrawer = true;
        } elseif (str_contains($kindLower, 'device')) {
            $this->showMedicalDeviceDrawer = true;
        }
    }

    protected function afterActivitySaved(?CarePlanActivity $activity = null): void
    {
        if (!$activity) {
            $this->redirectRoute('care-plans.show', [legalEntity(), $this->carePlan->id], navigate: true);

            return;
        }

        $this->redirectRoute(
            'care-plans.activities.show',
            [legalEntity(), $this->carePlan->id, $activity->id],
            navigate: true
        );
    }

    protected function renderCarePlan()
    {
        return view('livewire.care-plan.activity.create.care-plan-activity-create');
    }
}
