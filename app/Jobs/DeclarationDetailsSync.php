<?php

declare(strict_types=1);

namespace App\Jobs;

use Throwable;
use App\Core\EHealthJob;
use App\Models\LegalEntity;
use App\Models\Declaration;
use App\Repositories\Repository;
use App\Classes\eHealth\EHealth;
use Illuminate\Queue\SerializesModels;
use App\Classes\eHealth\EHealthResponse;
use App\Traits\BatchLegalEntityQueries;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\RateLimited;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Dto\Declaration\Model as DeclarationData;

class DeclarationDetailsSync extends EHealthJob
{
    use BatchLegalEntityQueries;
    use Dispatchable;
    use SerializesModels;

    protected const int RATE_LIMIT_DELAY = 3;

    public const string BATCH_NAME = 'DeclarationDetailsSync';

    public const string SCOPE_REQUIRED = 'declaration:read';

    public const string ENTITY = LegalEntity::ENTITY_DECLARATION;

    public function __construct(
        public Declaration $declaration,
        public ?LegalEntity $legalEntity,
        protected ?EHealthJob $nextEntity = null,
        public bool $standalone = false,
    ) {
        parent::__construct(legalEntity: $legalEntity, nextEntity: $nextEntity, standalone: $standalone);
    }

    // Get data from EHealth API

    /**
     * @throws EHealthConnectionException
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null {
        return EHealth::declaration()
            ->withToken($token)
            ->get(uuid: $this->declaration->uuid);
    }

    /**
     * Store or update declaration data in the database.
     *
     * @throws Throwable
     */
    protected function processResponse(?EHealthResponse $response): void {
        if ($response === null) {
            return;
        }

        $data = objectMapper()->map($response->validate(), DeclarationData::class);

        Repository::declaration()->syncDetails($data, $this->declaration, $this->legalEntity);
    }

    /**
     * Get additional middleware configurations for the job.
     *
     * @return array Returns an array of middleware configurations to be applied to the job
     */
    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('ehealth-declaration-get')
        ];
    }

    // Get next entity job if needed
    protected function getNextEntityJob(): ?EHealthJob
    {
        // After declaration's details sync, we need to sync the person's authentication methods if they exist
        $personAuthMethodJob = $this->declaration?->person
            ? new PersonAuthMethodSync($this->declaration->person, $this->legalEntity, $this->nextEntity)
            : null;

        $nextEntity = $personAuthMethodJob ?? $this->nextEntity;

        return $this->standalone || !$nextEntity
            ? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $nextEntity;
    }
}
