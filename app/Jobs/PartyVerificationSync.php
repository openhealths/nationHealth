<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Core\EHealthJob;
use App\Models\Relations\Party;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\ProcessesPartyVerificationResponses;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Throwable;

class PartyVerificationSync extends EHealthJob
{
    use BatchLegalEntityQueries;
    use ProcessesPartyVerificationResponses;

    public const string BATCH_NAME = 'PartyVerificationFullSync';
    public const string SCOPE_REQUIRED = 'party_verification:details';

    /**
     * Sync via GET /api/parties/{id}/verification (party_verification:details)
     * instead of GET /api/parties/verifications (party_verification:read).
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null
    {
        $parties = Party::query()
            ->whereHas(
                'employees',
                fn ($query) => $query->where('legal_entity_id', $this->legalEntity->id)
            )
            ->whereNotNull('uuid')
            ->orderBy('id')
            ->get();

        foreach ($parties as $party) {
            try {
                $response = EHealth::party()
                    ->withToken($token)
                    ->getDetails($party->uuid);

                $this->processPartyVerificationDetail($party->uuid, $response, $this->legalEntity);
            } catch (Throwable $e) {
                Log::warning('PartyVerificationSync: failed to fetch details', [
                    'party_uuid' => $party->uuid,
                    'legal_entity_id' => $this->legalEntity->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    protected function processResponse(?EHealthResponse $response): void
    {
        // Handled in sendRequest via getDetails per party.
    }

    protected function getAdditionalMiddleware(): array
    {
        return [new RateLimited('ehealth-party-verification-get')];
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable|null $exception): void
    {
        Log::error('Job [PartyVerificationSync] failed.', [
            'legal_entity_id' => $this->legalEntity->id ?? 'unknown',
            'error' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);
    }

    /**
     * Get next entity job if needed.
     */
    protected function getNextEntityJob(): ?EHealthJob
    {
        return $this->standalone || !$this->nextEntity
            ? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->nextEntity;
    }
}
