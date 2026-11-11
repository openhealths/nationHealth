<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\Approval as ApprovalApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\Person\Records\PatientApproval;
use App\Models\CarePlan;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\Role;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Services\MedicalEvents\PatientApprovalService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use ReflectionMethod;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Feature\Composition\UsesCompositionDictionaries;
use Tests\TestCase;

class CarePlanReadAccessTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions;
    use UsesCompositionDictionaries;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('uk');
        Http::preventStrayRequests();
        Bus::fake();
    }

    private function context(bool $allowed = true): array
    {
        $context = $this->compositionFixture(scopes: $allowed ? ['care_plan:read', 'approval:create'] : ['care_plan:read']);
        if ($allowed) {
            $role = Role::findByName('SPECIALIST');
            $role->syncPermissions(['approval:create']);
            $context['user']->assignRole($role);
        }

        return $context;
    }

    private function plan(array $context, array $attributes = []): CarePlan
    {
        return CarePlan::forceCreate($attributes + [
            'uuid' => (string) Str::uuid(), 'personId' => $context['person']->id,
            'legalEntityId' => $context['legalEntity']->id, 'status' => 'active',
            'title' => 'Plan requiring access', 'requisition' => '1111-2222-3333-4444',
        ]);
    }

    private function approvalComponent(array $context)
    {
        return Livewire::test(PatientApproval::class, ['personId' => $context['person']->id, 'carePlansOnly' => true]);
    }

    public function test_bulk_form_selects_only_patient_and_clinic_incomplete_remote_plans_including_history(): void
    {
        $context = $this->context();
        $active = $this->plan($context);
        $historical = $this->plan($context, ['status' => 'terminated']);
        $this->plan($context, ['status' => 'draft']);
        $this->plan($context, ['detailsSyncedAt' => now()]);
        $this->plan($context, ['uuid' => null]);
        // This unrelated patient/facility needs no login role and must not replace the acting specialist's permissions.
        $otherContext = $this->compositionFixture(scopes: []);
        $this->plan($otherContext);
        $this->plan($context, ['legalEntityId' => $otherContext['legalEntity']->id]);
        $this->instance('legalEntity', $context['legalEntity']);
        setPermissionsTeamId($context['legalEntity']->id);
        $this->actingAs($context['user']);

        $component = $this->approvalComponent($context)->call('openDrawer')->assertSet('open', true)
            ->assertSet('accessLevel', 'read')->assertSet('grantToLegalEntity', false)
            ->assertSee(__('care-plan.read_access_description', ['count' => 2]));
        $this->assertEqualsCanonicalizing([$active->uuid, $historical->uuid], $component->get('resourceIds'));
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_bulk_payload_cannot_expand_to_write_or_other_data_and_duplicate_clinic_copy_is_allowed(): void
    {
        $context = $this->context();
        $plan = $this->plan($context);
        // This unrelated patient/facility needs no login role and must not replace the acting specialist's permissions.
        $otherContext = $this->compositionFixture(scopes: []);
        $this->plan($context, ['uuid' => $plan->uuid, 'legalEntityId' => $otherContext['legalEntity']->id]);
        $this->instance('legalEntity', $context['legalEntity']);
        setPermissionsTeamId($context['legalEntity']->id);
        $this->actingAs($context['user']);
        $authUuid = (string) Str::uuid();
        $service = $this->mock(PatientApprovalService::class)->makePartial();
        $service->shouldReceive('availableAuthenticationMethods')->once()->andReturn([['uuid' => $authUuid, 'type' => 'OTP']]);
        $service->shouldReceive('create')->once()->with(Mockery::any(), Mockery::on(function (array $payload) use ($context, $plan, $authUuid): bool {
            $this->assertSame('read', $payload['access_level']);
            $this->assertSame($context['employee']->uuid, data_get($payload, 'granted_to.identifier.value'));
            $this->assertSame('employee', data_get($payload, 'granted_to.identifier.type.coding.0.code'));
            $this->assertSame($context['employee']->uuid, data_get($payload, 'created_by.identifier.value'));
            $this->assertSame('care_plan', data_get($payload, 'resources.0.identifier.type.coding.0.code'));
            $this->assertSame($plan->uuid, data_get($payload, 'resources.0.identifier.value'));
            $this->assertCount(1, $payload['resources']);
            $this->assertArrayNotHasKey('person', $payload);
            $this->assertSame($authUuid, $payload['authorize_with']);

            return true;
        }))->andReturn(['approval_id' => null, 'link_id' => 42]);

        $this->approvalComponent($context)->call('openDrawer')->set('accessLevel', 'write')->set('grantToLegalEntity', true)
            ->set('mode', 'person')->set('resourceType', 'encounter')->set('selectedAuthUuid', $authUuid)
            ->call('create')->assertHasNoErrors()->assertSet('step', 'processing')->assertNotDispatched('care-plan-read-access-granted');
        Http::assertNothingSent();
    }

    public function test_denied_creation_has_visible_feedback_without_side_effects(): void
    {
        $context = $this->context(false);
        $this->plan($context);
        $this->mock(PatientApprovalService::class)->shouldNotReceive('create');
        $this->approvalComponent($context)->call('openDrawer')->assertSet('open', false)
            ->assertSee(__('approval.create.role_forbidden'))->call('create')->assertSee(__('approval.create.role_forbidden'));
        $this->assertSame(0, Approval::count());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_selection_outside_the_opened_batch_is_rejected_before_api(): void
    {
        $context = $this->context();
        $this->plan($context);
        $complete = $this->plan($context, ['detailsSyncedAt' => now()]);
        $this->mock(PatientApprovalService::class)->shouldNotReceive('create');
        $this->approvalComponent($context)->call('openDrawer')->set('resourceIds', [$complete->uuid])->call('create')
            ->assertHasErrors('resourceIds')->assertSee(__('approval.create.record_patient_mismatch'));
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_pending_request_survives_reload_and_failed_creation_can_be_retried(): void
    {
        $context = $this->context();
        $plan = $this->plan($context);
        $authUuid = (string) Str::uuid();
        $service = $this->mock(PatientApprovalService::class)->makePartial();
        $payload = [
            'access_level' => 'read',
            'created_by' => ['identifier' => ['value' => $context['employee']->uuid]],
            'granted_to' => ['identifier' => ['value' => $context['employee']->uuid, 'type' => ['coding' => [['code' => 'employee']]]]],
            'resources' => [['identifier' => ['value' => $plan->uuid, 'type' => ['coding' => [['code' => 'care_plan']]]]]],
        ];
        $payload['authorize_with'] = $authUuid;
        $repository = MedicalEventsRepository::approval();
        $approval = $repository->storePending($context['person'], $payload, $context['employee']);
        $link = $repository->attachEhealthLink($approval, ['href' => '/api/jobs/pending']);
        $repository->recordPatientRequest($link, $payload + ['patientApprovalAction' => 'create']);
        $service->shouldNotReceive('create');
        $service->shouldNotReceive('availableAuthenticationMethods');

        $this->approvalComponent($context)->call('openDrawer')->assertSet('step', 'processing')->assertSet('linkId', $link->id)
            ->assertSet('resourceIds', [$plan->uuid])->assertSet('selectedAuthUuid', $authUuid)
            ->assertSee(__('care-plan.read_access_pending'))->call('create')->assertSet('linkId', $link->id);
        $this->assertSame(1, Approval::count());

        $link->update(['status' => JobStatus::PROCESSED->value]);
        $link = $repository->attachEhealthLink($approval, ['href' => '/api/jobs/verify']);
        $repository->recordPatientRequest($link, ['patientApprovalAction' => 'verify']);
        $this->approvalComponent($context)->call('openDrawer')->assertSet('step', 'verifying')->assertSet('approvalId', $approval->uuid);
        $link->update(['status' => JobStatus::FAILED->value]);
        $this->approvalComponent($context)->call('openDrawer')->assertSet('step', 'form')->assertSet('linkId', null)->assertSet('open', true);
        Http::assertNothingSent();
    }

    public function test_success_is_dispatched_only_after_remote_verification_is_confirmed(): void
    {
        $context = $this->context();
        $this->plan($context);
        $approvalUuid = (string) Str::uuid();
        $authUuid = (string) Str::uuid();
        $service = $this->mock(PatientApprovalService::class)->makePartial();
        $service->shouldReceive('availableAuthenticationMethods')->once()->andReturn([['uuid' => $authUuid, 'type' => 'OTP']]);
        $service->shouldReceive('create')->once()->andReturn(['approval_id' => $approvalUuid, 'link_id' => null]);
        $service->shouldReceive('details')->once()->andReturn(['is_verified' => false, 'authentication_method_current' => ['type' => 'OTP']]);
        $service->shouldReceive('resolveConfirmationMethod')->once()->andReturn(['type' => 'OTP']);
        $service->shouldReceive('smsResendAvailableAt')->once()->andReturn(now()->addMinute()->timestamp);
        $service->shouldReceive('verify')->once()->with(Mockery::any(), $approvalUuid, 1234)->andReturn(null);

        $component = $this->approvalComponent($context)->call('openDrawer')->set('selectedAuthUuid', $authUuid)->call('create')
            ->assertSet('step', 'code')->assertNotDispatched('care-plan-read-access-granted');
        $component->set('verificationCode', '1234')->call('verifyCode')->assertSet('open', false)
            ->assertDispatched('care-plan-read-access-granted')->assertNoRedirect();
        Http::assertNothingSent();
    }

    public function test_concurrent_request_is_not_created_while_lock_is_held(): void
    {
        $context = $this->context();
        $this->plan($context);
        $lock = Cache::lock('care-plan-read-approval:'.$context['legalEntity']->id.':'.$context['person']->id.':'.$context['employee']->uuid, 300);
        $this->assertTrue($lock->get());
        $this->mock(PatientApprovalService::class)->shouldNotReceive('create');
        try {
            $this->approvalComponent($context)->call('openDrawer')->call('create')->assertSee(__('care-plan.read_access_pending'))
                ->assertNotDispatched('care-plan-read-access-granted');
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    public function test_async_creation_records_resources_for_resumption_before_dispatching_polling(): void
    {
        $context = $this->context();
        $plan = $this->plan($context);
        $payload = [
            'access_level' => 'read', 'authorize_with' => (string) Str::uuid(),
            'created_by' => ['identifier' => ['value' => $context['employee']->uuid]],
            'granted_to' => ['identifier' => ['value' => $context['employee']->uuid, 'type' => ['coding' => [['code' => 'employee']]]]],
            'resources' => [['identifier' => ['value' => $plan->uuid, 'type' => ['coding' => [['code' => 'care_plan']]]]]],
        ];
        $this->mock(ApprovalApi::class)->shouldReceive('createApproval')->once()->with($context['person']->uuid, $payload)
            ->andReturn(new EHealthResponse(new PsrResponse(202, [], json_encode(['data' => ['status' => 'pending', 'links' => [['entity' => 'job', 'href' => '/api/jobs/create']]]])), (new ReflectionMethod(ApprovalApi::class, 'validateCreate'))->getClosure(new ApprovalApi())));
        session()->put(config('ehealth.api.oauth.bearer_token'), 'synthetic-test-token');

        $result = app(PatientApprovalService::class)->create($context['person'], $payload);

        $this->assertNull($result['approval_id']);
        $pending = MedicalEventsRepository::approval()->pendingCarePlanReadRequest($context['person'], $context['employee']->uuid, [$plan->uuid]);
        $this->assertNotNull($pending);
        $this->assertSame($result['link_id'], $pending->ehealthLinks->first()->id);
        $this->assertSame([$plan->uuid], MedicalEventsRepository::approval()->carePlanReadRequestUuids($pending));
        $this->assertSame('create', $pending->ehealthLinks->first()->job->request_data['patientApprovalAction']);
    }

    public function test_remote_creation_failure_stays_in_the_form_with_visible_error(): void
    {
        $context = $this->context();
        $this->plan($context);
        $authUuid = (string) Str::uuid();
        $service = $this->mock(PatientApprovalService::class)->makePartial();
        $service->shouldReceive('availableAuthenticationMethods')->once()->andReturn([['uuid' => $authUuid, 'type' => 'OTP']]);
        $service->shouldReceive('create')->once()->andThrow(new EHealthResponseException(new Response(new PsrResponse(403, [], json_encode(['error' => ['message' => 'Access denied']])))));

        $this->approvalComponent($context)->call('openDrawer')->set('selectedAuthUuid', $authUuid)->call('create')
            ->assertSet('open', true)->assertHasErrors('approvalCreation')->assertSee(__('approval.create.create_ehealth_failed'))
            ->assertNotDispatched('care-plan-read-access-granted')->assertNoRedirect();
        $this->assertSame(0, Approval::count());
        Bus::assertNothingDispatched();
    }

    public function test_failed_background_creation_preserves_the_patient_plan_page_and_selection(): void
    {
        $context = $this->context();
        $plan = $this->plan($context);
        $authUuid = (string) Str::uuid();
        $service = $this->mock(PatientApprovalService::class)->makePartial();
        $service->shouldReceive('availableAuthenticationMethods')->once()->andReturn([['uuid' => $authUuid, 'type' => 'OTP']]);
        $service->shouldReceive('create')->once()->andReturn(['approval_id' => null, 'link_id' => 42]);
        $service->shouldReceive('jobStatus')->once()->with(42, Mockery::any())->andReturn(['status' => 'failed', 'message' => 'Background request rejected']);

        $this->approvalComponent($context)->call('openDrawer')->set('selectedAuthUuid', $authUuid)->call('create')->call('poll')
            ->assertSet('open', true)->assertSet('step', 'form')->assertSet('linkId', null)->assertSet('resourceIds', [$plan->uuid])
            ->assertSee('Background request rejected')->assertNotDispatched('care-plan-read-access-granted')->assertNoRedirect();
        Http::assertNothingSent();
    }

    public function test_service_does_not_persist_or_dispatch_an_unvalidated_creation_receipt(): void
    {
        $context = $this->context();
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->once()->andReturnTrue();
        $response->shouldReceive('validate')->once()->andThrow(ValidationException::withMessages(['links' => 'Invalid pending receipt']));
        $response->shouldNotReceive('getData');
        $this->mock(ApprovalApi::class)->shouldReceive('createApproval')->once()->andReturn($response);
        try {
            app(PatientApprovalService::class)->create($context['person'], []);
            $this->fail('Malformed receipt was accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, Approval::count());
            Bus::assertNothingDispatched();
        }
    }

    public function test_confirmed_details_activate_the_existing_pending_approval_without_a_remote_status_field(): void
    {
        $context = $this->context();
        $approval = Approval::create([
            'uuid' => (string) Str::uuid(), 'approvable_type' => $context['person']->getMorphClass(),
            'approvable_id' => $context['person']->id, 'status' => 'pending', 'is_verified' => false, 'access_level' => 'read',
        ]);
        $this->mock(PatientApprovalService::class)->makePartial()->shouldReceive('details')->once()->andReturn([
            'id' => $approval->uuid, 'is_verified' => true, 'expires_at' => now()->addHour()->toIso8601String(),
        ]);

        app(PatientApprovalService::class)->syncVerified($context['person'], $approval->uuid);

        $this->assertSame('active', $approval->fresh()->status);
        $this->assertTrue($approval->fresh()->getAttribute('is_verified'));
        $this->assertSame(1, Approval::count());
    }

    public function test_historical_verification_cannot_confirm_expired_or_revoked_consent(): void
    {
        $context = $this->context();
        $service = $this->mock(PatientApprovalService::class)->makePartial();
        $service->shouldReceive('details')->andReturn(
            ['is_verified' => true, 'expires_at' => now()->subDay()->toIso8601String()],
            ['is_verified' => true, 'status' => 'terminated', 'expires_at' => now()->addDay()->toIso8601String()],
        );
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $service->syncVerified($context['person'], (string) Str::uuid());
                $this->fail('Unavailable consent was confirmed.');
            } catch (RuntimeException $exception) {
                $this->assertSame('eHealth has not confirmed an active approval.', $exception->getMessage());
            }
        }
        $this->assertSame(0, Approval::count());
        Bus::assertNothingDispatched();
    }

    public function test_details_for_another_approval_cannot_be_used_as_confirmation(): void
    {
        $context = $this->context();
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('successful')->once()->andReturnTrue();
        $response->shouldReceive('validate')->once()->andReturn(['id' => (string) Str::uuid(), 'is_verified' => true]);
        $response->shouldNotReceive('getData');
        $this->mock(ApprovalApi::class)->shouldReceive('getApprovalDetails')->once()->andReturn($response);
        $this->expectException(EHealthValidationException::class);

        app(PatientApprovalService::class)->syncVerified($context['person'], (string) Str::uuid());
    }
}
