<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use App\Enums\JobStatus;
use App\Models\LegalEntity;
use App\Models\User;
use App\Notifications\SyncNotification;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\HandlesSyncBatch;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Tests\TestCase;

/**
 * A full sync must not start over a batch that a concurrent request has just restarted.
 */
class RunningBatchCheckTest extends TestCase
{
    use DatabaseTransactions;

    public const string PATIENT_UUID = 'patient-uuid';

    private LegalEntity $legalEntity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->legalEntity = $this->createLegalEntity();
        app()->instance('legalEntity', $this->legalEntity);
    }

    public function test_running_batch_is_found_by_its_base_name_including_retries(): void
    {
        $this->createBatch('FirstLoginSync_retry_DivisionSync');

        $this->assertTrue($this->subject()->running(['DivisionSync']));
        $this->assertFalse($this->subject()->running(['EquipmentSync']));
    }

    public function test_finished_batch_and_batch_of_another_legal_entity_are_ignored(): void
    {
        $this->createBatch('DivisionSync', finished: true);
        $this->createBatch('DivisionSync', legalEntity: $this->createLegalEntity());

        $this->assertFalse($this->subject()->running(['DivisionSync']));
    }

    public function test_patient_flow_is_stopped_by_a_batch_restarted_after_the_status_check(): void
    {
        $this->createBatch('retry_ObservationSync', options: ['patient_uuid' => self::PATIENT_UUID]);
        Session::put(config('ehealth.api.oauth.bearer_token'), 'token');
        $this->actingAs(new User());

        $component = $this->subject();

        // The failed batch is gone, but the restarted one runs, so the caller must not start a full sync
        $this->assertTrue($component->shouldResumeSync('observation'));
        $this->assertTrue($component->handleResumeLogic('observation'));
        $this->assertNotNull(Session::get('error'));
    }

    public function test_patient_flow_runs_a_full_sync_when_nothing_is_running_or_failed(): void
    {
        Session::put(config('ehealth.api.oauth.bearer_token'), 'token');
        $this->actingAs(new User());

        $component = $this->subject();

        $this->assertFalse($component->shouldResumeSync('observation'));
        $this->assertFalse($component->handleResumeLogic('observation'));
        $this->assertNull(Session::get('error'));
    }

    public function test_patient_running_check_is_not_cached(): void
    {
        $component = $this->subject();

        $this->assertFalse($component->processing('observation'));

        $this->createBatch('retry_ObservationSync', options: ['patient_uuid' => self::PATIENT_UUID]);

        $this->assertFalse($component->processing('observation'));
        $this->assertTrue($component->processing('observation', fresh: true));
    }

    public function test_resume_skips_a_failed_patient_batch_without_jobs_and_restarts_the_next_one(): void
    {
        Session::put(config('ehealth.api.oauth.bearer_token'), 'token');
        $this->actingAs(new User());

        $options = ['patient_uuid' => self::PATIENT_UUID];
        $emptyOldest = $this->createBatch('retry_ObservationSync', options: $options, failedAt: time() - 100);
        $recoverable = $this->createBatch('ObservationSync_' . self::PATIENT_UUID, failedAt: time() - 10);
        $otherPatient = $this->createBatch('ObservationSync_other-patient', failedAt: time() - 50);

        $component = $this->subject();
        $component->restartable = [$recoverable];

        $this->assertTrue($component->resume('observation'));
        $this->assertSame([$emptyOldest, $recoverable], $component->attempted);
        $this->assertNotContains($otherPatient, $component->attempted);
    }

    public function test_resume_fails_only_after_all_failed_patient_batches_are_exhausted(): void
    {
        Session::put(config('ehealth.api.oauth.bearer_token'), 'token');
        $this->actingAs(new User());

        $first = $this->createBatch('ObservationSync_' . self::PATIENT_UUID, failedAt: time() - 100);
        $second = $this->createBatch('ObservationSync_' . self::PATIENT_UUID, failedAt: time() - 10);

        $component = $this->subject();

        $this->assertFalse($component->resume('observation'));
        $this->assertSame([$first, $second], $component->attempted);
    }

    public function test_resume_stops_before_the_next_candidate_when_another_request_has_restarted_the_first(): void
    {
        Notification::fake();
        Session::put(config('ehealth.api.oauth.bearer_token'), 'token');
        $this->actingAs(new User());

        $name = 'ObservationSync_' . self::PATIENT_UUID;
        $first = $this->createBatch($name, failedAt: time() - 100);
        $second = $this->createBatch($name, failedAt: time() - 10);
        $component = $this->subject();
        $component->restartable = [$second];
        $component->onRestart = function (stdClass $batch) use ($first, $name): void {
            if ($batch->id === $first) {
                DB::table('job_batches')->where('id', $first)->delete();
                $this->createBatch($name);
            }
        };

        // Prime the request cache before the other request creates its replacement batch.
        $this->assertFalse($component->processing('observation'));

        $this->assertTrue($component->handleResumeLogic('observation'));
        $this->assertSame([$first], $component->attempted);
        $this->assertSame(__('observations.messages.sync_already_running'), Session::get('error'));
        $this->assertNull(Session::get('success'));
        $this->assertTrue(DB::table('job_batches')->where('id', $second)->exists());
        Notification::assertNothingSent();
    }

    public function test_concurrent_resume_requests_cannot_restart_different_failed_batches_of_the_same_patient(): void
    {
        Notification::fake();
        Session::put(config('ehealth.api.oauth.bearer_token'), 'token');
        $this->actingAs(new User());

        $name = 'ObservationSync_' . self::PATIENT_UUID;
        $first = $this->createBatch($name, failedAt: time() - 100);
        $second = $this->createBatch($name, failedAt: time() - 10);
        $firstRequest = $this->subject();
        $secondRequest = $this->subject();
        $firstRequest->restartable = [$first];
        $secondRequest->restartable = [$first, $second];
        $secondResult = null;
        $firstRequest->onRestart = function () use ($first, $name, $secondRequest, &$secondResult): void {
            // The second request enters while the first still has no visible replacement batch.
            $secondResult = $secondRequest->handleResumeLogic('observation');
            DB::table('job_batches')->where('id', $first)->delete();
            $this->createBatch($name);
        };

        $this->assertFalse($firstRequest->processing('observation'));
        $this->assertFalse($secondRequest->processing('observation'));

        $this->assertTrue($firstRequest->handleResumeLogic('observation'));
        $this->assertTrue($secondResult);
        $this->assertSame([$first], $firstRequest->attempted);
        $this->assertSame([], $secondRequest->attempted);
        $this->assertSame(__('observations.messages.sync_already_running'), Session::get('error'));
        Notification::assertSentTimes(SyncNotification::class, 1);
    }

    public function test_resume_lock_is_released_after_a_dispatch_error(): void
    {
        Notification::fake();
        Session::put(config('ehealth.api.oauth.bearer_token'), 'token');
        $this->actingAs(new User());

        $batchId = $this->createBatch('ObservationSync_' . self::PATIENT_UUID, failedAt: time());
        $firstRequest = $this->subject();
        $firstRequest->onRestart = static function (): void {
            throw new RuntimeException('dispatch failed');
        };

        try {
            $firstRequest->handleResumeLogic('observation');
            $this->fail('The dispatch error has to be thrown');
        } catch (RuntimeException $exception) {
            $this->assertSame('dispatch failed', $exception->getMessage());
        }

        $secondRequest = $this->subject();
        $secondRequest->restartable = [$batchId];

        $this->assertTrue($secondRequest->handleResumeLogic('observation'));
        $this->assertSame([$batchId], $secondRequest->attempted);
        Notification::assertSentTimes(SyncNotification::class, 1);
    }

    private function createLegalEntity(): LegalEntity
    {
        return LegalEntity::create([
            'uuid' => (string)Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => DB::table('legal_entity_types')->value('id')
                ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']),
            'is_active' => true,
        ]);
    }

    private function createBatch(
        string $name,
        bool $finished = false,
        ?LegalEntity $legalEntity = null,
        array $options = [],
        ?int $failedAt = null
    ): string {
        $id = (string)Str::uuid();

        DB::table('job_batches')->insert([
            'id' => $id,
            'legal_entity_id' => ($legalEntity ?? $this->legalEntity)->id,
            'name' => $name,
            'total_jobs' => 1,
            'pending_jobs' => 1,
            'failed_jobs' => $failedAt === null ? 0 : 1,
            'failed_job_ids' => '[]',
            // The batch repository base64-encodes the options on PostgreSQL only
            'options' => DB::getDriverName() === 'pgsql' ? base64_encode(serialize($options)) : serialize($options),
            'created_at' => time(),
            'cancelled_at' => $failedAt,
            'finished_at' => $finished ? time() : null,
        ]);

        return $id;
    }

    private function subject(): object
    {
        return new class
        {
            use BatchLegalEntityQueries;
            use HandlesSyncBatch {
                shouldResumeSync as public;
                handleResumeLogic as public;
                resumeSynchronization as protected resumeSync;
            }

            public string $uuid = RunningBatchCheckTest::PATIENT_UUID;

            /** @var string[] */
            public array $restartable = [];

            /** @var string[] */
            public array $attempted = [];

            public ?Closure $onRestart = null;

            public function resume(string $entityType): bool
            {
                return $this->resumeSync($entityType, Auth::user(), 'token');
            }

            protected function restartBatch(
                stdClass $batch,
                User $user,
                string $token,
                ?LegalEntity $legalEntity = null,
                ?Closure $beforeDispatch = null
            ): bool {
                $this->attempted[] = $batch->id;
                $this->onRestart?->__invoke($batch);

                return in_array($batch->id, $this->restartable, true);
            }

            protected function onSyncStatusChanged(string $entityType, JobStatus $status): void
            {
            }

            public function running(array $names): bool
            {
                return $this->hasRunningBatch($names);
            }

            public function processing(string $entityType, bool $fresh = false): bool
            {
                return $this->isSyncProcessing($entityType, $fresh);
            }

            protected function getSyncStatus(string $entityType): ?string
            {
                return JobStatus::COMPLETED->value;
            }

            protected function getBatchName(string $entityType): string
            {
                return 'ObservationSync';
            }

            protected function getJobClass(string $entityType): string
            {
                return '';
            }

            protected function getEntityConstant(string $entityType): string
            {
                return LegalEntity::ENTITY_DIVISION;
            }
        };
    }
}
