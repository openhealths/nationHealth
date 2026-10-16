<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Classes\eHealth\Api\EmployeeRequest as EmployeeRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Employee\RevisionStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Jobs\CompleteSync;
use App\Jobs\EmployeeRequestDetailsUpsert;
use App\Jobs\EmployeeRequestsSyncAll;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\User;
use App\Services\Employee\EmployeeRequestProcessor;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class EmployeeRequestRoutingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('testing', config('database.connections.pgsql.database'));
        $this->assertSame('routing-db', config('database.connections.pgsql.host'));
        Http::preventStrayRequests();
    }

    private function entity(array $overrides = []): LegalEntity
    {
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        return LegalEntity::create(array_merge([
            'uuid' => (string) Str::uuid(), 'edrpou' => '1234567890',
            'legal_entity_type_id' => $typeId, 'is_active' => true,
            'status' => 'ACTIVE', 'sync_status' => 'COMPLETED',
        ], $overrides));
    }

    private function stage(LegalEntity $source, string $uuid): EmployeeRequest
    {
        app(EmployeeRequestProcessor::class)->processBatch([['uuid' => $uuid, 'status' => 'NEW']], $source);

        return EmployeeRequest::where('uuid', $uuid)->sole();
    }

    private function details(LegalEntity $owner, string $uuid, array $overrides = []): EHealthResponse
    {
        $raw = [
            'id' => $uuid, 'legal_entity_id' => $owner->uuid, 'division_id' => null,
            'status' => 'NEW', 'position' => 'P1', 'employee_type' => 'DOCTOR',
            'start_date' => '2026-01-01', 'inserted_at' => '2026-01-01T10:00:00Z',
            'updated_at' => '2026-01-01T10:00:00Z',
            'party' => ['email' => 'synthetic@example.invalid', 'first_name' => 'Synthetic',
                'last_name' => 'Person', 'gender' => 'FEMALE', 'birth_date' => '1990-01-01',
                'tax_id' => '1234567890', 'documents' => [], 'phones' => []],
        ];
        $raw = array_merge($raw, $overrides);
        $api = app(EmployeeRequestApi::class);

        return new EHealthResponse(
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => $raw])),
            fn ($response) => (new ReflectionMethod($api, 'validate'))->invoke($api, $response),
            $api->mapRequestCreate(...),
        );
    }

    private function process(EmployeeRequestDetailsUpsert $job, EHealthResponse $response): void
    {
        (new ReflectionMethod($job, 'processResponse'))->invoke($job, $response);
    }

    private function queuedUuids(?object $job): array
    {
        $uuids = [];
        while ($job instanceof EmployeeRequestDetailsUpsert) {
            $uuids[] = $job->employeeRequest->uuid;
            $job = (new ReflectionProperty($job, 'nextEntity'))->getValue($job);
        }

        return $uuids;
    }

    public function test_candidates_are_stored_without_assigning_a_legal_entity(): void
    {
        $source = $this->entity();
        $uuid = (string) Str::uuid();
        $request = $this->stage($source, $uuid);
        $this->assertNull($request->legalEntityId);
        $this->assertNull($request->legalEntityUuid);
        $this->assertSame('PARTIAL', $request->sync_status);
        $job = new EmployeeRequestsSyncAll($source);
        $chain = $job->getRequestDetailsChain(new CompleteSync($source), [$uuid]);
        $this->assertSame([$uuid], $this->queuedUuids($chain));
    }

    public function test_repeated_staging_does_not_duplicate_or_clear_known_ownership(): void
    {
        $source = $this->entity();
        $owner = $this->entity();
        $uuid = (string) Str::uuid();
        $request = $this->stage($source, $uuid);
        $this->stage($source, $uuid);
        $request->update(['legal_entity_id' => $owner->id, 'legal_entity_uuid' => $owner->uuid, 'sync_status' => 'COMPLETED', 'status' => 'APPROVED', 'applied_at' => now()]);
        $this->stage($source, $uuid);
        $this->assertDatabaseCount('employee_requests', 1);
        $this->assertSame($owner->id, $request->fresh()->legalEntityId);
        $this->assertSame($owner->uuid, $request->fresh()->legalEntityUuid);
        $this->assertSame('COMPLETED', $request->fresh()->sync_status);
    }

    public function test_both_entities_can_be_active_with_the_same_edrpou(): void
    {
        $a = $this->entity();
        $b = $this->entity();
        $ids = LegalEntity::whereEdrpou($a->edrpou)->where('is_active', true)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);
    }

    public function test_response_uuid_routes_candidates_to_their_exact_local_entities(): void
    {
        $a = $this->entity();
        $b = $this->entity();
        foreach ([$a, $b] as $owner) {
            $request = $this->stage($b, (string) Str::uuid());
            $this->process(new EmployeeRequestDetailsUpsert($request, $b), $this->details($owner, $request->uuid));
            $this->assertSame($owner->id, $request->fresh()->legalEntityId);
            $this->assertSame($owner->uuid, $request->fresh()->legalEntityUuid);
            $this->assertSame('COMPLETED', $request->fresh()->sync_status);
        }
        $this->assertDatabaseCount('employee_requests', 2);
        $this->assertDatabaseCount('revisions', 2);
    }

    public static function unavailableEntities(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('unavailableEntities')]
    public function test_unknown_or_inactive_entity_is_not_replaced_with_another_active_entity(bool $inactive): void
    {
        $source = $this->entity();
        $owner = $inactive ? $this->entity(['is_active' => false]) : new LegalEntity(['uuid' => (string) Str::uuid()]);
        $request = $this->stage($source, (string) Str::uuid());
        $this->process(new EmployeeRequestDetailsUpsert($request, $source), $this->details($owner, $request->uuid));
        $this->assertNull($request->fresh()->legalEntityId);
        $this->assertNull($request->fresh()->legalEntityUuid);
        $this->assertDatabaseCount('revisions', 0);
    }

    public function test_wrong_response_request_uuid_leaves_candidate_unassigned(): void
    {
        $owner = $this->entity();
        $request = $this->stage($owner, (string) Str::uuid());
        $this->process(new EmployeeRequestDetailsUpsert($request, $owner), $this->details($owner, (string) Str::uuid()));
        $this->assertNull($request->fresh()->legalEntityId);
        $this->assertDatabaseCount('revisions', 0);
    }

    public static function remoteStatuses(): array
    {
        return [['NEW', RevisionStatus::PENDING], ['APPROVED', RevisionStatus::APPLIED],
            ['REJECTED', RevisionStatus::OUTDATED], ['EXPIRED', RevisionStatus::OUTDATED]];
    }

    #[DataProvider('remoteStatuses')]
    public function test_terminal_and_pending_revisions_belong_to_the_response_entity(string $status, RevisionStatus $revisionStatus): void
    {
        $source = $this->entity();
        $owner = $this->entity();
        $request = $this->stage($source, (string) Str::uuid());
        $this->process(new EmployeeRequestDetailsUpsert($request, $source), $this->details($owner, $request->uuid, ['status' => $status]));
        $this->assertSame($owner->id, $request->fresh()->legalEntityId);
        $this->assertSame($revisionStatus, $request->fresh()->revision->status);
        $this->assertSame($status === 'NEW', $request->fresh()->appliedAt === null);
    }

    public static function unavailableResponses(): array
    {
        return [[403], [404]];
    }

    #[DataProvider('unavailableResponses')]
    public function test_unavailable_candidate_is_suspended_and_can_be_staged_again(int $status): void
    {
        $source = $this->entity();
        $request = $this->stage($source, (string) Str::uuid());
        $api = Mockery::mock(EmployeeRequestApi::class);
        $api->shouldReceive('withToken')->andReturnSelf();
        $api->shouldReceive('getDetails')->andThrow(new EHealthResponseException(
            new \Illuminate\Http\Client\Response(new Response($status, [], '{}')),
        ));
        $this->instance(EmployeeRequestApi::class, $api);
        $job = new EmployeeRequestDetailsUpsert($request, $source);
        $response = (new ReflectionMethod($job, 'sendRequest'))->invoke($job, 'synthetic-token');
        $this->assertNull($response);
        $this->assertSame('SUSPENDED', $request->fresh()->sync_status);
        $this->assertNull($request->fresh()->legalEntityId);
        $this->stage($source, $request->uuid);
        $this->assertSame('PARTIAL', $request->fresh()->sync_status);
        $this->assertDatabaseCount('employee_requests', 1);
    }

    public function test_conflicting_previously_assigned_request_is_not_blindly_reassigned(): void
    {
        $a = $this->entity();
        $b = $this->entity();
        $request = $this->stage($b, (string) Str::uuid());
        $request->update(['legal_entity_id' => $b->id, 'legal_entity_uuid' => $b->uuid]);
        $this->process(new EmployeeRequestDetailsUpsert($request, $b), $this->details($a, $request->uuid));
        $this->assertSame($b->id, $request->fresh()->legalEntityId);
        $this->assertDatabaseCount('revisions', 0);
    }

    public function test_resume_and_explicit_candidates_do_not_capture_other_sync_candidates(): void
    {
        $a = $this->entity();
        $b = $this->entity(['edrpou' => '9876543210']);
        $own = $this->stage($a, (string) Str::uuid());
        $other = $this->stage($b, (string) Str::uuid());
        $job = new EmployeeRequestsSyncAll($a);
        $globalChain = (new ReflectionMethod($job, 'getEmployeeRequestDetailsStartJob'))->invoke($job, $a, new CompleteSync($a));
        $this->assertSame([], $this->queuedUuids($globalChain));
        $scopedChain = $job->getRequestDetailsChain(new CompleteSync($a), [$own->uuid]);
        $this->assertSame([$own->uuid], $this->queuedUuids($scopedChain));
    }

    public function test_routing_preserves_the_source_batch_context(): void
    {
        $source = $this->entity();
        $owner = $this->entity();
        $request = $this->stage($source, (string) Str::uuid());
        $response = $this->details($owner, $request->uuid);
        $api = Mockery::mock(EmployeeRequestApi::class)->makePartial();
        $api->shouldReceive('withToken')->with('synthetic-token')->andReturnSelf();
        $api->shouldReceive('getDetails')->once()->with($request->uuid)->andReturn($response);
        $this->instance(EmployeeRequestApi::class, $api);
        $user = User::create(['email' => 'synthetic@example.invalid', 'password' => 'synthetic']);
        $job = new EmployeeRequestDetailsUpsert($request, $source, new CompleteSync($source));
        $job->user = $user;
        $job->withFakeBatch(options: [
            'token' => Crypt::encryptString('synthetic-token'), 'user' => $user,
            'sync_entity' => LegalEntity::ENTITY_EMPLOYEE_REQUEST,
        ]);
        Bus::fake();
        $job->handle();

        $this->assertSame($owner->id, $request->fresh()->legalEntityId);
        $this->assertSame('PROCESSING', $source->fresh()->employee_request_sync_status);
        $continuation = null;
        $continuationOptions = [];
        Bus::assertBatched(function ($batch) use ($source, &$continuation, &$continuationOptions) {
            $nextJob = $batch->jobs->first();
            $this->assertSame($source->id, $nextJob->legalEntity->id);
            $this->assertSame($source->id, $batch->options['legal_entity_id']);
            $this->assertSame(true, $batch->options['legal_entity_id'] === $nextJob->legalEntity->id);
            $continuation = $nextJob;
            $continuationOptions = $batch->options;

            return true;
        });
        Notification::fake();
        $continuation->user = $user;
        $continuation->withFakeBatch(options: $continuationOptions);
        $continuation->handle();
        $this->assertSame('COMPLETED', $source->fresh()->employee_request_sync_status);
    }
}
