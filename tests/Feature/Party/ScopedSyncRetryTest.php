<?php

declare(strict_types=1);

namespace Tests\Feature\Party;

use App\Enums\JobStatus;
use App\Classes\eHealth\Api\Employee as EmployeeApi;
use App\Classes\eHealth\Api\Party as PartyApi;
use App\Classes\eHealth\Api\Declaration as DeclarationApi;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Events\EHealthUserLogin;
use App\Listeners\OnRegularLoginSyncronization;
use App\Jobs\EmployeeSync;
use App\Jobs\DeclarationsSync;
use App\Jobs\PartyVerificationSync;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Relations\Party;
use App\Models\Role as AppRole;
use App\Models\Permission as AppPermission;
use App\Notifications\SyncNotification;
use App\Repositories\Repository;
use App\Repositories\UserRepository;
use Illuminate\Bus\BatchRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Auth;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Http\Client\Response;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Mockery;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ScopedSyncRetryTest extends TestCase
{
    use DatabaseTransactions;

    private LegalEntity $legalEntity;

    private User $oldUser;

    private User $candidate;

    private SyncRetryProbe $job;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        Notification::fake();
        Event::listen(JobFailed::class, static function (JobFailed $event): void {
            app('queue.failer')->log($event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $event->exception);
        });
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id');
        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(), 'legal_entity_type_id' => $typeId, 'status' => 'ACTIVE',
            'sync_status' => JobStatus::COMPLETED->value, 'is_active' => true,
        ]);
        $this->oldUser = $this->createUser();
        $this->candidate = $this->createUser();
        $employee = Employee::create([
            'uuid' => (string) Str::uuid(), 'legal_entity_id' => $this->legalEntity->id,
            'user_id' => $this->candidate->id, 'employee_type' => 'DOCTOR',
            'status' => 'APPROVED', 'is_active' => true, 'position' => 'P1', 'start_date' => '2020-01-01',
        ]);
        $this->candidate->employees()->attach($employee->id);
        $this->grant('employee:read');
        $this->bindSession();
        $this->job = new SyncRetryProbe($this->legalEntity);
    }

    protected function tearDown(): void
    {
        setPermissionsTeamId(null);
        parent::tearDown();
    }

    public function testRetryUsesTheBoundSessionEvenWhenAnOlderSessionWasInsertedFirst(): void
    {
        DB::table('sessions')->delete();
        $this->bindSession('obsolete-session', 'obsolete-token');
        $this->bindSession('current-session', 'current-token');
        setPermissionsTeamId(999999);

        $selected = $this->job->selectUser($this->oldUser);

        $this->assertSame($this->candidate->id, $selected?->id);
        $this->assertSame('current-token', $this->job->tokenFor($selected));
        $this->assertSame(999999, getPermissionsTeamId());
    }

    public function testPermissionTeamIsRestoredWhenTheCandidateQueryThrows(): void
    {
        setPermissionsTeamId(999999);
        $repository = Mockery::mock(UserRepository::class);
        $repository->shouldReceive('getSyncRetryCandidates')->once()->andThrow(new RuntimeException('Query failed'));
        $this->instance(UserRepository::class, $repository);

        try {
            $this->job->selectUser($this->oldUser);
            $this->fail('Expected candidate query failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Query failed', $exception->getMessage());
        }

        $this->assertSame(999999, getPermissionsTeamId());
    }

    #[DataProvider('invalidCredentials')]
    public function testIneligibleSessionsOrPermissionsAreNotUsed(string $case): void
    {
        $payload = $this->payload();
        switch ($case) {
            case 'missing scope':
                $payload[config('ehealth.api.oauth.token_scopes')] = [];
                break;
            case 'malformed scopes':
                $payload[config('ehealth.api.oauth.token_scopes')] = 'employee:read';
                break;
            case 'missing token':
                unset($payload[config('ehealth.api.oauth.bearer_token')]);
                break;
            case 'malformed token':
                $payload[config('ehealth.api.oauth.bearer_token')] = ['unexpected'];
                break;
            case 'different token legal entity':
                $payload['ehealth_legal_entity_uuid'] = (string) Str::uuid();
                break;
            case 'expired token':
                $payload['auth_token_expires_at'] = now()->subMinute()->timestamp;
                break;
            case 'malformed token expiry':
                $payload['auth_token_expires_at'] = ['unexpected'];
                break;
            case 'expired session':
                DB::table('sessions')->update(['last_activity' => now()->subMinutes((int) config('session.lifetime') + 1)->timestamp]);
                break;
            case 'released binding':
                $this->candidate->forceFill(['session_id' => null])->save();
                break;
            case 'different session owner':
                DB::table('sessions')->update(['user_id' => $this->oldUser->id]);
                break;
            case 'no direct permissions':
                DB::table('model_has_permissions')->where('model_id', $this->candidate->id)->delete();
                break;
            case 'role permission only':
                DB::table('model_has_permissions')->where('model_id', $this->candidate->id)->delete();
                $role = Role::firstOrCreate(['name' => 'DOCTOR', 'guard_name' => 'ehealth']);
                $role->permissions()->sync([Permission::where('name', 'employee:read')->where('guard_name', 'ehealth')->value('id')]);
                $this->candidate->roles()->attach($role, ['legal_entity_id' => $this->legalEntity->id]);
                break;
            case 'permission from another team':
                DB::table('model_has_permissions')->where('model_id', $this->candidate->id)->update(['legal_entity_id' => $this->otherLegalEntity()->id]);
                break;
            case 'employee from another legal entity':
                $this->candidate->employees()->update(['legal_entity_id' => $this->otherLegalEntity()->id]);
                break;
            case 'invalid base64':
                DB::table('sessions')->update(['payload' => '%%invalid']);
                break;
            case 'invalid serialization':
                DB::table('sessions')->update(['payload' => base64_encode('invalid')]);
                break;
            case 'non-array session':
                DB::table('sessions')->update(['payload' => base64_encode(serialize('invalid'))]);
                break;
        }
        if (!in_array($case, ['invalid base64', 'invalid serialization', 'non-array session'], true)) {
            DB::table('sessions')->update(['payload' => base64_encode(serialize($payload))]);
        }

        $this->assertNull($this->job->selectUser($this->oldUser));
    }

    public static function invalidCredentials(): array
    {
        $cases = [
            'missing scope', 'malformed scopes', 'missing token', 'malformed token',
            'different token legal entity', 'expired token', 'malformed token expiry', 'expired session', 'released binding',
            'different session owner', 'no direct permissions', 'role permission only', 'permission from another team',
            'employee from another legal entity', 'invalid base64', 'invalid serialization', 'non-array session',
        ];

        return array_combine($cases, array_map(static fn (string $case): array => [$case], $cases));
    }

    public function testSavedChainRequiresBothDirectPermissionsAndSessionScopes(): void
    {
        $this->job = new SyncRetryProbe($this->legalEntity, new PartyVerificationSync($this->legalEntity));
        $this->assertNull($this->job->selectUser($this->oldUser));
        $this->grant(PartyVerificationSync::SCOPE_REQUIRED);
        $this->assertNull($this->job->selectUser($this->oldUser));
        $this->bindSession(scopes: ['employee:read', PartyVerificationSync::SCOPE_REQUIRED]);
        $this->assertSame($this->candidate->id, $this->job->selectUser($this->oldUser)?->id);
    }

    public function testTokenReadRechecksTheBindingAndScopeAfterCandidateSelection(): void
    {
        $selected = $this->job->selectUser($this->oldUser);
        $this->assertNotNull($selected);
        $this->bindSession('replacement-session', 'replacement-token', []);

        $this->assertSame('', $this->job->tokenFor($selected));
    }

    public function testSessionReaderUsesDatabaseBindingInsteadOfSerializedUserSessionId(): void
    {
        $staleUser = $this->candidate->fresh();
        $this->bindSession('replacement-session', 'replacement-token');

        $this->assertSame('replacement-token', $this->job->tokenFor($staleUser));
        $this->assertNotEmpty(Repository::user()->getSyncSessionPayload($staleUser));
    }

    public function testFailedJobPausesWhenNoEligibleUserExists(): void
    {
        DB::table('model_has_permissions')->where('model_id', $this->candidate->id)->delete();
        $batch = $this->batch();

        $this->job->failed(new RuntimeException('Unauthorized', 401));

        $this->assertDatabaseHas('job_batches', ['id' => $batch->id]);
        $this->assertSame(0, DB::table('jobs')->where('queue', 'sync')->count());
        $this->assertSame(JobStatus::PAUSED->value, $this->legalEntity->fresh()->employeeSyncStatus);
        Notification::assertSentTo(
            $this->oldUser,
            SyncNotification::class,
            fn ($notification) => $notification->toArray($this->oldUser)['action'] === 'paused'
        );
    }

    public function testFailedJobRetriesWithTheVerifiedUserAndToken(): void
    {
        $batch = $this->batch();

        $this->job->failed(new RuntimeException('Unauthorized', 401));

        $payload = json_decode(DB::table('jobs')->where('queue', 'sync')->value('payload'), true);
        $queued = unserialize($payload['data']['command']);
        $options = app(BatchRepository::class)->find($queued->batchId)->options;
        $this->assertSame($this->candidate->id, $queued->user->id);
        $this->assertSame('current-token', Crypt::decryptString($options['token']));
        $this->assertDatabaseMissing('job_batches', ['id' => $batch->id]);
    }

    public static function immediateFailureStatuses(): array
    {
        return ['unauthorized' => [401], 'forbidden' => [403]];
    }

    #[DataProvider('immediateFailureStatuses')]
    public function testWorkerPreservesFailedJobWhenNoSessionHasTheRequiredScope(int $status): void
    {
        $this->bindSession(scopes: []);
        $api = Mockery::mock(EmployeeApi::class);
        $api->shouldReceive('withToken')->once()->with('old-token')->andReturnSelf();
        $api->shouldReceive('getMany')->once()->andThrow(new EHealthResponseException(
            new Response(new GuzzleResponse($status, [], json_encode(['error' => ['message' => 'Access denied']])))
        ));
        $this->instance(EmployeeApi::class, $api);
        $job = new EmployeeSync($this->legalEntity, standalone: true);
        $batch = Bus::batch([$job])->name('EmployeeFullSync')
            ->withOption('legal_entity_id', $this->legalEntity->id)
            ->withOption('user', $this->oldUser)->withOption('token', Crypt::encryptString('old-token'))
            ->onQueue('sync')->dispatch();

        app('queue.worker')->runNextJob('database', 'sync', new WorkerOptions(sleep: 0, maxTries: 3));

        $this->assertDatabaseHas('job_batches', ['id' => $batch->id, 'failed_jobs' => 1]);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSame(0, DB::table('jobs')->where('queue', 'sync')->count());
        $this->assertSame(JobStatus::PAUSED->value, $this->legalEntity->fresh()->employeeSyncStatus);
    }

    public function testPartyScopeDenialUsesParentPauseEvenWhenAnotherEmployeeHasTheScope(): void
    {
        $this->grant(PartyVerificationSync::SCOPE_REQUIRED);
        $this->bindSession(scopes: [PartyVerificationSync::SCOPE_REQUIRED]);
        $api = Mockery::mock(PartyApi::class);
        $api->shouldReceive('withToken')->once()->with('old-token')->andReturnSelf();
        $api->shouldReceive('getMany')->once()->andThrow(new EHealthResponseException(
            new Response(new GuzzleResponse(403, [], json_encode([
                'error' => ['message' => 'Missing allowances: party_verification:read'],
            ])))
        ));
        $this->instance(PartyApi::class, $api);
        $batch = Bus::batch([new PartyVerificationSync($this->legalEntity, standalone: true)])
            ->name(PartyVerificationSync::BATCH_NAME)
            ->withOption('legal_entity_id', $this->legalEntity->id)
            ->withOption('user', $this->oldUser)->withOption('token', Crypt::encryptString('old-token'))
            ->onQueue('sync')->dispatch();

        $this->runNextSyncJob();

        $this->assertDatabaseHas('job_batches', ['id' => $batch->id, 'failed_jobs' => 1]);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSame(0, DB::table('jobs')->where('queue', 'sync')->count());
        $this->assertSame(JobStatus::PAUSED->value, $this->legalEntity->fresh()->partyVerificationSyncStatus);
        Notification::assertSentTo(
            $this->oldUser,
            SyncNotification::class,
            fn ($notification) => $notification->toArray($this->oldUser)['action'] === 'paused'
                && $notification->toArray($this->oldUser)['type'] === 'legal_entity'
        );
    }

    #[DataProvider('retryLifecycles')]
    public function testWorkerRetryAndResumeLeaveNoZombieFailedJobs(bool $pauseRetry): void
    {
        $api = Mockery::mock(EmployeeApi::class);
        $api->shouldReceive('withToken')->once()->with('old-token')->andReturnSelf();
        $api->shouldReceive('withToken')->once()->with('current-token')->andReturnSelf();
        if ($pauseRetry) {
            $api->shouldReceive('withToken')->once()->with('fresh-token')->andReturnSelf();
        }
        $calls = 0;
        $api->shouldReceive('getMany')->times($pauseRetry ? 3 : 2)
            ->andReturnUsing(function () use (&$calls, $pauseRetry): EHealthResponse {
                if (++$calls <= ($pauseRetry ? 2 : 1)) {
                    throw new EHealthResponseException(new Response(new GuzzleResponse(401, [], json_encode([
                        'error' => ['message' => 'Token expired'],
                    ]))));
                }

                return new EHealthResponse(
                    new GuzzleResponse(200, [], json_encode(['data' => []])),
                    static fn () => [],
                    static fn () => []
                );
            });
        $this->instance(EmployeeApi::class, $api);
        $batch = Bus::batch([new SyncRetryProbe($this->legalEntity, standalone: true)])
            ->name(EmployeeSync::BATCH_NAME)
            ->withOption('legal_entity_id', $this->legalEntity->id)
            ->withOption('sync_entity', LegalEntity::ENTITY_EMPLOYEE)
            ->withOption('user', $this->oldUser)->withOption('token', Crypt::encryptString('old-token'))
            ->onQueue('sync')->dispatch();

        $this->runNextSyncJob();

        $this->assertDatabaseMissing('job_batches', ['id' => $batch->id]);
        $this->assertDatabaseCount('failed_jobs', 1);
        $originalFailedUuid = DB::table('failed_jobs')->value('uuid');
        $payload = json_decode(DB::table('jobs')->where('queue', 'sync')->value('payload'), true);
        $retry = unserialize($payload['data']['command']);
        $this->assertSame($this->candidate->id, $retry->user->id);

        $this->runNextSyncJob();

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $originalFailedUuid]);
        if ($pauseRetry) {
            // Laravel logs the new failure after failed() returns. Only that failure must remain,
            // attached to the paused replacement batch, until the next suitable login resumes it.
            $this->assertDatabaseCount('failed_jobs', 1);
            $this->assertDatabaseHas('job_batches', ['id' => $retry->batchId, 'failed_jobs' => 1]);
            $this->assertSame(JobStatus::PAUSED->value, $this->legalEntity->fresh()->employeeSyncStatus);
            $this->withSession([config('ehealth.api.oauth.bearer_token') => 'fresh-token']);
            $this->bindSession(token: 'fresh-token');
            (new OnRegularLoginSyncronization())->handle(new EHealthUserLogin(
                $this->candidate,
                $this->legalEntity,
                $this->candidate->uuid,
                ['employee:read']
            ));
            $this->assertDatabaseMissing('job_batches', ['id' => $retry->batchId]);
            $this->assertDatabaseCount('failed_jobs', 0);

            $this->runNextSyncJob();
        }

        // Execute CompleteSync as well: this checks the entire worker lifecycle rather than
        // directly invoking failed(), which would miss the late JobFailed persistence event.
        $this->runNextSyncJob();

        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame(0, DB::table('jobs')->where('queue', 'sync')->count());
        $this->assertSame(JobStatus::COMPLETED->value, $this->legalEntity->fresh()->employeeSyncStatus);
    }

    public static function retryLifecycles(): array
    {
        return ['retry succeeds' => [false], 'retry pauses then resumes on login' => [true]];
    }

    public function testDeniedTokensCannotPingPongBetweenTwoActiveUsers(): void
    {
        $oldEmployee = Employee::create([
            'uuid' => (string) Str::uuid(), 'legal_entity_id' => $this->legalEntity->id,
            'user_id' => $this->oldUser->id, 'employee_type' => 'DOCTOR',
            'status' => 'APPROVED', 'is_active' => true, 'position' => 'P1', 'start_date' => '2020-01-01',
        ]);
        $this->oldUser->employees()->attach($oldEmployee->id);
        $permission = Permission::where('name', 'employee:read')->where('guard_name', 'ehealth')->firstOrFail();
        $this->oldUser->permissions()->attach($permission, ['legal_entity_id' => $this->legalEntity->id]);
        DB::table('sessions')->insert([
            'id' => 'old-session', 'user_id' => $this->oldUser->id, 'last_activity' => now()->timestamp,
            'payload' => base64_encode(serialize($this->payload('old-token'))),
        ]);
        $this->oldUser->forceFill(['session_id' => 'old-session'])->save();
        $api = Mockery::mock(EmployeeApi::class);
        $api->shouldReceive('withToken')->once()->with('old-token')->andReturnSelf();
        $api->shouldReceive('withToken')->once()->with('current-token')->andReturnSelf();
        $api->shouldReceive('getMany')->twice()->andThrow(new EHealthResponseException(
            new Response(new GuzzleResponse(401, [], json_encode(['error' => ['message' => 'Revoked token']])))
        ));
        $this->instance(EmployeeApi::class, $api);
        Bus::batch([new EmployeeSync($this->legalEntity, standalone: true)])
            ->name(EmployeeSync::BATCH_NAME)->withOption('legal_entity_id', $this->legalEntity->id)
            ->withOption('user', $this->oldUser)->withOption('token', Crypt::encryptString('old-token'))
            ->onQueue('sync')->dispatch();

        $this->runNextSyncJob();
        $this->runNextSyncJob();

        $this->assertSame(0, DB::table('jobs')->where('queue', 'sync')->count());
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseCount('job_batches', 1);
        $this->assertSame(JobStatus::PAUSED->value, $this->legalEntity->fresh()->employeeSyncStatus);
    }

    #[DataProvider('retryRequestResults')]
    public function testDeclarationRetryUsesItsLegalEntityRoleContextAndRestoresTheWorker(bool $throws): void
    {
        $party = Party::create(['uuid' => (string) Str::uuid(), 'first_name' => 'Doctor', 'last_name' => 'Fixture', 'gender' => 'MALE']);
        $this->candidate->forceFill(['party_id' => $party->id])->save();
        $employee = $this->candidate->employees()->firstOrFail();
        $employee->partyId = $party->id;
        $employee->save();
        $this->grant(DeclarationsSync::SCOPE_REQUIRED);
        $this->bindSession(scopes: [DeclarationsSync::SCOPE_REQUIRED]);
        setPermissionsTeamId($this->legalEntity->id);
        $role = AppRole::firstOrCreate(['name' => 'DOCTOR', 'guard_name' => 'ehealth', 'legal_entity_id' => null]);
        $permission = AppPermission::findByName(DeclarationsSync::SCOPE_REQUIRED, 'ehealth');
        $permission->legalEntityTypes()->syncWithoutDetaching([$this->legalEntity->legalEntityTypeId]);
        $role->syncPermissions([$permission]);
        Auth::shouldUse('ehealth');
        $this->candidate->assignRole($role);
        $this->assertTrue($this->candidate->hasAllowedRole('DOCTOR'));
        $previousTeamId = $this->otherLegalEntity()->id;
        setPermissionsTeamId($previousTeamId);
        Auth::shouldUse('web');
        $workerErrors = [];
        Event::listen(\Illuminate\Queue\Events\JobExceptionOccurred::class, static function ($event) use (&$workerErrors): void {
            $workerErrors[] = $event->exception->getMessage();
        });
        $queries = [];
        $api = Mockery::mock(DeclarationApi::class);
        $api->shouldReceive('withToken')->once()->with('old-token')->andReturnSelf();
        $api->shouldReceive('withToken')->once()->with('current-token')->andReturnSelf();
        $api->shouldReceive('getMany')->twice()->andReturnUsing(function (...$arguments) use (&$queries, $throws): EHealthResponse {
            $queries[] = $arguments['query'] ?? $arguments[0];
            if (count($queries) === 1 || $throws) {
                $status = count($queries) === 1 ? 401 : 503;
                throw new EHealthResponseException(new Response(new GuzzleResponse($status, [], json_encode(['error' => ['message' => 'Fixture error']]))));
            }

            return new EHealthResponse(new GuzzleResponse(200, [], json_encode(['data' => []])), static fn () => [], static fn () => []);
        });
        $this->instance(DeclarationApi::class, $api);
        Bus::batch([new DeclarationsSync($this->legalEntity, standalone: true)])
            ->name(DeclarationsSync::BATCH_NAME)->withOption('legal_entity_id', $this->legalEntity->id)
            ->withOption('user', $this->oldUser)->withOption('token', Crypt::encryptString('old-token'))
            ->onQueue('sync')->dispatch();

        $this->runNextSyncJob();
        $this->assertSame([], $workerErrors, json_encode($workerErrors));
        $this->assertSame($previousTeamId, getPermissionsTeamId());
        $this->assertSame('web', Auth::getDefaultDriver());
        $this->runNextSyncJob();

        $this->assertCount(2, $queries);
        $this->assertSame($employee->uuid, $queries[1]['employee_id'] ?? null);
        $this->assertSame($previousTeamId, getPermissionsTeamId());
        $this->assertSame('web', Auth::getDefaultDriver());
    }

    public static function retryRequestResults(): array
    {
        return ['success' => [false], 'API exception' => [true]];
    }

    private function runNextSyncJob(): void
    {
        app('queue.worker')->runNextJob('database', 'sync', new WorkerOptions(sleep: 0, maxTries: 1));
    }

    private function batch(): \Illuminate\Bus\Batch
    {
        $batch = app(BatchRepository::class)->store(Bus::batch([])
            ->withOption('legal_entity_id', $this->legalEntity->id)
            ->withOption('user', $this->oldUser)
            ->withOption('token', Crypt::encryptString('old-token')));
        $this->job->withBatchId($batch->id);
        $this->job->user = $this->oldUser;

        return $batch;
    }

    private function createUser(): User
    {
        return User::create(['uuid' => (string) Str::uuid(), 'email' => Str::uuid() . '@example.com', 'password' => 'unused']);
    }

    private function otherLegalEntity(): LegalEntity
    {
        return LegalEntity::create(['uuid' => (string) Str::uuid(), 'legal_entity_type_id' => $this->legalEntity->legalEntityTypeId]);
    }

    private function grant(string $scope): void
    {
        $permission = Permission::firstOrCreate(['name' => $scope, 'guard_name' => 'ehealth']);
        $this->candidate->permissions()->attach($permission, ['legal_entity_id' => $this->legalEntity->id]);
    }

    private function payload(string $token = 'current-token', array $scopes = ['employee:read']): array
    {
        return [config('ehealth.api.oauth.bearer_token') => $token,
            config('ehealth.api.oauth.token_scopes') => $scopes,
            'auth_token_expires_at' => now()->addHour()->timestamp,
            'ehealth_legal_entity_uuid' => $this->legalEntity->uuid];
    }

    private function bindSession(string $id = 'current-session', string $token = 'current-token', array $scopes = ['employee:read']): void
    {
        DB::table('sessions')->updateOrInsert(['id' => $id], [
            'user_id' => $this->candidate->id, 'last_activity' => now()->timestamp,
            'payload' => base64_encode(serialize($this->payload($token, $scopes))),
        ]);
        $this->candidate->forceFill(['session_id' => $id])->save();
    }
}

class SyncRetryProbe extends EmployeeSync
{
    public int $tries = 1;

    public function selectUser(User $user): ?User
    {
        return $this->getUserWithActiveSession($user);
    }

    public function tokenFor(User $user): string
    {
        $this->user = $user;

        return $this->getToken();
    }
}
