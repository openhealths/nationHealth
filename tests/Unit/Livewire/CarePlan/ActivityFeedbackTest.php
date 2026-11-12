<?php

declare(strict_types=1);

namespace Tests\Unit\Livewire\CarePlan;

use App\Enums\JobStatus;
use App\Livewire\CarePlan\Activity\Show\CarePlanActivityShow;
use App\Models\CarePlanActivity;
use App\Repositories\CarePlanActivityRepository;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Mockery;
use ReflectionProperty;
use Tests\TestCase;
use Tests\Unit\Livewire\CarePlan\ActivityFeedbackHarness;

class ActivityFeedbackTest extends TestCase
{
    public function test_ground_labels_resolve_by_resource_type_and_keep_unknown_references(): void
    {
        $component = new ActivityFeedbackHarness();
        (new ReflectionProperty(CarePlanActivityShow::class, 'activity'))->setValue($component, new CarePlanActivity(['reason_reference' => [
            'Condition/condition-id', 'Observation/condition-id', 'DiagnosticReport/report-id', 'Condition/missing-id',
        ]]));
        $component->availableConditions = [['uuid' => 'condition-id', 'name' => 'Назва діагнозу']];
        $component->availableReports = [['uuid' => 'report-id', 'name' => 'Назва звіту']];

        $this->assertSame(['Назва діагнозу', 'Observation/condition-id', 'Назва звіту', 'Condition/missing-id'], $component->labels());
    }

    public function test_referral_category_validation_uses_a_translated_field_name(): void
    {
        app()->setLocale('uk');
        $validator = Validator::make(['referralForm' => ['category' => '']], ['referralForm.category' => 'required']);

        $this->assertSame("Поле 'категорія направлення' є обов'язковим для заповнення.", $validator->errors()->first());
    }

    public function test_activity_completion_is_visible_once_for_each_activity_kind(): void
    {
        foreach (['service_request', 'medication_request', 'device_request'] as $kind) {
            $repository = Mockery::mock(CarePlanActivityRepository::class);
            $repository->shouldReceive('submissionStates')->andReturn(['activity-id' => $this->state(JobStatus::PROCESSED)]);
            $this->app->instance(CarePlanActivityRepository::class, $repository);

            Livewire::test(ActivityFeedbackHarness::class, ['kind' => $kind])
                ->call('poll')->assertSee(__('care-plan.activity_signed'))
                ->assertNotDispatched('flashMessage')
                ->call('poll')->assertDontSee(__('care-plan.activity_signed'));
        }
    }

    public function test_referral_background_completion_shows_its_number_once(): void
    {
        $repository = Mockery::mock(CarePlanActivityRepository::class);
        $repository->shouldReceive('submissionStates')->andReturn(['referral-id' => $this->state(JobStatus::PROCESSED, 'ABCD-1234')]);
        $this->app->instance(CarePlanActivityRepository::class, $repository);

        Livewire::test(ActivityFeedbackHarness::class, ['documentUuid' => 'referral-id'])
            ->call('poll')->assertSee(__('care-plan.referral_submission_confirmed'))->assertSee('ABCD-1234')
            ->call('poll')->assertDontSee('ABCD-1234');
    }

    public function test_background_failure_is_visible_once(): void
    {
        $state = $this->state(JobStatus::FAILED);
        $state['error'] = 'Відмова ЕСОЗ';
        $repository = Mockery::mock(CarePlanActivityRepository::class);
        $repository->shouldReceive('submissionStates')->andReturn(['activity-id' => $state]);
        $this->app->instance(CarePlanActivityRepository::class, $repository);

        Livewire::test(ActivityFeedbackHarness::class)->call('poll')->assertSee('Відмова ЕСОЗ')
            ->call('poll')->assertDontSee('Відмова ЕСОЗ');
    }

    public function test_newly_persisted_referral_is_reported_as_created_with_its_number(): void
    {
        Livewire::test(ActivityFeedbackHarness::class)->call('finishReferral')
            ->assertSee(__('care-plan.referral_submission_confirmed'))->assertSee('ABCD-1234')
            ->assertDontSee('вже існував')->assertSet('showSignatureModal', false)
            ->call('$refresh')->assertDontSee('ABCD-1234');
    }

    public function test_duplicate_referral_keeps_its_distinct_synchronization_message(): void
    {
        Livewire::test(ActivityFeedbackHarness::class)->call('finishReferral', true)
            ->assertSee('вже існував')->assertDontSee(__('care-plan.referral_submission_confirmed'));
    }

    public function test_completion_before_redirect_replaces_waiting_message(): void
    {
        $repository = Mockery::mock(CarePlanActivityRepository::class);
        $repository->shouldReceive('submissionStates')->andReturn(['activity-id' => $this->state(JobStatus::PROCESSED)]);
        $this->app->instance(CarePlanActivityRepository::class, $repository);

        Livewire::test(ActivityFeedbackHarness::class)->call('arriveAfterCompletion')
            ->assertSee(__('care-plan.activity_signed'))->assertDontSee(__('care-plan.submission_pending'))
            ->call('poll')->assertDontSee(__('care-plan.activity_signed'));
    }

    private function state(JobStatus $status, ?string $number = null): array
    {
        return ['id' => 15, 'status' => $status->value, 'number' => $number, 'error' => null, 'document' => 'Document'];
    }
}

class ActivityFeedbackHarness extends CarePlanActivityShow
{
    public string $testKind = 'service_request';

    public function mount($carePlan = null, $activity = null, string $kind = 'service_request', string $documentUuid = 'activity-id'): void
    {
        $this->testKind = $kind;
        $this->hydrate();
        $this->submissionStates = [$documentUuid => ['id' => 15, 'status' => JobStatus::PENDING->value]];
    }

    public function hydrate(): void
    {
        $this->activity = (new CarePlanActivity())->forceFill(['id' => 85, 'uuid' => 'activity-id', 'kind' => $this->testKind, 'status' => 'scheduled']);
    }

    public function poll(): void
    {
        $this->refreshSubmissionStatus();
    }

    public function arriveAfterCompletion(): void
    {
        $this->submissionStates = [];
        Session::flash('info', __('care-plan.submission_pending'));
        $this->poll();
    }

    public function labels(): array
    {
        return $this->resolveActivityReasonReferenceLabels();
    }

    public function finishReferral(bool $alreadyExists = false): void
    {
        $this->activity->status = 'in-progress';
        $this->finalizeSignedReferral(['status' => 'active', 'request_number' => 'ABCD-1234'], 'service_request', $this->activity, alreadyPersisted: true, alreadyExists: $alreadyExists);
    }

    protected function refreshCarePlan(): void
    {
    }

    protected function ownedActivity(int $activityId): CarePlanActivity
    {
        return $this->activity;
    }

    protected function resolveActivityProductLabel(CarePlanActivity $activity): string
    {
        return '';
    }

    protected function renderCarePlan()
    {
        return <<<'BLADE'
            <div>
                <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
            </div>
            BLADE;
    }
}
