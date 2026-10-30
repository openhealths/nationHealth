<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Bus\Batch;
use Illuminate\Support\Str;
use Illuminate\Bus\PendingBatch;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Support\Collection;
use stdClass;

class EHealthDatabaseBatchRepository extends DatabaseBatchRepository
{
    /** Include running batches: an old token may fail after login but before the listener runs. */
    public function getSyncResumeBatchIds(int $legalEntityId): array
    {
        return $this->connection->table($this->table)
            ->where('legal_entity_id', $legalEntityId)
            ->where(fn ($query) => $query->where('failed_jobs', '>', 0)
                ->orWhere(fn ($query) => $query->whereNull('finished_at')->whereNull('cancelled_at')))
            ->orderBy('cancelled_at')
            ->orderBy('id')
            ->pluck('id')->all();
    }

    public function getFailedSyncBatches(int $legalEntityId, array $batchIds): Collection
    {
        return $this->connection->table($this->table)
            ->select(['id', 'name'])
            ->where('legal_entity_id', $legalEntityId)
            ->where('failed_jobs', '>', 0)
            ->whereIn('id', $batchIds)
            ->orderBy('cancelled_at')
            ->orderBy('id')
            ->get();
    }

    public function findForUpdate(string $batchId): ?stdClass
    {
        return $this->connection->table($this->table)->where('id', $batchId)->lockForUpdate()->first();
    }

    public function getFailedJobs(stdClass $batch): Collection
    {
        return $this->connection->table(config('queue.failed.table', 'failed_jobs'))
            ->whereIn('uuid', json_decode($batch->failed_job_ids, true) ?? [])
            ->get();
    }

    public function deleteFailedJobs(stdClass $batch): void
    {
        $this->connection->table(config('queue.failed.table', 'failed_jobs'))
            ->whereIn('uuid', json_decode($batch->failed_job_ids, true) ?? [])
            ->delete();
    }

    public function store(PendingBatch $batch): Batch
    {
        $id = (string) Str::orderedUuid();

        $legalEntityId = $batch->options['legal_entity_id'] ?? null;

        unset($batch->options['legal_entity_id']);

        $this->connection->table($this->table)->insert([
            'id' => $id,
            'name' => $batch->name,
            'total_jobs' => 0,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => $this->serialize($batch->options),
            'legal_entity_id' => $legalEntityId,
            'created_at' => time(),
            'cancelled_at' => null,
            'finished_at' => null,
        ]);

        return $this->find($id);
    }
}
