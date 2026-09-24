<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Classes\eHealth\Api\EmployeeRequest as EmployeeRequestApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Employee\RequestStatus;
use App\Enums\Employee\RevisionStatus;
use App\Enums\JobStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Events\EHealthUserLogin;
use App\Listeners\eHealth\EmployeePendingEditApply;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Models\Revision;
use App\Models\User;
use App\Services\Employee\EmployeeRequestMatcher;
use App\Services\Employee\EmployeeRequestProcessor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Matrix for #826: create / party edits / position edits / mixed email acceptances /
 * always latest APPROVED revision / other-MIS upserts without local apply.
 */
class EmployeeRequestAcceptedRevisionScenariosTest extends TestCase
{
    use DatabaseTransactions;

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
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        return LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
    }

    private function makeParty(array $overrides = []): Party
    {
        return Party::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Іван',
            'last_name' => 'Тестовий',
            'tax_id' => '1234567890',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
        ], $overrides));
    }

    private function makeUser(Party $party, string $email = 'worker@example.com'): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'email' => $email,
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);
    }

    private function makeEmployee(LegalEntity $legalEntity, Party $party, User $user, array $overrides = []): Employee
    {
        return Employee::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'full_name' => trim($party->lastName.' '.$party->firstName),
            'employee_type' => Role::DOCTOR->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'P1',
            'start_date' => '2024-01-10',
            'user_id' => $user->id,
            'party_id' => $party->id,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $partyOverrides
     * @param  array<string, mixed>  $employeeOverrides
     * @param  array<string, mixed>  $requestOverrides
     */
    private function makeRequestWithRevision(
        LegalEntity $legalEntity,
        array $partyOverrides = [],
        array $employeeOverrides = [],
        array $requestOverrides = [],
        ?string $createdAt = null,
    ): EmployeeRequest {
        $request = EmployeeRequest::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $legalEntity->id,
            'status' => RequestStatus::NEW,
            'position' => $employeeOverrides['position'] ?? 'P1',
            'employee_type' => $employeeOverrides['employee_type'] ?? Role::DOCTOR->value,
            'start_date' => $employeeOverrides['start_date'] ?? '2024-01-10',
            'email' => 'worker@example.com',
        ], $requestOverrides));

        // Persist ordering explicitly — Eloquent timestamps can ignore forceFill on create.
        if ($createdAt !== null) {
            EmployeeRequest::whereKey($request->id)->update(['created_at' => $createdAt]);
            $request->refresh();
        }

        $revision = new Revision([
            'status' => RevisionStatus::PENDING,
            'data' => [
                'party' => array_merge([
                    'tax_id' => '1234567890',
                    'first_name' => 'Іван',
                    'last_name' => 'Тестовий',
                    'second_name' => null,
                    'phones' => [
                        ['type' => 'MOBILE', 'number' => '+380501111111'],
                    ],
                    'documents' => [
                        ['type' => 'PASSPORT', 'number' => 'AA111111'],
                    ],
                ], $partyOverrides),
                'employee' => array_merge([
                    'position' => 'P1',
                    'employee_type' => Role::DOCTOR->value,
                    'start_date' => '2024-01-10',
                ], $employeeOverrides),
                'documents' => $partyOverrides['documents'] ?? [
                    ['type' => 'PASSPORT', 'number' => 'AA111111'],
                ],
                'phones' => $partyOverrides['phones'] ?? [
                    ['type' => 'MOBILE', 'number' => '+380501111111'],
                ],
            ],
        ]);
        $request->revision()->save($revision);
        $request->load('revision');

        return $request->fresh(['revision']);
    }

    private function processBatchWithMockedApply(
        array $remoteBatch,
        LegalEntity $legalEntity,
        ?callable $applyAssertion = null,
        int $expectedApplyCount = 1,
    ): void {
        $matcher = Mockery::mock(EmployeeRequestMatcher::class);
        $this->instance(EmployeeRequestMatcher::class, $matcher);

        $processor = Mockery::mock(EmployeeRequestProcessor::class, [$matcher])->makePartial();
        $processor->shouldAllowMockingProtectedMethods();

        // Always stub apply — partial-mock withArgs misses fall through to the real method
        // and can abort the DatabaseTransactions connection mid-batch.
        if ($expectedApplyCount === 0) {
            $processor->shouldReceive('applyApprovedRequest')->never();
        } else {
            $processor->shouldReceive('applyApprovedRequest')
                ->times($expectedApplyCount)
                ->with(Mockery::type(EmployeeRequest::class), Mockery::type('array'))
                ->andReturnUsing(function (EmployeeRequest $request, array $payload) use ($applyAssertion): void {
                    if ($applyAssertion !== null) {
                        Assert::assertTrue(
                            $applyAssertion($request, $payload),
                            'Unexpected employee request passed to applyApprovedRequest.'
                        );
                    }
                });
        }

        $processor->processBatch($remoteBatch, $legalEntity);
    }

    #[Test]
    public function create_owner_stays_pending_until_email_approved(): void
    {
        $legalEntity = $this->makeLegalEntity();
        $request = $this->makeRequestWithRevision(
            $legalEntity,
            employeeOverrides: ['employee_type' => Role::OWNER->value],
            requestOverrides: [
                'employee_type' => Role::OWNER->value,
                'employee_id' => null,
            ],
        );
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
        $matcher->shouldNotReceive('findApprovedForRequest');
        $this->instance(EmployeeRequestMatcher::class, $matcher);

        $processor = Mockery::mock(EmployeeRequestProcessor::class, [$matcher])->makePartial();
        $processor->shouldAllowMockingProtectedMethods();
        $processor->shouldNotReceive('applyApprovedRequest');

        $result = $processor->syncSinglePendingRequest($request, $legalEntity);

        $this->assertSame(EmployeeRequestProcessor::OUTCOME_PENDING, $result['outcome']);
        $this->assertSame(RequestStatus::NEW, $request->fresh()->status);
        $this->assertNull($request->fresh()->appliedAt);
    }

    #[Test]
    public function create_employee_applies_only_when_remote_approved(): void
    {
        $legalEntity = $this->makeLegalEntity();
        $request = $this->makeRequestWithRevision($legalEntity, requestOverrides: ['employee_id' => null]);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'test-token');

        $employeeUuid = (string) Str::uuid();

        $response = Mockery::mock(EHealthResponse::class);
        $response->shouldReceive('validate')->once()->andReturn([
            'uuid' => $request->uuid,
            'status' => 'APPROVED',
            'legal_entity_id' => $legalEntity->uuid,
            'position' => 'P1',
            'employee_type' => Role::DOCTOR->value,
            'start_date' => '2024-01-10',
            'updated_at' => '2024-06-15T12:00:00Z',
        ]);

        $api = Mockery::mock(EmployeeRequestApi::class);
        $api->shouldReceive('getDetails')->once()->with($request->uuid)->andReturn($response);
        $this->instance(EmployeeRequestApi::class, $api);

        $matcher = Mockery::mock(EmployeeRequestMatcher::class);
        $matcher->shouldReceive('findApprovedForRequest')->once()->andReturn([
            'uuid' => $employeeUuid,
            'status' => 'APPROVED',
        ]);
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

    #[Test]
    public function consecutive_party_edits_apply_only_latest_approved_in_batch(): void
    {
        $legalEntity = $this->makeLegalEntity();
        $party = $this->makeParty();
        $user = $this->makeUser($party);
        $employee = $this->makeEmployee($legalEntity, $party, $user);

        $older = $this->makeRequestWithRevision(
            $legalEntity,
            partyOverrides: [
                'first_name' => 'Старий',
                'phones' => [['type' => 'MOBILE', 'number' => '+380501111111']],
                'documents' => [['type' => 'PASSPORT', 'number' => 'AA111111']],
            ],
            requestOverrides: ['employee_id' => $employee->id, 'email' => $user->email],
            createdAt: now()->subDay()->toDateTimeString(),
        );
        $newer = $this->makeRequestWithRevision(
            $legalEntity,
            partyOverrides: [
                'first_name' => 'Новий',
                'phones' => [['type' => 'MOBILE', 'number' => '+380509999999']],
                'documents' => [['type' => 'PASSPORT', 'number' => 'BB222222']],
            ],
            requestOverrides: ['employee_id' => $employee->id, 'email' => $user->email],
            createdAt: now()->toDateTimeString(),
        );

        $this->processBatchWithMockedApply(
            [
                ['uuid' => $older->uuid, 'status' => 'APPROVED', 'employee_id' => $employee->uuid],
                ['uuid' => $newer->uuid, 'status' => 'APPROVED', 'employee_id' => $employee->uuid],
            ],
            $legalEntity,
            applyAssertion: fn (EmployeeRequest $req): bool => $req->id === $newer->id,
            expectedApplyCount: 1,
        );

        $olderFresh = $older->fresh(['revision']);
        $this->assertSame(RequestStatus::APPROVED, $olderFresh->status);
        $this->assertNotNull($olderFresh->appliedAt);
        $this->assertSame(RevisionStatus::OUTDATED, $olderFresh->revision->status);
        $this->assertSame(RequestStatus::NEW, $newer->fresh()->status);
    }

    #[Test]
    public function consecutive_position_edits_apply_only_latest_approved_in_batch(): void
    {
        $legalEntity = $this->makeLegalEntity();
        $party = $this->makeParty();
        $user = $this->makeUser($party);
        $employee = $this->makeEmployee($legalEntity, $party, $user);

        $older = $this->makeRequestWithRevision(
            $legalEntity,
            employeeOverrides: ['position' => 'P1', 'start_date' => '2024-01-10'],
            requestOverrides: [
                'employee_id' => $employee->id,
                'email' => $user->email,
                'position' => 'P1',
            ],
            createdAt: now()->subHours(2)->toDateTimeString(),
        );
        $newer = $this->makeRequestWithRevision(
            $legalEntity,
            employeeOverrides: ['position' => 'P2', 'start_date' => '2024-06-01'],
            requestOverrides: [
                'employee_id' => $employee->id,
                'email' => $user->email,
                'position' => 'P2',
                'start_date' => '2024-06-01',
            ],
            createdAt: now()->toDateTimeString(),
        );

        $this->processBatchWithMockedApply(
            [
                ['uuid' => $older->uuid, 'status' => 'APPROVED', 'employee_id' => $employee->uuid],
                ['uuid' => $newer->uuid, 'status' => 'APPROVED', 'employee_id' => $employee->uuid],
            ],
            $legalEntity,
            applyAssertion: fn (EmployeeRequest $req): bool => $req->id === $newer->id
                && data_get($req->revision->data, 'employee.position') === 'P2',
        );

        $this->assertSame(RevisionStatus::OUTDATED, $older->fresh(['revision'])->revision->status);
    }

    #[Test]
    public function mixed_acceptance_applies_approved_and_leaves_newer_pending_then_applies_on_next_sync(): void
    {
        $legalEntity = $this->makeLegalEntity();
        $party = $this->makeParty();
        $user = $this->makeUser($party);
        $employee = $this->makeEmployee($legalEntity, $party, $user);

        $first = $this->makeRequestWithRevision(
            $legalEntity,
            partyOverrides: ['first_name' => 'Перший'],
            requestOverrides: ['employee_id' => $employee->id, 'email' => $user->email],
            createdAt: now()->subDays(2)->toDateTimeString(),
        );
        $second = $this->makeRequestWithRevision(
            $legalEntity,
            partyOverrides: ['first_name' => 'Другий'],
            requestOverrides: ['employee_id' => $employee->id, 'email' => $user->email],
            createdAt: now()->subDay()->toDateTimeString(),
        );

        // Round 1: first accepted on email, second still waiting.
        $this->processBatchWithMockedApply(
            [
                ['uuid' => $first->uuid, 'status' => 'APPROVED', 'employee_id' => $employee->uuid],
                ['uuid' => $second->uuid, 'status' => 'NEW'],
            ],
            $legalEntity,
            applyAssertion: fn (EmployeeRequest $req): bool => $req->id === $first->id,
        );

        $this->assertSame(RequestStatus::NEW, $second->fresh()->status);
        $this->assertNull($second->fresh()->appliedAt);

        // Round 2: second accepted later — must apply second (latest accepted content).
        $this->processBatchWithMockedApply(
            [
                ['uuid' => $second->uuid, 'status' => 'APPROVED', 'employee_id' => $employee->uuid],
            ],
            $legalEntity,
            applyAssertion: fn (EmployeeRequest $req): bool => $req->id === $second->id
                && data_get($req->revision->data, 'party.first_name') === 'Другий',
        );
    }

    #[Test]
    public function reject_then_accept_newer_edit_never_applies_rejected_revision(): void
    {
        $legalEntity = $this->makeLegalEntity();
        $party = $this->makeParty();
        $user = $this->makeUser($party);
        $employee = $this->makeEmployee($legalEntity, $party, $user);

        $rejected = $this->makeRequestWithRevision(
            $legalEntity,
            partyOverrides: ['first_name' => 'Відхилений'],
            requestOverrides: ['employee_id' => $employee->id, 'email' => $user->email],
            createdAt: now()->subDay()->toDateTimeString(),
        );
        $accepted = $this->makeRequestWithRevision(
            $legalEntity,
            partyOverrides: ['first_name' => 'Прийнятий'],
            requestOverrides: ['employee_id' => $employee->id, 'email' => $user->email],
            createdAt: now()->toDateTimeString(),
        );

        $this->processBatchWithMockedApply(
            [
                ['uuid' => $rejected->uuid, 'status' => 'REJECTED'],
                ['uuid' => $accepted->uuid, 'status' => 'APPROVED', 'employee_id' => $employee->uuid],
            ],
            $legalEntity,
            applyAssertion: fn (EmployeeRequest $req): bool => $req->id === $accepted->id,
        );

        $rejectedFresh = $rejected->fresh(['revision']);
        $this->assertSame(RequestStatus::REJECTED, $rejectedFresh->status);
        $this->assertNotNull($rejectedFresh->appliedAt);
        $this->assertSame(RevisionStatus::OUTDATED, $rejectedFresh->revision->status);
    }

    #[Test]
    public function mark_older_pending_edits_superseded_after_newer_apply(): void
    {
        $legalEntity = $this->makeLegalEntity();
        $party = $this->makeParty();
        $user = $this->makeUser($party);
        $employee = $this->makeEmployee($legalEntity, $party, $user);

        $olderPending = $this->makeRequestWithRevision(
            $legalEntity,
            partyOverrides: ['first_name' => 'Вчора'],
            requestOverrides: ['employee_id' => $employee->id, 'email' => $user->email],
            createdAt: now()->subDay()->toDateTimeString(),
        );
        $applied = $this->makeRequestWithRevision(
            $legalEntity,
            partyOverrides: ['first_name' => 'Сьогодні'],
            requestOverrides: [
                'employee_id' => $employee->id,
                'email' => $user->email,
                'status' => RequestStatus::APPROVED,
                'applied_at' => now(),
            ],
            createdAt: now()->toDateTimeString(),
        );
        $applied->revision?->update(['status' => RevisionStatus::APPLIED]);

        app(EmployeeRequestProcessor::class)->markOlderPendingEditsSuperseded($applied);

        $olderFresh = $olderPending->fresh(['revision']);
        $this->assertSame(RequestStatus::EXPIRED, $olderFresh->status);
        $this->assertNotNull($olderFresh->appliedAt);
        $this->assertSame(RevisionStatus::OUTDATED, $olderFresh->revision->status);
    }

    #[Test]
    public function login_apply_syncs_only_latest_pending_edit_per_employee_in_current_le(): void
    {
        $legalEntity = $this->makeLegalEntity();
        $otherLe = $this->makeLegalEntity();
        $party = $this->makeParty();
        $user = $this->makeUser($party, 'shared@example.com');
        $employee = $this->makeEmployee($legalEntity, $party, $user);
        $otherEmployee = $this->makeEmployee($otherLe, $party, $user, [
            'uuid' => (string) Str::uuid(),
        ]);

        $olderSameLe = $this->makeRequestWithRevision(
            $legalEntity,
            partyOverrides: ['first_name' => 'Старий'],
            requestOverrides: ['employee_id' => $employee->id, 'email' => $user->email],
            createdAt: now()->subDay()->toDateTimeString(),
        );
        $latestSameLe = $this->makeRequestWithRevision(
            $legalEntity,
            partyOverrides: ['first_name' => 'Новий'],
            requestOverrides: ['employee_id' => $employee->id, 'email' => $user->email],
            createdAt: now()->toDateTimeString(),
        );
        $foreignLe = $this->makeRequestWithRevision(
            $otherLe,
            partyOverrides: ['first_name' => 'ЧужийМІС'],
            requestOverrides: ['employee_id' => $otherEmployee->id, 'email' => $user->email],
            createdAt: now()->toDateTimeString(),
        );

        $processor = Mockery::mock(EmployeeRequestProcessor::class);
        $processor->shouldReceive('syncSinglePendingRequest')
            ->once()
            ->withArgs(fn (EmployeeRequest $synced, LegalEntity $le): bool => $synced->id === $latestSameLe->id
                && $le->id === $legalEntity->id
                && $synced->id !== $olderSameLe->id
                && $synced->id !== $foreignLe->id)
            ->andReturn([
                'outcome' => EmployeeRequestProcessor::OUTCOME_APPROVED,
                'message' => 'ok',
            ]);
        $processor->shouldReceive('markOlderPendingEditsSuperseded')
            ->once()
            ->withArgs(fn (EmployeeRequest $applied): bool => $applied->id === $latestSameLe->id);
        $this->instance(EmployeeRequestProcessor::class, $processor);

        session()->put(config('ehealth.api.oauth.bearer_token'), 'test-token');
        app(EmployeePendingEditApply::class)->handle(new EHealthUserLogin(
            $user,
            $legalEntity,
            (string) Str::uuid(),
            ['employee_request:read']
        ));
    }

    #[Test]
    public function other_mis_remote_only_requests_are_upserted_partial_without_local_apply(): void
    {
        $legalEntity = $this->makeLegalEntity();
        $foreignUuid = (string) Str::uuid();

        $matcher = Mockery::mock(EmployeeRequestMatcher::class);
        $this->instance(EmployeeRequestMatcher::class, $matcher);

        $processor = Mockery::mock(EmployeeRequestProcessor::class, [$matcher])->makePartial();
        $processor->shouldAllowMockingProtectedMethods();
        $processor->shouldNotReceive('applyApprovedRequest');

        $processor->processBatch([
            [
                'uuid' => $foreignUuid,
                'status' => 'APPROVED',
                'inserted_at' => now()->toIso8601String(),
                'employee_id' => (string) Str::uuid(),
            ],
        ], $legalEntity);

        $imported = EmployeeRequest::query()->where('uuid', $foreignUuid)->first();

        $this->assertNotNull($imported);
        $this->assertSame($legalEntity->id, $imported->legalEntityId);
        $this->assertSame(RequestStatus::APPROVED, $imported->status);
        $this->assertSame(JobStatus::PARTIAL->value, $imported->syncStatus);
        $this->assertNull($imported->revision);
        $this->assertNull($imported->appliedAt);
    }

    #[Test]
    public function create_and_edit_approved_in_same_batch_both_apply_independently(): void
    {
        $legalEntity = $this->makeLegalEntity();
        $party = $this->makeParty();
        $user = $this->makeUser($party);
        $employee = $this->makeEmployee($legalEntity, $party, $user);

        $create = $this->makeRequestWithRevision(
            $legalEntity,
            requestOverrides: [
                'employee_id' => null,
                'email' => 'newhire@example.com',
            ],
            createdAt: now()->subHour()->toDateTimeString(),
        );
        $edit = $this->makeRequestWithRevision(
            $legalEntity,
            partyOverrides: ['first_name' => 'Оновлений'],
            requestOverrides: [
                'employee_id' => $employee->id,
                'email' => $user->email,
            ],
            createdAt: now()->toDateTimeString(),
        );

        $appliedIds = [];
        $this->processBatchWithMockedApply(
            [
                ['uuid' => $create->uuid, 'status' => 'APPROVED', 'employee_id' => (string) Str::uuid()],
                ['uuid' => $edit->uuid, 'status' => 'APPROVED', 'employee_id' => $employee->uuid],
            ],
            $legalEntity,
            applyAssertion: function (EmployeeRequest $req) use (&$appliedIds): bool {
                $appliedIds[] = $req->id;

                return true;
            },
            expectedApplyCount: 2,
        );

        sort($appliedIds);
        $this->assertSame([$create->id, $edit->id], $appliedIds);
    }
}
