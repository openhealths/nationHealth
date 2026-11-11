<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use RuntimeException;
use Illuminate\Support\Facades\DB;
use App\Enums\User\Role;
use App\Enums\Status;

use App\Classes\eHealth\Api\Approval as ApprovalApi;
use App\Classes\eHealth\EHealthResponse;
use App\Models\CarePlan;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\Identifier;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\User;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Services\MedicalEvents\CarePlanApprovalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;
use App\Repositories\Repository;

/**
 * Get approvals must send granted_resource_type + granted_resources filters.
 *
 * @see https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/2115600961/Get+approvals
 */
class CarePlanApprovalSyncFiltersTest extends TestCase
{
    use DatabaseTransactions;

    protected function migrateDatabases()
    {
        $this->artisan('migrate:fresh', [
            '--path' => [
                database_path('migrations'),
                database_path('migrations/install'),
                database_path('migrations/update/0_1'),
            ],
            '--realpath' => true,
        ]);
    }

    public function test_sync_for_care_plan_passes_get_approvals_resource_filters(): void
    {
        $carePlan = $this->makeCarePlan();

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->andReturn([]);
        $response->shouldReceive('getPaging')->andReturn(['page_number' => 1, 'total_pages' => 1]);

        $api = Mockery::mock(ApprovalApi::class);
        $api->shouldReceive('getPatientApprovals')
            ->once()
            ->with($carePlan->person->uuid, [
                'granted_resource_type' => 'care_plan',
                'granted_resources' => $carePlan->uuid,
            ])
            ->andReturn($response);
        $this->instance(ApprovalApi::class, $api);

        app(CarePlanApprovalService::class)->syncForCarePlan($carePlan->fresh(['person']));
    }

    public function test_sync_approvals_passes_get_approvals_resource_filters(): void
    {
        $carePlan = $this->makeCarePlan();

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->andReturn([]);
        $response->shouldReceive('getPaging')->andReturn(['page_number' => 1, 'total_pages' => 1]);

        $api = Mockery::mock(ApprovalApi::class);
        $api->shouldReceive('getPatientApprovals')
            ->once()
            ->with($carePlan->person->uuid, [
                'granted_resource_type' => 'care_plan',
                'granted_resources' => $carePlan->uuid,
            ])
            ->andReturn($response);
        $this->instance(ApprovalApi::class, $api);

        MedicalEventsRepository::approval()->syncApprovals($carePlan->fresh(['person']), 'care_plan');
    }

    public static function verificationFlags(): array
    {
        return ['verified' => [true], 'awaiting patient' => [false]];
    }

    #[DataProvider('verificationFlags')]
    public function test_remote_verification_replaces_local_consent_without_a_status(bool $verified): void
    {
        $plan = $this->makeCarePlan();
        $employeeUuid = (string) Str::uuid();
        $approval = $this->localGrant($plan, $employeeUuid);
        $approval->update(['status' => 'inactive']);
        $data = $this->remoteGrant($plan, $employeeUuid, $approval->uuid);
        $data['is_verified'] = $verified;
        $this->mock(ApprovalApi::class)->shouldReceive('getPatientApprovals')->once()->andReturn($this->approvalPage([$data]));

        MedicalEventsRepository::approval()->syncApprovals($plan, 'care_plan');

        $this->assertSame('inactive', $approval->fresh()->status);
        $this->assertSame($verified, $approval->fresh()->getAttribute('is_verified'));
        $this->assertSame($verified, Repository::carePlan()->hasGrantedApproval($plan->fresh(), $employeeUuid));
    }

    public function test_empty_snapshot_revokes_only_the_requested_scope_and_preserves_pending_jobs(): void
    {
        $plan = $this->makeCarePlan();
        $employeeUuid = (string) Str::uuid();
        $stale = $this->localGrant($plan, $employeeUuid);
        $otherEmployee = $this->localGrant($plan, (string) Str::uuid());
        $otherPlan = $this->localGrant($this->makeCarePlan(), $employeeUuid);
        $pending = $this->localGrant($plan, $employeeUuid);
        $pending->update(['status' => 'pending', 'is_verified' => false]);
        MedicalEventsRepository::approval()->attachEhealthLink($pending, ['href' => '/jobs/pending']);
        $this->mock(ApprovalApi::class)->shouldReceive('getPatientApprovals')->once()->andReturn($this->approvalPage([]));

        $plan->load('approvals.grantedTo');
        MedicalEventsRepository::approval()->syncApprovals($plan, 'care_plan', ['granted_to.identifier.value' => $employeeUuid]);

        $this->assertFalse($stale->fresh()->expiresAt->isFuture());
        $this->assertSame('active', $otherEmployee->fresh()->status);
        $this->assertSame('active', $otherPlan->fresh()->status);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertFalse(Repository::carePlan()->hasGrantedApproval($plan, $employeeUuid));
    }

    public function test_all_pages_are_imported_before_reconciling_missing_grants(): void
    {
        $plan = $this->makeCarePlan();
        $employeeUuid = (string) Str::uuid();
        $first = $this->localGrant($plan, $employeeUuid);
        $second = $this->localGrant($plan, (string) Str::uuid());
        $stale = $this->localGrant($plan, (string) Str::uuid());
        $query = ['granted_resource_type' => 'care_plan', 'granted_resources' => $plan->uuid];
        $api = $this->mock(ApprovalApi::class);
        $api->shouldReceive('getPatientApprovals')->once()->with($plan->person->uuid, $query)
            ->andReturn($this->approvalPage([$this->remoteGrant($plan, $employeeUuid, $first->uuid)], 1, 2));
        $api->shouldReceive('getPatientApprovals')->once()->with($plan->person->uuid, $query + ['page' => 2])
            ->andReturn($this->approvalPage([$this->remoteGrant($plan, $second->grantedTo->value, $second->uuid)], 2, 2));

        MedicalEventsRepository::approval()->syncApprovals($plan, 'care_plan');

        $this->assertSame('active', $first->fresh()->status);
        $this->assertSame('active', $second->fresh()->status);
        $this->assertFalse($stale->fresh()->expiresAt->isFuture());
    }

    public function test_a_failed_later_page_preserves_the_entire_local_snapshot(): void
    {
        $plan = $this->makeCarePlan();
        $employeeUuid = (string) Str::uuid();
        $approval = $this->localGrant($plan, $employeeUuid);
        $query = ['granted_resource_type' => 'care_plan', 'granted_resources' => $plan->uuid];
        $api = $this->mock(ApprovalApi::class);
        $api->shouldReceive('getPatientApprovals')->once()->with($plan->person->uuid, $query)
            ->andReturn($this->approvalPage([$this->remoteGrant($plan, $employeeUuid, $approval->uuid)], 1, 2));
        $api->shouldReceive('getPatientApprovals')->once()->with($plan->person->uuid, $query + ['page' => 2])
            ->andThrow(new RuntimeException('Second page unavailable'));

        try {
            MedicalEventsRepository::approval()->syncApprovals($plan, 'care_plan');
            $this->fail('An incomplete snapshot must not be accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Second page unavailable', $exception->getMessage());
        }
        $this->assertSame('active', $approval->fresh()->status);
        $this->assertTrue(Repository::carePlan()->hasGrantedApproval($plan->fresh(), $employeeUuid));
    }

    public function test_delayed_creation_result_does_not_restore_cancelled_access(): void
    {
        $plan = $this->makeCarePlan();
        $employeeUuid = (string) Str::uuid();
        $approval = $this->localGrant($plan, $employeeUuid);
        $data = $this->remoteGrant($plan, $employeeUuid, $approval->uuid);
        MedicalEventsRepository::approval()->markCancelled($approval->uuid);
        $link = MedicalEventsRepository::approval()->attachEhealthLink($approval->fresh(), ['href' => '/jobs/completed']);

        MedicalEventsRepository::approval()->completeCarePlanRequest($link, $data);

        $this->assertFalse($approval->fresh()->expiresAt->isFuture());
        $this->assertTrue($approval->fresh()->getAttribute('is_verified'));
        $this->assertFalse(Repository::carePlan()->hasGrantedApproval($plan->fresh(), $employeeUuid));
    }

    public function test_patient_owned_care_plan_read_consent_is_stored_and_reused_without_a_status(): void
    {
        $plan = $this->makeCarePlan();
        $employeeUuid = $plan->author->uuid;
        $data = $this->remoteGrant($plan, $employeeUuid, (string) Str::uuid());
        $data['access_level'] = 'read';

        MedicalEventsRepository::approval()->sync($data, $plan->person);

        $approval = Approval::whereUuid($data['id'])->firstOrFail();
        $this->assertNull($approval->status);
        $this->assertTrue($approval->getAttribute('is_verified'));
        $this->assertCount(1, MedicalEventsRepository::approval()->verifiedCarePlanReadRequests($plan->person, $employeeUuid, [$plan->uuid]));

        MedicalEventsRepository::approval()->markCancelled($approval->uuid);

        $this->assertNull($approval->fresh()->status);
        $this->assertFalse($approval->fresh()->expiresAt->isFuture());
        $this->assertCount(0, MedicalEventsRepository::approval()->verifiedCarePlanReadRequests($plan->person, $employeeUuid, [$plan->uuid]));
    }

    public function test_patient_owned_care_plan_request_uses_its_job_instead_of_a_local_status(): void
    {
        $plan = $this->makeCarePlan();
        $employee = $plan->author;
        $payload = app(CarePlanApprovalService::class)->buildCreatePayload($plan, $employee->uuid, 'read');
        $payload['created_by'] = $payload['granted_to'];
        $payload['authorize_with'] = null;

        $approval = MedicalEventsRepository::approval()->storePending($plan->person, $payload, $employee);
        $link = MedicalEventsRepository::approval()->attachEhealthLink($approval, ['href' => '/jobs/pending']);
        MedicalEventsRepository::approval()->recordPatientRequest($link, $payload);

        $this->assertNull($approval->status);
        $this->assertFalse($approval->getAttribute('is_verified'));
        $this->assertSame($approval->id, MedicalEventsRepository::approval()->pendingCarePlanReadRequest($plan->person, $employee->uuid, [$plan->uuid])?->id);
    }

    public function test_patient_snapshot_expires_missing_care_plan_grants_without_changing_other_consent_rules(): void
    {
        $plan = $this->makeCarePlan();
        $person = $plan->person;
        $employeeUuid = $plan->author->uuid;
        $repository = MedicalEventsRepository::approval();
        $data = $this->remoteGrant($plan, $employeeUuid, (string) Str::uuid());
        $data['access_level'] = 'read';
        $stale = $this->patientGrant($data, $person);
        $otherEmployee = $this->patientGrant($this->remoteGrant($plan, (string) Str::uuid(), (string) Str::uuid()), $person);
        $otherPerson = $this->patientGrant($this->remoteGrant($plan, $employeeUuid, (string) Str::uuid()), $this->makeCarePlan()->person);
        $pending = $this->patientGrant(array_replace($data, ['id' => (string) Str::uuid(), 'is_verified' => false]), $person);
        $repository->attachEhealthLink($pending, ['href' => '/jobs/pending']);
        $episodeData = array_replace($data, ['id' => (string) Str::uuid()]);
        $episodeData['granted_resources'][0]['identifier']['type']['coding'][0]['code'] = 'episode_of_care';
        $episodeData['granted_resources'][0]['identifier']['value'] = (string) Str::uuid();
        $episodeApproval = $this->patientGrant($episodeData, $person);
        $unchanged = collect([$otherEmployee, $otherPerson, $pending])->mapWithKeys(
            static fn (Approval $approval): array => [$approval->id => $approval->fresh()->getAttributes()]
        );
        $this->assertCount(1, $repository->verifiedCarePlanReadRequests($person, $employeeUuid, [$plan->uuid]));
        $this->mock(ApprovalApi::class)->shouldReceive('getMany')->once()->andReturn($this->approvalPage([]));

        $repository->syncApprovals($person, 'person', ['granted_to.identifier.value' => $employeeUuid]);

        $this->assertNull($stale->fresh()->status);
        $this->assertTrue($stale->fresh()->getAttribute('is_verified'));
        $this->assertFalse($stale->fresh()->expiresAt->isFuture());
        $this->assertCount(0, $repository->verifiedCarePlanReadRequests($person, $employeeUuid, [$plan->uuid]));
        $this->assertSame('inactive', $episodeApproval->fresh()->status);
        $this->assertTrue($episodeApproval->fresh()->expiresAt->isFuture());
        foreach ([$otherEmployee, $otherPerson, $pending] as $approval) {
            $this->assertSame($unchanged[$approval->id], $approval->fresh()->getAttributes());
        }
    }

    public function test_failed_patient_snapshot_preserves_verified_care_plan_access(): void
    {
        $plan = $this->makeCarePlan();
        $repository = MedicalEventsRepository::approval();
        $data = $this->remoteGrant($plan, $plan->author->uuid, (string) Str::uuid());
        $data['access_level'] = 'read';
        $approval = $this->patientGrant($data, $plan->person);
        $before = $approval->fresh()->getAttributes();
        $this->mock(ApprovalApi::class)->shouldReceive('getMany')->once()->andThrow(new RuntimeException('Snapshot unavailable'));

        $repository->syncApprovals($plan->person, 'person');

        $this->assertSame($before, $approval->fresh()->getAttributes());
        $this->assertCount(1, $repository->verifiedCarePlanReadRequests($plan->person, $plan->author->uuid, [$plan->uuid]));
    }

    private function patientGrant(array $data, Person $person): Approval
    {
        MedicalEventsRepository::approval()->sync($data, $person);

        return Approval::whereUuid($data['id'])->firstOrFail();
    }

    private function localGrant(CarePlan $plan, string $employeeUuid): Approval
    {
        $identifier = Identifier::create(['value' => $employeeUuid]);

        return $plan->approvals()->create([
            'uuid' => (string) Str::uuid(), 'granted_to_id' => $identifier->id,
            'granted_to_type' => 'employee', 'status' => 'active', 'is_verified' => true,
            'access_level' => 'write', 'expires_at' => now()->addDay(),
        ]);
    }

    private function remoteGrant(CarePlan $plan, string $employeeUuid, string $uuid): array
    {
        return [
            'id' => $uuid, 'is_verified' => true, 'access_level' => 'write',
            'expires_at' => now()->addDay()->toIso8601String(),
            'granted_to' => ['identifier' => ['value' => $employeeUuid, 'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'employee']]]]],
            'granted_resources' => [['identifier' => ['value' => $plan->uuid, 'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'care_plan']]]]]],
        ];
    }

    private function approvalPage(array $data, int $page = 1, int $totalPages = 1): EHealthResponse
    {
        return new EHealthResponse(new Response(200, [], json_encode([
            'data' => $data, 'paging' => ['page_number' => $page, 'total_pages' => $totalPages],
        ], JSON_THROW_ON_ERROR)), fn (EHealthResponse $response): array => $response->getData());
    }

    private function makeCarePlan(): CarePlan
    {
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Doctor',
            'last_name' => 'Who',
            'tax_id' => (string) random_int(1000000000, 9999999999),
            'birth_date' => '1970-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'doctor-'.Str::random(8).'@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. Test',
            'employee_type' => Role::DOCTOR->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'John',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        return CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => $employee->id,
            'legal_entity_id' => $legalEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Test Care Plan',
            'status' => 'draft',
        ]);
    }
}
