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
    }

    public function test_login_without_read_scope_preserves_failed_batch_and_sends_no_resume_notification(): void
    {
        $batch = $this->failedBatch([new PartyVerificationSync($this->legalEntity)]);
        $this->withSession([config('ehealth.api.oauth.token_scopes') => [PartyVerificationSync::SCOPE_REQUIRED]]);

        $this->login(['employee:read']);

        $this->assertDatabaseHas('job_batches', ['id' => $batch->id, 'failed_jobs' => 1]);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSyncJobsCount(0);
        Notification::assertNothingSent();
    }

    public function test_login_with_read_scope_resumes_old_job_with_current_user_and_token(): void
    {
        $job = new PartyVerificationSync($this->legalEntity);
        $job->user = $this->createUser();
        $batch = $this->failedBatch([$job]);

        $this->login([PartyVerificationSync::SCOPE_REQUIRED]);

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

    public function test_login_checks_party_verification_inside_a_saved_chain(): void
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

    public function test_mixed_batch_stays_intact_until_party_verification_can_resume(): void
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

    public function test_other_sync_jobs_keep_their_existing_resume_behavior(): void
    {
        $batch = $this->failedBatch([new EmployeeSync($this->legalEntity)]);

        $this->login([]);

        $this->assertDatabaseMissing('job_batches', ['id' => $batch->id]);
        $this->assertInstanceOf(EmployeeSync::class, $this->queuedJob());
    }

    public function test_queued_login_listener_also_preserves_jobs_without_read_scope(): void
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

    public function test_dispatch_failure_does_not_delete_failed_records(): void
    {
        $batch = $this->failedBatch([new PartyVerificationSync($this->legalEntity)]);
        Bus::shouldReceive('batch')->once()->andThrow(new RuntimeException('Queue unavailable'));

        try {
            $this->login([PartyVerificationSync::SCOPE_REQUIRED]);
            $this->fail('Expected dispatch failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Queue unavailable', $exception->getMessage());
        }

        $this->assertDatabaseHas('job_batches', ['id' => $batch->id, 'failed_jobs' => 1]);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function test_failure_after_enqueue_restores_failed_records_and_rolls_back_new_job(): void
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

    public function test_missing_allowances_stops_worker_retries_and_preserves_job_for_next_login(): void
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
            && $notification->toArray($this->user)['type'] === 'party_verification');
    }

    #[DataProvider('otherErrors')]
    public function test_other_api_errors_keep_existing_queue_retries(int $status, string $message): void
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
            'another forbidden response' => [403, 'Party is not verified'],
        ];
    }

    public function test_successful_party_sync_still_schedules_completion(): void
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
