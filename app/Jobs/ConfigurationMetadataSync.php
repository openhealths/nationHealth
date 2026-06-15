<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\EHealth;
use App\Models\ConfigurationMetadata;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ConfigurationMetadataSync implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 120;

    /**
     * Poll the eHealth configurations metadata endpoint and record per-resource changes.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            $resources = EHealth::configuration()->getMetadata()->getData();

            foreach ($resources as $resource) {
                $this->syncResource($resource['resource'], $resource['updated_at']);
            }
        } catch (Exception $exception) {
            Log::channel('task_scheduling')->error('Configurations metadata sync failed.', [
                'message' => $exception->getMessage()
            ]);
        }
    }

    /**
     * Persist a single resource's metadata, logging when its updated_at has changed.
     *
     * @param  string  $resource
     * @param  string  $updatedAt
     * @return void
     */
    private function syncResource(string $resource, string $updatedAt): void
    {
        $record = ConfigurationMetadata::firstOrNew(['resource' => $resource]);
        $previousUpdatedAt = $record->resourceUpdatedAt;

        $record->resourceUpdatedAt = $updatedAt;

        if ($previousUpdatedAt !== $record->resourceUpdatedAt) {
            Log::channel('task_scheduling')->info('Configuration resource changed.', [
                'resource' => $resource,
                'previous_updated_at' => $previousUpdatedAt,
                'updated_at' => $record->resourceUpdatedAt
            ]);
        }

        $record->save();
    }
}
