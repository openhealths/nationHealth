<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Livewire\CarePlan\CarePlanShow;
use App\Livewire\CarePlan\Forms\CarePlanActivityForm;
use App\Livewire\CarePlan\Forms\CarePlanEPrescriptionForm;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Livewire\CarePlan\Forms\CarePlanReferralForm;
use App\Models\CarePlan;

class CarePlanShowHarness extends CarePlanShow
{
    public function __construct()
    {
        $this->form = new CarePlanForm($this, 'form');
        $this->activityForm = new CarePlanActivityForm($this, 'activityForm');
        $this->ePrescriptionForm = new CarePlanEPrescriptionForm($this, 'ePrescriptionForm');
        $this->referralForm = new CarePlanReferralForm($this, 'referralForm');
    }

    public function setCarePlan(CarePlan $carePlan): void
    {
        $this->carePlan = $carePlan;
        $this->carePlanId = (int) $carePlan->id;
    }
}
