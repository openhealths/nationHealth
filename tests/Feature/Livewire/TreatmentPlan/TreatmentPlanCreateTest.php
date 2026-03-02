<?php

declare(strict_types=1);

use App\Enums\TreatmentPlan\Category;
use App\Enums\TreatmentPlan\Intention;
use App\Enums\TreatmentPlan\Status;
use App\Enums\TreatmentPlan\TermsService;
use App\Livewire\TreatmentPlan\TreatmentPlanCreate;
use App\Models\TreatmentPlan\TreatmentPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('renders the treatment plan create component successfully', function () {
    Livewire::test(TreatmentPlanCreate::class)
        ->assertStatus(200);
});

it('validates required fields', function () {
    Livewire::test(TreatmentPlanCreate::class)
        ->call('save')
        ->assertHasErrors([
            'category' => 'required',
            'intention' => 'required',
            'termsService' => 'required',
            'nameTreatmentPlan' => 'required',
            'period.during.startDate' => 'required',
            'period.during.endDate' => 'required',
        ]);
});

it('validates end date is after or equal to start date', function () {
    Livewire::test(TreatmentPlanCreate::class)
        ->set('period.during.startDate', '10.10.2023')
        ->set('period.during.endDate', '09.10.2023')
        ->call('save')
        ->assertHasErrors(['period.during.endDate']);
});

it('creates a treatment plan successfully', function () {
    Livewire::test(TreatmentPlanCreate::class)
        ->set('category', Category::PRIMARY_CARE->value)
        ->set('intention', Intention::PLAN->value)
        ->set('termsService', TermsService::AMBULATORY->value)
        ->set('nameTreatmentPlan', 'Test Treatment Plan')
        ->set('period.during.startDate', '15.10.2023')
        ->set('period.during.startTime', '10:00')
        ->set('period.during.endDate', '20.10.2023')
        ->set('period.during.endTime', '12:00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('treatment-plan-created');

    $this->assertDatabaseHas('treatment_plans', [
        'name_treatment_plan' => 'Test Treatment Plan',
        'category' => Category::PRIMARY_CARE->value,
        'intention' => Intention::PLAN->value,
        'terms_service' => TermsService::AMBULATORY->value,
        'patient_id' => 'temp-patient-id',
        'status' => Status::DRAFT->value,
    ]);

    $plan = TreatmentPlan::first();
    expect($plan->period_start->format('Y-m-d H:i'))->toBe('2023-10-15 10:00')
        ->and($plan->period_end->format('Y-m-d H:i'))->toBe('2023-10-20 12:00');
});
