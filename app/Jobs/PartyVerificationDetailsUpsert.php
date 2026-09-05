<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Core\EHealthJob;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Services\Party\PartyVerificationCache;
use App\Traits\ProcessesPartyVerificationResponses;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sync a single party verification status via GET /api/parties/{id}/verification.
 * Requires party_verification:details (OWNER/ADMIN/HR).
 */
class PartyVerificationDetailsUpsert extends EHealthJob
{
    use Dispatchable;
    use ProcessesPartyVerificationResponses;
    use SerializesModels;

    public const string BATCH_NAME = 'PartyVerificationDetailsSync';

    public const string SCOPE_REQUIRED = 'party_verification:details';

    public const string ENTITY = LegalEntity::ENTITY_PARTY_VERIFICATION;

    public function __construct(
        public Party $party,
        public ?LegalEntity $legalEntity,
        protected ?EHealthJob $nextEntity = null,
        public bool $standalone = false,
    ) {
        parent::__construct(legalEntity: $legalEntity, nextEntity: $nextEntity, standalone: $standalone);
    }

    protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null
    {
        return EHealth::party()
            ->withToken($token)
            ->getDetails($this->party->uuid);
    }

    protected function processResponse(?EHealthResponse $response): void
    {
        if ($response === null) {
            return;
        }

        $this->processPartyVerificationDetail($this->party->uuid, $response, $this->legalEntity);

        $payload = $response->json();
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        if (is_array($data)) {
            PartyVerificationCache::put($this->party->uuid, $data);
        }
    }

    protected function getAdditionalMiddleware(): array
    {
        return [new RateLimited('ehealth-party-verification-get')];
    }

    public function failed(Throwable|null $exception): void
    {
        Log::error('Job [PartyVerificationDetailsUpsert] failed.', [
            'legal_entity_id' => $this->legalEntity->id ?? 'unknown',
            'party_uuid' => $this->party->uuid ?? 'unknown',
            'error' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);

        parent::failed($exception);
    }

    protected function getNextEntityJob(): ?EHealthJob
    {
        return $this->standalone || !$this->nextEntity
            ? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->nextEntity;
    }
}
