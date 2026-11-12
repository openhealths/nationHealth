<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\CarePlan as CarePlanApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\CarePlanCompletenessFilter;
use App\Enums\CarePlanStatus;
use App\Livewire\CarePlan\CarePlanComponent;
use App\Livewire\CarePlan\CarePlanIndex;
use App\Livewire\Person\Records\PatientCarePlans;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\User;
use App\Repositories\CarePlanActivityRepository;
use App\Repositories\CarePlanRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Mockery;
use ReflectionProperty;
use Tests\TestCase;

class CarePlanTv39SearchTest extends TestCase
{
    private function authorizeSearch(bool $allowed): void
    {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('cannot')->once()->with('search', CarePlan::class)->andReturn(!$allowed);
        Auth::shouldReceive('user')->once()->andReturn($user);
        $entity = new LegalEntity();
        $entity->id = 2;
        $this->instance('legalEntity', $entity);
    }

    private function response(array $items, int $pages): EHealthResponse
    {
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn($items);
        $response->shouldReceive('getPaging')->once()->andReturn(['total_pages' => $pages]);

        return $response;
    }

    public function test_patient_search_passes_all_tv39_filters_and_imports_all_registry_pages(): void
    {
        $this->authorizeSearch(true);
        $component = new class extends PatientCarePlans
        {
            public bool $listingRefreshed = false;

            public function loadCarePlans(): void
            {
                $this->listingRefreshed = true;
            }
        };
        $component->personId = 3;
        $component->uuid = 'a74bd134-9cfb-4ad1-8f22-bd266f1d80df';
        $component->filterEncounterId = '30f85588-8a99-43b0-8ffc-0dde59e1dc2d';
        $component->filterBasedOn = '7b4b1285-8269-4829-a93a-90341b8e3752';
        $component->filterPartOf = 'af40236f-2264-47cf-8d00-47fa0f459612';
        $component->filterManagingOrganization = '59b96c96-e780-442e-a336-8ee303b35397';
        $component->filterPeriodDate = '2026-11-11';
        $component->filterStatus = CarePlanStatus::ACTIVE->value;
        $query = [
            'encounter_id' => $component->filterEncounterId,
            'based_on' => $component->filterBasedOn,
            'part_of' => $component->filterPartOf,
            'period_date' => '2026-11-11',
            'managing_organization_id' => $component->filterManagingOrganization,
            'status' => $component->filterStatus,
        ];
        $api = Mockery::mock(CarePlanApi::class);
        $api->shouldReceive('getBySearchParams')->once()->with($component->uuid, $query + ['page' => 1])->andReturn($this->response([['id' => 'first']], 2));
        $api->shouldReceive('getBySearchParams')->once()->with($component->uuid, $query + ['page' => 2])->andReturn($this->response([['id' => 'second']], 2));
        $api->shouldNotReceive('getDetails');
        $this->instance(CarePlanApi::class, $api);
        $repository = Mockery::mock(CarePlanRepository::class);
        $repository->shouldReceive('storeSummaries')->once()->with([['id' => 'first'], ['id' => 'second']], 3, 2);
        $this->instance(CarePlanRepository::class, $repository);
        $component->search();
        $this->assertTrue($component->listingRefreshed);
        $this->assertSame(CarePlanCompletenessFilter::ALL->value, $component->filterCompleteness);
    }

    public function test_patient_search_denial_flashes_without_api_persistence_or_refresh(): void
    {
        $this->authorizeSearch(false);
        $component = new class extends PatientCarePlans
        {
            public bool $listingRefreshed = false;

            public function loadCarePlans(): void
            {
                $this->listingRefreshed = true;
            }
        };
        $api = Mockery::mock(CarePlanApi::class);
        $api->shouldNotReceive('getBySearchParams');
        $this->instance(CarePlanApi::class, $api);
        $repository = Mockery::mock(CarePlanRepository::class);
        $repository->shouldNotReceive('storeSummaries');
        $this->instance(CarePlanRepository::class, $repository);
        $component->search();
        $this->assertFalse($component->listingRefreshed);
        $this->assertSame(__('care-plan.sync_access_denied'), session('error'));
    }

    public function test_requisition_search_returns_masked_results_without_a_local_patient_or_detail_read(): void
    {
        $this->authorizeSearch(true);
        $component = new CarePlanIndex();
        $component->searchRequisition = '1111-2222-3333-4444';
        $rows = [['id' => 'first', 'identity' => ['lastName' => 'Synthetic', 'firstName' => 'A', 'age' => 42]]];
        $api = Mockery::mock(CarePlanApi::class);
        $api->shouldReceive('getMany')->once()->with(['requisition' => $component->searchRequisition, 'page' => 1])->andReturn($this->response($rows, 1));
        $api->shouldNotReceive('getDetails');
        $this->instance(CarePlanApi::class, $api);
        $component->searchByRequisition();
        $this->assertSame($rows, $component->requisitionResults);
        $this->assertSame(CarePlanCompletenessFilter::ALL->value, $component->filterCompleteness);
    }

    public function test_requisition_search_denial_keeps_results_and_has_no_remote_side_effects(): void
    {
        $this->authorizeSearch(false);
        $component = new CarePlanIndex();
        $component->searchRequisition = '1111-2222-3333-4444';
        $api = Mockery::mock(CarePlanApi::class);
        $api->shouldNotReceive('getMany');
        $this->instance(CarePlanApi::class, $api);
        $component->searchByRequisition();
        $this->assertSame([], $component->requisitionResults);
        $this->assertSame(__('care-plan.sync_access_denied'), session('error'));
    }
    private function mutationComponent(): CarePlanComponent
    {
        return new class extends CarePlanComponent
        {

            protected function renderCarePlan(): string
            {
                return 'livewire.care-plan.care-plan-show';
            }

            public function permitted(): bool
            {
                return $this->authorizeCarePlanActivityWrite();
            }

            public function signDraft(CarePlanRepository $repository): void
            {
                $this->signPlan($repository);
            }
        };
    }

    public function test_activity_denial_rechecks_the_plan_and_returns_without_draft_side_effects(): void
    {
        $plan = Mockery::mock(CarePlan::class)->makePartial();
        $plan->id = 1;
        $plan->shouldReceive('requiresFhirImport')->once()->andReturn(false);
        $repository = Mockery::mock(CarePlanRepository::class);
        $repository->shouldReceive('findById')->once()->with(1)->andReturn($plan);
        $this->instance(CarePlanRepository::class, $repository);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('cannot')->once()->with('manageActivities', $plan)->andReturn(true);
        Auth::shouldReceive('user')->once()->andReturn($user);
        $activityRepository = Mockery::mock(CarePlanActivityRepository::class);
        $activityRepository->shouldNotReceive('saveForm');
        $component = $this->mutationComponent();
        (new ReflectionProperty(CarePlanComponent::class, 'carePlan'))->setValue($component, $plan);
        $this->instance(CarePlanActivityRepository::class, $activityRepository);
        $component->saveActivity();
        $this->assertSame(__('care-plan.prescribing_patient_access_required'), session('error'));
    }

    public function test_activity_allowed_path_uses_the_refreshed_policy_grant(): void
    {
        $plan = Mockery::mock(CarePlan::class)->makePartial();
        $plan->id = 1;
        $plan->shouldReceive('requiresFhirImport')->once()->andReturn(false);
        $repository = Mockery::mock(CarePlanRepository::class);
        $repository->shouldReceive('findById')->once()->with(1)->andReturn($plan);
        $this->instance(CarePlanRepository::class, $repository);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('cannot')->once()->with('manageActivities', $plan)->andReturn(false);
        Auth::shouldReceive('user')->once()->andReturn($user);
        $component = $this->mutationComponent();
        (new ReflectionProperty(CarePlanComponent::class, 'carePlan'))->setValue($component, $plan);
        $this->assertTrue($component->permitted());
    }

    public function test_signing_validation_keeps_kep_open_and_flashes_the_field_error(): void
    {
        $plan = new CarePlan();
        $plan->id = 1;
        $repository = Mockery::mock(CarePlanRepository::class);
        $repository->shouldReceive('assertDraftSigningReady')->once()->with($plan)->andThrow(ValidationException::withMessages(['form.encounter' => 'Synthetic invalid author']));
        $repository->shouldReceive('submissionState')->once()->with($plan)->andReturn([]);
        $repository->shouldNotReceive('getResource');
        $component = $this->mutationComponent();
        (new ReflectionProperty(CarePlanComponent::class, 'carePlan'))->setValue($component, $plan);
        $component->showSignatureModal = true;
        $component->signDraft($repository);
        $this->assertTrue($component->showSignatureModal);
        $this->assertSame('Synthetic invalid author', session('error'));
        $this->assertSame('Synthetic invalid author', $component->getErrorBag()->first('form.encounter'));
    }

}
