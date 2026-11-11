<?php

declare(strict_types=1);

namespace Tests\Feature\MedicalEvents;

use App\Classes\eHealth\Api\Job as JobApi;
use App\Enums\JobStatus;
use App\Livewire\Components\SubmissionStatus;
use App\Models\CarePlan;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\CreatesFhirCarePlans;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Support\GrantsReferralEmployeeRole;
use Tests\TestCase;

class SharedSubmissionStatusTest extends TestCase
{
    use CompositionTestFixtures;
    use CreatesFhirCarePlans;
    use DatabaseTransactions;
    use GrantsReferralEmployeeRole;

    public function test_pending_job_details_are_visible_without_finishing_the_submission(): void
    {
        [$context, $plan, $link] = $this->submission();
        $this->fakeJob(['status' => 'pending']);
        Queue::fake();
        Livewire::test(SubmissionStatus::class, $this->parameters($plan))
            ->assertSet('jobId', 'shared-job')
            ->call('openDetails')
            ->assertSet('showDetails', true)
            ->assertSet('job.status', JobStatus::PENDING->label())
            ->assertSee(JobStatus::PENDING->label());
        $this->assertSame(JobStatus::PENDING->value, $link->fresh()->status);
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
    }

    public function test_permission_removed_after_mount_denies_details_before_remote_or_queue_work(): void
    {
        [$context, $plan, $link] = $this->submission();
        $component = Livewire::test(SubmissionStatus::class, $this->parameters($plan));
        $context['user']->revokePermissionTo('care_plan:read');
        $context['user']->unsetRelation('permissions');
        Http::fake();
        Queue::fake();
        $component->call('openDetails')
            ->assertSet('jobId', null)
            ->assertSet('showDetails', false)
            ->assertSet('job', [])
            ->assertSee(__('care-plan.sync_access_denied'));
        $this->assertSame(JobStatus::PENDING->value, $link->fresh()->status);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_foreign_facility_submission_is_hidden_before_fetching_details(): void
    {
        [$context, $plan, $link] = $this->submission();
        $foreign = $this->compositionFixture(scopes: ['care_plan:read']);
        Http::fake();
        Queue::fake();
        Livewire::test(SubmissionStatus::class, $this->parameters($plan))
            ->call('openDetails')
            ->assertSet('jobId', null)
            ->assertSet('showDetails', false)
            ->assertSee(__('care-plan.sync_access_denied'));
        $this->assertSame(JobStatus::PENDING->value, $link->fresh()->status);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_malformed_successful_job_shows_feedback_and_preserves_local_submission(): void
    {
        [$context, $plan, $link] = $this->submission();
        $this->fakeJob(['status' => null]);
        Queue::fake();
        Livewire::test(SubmissionStatus::class, $this->parameters($plan))
            ->call('openDetails')
            ->assertSet('showDetails', false)
            ->assertSet('job', [])
            ->assertSee(__('errors.ehealth.messages.request_error'));
        $this->assertSame(JobStatus::PENDING->value, $link->fresh()->status);
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
    }

    private function submission(): array
    {
        $context = $this->compositionFixture(scopes: ['care_plan:read']);
        $this->grantReferralEmployeeRole($context['user'], $context['employee']);
        $plan = $this->createFhirCarePlan([
            'person_id' => $context['person']->id,
            'legal_entity_id' => $context['legalEntity']->id,
            'author_id' => $context['employee']->id,
        ]);
        $link = $plan->ehealthLinks()->create([
            'entity' => 'job', 'href' => '/api/jobs/shared-job', 'status' => JobStatus::PENDING->value,
        ]);

        return [$context, $plan, $link];
    }

    private function parameters(CarePlan $plan): array
    {
        return ['recordType' => CarePlan::class, 'recordId' => $plan->id, 'recordStatusLabel' => 'Draft'];
    }

    private function fakeJob(array $data): void
    {
        config(['ehealth.api.domain' => 'https://ehealth.test']);
        $factory = Http::getFacadeRoot();
        Http::fake(['ehealth.test/*' => Http::response(['data' => $data])]);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api = new JobApi($factory);
        $api->stub($stubs)->preventStrayRequests();
        $this->instance(JobApi::class, $api);
    }
}
