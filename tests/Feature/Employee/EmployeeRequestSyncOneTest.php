<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Classes\eHealth\Api\EmployeeRequest as EmployeeRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Employee\RequestStatus;
use App\Enums\Employee\RevisionStatus;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\Revision;
use App\Services\Employee\EmployeeRequestMatcher;
use App\Services\Employee\EmployeeRequestProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeRequestSyncOneTest extends TestCase
{
    use RefreshDatabase;

    protected function migrateDatabases(): void
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

    private function makeLegalEntity(): LegalEntity
    {
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        return LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
    }

    private function makePendingRequest(LegalEntity $legalEntity, array $overrides = []): EmployeeRequest
    {
        $request = EmployeeRequest::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $legalEntity->id,
            'status' => RequestStatus::NEW,
            'position' => 'P1',
            'employee_type' => 'DOCTOR',
            'start_date' => '2024-01-10',
            'email' => 'doc@example.com',
        ], $overrides));

        $revision = new Revision([
            'status' => RevisionStatus::PENDING,
            'data' => [
                'party' => [
                    'tax_id' => '1234567890',
                    'first_name' => 'Test',
                    'last_name' => 'Doctor',
                    'second_name' => null,
                ],
                'employee' => [
                    'position' => 'P1',
                    'employee_type' => 'DOCTOR',
                    'start_date' => '2024-01-10',
                ],
            ],
        ]);
        $request->revision()->save($revision);
        $request->load('revision');

        return $request;
    }

    #[Test]
    public function sync_fails_when_request_is_not_pending(): void
    {
        $legalEntity = $this->makeLegalEntity();
        $request = $this->makePendingRequest($legalEntity, [
            'status' => RequestStatus::APPROVED,
        ]);

        $result = app(EmployeeRequestProcessor::class)->syncSinglePendingRequest($request, $legalEntity);

        $this->assertSame(EmployeeRequestProcessor::OUTCOME_FAILED, $result['outcome']);
    }

    #[Test]
    public function sync_fails_without_session_token(): void
    {
        $legalEntity = $this->makeLegalEntity();
        $request = $this->makePendingRequest($legalEntity);
        session()->forget(config('ehealth.api.oauth.bearer_token'));

        $result = app(EmployeeRequestProcessor::class)->syncSinglePendingRequest($request, $legalEntity);

        $this->assertSame(EmployeeRequestProcessor::OUTCOME_FAILED, $result['outcome']);
    }

    #[Test]
    public function sync_marks_rejected_remote_request(): void
    {
        $legalEntity = $this->makeLegalEntity();
        $request = $this->makePendingRequest($legalEntity);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'test-token');

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([
            'uuid' => $request->uuid,
            'status' => 'REJECTED',
        ]);

        $api = Mockery::mock(EmployeeRequestApi::class);
        $api->shouldReceive('getDetails')->once()->with($request->uuid)->andReturn($response);
        $this->instance(EmployeeRequestApi::class, $api);

        $result = app(EmployeeRequestProcessor::class)->syncSinglePendingRequest($request, $legalEntity);

        $this->assertSame(EmployeeRequestProcessor::OUTCOME_REJECTED, $result['outcome']);
        $this->assertSame(RequestStatus::REJECTED, $request->fresh()->status);
    }

    #[Test]
    public function sync_marks_expired_remote_request(): void
    {
        $legalEntity = $this->makeLegalEntity();
        $request = $this->makePendingRequest($legalEntity);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'test-token');

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([
            'uuid' => $request->uuid,
            'status' => 'EXPIRED',
        ]);

        $api = Mockery::mock(EmployeeRequestApi::class);
        $api->shouldReceive('getDetails')->once()->with($request->uuid)->andReturn($response);
        $this->instance(EmployeeRequestApi::class, $api);

        $result = app(EmployeeRequestProcessor::class)->syncSinglePendingRequest($request, $legalEntity);

        $this->assertSame(EmployeeRequestProcessor::OUTCOME_EXPIRED, $result['outcome']);
        $this->assertSame(RequestStatus::EXPIRED, $request->fresh()->status);
    }

    #[Test]
    public function sync_returns_pending_when_remote_still_new_without_employee(): void
    {
        $legalEntity = $this->makeLegalEntity();
        $request = $this->makePendingRequest($legalEntity);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'test-token');

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([
            'uuid' => $request->uuid,
            'status' => 'NEW',
        ]);

        $api = Mockery::mock(EmployeeRequestApi::class);
        $api->shouldReceive('getDetails')->once()->with($request->uuid)->andReturn($response);
        $this->instance(EmployeeRequestApi::class, $api);

        $matcher = Mockery::mock(EmployeeRequestMatcher::class);
        $matcher->shouldReceive('findApprovedForRequest')->once()->andReturn(null);
        $this->instance(EmployeeRequestMatcher::class, $matcher);

        $result = app(EmployeeRequestProcessor::class)->syncSinglePendingRequest($request, $legalEntity);

        $this->assertSame(EmployeeRequestProcessor::OUTCOME_PENDING, $result['outcome']);
        $this->assertSame(RequestStatus::NEW, $request->fresh()->status);
    }

    #[Test]
    public function sync_applies_when_details_include_employee_id_even_if_search_misses(): void
    {
        $legalEntity = $this->makeLegalEntity();
        $request = $this->makePendingRequest($legalEntity);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'test-token');

        $employeeUuid = (string) Str::uuid();

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([
            'uuid' => $request->uuid,
            'status' => 'APPROVED',
            'employee_id' => $employeeUuid,
            'legal_entity_id' => $legalEntity->uuid,
            'position' => 'P1',
            'employee_type' => 'DOCTOR',
            'start_date' => '2024-01-10',
        ]);

        $api = Mockery::mock(EmployeeRequestApi::class);
        $api->shouldReceive('getDetails')->once()->with($request->uuid)->andReturn($response);
        $this->instance(EmployeeRequestApi::class, $api);

        $matcher = Mockery::mock(EmployeeRequestMatcher::class);
        $matcher->shouldReceive('findApprovedForRequest')->once()->andReturn(null);
        $this->instance(EmployeeRequestMatcher::class, $matcher);

        $processor = Mockery::mock(EmployeeRequestProcessor::class, [$matcher])->makePartial();
        $processor->shouldAllowMockingProtectedMethods();
        $processor->shouldReceive('applyApprovedRequest')->once()->withArgs(
            function (EmployeeRequest $req, array $payload) use ($request, $employeeUuid): bool {
                return $req->is($request)
                    && ($payload['employee_id'] ?? null) === $employeeUuid
                    && ($payload['status'] ?? null) === 'APPROVED';
            }
        );

        $result = $processor->syncSinglePendingRequest($request, $legalEntity);

        $this->assertSame(EmployeeRequestProcessor::OUTCOME_APPROVED, $result['outcome']);
    }
}
