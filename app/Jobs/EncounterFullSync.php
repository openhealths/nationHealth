<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\EHealthJob;
use App\Models\LegalEntity;
use App\Classes\eHealth\EHealth;
use App\Repositories\MedicalEvents\Repository;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class EncounterFullSync extends EHealthJob
{
    public const string BATCH_NAME = 'EncounterFullSync';

    public const string SCOPE_REQUIRED = 'encounter:read';

    public const string ENTITY = LegalEntity::ENTITY_ENCOUNTER;

    protected ?string $patientUuid = null;
    protected ?int $personId = null;

    public function handle(): void
    {
        $this->patientUuid = $this->batch()->options['patient_uuid'] ?? null;
        $this->personId = $this->batch()->options['person_id'] ?? null;

        parent::handle();
    }

    protected function sendRequest(string $token): PromiseInterface|EHealthResponse
    {
        return EHealth::encounter()
            ->withToken($token)
            ->getBySearchParams($this->patientUuid, [
                'managing_organization_id' => $this->legalEntity->uuid,
                'page' => $this->page
            ]);
    }

    /**
     * @throws Throwable
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        $validatedData = $response?->validate();

        if (empty($validatedData)) {
            return;
        }

        Repository::encounter()->sync($this->personId, $validatedData);
    }

    protected function getNextEntityJob(): ?EHealthJob
    {
        return $this->standalone || !$this->nextEntity
            ? new CompleteSync(legalEntity: $this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->nextEntity;
    }

    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('ehealth-encounter-get')
        ];
    }
}
