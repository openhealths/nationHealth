<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use App\Enums\JobStatus;
use App\Jobs\DivisionSync;
use App\Models\LegalEntity;
use App\Models\User;
use App\Traits\BatchLegalEntityQueries;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Tests\TestCase;

/**
 * A failed batch must not lose its jobs when the restart cannot be completed.
 */
class RestartBatchAtomicityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_failed_payloads_survive_a_dispatch_error(): void
    {
        [$batch, $jobUuid] = $this->createFailedBatch();

        try {
            $this->restarter(failDispatch: true)->restart($batch);
            $this->fail('The dispatch error has to be thrown');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertTrue(DB::table('failed_jobs')->where('uuid', $jobUuid)->exists());
        $this->assertTrue(DB::table('job_batches')->where('id', $batch->id)->exists());
    }

    public function test_batch_that_has_already_been_restarted_is_not_restarted_again(): void
    {
        [$batch, $jobUuid] = $this->createFailedBatch();

        DB::table('job_batches')->where('id', $batch->id)->delete();

        $this->assertFalse($this->restarter()->restart($batch));
        $this->assertTrue(DB::table('failed_jobs')->where('uuid', $jobUuid)->exists());
    }

    public function test_status_is_set_before_the_dispatch(): void
    {
        [$batch] = $this->createFailedBatch();
        $restarter = $this->restarter();

        $this->assertTrue($restarter->restart($batch, $this->markProcessing($batch)));
        $this->assertSame(JobStatus::PROCESSING->value, $restarter->statusAtDispatch);
    }

    public function test_status_is_rolled_back_with_a_dispatch_error(): void
    {
        [$batch] = $this->createFailedBatch();

        try {
            $this->restarter(failDispatch: true)->restart($batch, $this->markProcessing($batch));
            $this->fail('The dispatch error has to be thrown');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame('COMPLETED', LegalEntity::find($batch->legal_entity_id)->sync_status);
    }

    private function markProcessing(stdClass $batch): Closure
    {
        return fn () => LegalEntity::find($batch->legal_entity_id)->setEntityStatus(JobStatus::PROCESSING);
    }

    /**
     * @return array{0: stdClass, 1: string}
     */
    private function createFailedBatch(): array
    {
        $legalEntity = LegalEntity::create([
            'uuid' => (string)Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => DB::table('legal_entity_types')->value('id')
                ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']),
            'is_active' => true,
        ]);

        $jobUuid = (string)Str::uuid();
        $batchId = (string)Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $jobUuid,
            'connection' => 'database',
            'queue' => 'sync',
            'payload' => json_encode(['data' => ['command' => serialize(new DivisionSync($legalEntity))]]),
            'exception' => 'test',
        ]);

        DB::table('job_batches')->insert([
            'id' => $batchId,
            'legal_entity_id' => $legalEntity->id,
            'name' => DivisionSync::BATCH_NAME,
            'total_jobs' => 1,
            'pending_jobs' => 1,
            'failed_jobs' => 1,
            'failed_job_ids' => json_encode([$jobUuid]),
            'options' => base64_encode(serialize([])),
            'created_at' => time(),
        ]);

        return [DB::table('job_batches')->where('id', $batchId)->first(), $jobUuid];
    }

    private function restarter(bool $failDispatch = false): object
    {
        return new class($failDispatch) {
            use BatchLegalEntityQueries;

            public ?string $statusAtDispatch = null;

            public function __construct(private readonly bool $failDispatch)
            {
            }

            public function restart(stdClass $batch, ?Closure $beforeDispatch = null): bool
            {
                return $this->restartBatch($batch, new User(), '', LegalEntity::find($batch->legal_entity_id), $beforeDispatch);
            }

            protected function restartFailedBatch(User $user, stdClass $batch, string $token, LegalEntity $legalEntity, array $pendingJobs): void
            {
                $this->statusAtDispatch = LegalEntity::find($legalEntity->id)->sync_status;

                if ($this->failDispatch) {
                    throw new RuntimeException('dispatch failed');
                }
            }
        };
    }
}
