<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\Approval as ApprovalApi;
use App\Classes\eHealth\Api\CarePlan as CarePlanApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Livewire\CarePlan\CarePlanApprovals;
use App\Livewire\Person\Records\PatientApproval;
use App\Livewire\Person\Records\PatientCarePlans;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\Person\Person;
use App\Models\Role;
use App\Policies\ApprovalPolicy;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\MedicalRequestOwnership;
use App\Services\MedicalEvents\PatientApprovalService;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\TestCase;

class CarePlanReadAccessRecoveryTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;
    use UsesCompositionDictionaries;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('uk');
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function context(): array
    {
        $context = $this->compositionFixture(scopes: ['care_plan:read', 'approval:create']);
        $role = Role::findByName('SPECIALIST');
        $role->syncPermissions(['approval:create']);
        $context['user']->assignRole($role);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'synthetic-review-token');

        return $context;
    }

    private function plan(array $context): CarePlan
    {
        return CarePlan::forceCreate([
            'uuid' => (string) Str::uuid(), 'personId' => $context['person']->id,
            'legalEntityId' => $context['legalEntity']->id, 'status' => 'active', 'title' => 'Review plan',
        ]);
    }

    private function request(array $context, array $plans): array
    {
        $identifier = ['identifier' => [
            'value' => $context['employee']->uuid,
            'type' => ['coding' => [['code' => 'employee', 'system' => 'eHealth/resources']]],
        ]];
        $resources = array_map(static fn (CarePlan $plan): array => ['identifier' => [
            'value' => $plan->uuid,
            'type' => ['coding' => [['code' => 'care_plan', 'system' => 'eHealth/resources']]],
        ]], $plans);
        $payload = ['created_by' => $identifier, 'granted_to' => $identifier,
            'resources' => $resources, 'access_level' => 'read', 'authorize_with' => (string) Str::uuid()];
        $approval = Repository::approval()->storePending($context['person'], $payload, $context['employee']);
        $link = Repository::approval()->attachEhealthLink($approval, ['href' => '/api/jobs/synthetic-create']);
        Repository::approval()->recordPatientRequest($link, $payload + ['patientApprovalAction' => 'create']);

        return compact('approval', 'link', 'payload', 'resources');
    }

    private function remote(Approval $approval, array $payload, array $changes = []): array
    {
        return array_replace([
            'id' => $approval->uuid,
            'is_verified' => true,
            'access_level' => 'read',
            'expires_at' => now()->addHour()->toIso8601String(),
            'created_by' => $payload['created_by'],
            'granted_to' => $payload['granted_to'],
            'granted_resources' => $payload['resources'],
            'granted_resource_types' => [],
            'authentication_method_current' => ['type' => 'OTP', 'number' => '+380000000000'],
        ], $changes);
    }

    public function test_reload_after_completed_verification_does_not_create_a_second_consent(): void
    {
        $context = $this->context();
        $plan = $this->plan($context);
        ['approval' => $approval, 'link' => $link, 'payload' => $payload] = $this->request($context, [$plan]);
        $link->update(['status' => JobStatus::PROCESSED->value]);
        $link->job->update(['status' => JobStatus::PROCESSED->value]);
        $verification = Repository::approval()->attachEhealthLink($approval, ['href' => '/api/jobs/synthetic-verify']);
        Repository::approval()->recordPatientRequest($verification, ['patientApprovalAction' => 'verify']);
        $verification->update(['status' => JobStatus::PROCESSED->value]);
        $verification->job->update(['status' => JobStatus::PROCESSED->value]);
        $remote = $this->remote($approval, $payload);
        Repository::approval()->sync($remote, $context['person'], $approval);
        $calls = 0;
        $service = $this->mock(PatientApprovalService::class)->makePartial();
        $service->shouldReceive('details')->once()->withArgs(fn (Person $person, string $uuid): bool => $person->is($context['person']) && $uuid === $approval->uuid)->andReturn($remote);
        $service->shouldReceive('availableAuthenticationMethods')->andReturn([['uuid' => $payload['authorize_with'], 'type' => 'OTP']]);
        $service->shouldReceive('create')->andReturnUsing(function () use (&$calls): array {
            $calls++;

            return ['approval_id' => null, 'link_id' => 123456];
        });

        Livewire::test(PatientApproval::class, ['personId' => $context['person']->id, 'carePlansOnly' => true])
            ->call('openDrawer')->assertDispatched('care-plan-read-access-granted')->assertSet('open', false);

        $this->assertSame(0, $calls, 'A verified, unexpired consent must be reused after reload instead of requesting it again.');
    }

    public function test_existing_grants_are_combined_and_only_uncovered_plans_are_requested(): void
    {
        $context = $this->context();
        $plans = [$this->plan($context), $this->plan($context), $this->plan($context)];
        $service = $this->mock(PatientApprovalService::class)->makePartial();
        foreach (array_slice($plans, 0, 2) as $plan) {
            ['approval' => $approval, 'payload' => $payload] = $this->request($context, [$plan]);
            $remote = $this->remote($approval, $payload);
            Repository::approval()->sync($remote, $context['person'], $approval);
            $service->shouldReceive('details')->once()->withArgs(fn (Person $person, string $uuid): bool => $person->is($context['person']) && $uuid === $remote['id'])->andReturn($remote);
        }
        $authUuid = (string) Str::uuid();
        $service->shouldReceive('availableAuthenticationMethods')->andReturn([['uuid' => $authUuid, 'type' => 'OTP']]);
        $service->shouldReceive('create')->once()->withArgs(function (Person $person, array $payload) use ($plans, $context): bool {
            return $person->is($context['person']) && $payload['access_level'] === 'read'
                && array_column(array_column($payload['resources'], 'identifier'), 'value') === [$plans[2]->uuid];
        })->andReturn(['approval_id' => null, 'link_id' => 123456]);

        Livewire::test(PatientApproval::class, ['personId' => $context['person']->id, 'carePlansOnly' => true])
            ->call('openDrawer')->assertSet('resourceIds', [$plans[2]->uuid])
            ->call('chooseAuthentication')->set('selectedAuthUuid', $authUuid)->call('create')->assertHasNoErrors();
    }

    #[DataProvider('unavailableGrants')]
    public function test_remote_revocation_expiry_or_removed_resources_cannot_be_reused(array $changes): void
    {
        $context = $this->context();
        $plan = $this->plan($context);
        ['approval' => $approval, 'payload' => $payload] = $this->request($context, [$plan]);
        $remote = $this->remote($approval, $payload);
        Repository::approval()->sync($remote, $context['person'], $approval);
        $this->mock(PatientApprovalService::class)->makePartial()->shouldReceive('details')->once()->andReturn(array_replace($remote, $changes));

        Livewire::test(PatientApproval::class, ['personId' => $context['person']->id, 'carePlansOnly' => true])
            ->call('openDrawer')->assertNotDispatched('care-plan-read-access-granted')
            ->assertSet('open', true)->assertSet('resourceIds', [$plan->uuid])->assertSet('approvalId', null);
    }

    public static function unavailableGrants(): array
    {
        return [
            'unverified' => [['is_verified' => false]],
            'revoked or expired' => [['expires_at' => '2000-01-01T00:00:00Z']],
            'resources removed' => [['granted_resources' => []]],
        ];
    }

    #[DataProvider('readFailures')]
    public function test_remote_read_failure_cannot_trigger_duplicate_consent(int $status): void
    {
        $context = $this->context();
        $plan = $this->plan($context);
        ['approval' => $approval, 'payload' => $payload] = $this->request($context, [$plan]);
        Repository::approval()->sync($this->remote($approval, $payload), $context['person'], $approval);
        $before = $approval->fresh()->getAttributes();
        $service = $this->mock(PatientApprovalService::class)->makePartial();
        $service->shouldReceive('details')->once()->andThrow(new EHealthResponseException(new HttpResponse(new Response($status, [], '{"error":{"message":"Synthetic failure"}}'))));
        $service->shouldNotReceive('create');

        $component = Livewire::test(PatientApproval::class, ['personId' => $context['person']->id, 'carePlansOnly' => true])->call('openDrawer')
            ->assertNotDispatched('care-plan-read-access-granted');
        if ($status === 404) {
            $component->assertSet('open', true)->assertSet('approvalId', null);
            $this->assertFalse($approval->fresh()->expiresAt->isFuture());
            $this->assertCount(0, Repository::approval()->verifiedCarePlanReadRequests($context['person'], $context['employee']->uuid, [$plan->uuid]));
        } else {
            $component->assertSee(__('approval.create.confirmation_failed'));
            $this->assertSame($before, $approval->fresh()->getAttributes());
        }
        $this->assertTrue($approval->fresh()->getAttribute('is_verified'));
        $this->assertNull($approval->fresh()->status);
    }

    public static function readFailures(): array
    {
        return ['missing' => [404], 'forbidden' => [403], 'unavailable' => [503]];
    }

    public function test_verified_grant_lookup_is_scoped_to_patient_recipient_and_read_access(): void
    {
        $context = $this->context();
        $plan = $this->plan($context);
        ['approval' => $approval, 'payload' => $payload] = $this->request($context, [$plan]);
        Repository::approval()->sync($this->remote($approval, $payload), $context['person'], $approval);
        $repository = Repository::approval();
        $this->assertCount(1, $repository->verifiedCarePlanReadRequests($context['person'], $context['employee']->uuid, [$plan->uuid]));
        $this->assertCount(0, $repository->verifiedCarePlanReadRequests($context['person'], (string) Str::uuid(), [$plan->uuid]));
        $this->assertCount(0, $repository->verifiedCarePlanReadRequests(new Person(['id' => 987654321]), $context['employee']->uuid, [$plan->uuid]));
        $approval->update(['access_level' => 'write']);
        $this->assertCount(0, $repository->verifiedCarePlanReadRequests($context['person'], $context['employee']->uuid, [$plan->uuid]));
    }

    public function test_plan_approval_sync_preserves_the_owner_of_a_multi_plan_request(): void
    {
        $context = $this->context();
        $plans = [$this->plan($context), $this->plan($context)];
        ['approval' => $approval, 'link' => $link, 'payload' => $payload, 'resources' => $resources] = $this->request($context, $plans);
        $remote = ['id' => $approval->uuid, 'is_verified' => false, 'access_level' => 'read',
            'expires_at' => now()->addHour()->toIso8601String(), 'created_by' => $payload['created_by'],
            'granted_to' => $payload['granted_to'], 'granted_resources' => $resources];
        $page = Mockery::mock(EHealthResponse::class);
        $page->shouldReceive('validate')->once()->andReturn([$remote]);
        $page->shouldReceive('getPaging')->once()->andReturn(['page_number' => 1, 'total_pages' => 1]);
        $this->mock(ApprovalApi::class)->shouldReceive('getPatientApprovals')->once()->andReturn($page);

        Repository::approval()->syncApprovals($plans[0], 'care_plan');

        foreach ($plans as $plan) {
            $this->assertSame($approval->id, Repository::approval()->carePlanApprovalForCancellation($plan, $approval->uuid)->id);
            $this->assertTrue(Repository::approval()->localForCarePlan($plan)->isEmpty());
        }
        $unrelated = $this->plan($context);
        try {
            Repository::approval()->carePlanApprovalForCancellation($unrelated, $approval->uuid);
            $this->fail('A shared approval must not be available on an unrelated plan.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        try {
            $pollStatus = app(PatientApprovalService::class)->jobStatus($link->id, $context['person'])['status'];
        } catch (RuntimeException $exception) {
            $pollStatus = $exception->getMessage();
        }
        $resumable = Repository::approval()->pendingCarePlanReadRequest($context['person'], $context['employee']->uuid, [$plans[0]->uuid]) !== null;
        $this->assertSame(
            [Person::class, 'pending', true],
            [$approval->fresh()->approvableType, $pollStatus, $resumable],
            'Syncing one plan must preserve patient ownership, polling and resumption of the shared approval.'
        );
    }

    #[DataProvider('reloadModes')]
    public function test_grant_during_running_sync_is_retried_after_the_old_batch_finishes(bool $reload): void
    {
        $context = $this->context();
        $plan = $this->plan($context);
        $otherPlan = $this->plan($context);
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->andReturn([
            ['id' => $plan->uuid, 'status' => 'active', 'title' => 'Review plan'],
            ['id' => $otherPlan->uuid, 'status' => 'active', 'title' => 'Other plan'],
        ]);
        $response->shouldReceive('isNotLast')->andReturnFalse();
        $api = $this->mock(CarePlanApi::class);
        $api->shouldReceive('withToken')->andReturnSelf();
        $requests = 0;
        $api->shouldReceive('getBySearchParams')->andReturnUsing(function () use (&$requests, $response): EHealthResponse {
            $requests++;

            return $response;
        });
        $component = Livewire::test(PatientCarePlans::class, ['legalEntity' => $context['legalEntity'], 'personId' => $context['person']->id])->call('sync');
        $batchId = $component->get('syncBatchId');
        $this->assertNotNull(Bus::findBatch($batchId));
        DB::table('job_batches')->where('id', $batchId)->update(['pending_jobs' => 1]);
        Cache::put('care-plan-sync-denied:'.$batchId, 1, now()->addHour());

        $component->call('synchronizeGrantedPlans');
        DB::table('job_batches')->where('id', $batchId)->update(['pending_jobs' => 0, 'finished_at' => time()]);
        if ($reload) {
            $component = Livewire::test(PatientCarePlans::class, ['legalEntity' => $context['legalEntity'], 'personId' => $context['person']->id]);
        } else {
            $component->call('checkSyncStatus');
        }
        $this->assertFalse(Cache::has('care-plan-sync-after-access:'.$context['legalEntity']->id.':'.$context['person']->uuid));

        $this->assertSame(2, $requests, 'The access-granted event must not be discarded when a pre-grant batch is still running.');
    }

    public static function reloadModes(): array
    {
        return ['poll' => [false], 'reload' => [true]];
    }

    public function test_denied_access_granted_event_has_no_api_queue_or_retry_side_effects(): void
    {
        $context = $this->context();
        $this->plan($context);
        $context['user']->syncPermissions(['approval:create']);
        $this->mock(CarePlanApi::class)->shouldNotReceive('getBySearchParams');

        Livewire::test(PatientCarePlans::class, ['legalEntity' => $context['legalEntity'], 'personId' => $context['person']->id])
            ->call('synchronizeGrantedPlans')->assertSee(__('care-plan.sync_access_denied'));

        $this->assertFalse(Cache::has('care-plan-sync-after-access:'.$context['legalEntity']->id.':'.$context['person']->uuid));
        Queue::assertNothingPushed();
    }

    public function test_cancellation_policy_uses_the_explicit_clinic_context(): void
    {
        $context = $this->context();
        $context['user']->givePermissionToParent('approval:cancel');
        ['approval' => $approval, 'payload' => $payload] = $this->request($context, [$this->plan($context)]);
        Repository::approval()->sync($this->remote($approval, $payload), $context['person'], $approval);
        $plan = CarePlan::whereUuid($payload['resources'][0]['identifier']['value'])->firstOrFail();
        $this->assertSame([$approval->uuid], Repository::approval()->localForCarePlan($plan)->pluck('uuid')->all());
        try {
            app(MedicalRequestOwnership::class)->approvalForCarePlan($plan, $approval->uuid);
            $this->fail('Patient-owned consent must use PatientApproval for verification, not the plan write/OTP workflow.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
        $otherClinic = LegalEntity::factory()->create();
        $policy = app(ApprovalPolicy::class);

        $this->assertTrue($policy->cancel($context['user'], $approval, $context['legalEntity'])->allowed());
        $this->assertFalse($policy->cancel($context['user'], $approval, $otherClinic)->allowed());
        $approval->update(['expires_at' => now()->subMinute()]);
        $this->assertFalse($policy->cancel($context['user'], $approval, $context['legalEntity'])->allowed());
        Http::assertNothingSent();
    }

    public function test_shared_confirmed_approval_can_be_revoked_from_the_plan_without_entering_its_otp_workflow(): void
    {
        $context = $this->context();
        $context['user']->givePermissionToParent('approval:cancel');
        $plan = $this->plan($context);
        ['approval' => $approval, 'payload' => $payload] = $this->request($context, [$plan, $this->plan($context)]);
        $plan->update(['terms_of_service' => 'INPATIENT']);
        Repository::approval()->sync($this->remote($approval, $payload), $context['person'], $approval);

        $service = $this->mock(PatientApprovalService::class)->makePartial();
        $service->shouldReceive('cancel')->once()->withArgs(fn (Approval $candidate): bool => $candidate->id === $approval->id && $candidate->approvable->is($context['person']));

        Livewire::test(CarePlanApprovals::class, ['legalEntity' => $context['legalEntity'], 'carePlan' => $plan])
            ->assertSee(__('care-plan.shared_approval'))
            ->assertSee(__('care-plan.confirm_cancel_shared_approval'))
            ->assertDontSeeHtml('wire:click="resumeApprovalRequest')
            ->assertDontSeeHtml('wire:click="verifyExistingApproval')
            ->assertDontSeeHtml('wire:click="recreateApproval')
            ->call('cancelApproval', $approval->uuid)->assertSee(__('care-plan.approval_cancelled'));
    }

}
