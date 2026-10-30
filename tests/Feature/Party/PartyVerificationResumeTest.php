<?php

declare(strict_types=1);

namespace Tests\Feature\Party;

use App\Classes\eHealth\Api\Party as PartyApi;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\JobStatus;
use App\Events\EHealthUserLogin;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Jobs\CompleteSync;
use App\Jobs\EmployeeSync;
use App\Jobs\ObservationConfigurationSync;
use App\Jobs\PartyVerificationSync;
use App\Listeners\OnRegularLoginSyncronization;
use App\Models\LegalEntity;
use App\Models\User;
use App\Notifications\SyncNotification;
use App\Traits\BatchLegalEntityQueries;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Bus\BatchRepository;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Tests\TestCase;

class PartyVerificationResumeTest extends TestCase
{
    use DatabaseTransactions;

    private LegalEntity $legalEntity;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        Notification::fake();
        Http::preventStrayRequests();
        app('queue')->connection('database')->push(new ObservationConfigurationSync());
        Event::listen(JobFailed::class, static function (JobFailed $event): void {
            app('queue.failer')->log($event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $event->exception);
        });

        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);
        $this->legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => JobStatus::COMPLETED->value,
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->user = $this->createUser();
        $this->withSession([config('ehealth.api.oauth.bearer_token') => 'new-token']);
        $this->bindLoginSession();
    }

    public function testLoginWithoutReadScopePreservesFailedBatchAndSendsNoResumeNotification(): void
    {
        $batch = $this->failedBatch([new PartyVerificationSync($this->legalEntity)]);
        $this->withSession([config('ehealth.api.oauth.token_scopes') => [PartyVerificationSync::SCOPE_REQUIRED]]);

        $this->login(['employee:read']);

        $this->assertDatabaseHas('job_batches', ['id' => $batch->id, 'failed_jobs' => 1]);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSyncJobsCount(0);
        Notification::assertNothingSent();
    }

    public function testLoginWithReadScopeResumesOldJobWithCurrentUserAndToken(): void
    {
        $job = new PartyVerificationSync($this->legalEntity);
        $job->user = $this->createUser();
        $batch = $this->failedBatch([$job]);

        $this->login(['employee:read', PartyVerificationSync::SCOPE_REQUIRED]);

        $this->assertDatabaseMissing('job_batches', ['id' => $batch->id]);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSyncJobsCount(1);
        $queued = $this->queuedJob();
        $options = app(BatchRepository::class)->find($queued->batchId)->options;
        $this->assertSame($this->user->id, $queued->user->id);
        $this->assertSame('new-token', Crypt::decryptString($options['token']));
        $this->assertArrayNotHasKey('tokenScopes', $options);
        Notification::assertSentTo($this->user, SyncNotification::class, fn ($notification) => $notification->toArray($this->user)['action'] === 'resumed');
    }

    public function testLoginChecksPartyVerificationInsideASavedChain(): void
    {
        $batch = $this->failedBatch([new EmployeeSync(
            $this->legalEntity,
            new PartyVerificationSync($this->legalEntity)
        )]);

        $this->login(['employee:read']);

        $this->assertDatabaseHas('job_batches', ['id' => $batch->id, 'failed_jobs' => 1]);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSyncJobsCount(0);
    }

    public function testMixedBatchStaysIntactUntilPartyVerificationCanResume(): void
    {
        $batch = $this->failedBatch([
            new EmployeeSync($this->legalEntity),
            new PartyVerificationSync($this->legalEntity),
        ]);

        $this->login(['employee:read']);

        $this->assertDatabaseHas('job_batches', ['id' => $batch->id, 'failed_jobs' => 2, 'pending_jobs' => 2, 'total_jobs' => 2]);
        $this->assertDatabaseCount('failed_jobs', 2);
        $this->assertSyncJobsCount(0);
    }

    public function testOtherSyncJobsResumeWhenTheirScopeIsGranted(): void
    {
        $batch = $this->failedBatch([new EmployeeSync($this->legalEntity)]);

        $this->login(['employee:read']);

        $this->assertDatabaseMissing('job_batches', ['id' => $batch->id]);
        $this->assertInstanceOf(EmployeeSync::class, $this->queuedJob());
    }

    public function testLoginWithoutEmployeeScopePreservesAnEmployeeBatch(): void
    {
        $batch = $this->failedBatch([new EmployeeSync($this->legalEntity)]);

        $this->login([PartyVerificationSync::SCOPE_REQUIRED]);

        $this->assertDatabaseHas('job_batches', ['id' => $batch->id]);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSyncJobsCount(0);
        Notification::assertNothingSent();
    }

    public function testFirstLoginBatchRemainsPausedWhenResumeIsSkipped(): void
    {
        $batch = $this->failedBatch([new PartyVerificationSync($this->legalEntity)]);
        DB::table('job_batches')->where('id', $batch->id)->update(['name' => 'FirstLoginSync']);
        $this->legalEntity->setEntityStatus(JobStatus::PAUSED);

        $this->login([]);

        $this->assertSame(JobStatus::PAUSED->value, $this->legalEntity->fresh()->syncStatus);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSyncJobsCount(0);
        Notification::assertNothingSent();
    }

    public function testResumePreservesPatientContextAndUpdatesTheEntireSavedChain(): void
    {
        $nextJob = new PartyVerificationSync($this->legalEntity);
        $nextJob->user = $this->createUser();
        $batch = $this->failedBatch([new EmployeeSync($this->legalEntity, $nextJob)]);
        $options = app(BatchRepository::class)->find($batch->id)->options;
        $context = ['sync_entity' => 'employee', 'patient_uuid' => (string) Str::uuid(),
            'person_id' => 123, 'preperson_id' => null, 'has_patient_access' => true];
        DB::table('job_batches')->where('id', $batch->id)->update(['options' => base64_encode(serialize(array_merge($options, $context)))]);

        $this->login(['employee:read', PartyVerificationSync::SCOPE_REQUIRED]);

        $queued = $this->queuedJob();
        $next = (new \ReflectionProperty($queued, 'nextEntity'))->getValue($queued);
        $this->assertSame($this->user->id, $next->user->id);
        $newOptions = app(BatchRepository::class)->find($queued->batchId)->options;
        foreach ($context as $key => $value) {
            $this->assertArrayHasKey($key, $newOptions);
            $this->assertSame($value, $newOptions[$key]);
        }
    }

    public function testReusingAStaleBatchSnapshotDoesNotDispatchASecondReplacement(): void
    {
        $batch = $this->failedBatch([new PartyVerificationSync($this->legalEntity)]);
        $resumer = new class
        {
            use BatchLegalEntityQueries;

            public function resume(stdClass $batch, User $user, LegalEntity $legalEntity): bool
            {
                return $this->restartBatch($batch, $user, Crypt::encryptString('new-token'), $legalEntity, [PartyVerificationSync::SCOPE_REQUIRED]);
            }
        };

        $this->assertTrue($resumer->resume($batch, $this->user, $this->legalEntity));
        $this->assertFalse($resumer->resume($batch, $this->user, $this->legalEntity));
        $this->assertSyncJobsCount(1);
    }

    #[DataProvider('incompleteBatches')]
    public function testIncompleteOrInvalidFailedPayloadsArePreserved(string $case): void
    {
        $batch = $this->failedBatch([new EmployeeSync($this->legalEntity), new PartyVerificationSync($this->legalEntity)]);
        $failedId = DB::table('failed_jobs')->orderBy('id')->value('id');
        if ($case === 'missing record') {
            DB::table('failed_jobs')->where('id', $failedId)->delete();
        } else {
            $command = $case === 'invalid command' ? 'invalid' : serialize(new stdClass());
            DB::table('failed_jobs')->where('id', $failedId)->update(['payload' => json_encode(['data' => ['command' => $command]])]);
        }
        $count = DB::table('failed_jobs')->count();

        $this->login(['employee:read', PartyVerificationSync::SCOPE_REQUIRED]);

        $this->assertDatabaseHas('job_batches', ['id' => $batch->id]);
        $this->assertDatabaseCount('failed_jobs', $count);
        $this->assertSyncJobsCount(0);
        Notification::assertNothingSent();
    }

    public static function incompleteBatches(): array
    {
        return ['missing record' => ['missing record'], 'invalid command' => ['invalid command'], 'wrong command type' => ['wrong command type']];
    }

    public function testQueuedLoginListenerAlsoPreservesJobsWithoutReadScope(): void
    {
        $batch = $this->failedBatch([new PartyVerificationSync($this->legalEntity)]);
        $event = new EHealthUserLogin($this->user, $this->legalEntity, $this->user->uuid, ['employee:read']);
        $listener = new CallQueuedListener(OnRegularLoginSyncronization::class, 'handle', [$event]);
        app('queue')->connection('database')->push($listener, '', 'sync');

        $this->runNextJob();

        $this->assertDatabaseHas('job_batches', ['id' => $batch->id, 'failed_jobs' => 1]);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSyncJobsCount(0);
        Notification::assertNothingSent();
    }

    public function testDispatchFailureDoesNotDeleteFailedRecords(): void
    {
        $batch = $this->failedBatch([new PartyVerificationSync($this->legalEntity)]);
        Bus::shouldReceive('batch')->once()->andThrow(new RuntimeException('Queue unavailable'));

        try {
            $this->login(['employee:read', PartyVerificationSync::SCOPE_REQUIRED]);
            $this->fail('Expected dispatch failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Queue unavailable', $exception->getMessage());
        }

        $this->assertDatabaseHas('job_batches', ['id' => $batch->id, 'failed_jobs' => 1]);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function testRedeliveredLoginDoesNotResumeFailuresCreatedAfterThatLogin(): void
    {
        $original = $this->failedBatch([new PartyVerificationSync($this->legalEntity)]);
        $event = new EHealthUserLogin($this->user, $this->legalEntity, $this->user->uuid, [PartyVerificationSync::SCOPE_REQUIRED]);
        $payload = serialize($event);
        (new OnRegularLoginSyncronization())->handle($event);
        $this->assertDatabaseMissing('job_batches', ['id' => $original->id]);

        $replacement = $this->failedBatch([new PartyVerificationSync($this->legalEntity)]);
        Notification::fake();
        (new OnRegularLoginSyncronization())->handle(unserialize($payload));

        $this->assertDatabaseHas('job_batches', ['id' => $replacement->id, 'failed_jobs' => 1]);
        $this->assertSyncJobsCount(1);
        Notification::assertNothingSent();
    }

    public function testLoginResumesAnExistingBatchThatFailsAfterTheEventWasCreated(): void
    {
        $this->queuePartyJob();
        $batchId = $this->queuedJob()->batchId;
        $this->assertDatabaseHas('job_batches', ['id' => $batchId, 'failed_jobs' => 0]);
        $event = new EHealthUserLogin($this->user, $this->legalEntity, $this->user->uuid, [PartyVerificationSync::SCOPE_REQUIRED]);
        $this->mockPartyError(401, 'Revoked token');

        $this->runNextJob();
        $this->assertDatabaseHas('job_batches', ['id' => $batchId, 'failed_jobs' => 1]);
        (new OnRegularLoginSyncronization())->handle(unserialize(serialize($event)));

        $this->assertDatabaseMissing('job_batches', ['id' => $batchId]);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSyncJobsCount(1);
        Notification::assertSentTo($this->user, SyncNotification::class, fn ($notification) => $notification->toArray($this->user)['action'] === 'resumed');
    }

    #[DataProvider('staleLogins')]
    public function testStaleLoginCannotReplaceAFailedBatch(string $change): void
    {
        $batch = $this->failedBatch([new PartyVerificationSync($this->legalEntity)]);
        $event = new EHealthUserLogin($this->user, $this->legalEntity, $this->user->uuid, [PartyVerificationSync::SCOPE_REQUIRED]);
        if ($change === 'logout') {
            $this->user->forceFill(['session_id' => null])->save();
        } elseif ($change === 'relogin') {
            $this->bindLoginSession('another-token');
        } elseif ($change === 'expired session') {
            DB::table('sessions')->where('user_id', $this->user->id)->update(['last_activity' => 0]);
        } elseif ($change === 'new session with same token') {
            $session = (array) DB::table('sessions')->where('user_id', $this->user->id)->first();
            $session['id'] = 'another-session';
            DB::table('sessions')->insert($session);
            $this->user->forceFill(['session_id' => $session['id']])->save();
        } else {
            $this->bindLoginSession(expiresAt: now()->subSecond()->timestamp);
        }

        (new OnRegularLoginSyncronization())->handle(unserialize(serialize($event)));

        $this->assertDatabaseHas('job_batches', ['id' => $batch->id, 'failed_jobs' => 1]);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSyncJobsCount(0);
        Notification::assertNothingSent();
    }

    public static function staleLogins(): array
    {
        return ['logout' => ['logout'], 'relogin' => ['relogin'], 'expired session' => ['expired session'], 'expired token' => ['expired token'], 'new session with same token' => ['new session with same token']];
    }

    public function testFirstLocalLoginCanResumeAnExistingLegalEntity(): void
    {
        $batch = $this->failedBatch([new PartyVerificationSync($this->legalEntity)]);
        (new OnRegularLoginSyncronization())->handle(new EHealthUserLogin(
            $this->user,
            $this->legalEntity,
            $this->user->uuid,
            [PartyVerificationSync::SCOPE_REQUIRED],
            isFirstLogin: true
        ));

        $this->assertDatabaseMissing('job_batches', ['id' => $batch->id]);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSyncJobsCount(1);
    }

    public function testBacklogIsResumedInBoundedDeliveriesWithoutRepeatedNotifications(): void
    {
        $count = OnRegularLoginSyncronization::BATCHES_PER_RUN + 2;
        for ($index = 0; $index < $count; $index++) {
            $this->failedBatch([new PartyVerificationSync($this->legalEntity)]);
        }
        $event = new EHealthUserLogin($this->user, $this->legalEntity, $this->user->uuid, [PartyVerificationSync::SCOPE_REQUIRED]);
        (new OnRegularLoginSyncronization())->handle($event);

        $this->assertDatabaseCount('failed_jobs', 2);
        Notification::assertNothingSent();
        $continuation = DB::table('jobs')->where('queue', 'sync')->orderByDesc('id')->first();
        // Deliver the continuation first; HTTP jobs remain queued and require no external API.
        DB::table('jobs')->where('queue', 'sync')->where('id', '!=', $continuation->id)->update(['available_at' => now()->addMinute()->timestamp]);
        $this->runNextJob();

        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSyncJobsCount($count);
        Notification::assertSentToTimes($this->user, SyncNotification::class, 1);
    }

    public function testLogoutDuringABacklogLeavesTheRemainingFailuresForTheNextLogin(): void
    {
        for ($index = 0; $index < OnRegularLoginSyncronization::BATCHES_PER_RUN + 1; $index++) {
            $this->failedBatch([new PartyVerificationSync($this->legalEntity)]);
        }
        $event = new EHealthUserLogin($this->user, $this->legalEntity, $this->user->uuid, [PartyVerificationSync::SCOPE_REQUIRED]);
        (new OnRegularLoginSyncronization())->handle($event);
        $continuation = DB::table('jobs')->where('queue', 'sync')->orderByDesc('id')->first();
        DB::table('jobs')->where('queue', 'sync')->where('id', '!=', $continuation->id)->update(['available_at' => now()->addMinute()->timestamp]);
        $this->user->forceFill(['session_id' => null])->save();

        $this->runNextJob();

        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSyncJobsCount(OnRegularLoginSyncronization::BATCHES_PER_RUN);
        Notification::assertNothingSent();
    }

    private function bindLoginSession(string $token = 'new-token', ?int $expiresAt = null): void
    {
        $id = 'resume-session-' . $this->user->id;
        DB::table('sessions')->updateOrInsert(['id' => $id], [
            'user_id' => $this->user->id, 'last_activity' => now()->timestamp,
            'payload' => base64_encode(serialize([
                config('ehealth.api.oauth.bearer_token') => $token,
                'ehealth_legal_entity_uuid' => $this->legalEntity->uuid,
                'auth_token_expires_at' => $expiresAt ?? now()->addHour()->timestamp,
            ])),
        ]);
        $this->user->forceFill(['session_id' => $id])->save();
    }

    #[DataProvider('loginLogoutRuns')]
    public function testRepeatedLoginCyclesDoNotAccumulateFailedBatches(bool $logout): void
    {
        $this->failedBatch([new PartyVerificationSync($this->legalEntity)]);
        for ($cycle = 0; $cycle < 3; $cycle++) {
            $token = 'cycle-token-' . $cycle;
            $this->withSession([config('ehealth.api.oauth.bearer_token') => $token]);
            $this->bindLoginSession($token);
            $event = new EHealthUserLogin($this->user, $this->legalEntity, $this->user->uuid, [PartyVerificationSync::SCOPE_REQUIRED]);
            $payload = serialize($event);
            (new OnRegularLoginSyncronization())->handle($event);
            if ($logout) {
                $this->user->forceFill(['session_id' => null])->save();
            }
            $api = Mockery::mock(PartyApi::class);
            $api->shouldReceive('withToken')->once()->with($token)->andReturnSelf();
            $api->shouldReceive('getMany')->once()->andThrow(new EHealthResponseException(
                new Response(new GuzzleResponse(401, [], json_encode(['error' => ['message' => 'Revoked token']])))
            ));
            $this->instance(PartyApi::class, $api);
            $this->runNextJob();
            (new OnRegularLoginSyncronization())->handle(unserialize($payload));

            $this->assertDatabaseCount('job_batches', 1);
            $this->assertDatabaseCount('failed_jobs', 1);
            $this->assertSyncJobsCount(0);
            $failedUuid = DB::table('failed_jobs')->value('uuid');
            $batch = DB::table('job_batches')->first();
            $this->assertSame([$failedUuid], json_decode($batch->failed_job_ids, true));
            $this->assertSame(JobStatus::PAUSED->value, $this->legalEntity->fresh()->partyVerificationSyncStatus);
        }
    }

    public static function loginLogoutRuns(): array
    {
        return ['login and stay' => [false], 'login then logout' => [true]];
    }

    public function testFailureAfterEnqueueRestoresFailedRecordsAndRollsBackNewJob(): void
    {
        $batch = $this->failedBatch([new PartyVerificationSync($this->legalEntity)]);
        $resumer = new class
        {
            use BatchLegalEntityQueries {
                restartFailedBatch as private dispatchRestart;
            }

            public function resume(stdClass $batch, User $user, LegalEntity $legalEntity): void
            {
                $this->restartBatch($batch, $user, Crypt::encryptString('new-token'), $legalEntity, [PartyVerificationSync::SCOPE_REQUIRED]);
            }

            protected function restartFailedBatch(User $user, stdClass $batch, string $token, LegalEntity $legalEntity, array $pendingJobs): void
            {
                $this->dispatchRestart($user, $batch, $token, $legalEntity, $pendingJobs);

                throw new RuntimeException('Cleanup failed');
            }
        };

        try {
            $resumer->resume($batch, $this->user, $this->legalEntity);
            $this->fail('Expected cleanup failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cleanup failed', $exception->getMessage());
        }

        $this->assertDatabaseHas('job_batches', ['id' => $batch->id, 'failed_jobs' => 1]);
        $this->assertDatabaseCount('job_batches', 1);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSyncJobsCount(0);
    }

    public function testMissingAllowancesStopsWorkerRetriesAndPreservesJobForNextLogin(): void
    {
        $this->mockPartyError(403, 'Your scope does not allow to access this resource. Missing allowances: party_verification:read');
        $this->queuePartyJob(new EmployeeSync($this->legalEntity));

        $this->runNextJob();
        $this->login([]);

        $this->assertSyncJobsCount(0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSame(JobStatus::PAUSED->value, $this->legalEntity->fresh()->partyVerificationSyncStatus);
        $this->assertSame(JobStatus::COMPLETED->value, $this->legalEntity->fresh()->syncStatus);
        Notification::assertSentTo($this->user, SyncNotification::class, fn ($notification) => $notification->toArray($this->user)['action'] === 'paused'
            && $notification->toArray($this->user)['type'] === 'legal_entity');
    }

    public function testMissingAllowancesCanResumeOnSuitableLoginAndCompleteWithoutZombieFailedJobs(): void
    {
        $this->mockPartyError(403, 'Missing allowances: party_verification:read');
        $this->queuePartyJob();
        $oldBatchId = $this->queuedJob()->batchId;

        $this->runNextJob();

        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseHas('job_batches', ['id' => $oldBatchId, 'failed_jobs' => 1]);
        $response = new EHealthResponse(
            new GuzzleResponse(200, [], json_encode(['data' => []])),
            static fn () => [],
            static fn () => []
        );
        $api = Mockery::mock(PartyApi::class);
        $api->shouldReceive('withToken')->once()->with('fresh-token')->andReturnSelf();
        $api->shouldReceive('getMany')->once()->with([], 1)->andReturn($response);
        $this->instance(PartyApi::class, $api);
        $this->withSession([config('ehealth.api.oauth.bearer_token') => 'fresh-token']);
        $this->bindLoginSession('fresh-token');

        $this->login([PartyVerificationSync::SCOPE_REQUIRED]);

        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseMissing('job_batches', ['id' => $oldBatchId]);
        $this->assertSyncJobsCount(1);

        $this->runNextJob();

        $this->assertInstanceOf(CompleteSync::class, $this->queuedJob());
        $this->runNextJob();

        $this->assertSyncJobsCount(0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame(JobStatus::COMPLETED->value, $this->legalEntity->fresh()->partyVerificationSyncStatus);
    }

    #[DataProvider('otherErrors')]
    public function testOtherApiErrorsKeepExistingQueueRetries(int $status, string $message): void
    {
        $this->mockPartyError($status, $message);
        $this->queuePartyJob();

        $this->runNextJob();

        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseHas('jobs', ['attempts' => 1]);
    }

    public static function otherErrors(): array
    {
        return [
            'temporary failure' => [503, 'Temporarily unavailable'],
            'rate limit' => [429, 'Too many requests'],
        ];
    }

    public function testSuccessfulPartySyncStillSchedulesCompletion(): void
    {
        $response = new EHealthResponse(
            new GuzzleResponse(200, [], json_encode(['data' => []])),
            static fn () => [],
            static fn () => []
        );
        $api = Mockery::mock(PartyApi::class);
        $api->shouldReceive('withToken')->once()->with('new-token')->andReturnSelf();
        $api->shouldReceive('getMany')->once()->with([], 1)->andReturn($response);
        $this->instance(PartyApi::class, $api);
        $this->queuePartyJob();

        $this->runNextJob();

        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertInstanceOf(CompleteSync::class, $this->queuedJob());
    }

    private function createUser(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'email' => Str::uuid() . '@example.com',
            'password' => 'unused-test-password',
        ]);
    }

    private function login(array $scopes): void
    {
        (new OnRegularLoginSyncronization())->handle(new EHealthUserLogin(
            $this->user,
            $this->legalEntity,
            $this->user->uuid,
            $scopes
        ));
    }

    private function failedBatch(array $jobs): stdClass
    {
        $batch = app(BatchRepository::class)->store(Bus::batch([])
            ->name('Party Verification Status Sync')
            ->withOption('legal_entity_id', $this->legalEntity->id)
            ->withOption('token', Crypt::encryptString('old-token'))
            ->withOption('user', $this->user));
        $ids = [];

        foreach ($jobs as $job) {
            $ids[] = $uuid = (string) Str::uuid();
            DB::table('failed_jobs')->insert([
                'uuid' => $uuid,
                'connection' => 'database',
                'queue' => 'sync',
                'payload' => json_encode(['data' => ['command' => serialize($job)]]),
                'exception' => 'Previous synchronization failure',
            ]);
        }

        DB::table('job_batches')->where('id', $batch->id)->update([
            'failed_job_ids' => json_encode($ids),
            'failed_jobs' => count($ids),
            'total_jobs' => count($ids),
            'pending_jobs' => count($ids),
            'cancelled_at' => now()->timestamp,
        ]);

        return DB::table('job_batches')->where('id', $batch->id)->first();
    }

    private function queuePartyJob(?EmployeeSync $nextJob = null): void
    {
        Bus::batch([new PartyVerificationSync($this->legalEntity, $nextJob, standalone: $nextJob === null)])
            ->name('Party Verification Status Sync')
            ->withOption('legal_entity_id', $this->legalEntity->id)
            ->withOption('token', Crypt::encryptString('new-token'))
            ->withOption('user', $this->user)
            ->onQueue('sync')
            ->dispatch();
    }

    private function queuedJob(): object
    {
        $payload = json_decode(DB::table('jobs')->where('queue', 'sync')->orderBy('id')->value('payload'), true);

        return unserialize($payload['data']['command']);
    }

    private function assertSyncJobsCount(int $expected): void
    {
        $this->assertSame($expected, DB::table('jobs')->where('queue', 'sync')->count());
    }

    private function runNextJob(): void
    {
        app('queue.worker')->runNextJob('database', 'sync', new WorkerOptions(sleep: 0, maxTries: 3));
    }

    private function mockPartyError(int $status, string $message): void
    {
        $api = Mockery::mock(PartyApi::class);
        $api->shouldReceive('withToken')->once()->with('new-token')->andReturnSelf();
        $api->shouldReceive('getMany')->once()->andThrow(new EHealthResponseException(
            new Response(new GuzzleResponse($status, [], json_encode(['error' => ['message' => $message]])))
        ));
        $this->instance(PartyApi::class, $api);
    }
}
