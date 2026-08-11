<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\EHealthJob;
use App\Models\Relations\Party;
use App\Repositories\Repository;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * This job is responsible for finalizing a full synchronization operation between different data sources
 *
 * @package App\Jobs
 */
class CompleteSync extends EHealthJob
{
    public const string BATCH_NAME = 'CompleteSync';

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        echo 'Starting CompleteSync for legalEntity : ' . $this->legalEntity->id . PHP_EOL;

        parent::handle();

        // Notify user about completion of sync of other entities (used for manual syncs)
        $this->sendEntityNotification(null, 'completed');
    }

    // Get data from EHealth API (here it mostly dummy method)
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null
    {
        return null;
    }

    /**
     * Finalize the sync: give every synced position an owner and refresh roles.
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        if (!$this->legalEntity) {
            return;
        }

        setPermissionsTeamId($this->legalEntity->id);

        try {
            $partyIds = Repository::employee()->bindOwnerlessEmployeesToUsers($this->legalEntity);

            $user = $this->user ?? ($this->batch()?->options['user'] ?? null);

            if ($user?->partyId !== null) {
                $partyIds[] = (int) $user->partyId;
            }

            foreach (array_unique($partyIds) as $partyId) {
                $party = Party::find($partyId);

                if ($party) {
                    Repository::party()->syncUserEmployeesAndRoles($party, $this->legalEntity);
                }
            }
        } catch (Throwable $exception) {
            Log::error('CompleteSync employee binding / role sync failed', [
                'legal_entity_id' => $this->legalEntity->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Get additional middleware configurations for the job.
     *
     * @return array Returns an array of middleware configurations to be applied to the job
     */
    protected function getAdditionalMiddleware(): array
    {
        return [];
    }
}
