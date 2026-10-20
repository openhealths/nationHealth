<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Classes\eHealth\Api\EmployeeRequest as EmployeeRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Employee\RevisionStatus;
use App\Events\EHealthUserLogin;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Jobs\CompleteSync;
use App\Jobs\EmployeeRequestDetailsUpsert;
use App\Jobs\EmployeeRequestsSyncAll;
use App\Listeners\eHealth\EmployeeCreate;
use App\Livewire\EmployeeRequest\EmployeeRequestIndex;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\Revision;
use App\Models\User;
use App\Services\Employee\EmployeeRequestProcessor;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;
use UnexpectedValueException;

class EmployeeLegalEntityIsolationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function entity(): LegalEntity
    {
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        return LegalEntity::create([
            'uuid' => (string) Str::uuid(), 'edrpou' => '1234567890',
            'legal_entity_type_id' => $typeId, 'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED', 'is_active' => true,
        ]);
    }

    private function request(LegalEntity $entity, array $overrides = []): EmployeeRequest
    {
        return EmployeeRequest::create(array_merge([
            'uuid' => (string) Str::uuid(), 'legal_entity_id' => $entity->id,
            'legal_entity_uuid' => $entity->uuid, 'status' => 'NEW', 'position' => 'P1',
            'employee_type' => 'DOCTOR', 'start_date' => '2026-01-01',
        ], $overrides));
    }

    private function party(): Party
    {
        return Party::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Synthetic', 'last_name' => 'Person',
            'birth_date' => '1990-01-01', 'gender' => 'FEMALE', 'tax_id' => '1234567890',
        ]);
    }

    private function details(LegalEntity $entity, string $uuid, array $overrides = []): EHealthResponse
    {
        $raw = array_merge([
            'id' => $uuid, 'legal_entity_id' => $entity->uuid, 'division_id' => null,
            'employee_id' => null, 'status' => 'NEW', 'position' => 'P1', 'employee_type' => 'DOCTOR',
            'start_date' => '2026-01-01', 'inserted_at' => '2026-01-01T10:00:00Z',
            'updated_at' => '2026-01-01T10:00:00Z',
            'party' => ['email' => 'synthetic@example.invalid', 'first_name' => 'Synthetic',
                'last_name' => 'Person', 'gender' => 'FEMALE', 'birth_date' => '1990-01-01',
                'tax_id' => '1234567890', 'documents' => [], 'phones' => []],
        ], $overrides);
        $api = app(EmployeeRequestApi::class);

        return new EHealthResponse(
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => $raw])),
            fn ($response) => (new ReflectionMethod($api, 'validate'))->invoke($api, $response),
            $api->mapRequestCreate(...),
        );
    }

    private function process(object $job, ?EHealthResponse $response): void
    {
        (new ReflectionMethod($job, 'processResponse'))->invoke($job, $response);
    }

    public static function statuses(): array
    {
        return [['NEW'], ['APPROVED'], ['REJECTED'], ['EXPIRED']];
    }

    public function test_edrpou_list_only_returns_candidates_without_writing_statuses_or_skeletons(): void
    {
        $a = $this->entity();
        $b = $this->entity();
        $request = $this->request($b);
        $uuid = (string) Str::uuid();
        $candidates = app(EmployeeRequestProcessor::class)->processBatch([
            ['uuid' => $request->uuid, 'status' => 'REJECTED'],
            ['uuid' => $uuid], ['uuid' => $uuid],
            ['uuid' => (string) Str::uuid(), 'legal_entity_uuid' => $a->uuid],
        ], $b);
        $this->assertSame($a->edrpou, $b->edrpou);
        $this->assertSame([$request->uuid, $uuid], $candidates);
        $this->assertSame('NEW', $request->fresh()->status->value);
        $this->assertDatabaseCount('employee_requests', 1);
    }

    #[DataProvider('statuses')]
    public function test_foreign_details_never_create_request_or_revision(string $status): void
    {
        $a = $this->entity();
        $b = $this->entity();
        $uuid = (string) Str::uuid();
        $this->process(new EmployeeRequestDetailsUpsert($uuid, $b), $this->details($a, $uuid, ['status' => $status]));
        $this->assertDatabaseCount('employee_requests', 0);
        $this->assertDatabaseCount('revisions', 0);
    }

    #[DataProvider('statuses')]
    public function test_own_details_are_imported_with_correct_identity_and_revision_status(string $status): void
    {
        $entity = $this->entity();
        $uuid = (string) Str::uuid();
        $this->process(new EmployeeRequestDetailsUpsert($uuid, $entity), $this->details($entity, $uuid, ['status' => $status]));
        $request = EmployeeRequest::sole();
        $expectedRevisionStatus = match ($status) {
            'NEW' => RevisionStatus::PENDING,
            'APPROVED' => RevisionStatus::APPLIED,
            default => RevisionStatus::OUTDATED,
        };
        $this->assertSame($uuid, $request->uuid);
        $this->assertSame($entity->id, $request->legalEntityId);
        $this->assertSame($entity->uuid, $request->legalEntityUuid);
        $this->assertSame($expectedRevisionStatus, $request->revision->status);
        $this->assertSame($status === 'NEW', $request->appliedAt === null);
    }

    public function test_existing_foreign_uuid_is_neither_copied_nor_reassigned(): void
    {
        $a = $this->entity();
        $b = $this->entity();
        $request = $this->request($a);
        foreach ([$a, $b] as $responseEntity) {
            $this->process(new EmployeeRequestDetailsUpsert($request->uuid, $b), $this->details($responseEntity, $request->uuid));
        }
        $this->assertDatabaseCount('employee_requests', 1);
        $this->assertDatabaseCount('revisions', 0);
        $this->assertSame($a->id, $request->fresh()->legalEntityId);
    }

    public function test_mismatched_response_uuid_is_not_persisted(): void
    {
        $entity = $this->entity();
        $this->process(new EmployeeRequestDetailsUpsert((string) Str::uuid(), $entity), $this->details($entity, (string) Str::uuid()));
        $this->assertDatabaseCount('employee_requests', 0);
    }

    #[DataProvider('statuses')]
    public function test_single_request_sync_does_not_apply_a_foreign_decision(string $status): void
    {
        $a = $this->entity();
        $b = $this->entity();
        $request = $this->request($b);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'synthetic-token');
        $response = $this->details($a, $request->uuid, ['status' => $status]);
        $api = Mockery::mock(EmployeeRequestApi::class);
        $api->shouldReceive('getDetails')->once()->with($request->uuid)->andReturn($response);
        $this->instance(EmployeeRequestApi::class, $api);
        try {
            app(EmployeeRequestProcessor::class)->syncSinglePendingRequest($request, $b);
            $this->fail('Foreign decision was accepted.');
        } catch (UnexpectedValueException) {
            $this->assertSame('NEW', $request->fresh()->status->value);
            $this->assertNull($request->appliedAt);
        }
    }

    public static function inaccessibleStatuses(): array
    {
        return [[403], [404]];
    }

    #[DataProvider('inaccessibleStatuses')]
    public function test_inaccessible_candidate_is_skipped_without_a_local_skeleton(int $status): void
    {
        $entity = $this->entity();
        $uuid = (string) Str::uuid();
        $api = Mockery::mock(EmployeeRequestApi::class);
        $api->shouldReceive('withToken')->with('synthetic-token')->andReturnSelf();
        $api->shouldReceive('getDetails')->with($uuid)->andThrow(new EHealthResponseException(
            new \Illuminate\Http\Client\Response(new Response($status, ['Content-Type' => 'application/json'], '{}')),
        ));
        $this->instance(EmployeeRequestApi::class, $api);
        $job = new EmployeeRequestDetailsUpsert($uuid, $entity);
        $response = (new ReflectionMethod($job, 'sendRequest'))->invoke($job, 'synthetic-token');
        $this->assertNull($response);
        $this->process($job, $response);
        $this->assertDatabaseCount('employee_requests', 0);
    }

    public function test_other_api_errors_are_not_treated_as_foreign_candidates(): void
    {
        $api = Mockery::mock(EmployeeRequestApi::class);
        $api->shouldReceive('withToken')->andReturnSelf();
        $api->shouldReceive('getDetails')->andThrow(new EHealthResponseException(
            new \Illuminate\Http\Client\Response(new Response(500, ['Content-Type' => 'application/json'], '{}')),
        ));
        $this->instance(EmployeeRequestApi::class, $api);
        $job = new EmployeeRequestDetailsUpsert((string) Str::uuid(), $this->entity());
        $this->expectException(EHealthResponseException::class);
        (new ReflectionMethod($job, 'sendRequest'))->invoke($job, 'synthetic-token');
    }

    public function test_every_page_verifies_its_candidates_before_continuing(): void
    {
        $entity = $this->entity();
        $job = new EmployeeRequestsSyncAll($entity, isFirstLogin: true);
        $uuids = [(string) Str::uuid(), (string) Str::uuid()];
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->andReturn([['uuid' => $uuids[0]], ['uuid' => $uuids[1]]]);
        $this->process($job, $response);
        $chain = (new ReflectionMethod($job, 'getNextPageJob'))->invoke($job);
        foreach ($uuids as $uuid) {
            $this->assertInstanceOf(EmployeeRequestDetailsUpsert::class, $chain);
            $this->assertSame($uuid, $chain->employeeRequest);
            $this->assertTrue((new ReflectionProperty($chain, 'isFirstLogin'))->getValue($chain));
            $chain = (new ReflectionProperty($chain, 'nextEntity'))->getValue($chain);
        }
        $this->assertInstanceOf(EmployeeRequestsSyncAll::class, $chain);
        $this->assertSame(2, (new ReflectionProperty($chain, 'page'))->getValue($chain));
        $terminal = $job->getRequestDetailsChain(new CompleteSync($entity), [$uuids[0]]);
        $this->assertInstanceOf(CompleteSync::class, (new ReflectionProperty($terminal, 'nextEntity'))->getValue($terminal));
        $this->assertDatabaseCount('employee_requests', 0);
    }

    public static function manualPages(): array
    {
        return [[true, true], [true, false], [false, true], [false, false]];
    }

    #[DataProvider('manualPages')]
    public function test_manual_sync_queues_first_page_details_before_following_pages(bool $hasMorePages, bool $hasCandidates): void
    {
        $entity = $this->entity();
        $uuid = (string) Str::uuid();
        $user = User::create(['email' => 'synthetic@example.invalid', 'password' => 'synthetic']);
        $this->actingAs($user);
        $this->instance('legalEntity', $entity);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'synthetic-token');
        Gate::before(fn () => true);
        Bus::fake();
        Notification::fake();
        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->andReturn($hasCandidates ? [['uuid' => $uuid]] : []);
        $response->shouldReceive('isNotLast')->andReturn($hasMorePages);
        $api = Mockery::mock(EmployeeRequestApi::class);
        $api->shouldReceive('getMany')->once()->with(['edrpou' => $entity->edrpou])->andReturn($response);
        $this->instance(EmployeeRequestApi::class, $api);
        $component = Mockery::mock(EmployeeRequestIndex::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $component->shouldReceive('isSyncProcessing')->andReturnFalse();
        $component->shouldReceive('dispatch', 'resetPage');
        (new ReflectionProperty(EmployeeRequestIndex::class, 'legalEntity'))->setValue($component, $entity);
        $component->sync(app(EmployeeRequestProcessor::class));

        Bus::assertBatchCount(1);
        Bus::assertBatched(function ($batch) use ($hasCandidates, $hasMorePages, $uuid) {
            $next = $batch->jobs->first();
            if ($hasCandidates) {
                if (!$next instanceof EmployeeRequestDetailsUpsert || $next->employeeRequest !== $uuid) {
                    return false;
                }
                $next = (new ReflectionProperty($next, 'nextEntity'))->getValue($next);
            }

            return $hasMorePages
                ? $next instanceof EmployeeRequestsSyncAll && (new ReflectionProperty($next, 'page'))->getValue($next) === 2
                : $next === null;
        });
        $this->assertDatabaseCount('employee_requests', 0);
    }

    public function test_jobs_queued_with_models_before_the_fix_remain_compatible(): void
    {
        $entity = $this->entity();
        $request = $this->request($entity);
        $job = new EmployeeRequestDetailsUpsert($request, $entity);
        $job->employeeRequest = $request;
        $this->process($job, $this->details($entity, $request->uuid));
        $this->assertSame('COMPLETED', $request->fresh()->sync_status);
        $this->assertDatabaseCount('employee_requests', 1);
    }

    public function test_owner_login_uses_only_current_entity_requests_with_the_same_email(): void
    {
        $a = $this->entity();
        $b = $this->entity();
        $user = User::create(['uuid' => (string) Str::uuid(), 'email' => 'synthetic@example.invalid', 'password' => 'synthetic']);
        $ownParty = $this->party();
        $foreign = $this->request($a, ['email' => $user->email, 'party_id' => $this->party()->id, 'employee_type' => 'OWNER']);
        $this->request($b, ['email' => $user->email, 'party_id' => $foreign->partyId, 'legal_entity_uuid' => $a->uuid]);
        $event = new EHealthUserLogin($user, $b, $user->uuid, []);
        app(EmployeeCreate::class)->handle($event);
        $this->assertNull($user->fresh()->partyId);
        $this->request($b, ['email' => $user->email, 'party_id' => $ownParty->id]);
        app(EmployeeCreate::class)->handle($event);
        $this->assertSame($ownParty->id, $user->fresh()->partyId);
        $this->assertSame('NEW', $foreign->fresh()->status->value);
        Http::assertNothingSent();
    }

    public function test_list_hides_previously_mislabelled_requests_but_keeps_current_requests_and_drafts(): void
    {
        $a = $this->entity();
        $b = $this->entity();
        $this->instance('legalEntity', $b);
        $own = $this->request($b);
        $draft = $this->request($b, ['uuid' => null, 'legal_entity_uuid' => null]);
        $foreign = $this->request($a);
        $mislabelled = $this->request($b, ['legal_entity_uuid' => $a->uuid]);
        foreach ([$own, $draft, $foreign, $mislabelled] as $request) {
            $request->revision()->save(new Revision(['status' => RevisionStatus::PENDING, 'data' => []]));
        }
        $ids = (new EmployeeRequestIndex())->requests()->getCollection()->modelKeys();
        $this->assertEqualsCanonicalizing([$own->id, $draft->id], $ids);
    }

    public function test_verified_approval_still_updates_the_current_employee(): void
    {
        $entity = $this->entity();
        $party = $this->party();
        $employee = Employee::create([
            'uuid' => (string) Str::uuid(), 'legal_entity_id' => $entity->id, 'party_id' => $party->id,
            'status' => 'APPROVED', 'is_active' => true, 'position' => 'P1',
            'employee_type' => 'DOCTOR', 'start_date' => '2026-01-01',
        ]);
        $request = $this->request($entity, ['employee_id' => $employee->id, 'party_id' => $party->id]);
        $request->revision()->save(new Revision(['status' => RevisionStatus::PENDING, 'data' => [
            'party' => ['uuid' => $party->uuid, 'tax_id' => '1234567890', 'first_name' => 'Synthetic', 'last_name' => 'Person'],
            'employee_request_data' => ['position' => 'P2', 'employee_type' => 'DOCTOR', 'start_date' => '2026-01-01'],
            'documents' => [], 'phones' => [],
        ]]));
        $this->process(new EmployeeRequestDetailsUpsert($request->uuid, $entity), $this->details($entity, $request->uuid, [
            'status' => 'APPROVED', 'employee_id' => $employee->uuid, 'position' => 'P2',
        ]));
        $this->assertSame('P2', $employee->fresh()->position);
        $this->assertSame($entity->id, $employee->fresh()->legalEntityId);
        $this->assertSame($party->id, $request->fresh()->partyId);
        $this->assertSame('APPROVED', $request->fresh()->status->value);
    }
}
