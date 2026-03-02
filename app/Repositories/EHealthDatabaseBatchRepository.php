<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Bus\Batch;
use Illuminate\Support\Str;
use Illuminate\Bus\PendingBatch;
use Illuminate\Bus\DatabaseBatchRepository;

class EHealthDatabaseBatchRepository extends DatabaseBatchRepository
{
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
