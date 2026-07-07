<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\EHealthJob;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Classes\eHealth\EHealth;
use App\Repositories\MedicalEvents\Repository;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class ProcedureSync extends EHealthJob
{
    public const string BATCH_NAME = 'ProcedureSync';

    public const string SCOPE_REQUIRED = 'procedure:read';

    public const string ENTITY = LegalEntity::ENTITY_PROCEDURE;

    protected ?string $patientUuid = null;
    protected ?int $personId = null;
    protected ?int $prepersonId = null;

    public function handle(): void
    {
        // Get patient info from batch options
        $this->patientUuid = $this->batch()->options['patient_uuid'] ?? null;
        $this->personId = $this->batch()->options['person_id'] ?? null;
        $this->prepersonId = $this->batch()->options['preperson_id'] ?? null;

        parent::handle();
    }

    /**
     * {@inheritDoc}
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse
    {
        return EHealth::procedure()
            ->withToken($token)
            ->getBySearchParams($this->patientUuid, [
                'managing_organization_id' => $this->legalEntity->uuid,
                'page' => $this->page
            ]);
    }

    /**
     * {@inheritDoc}
     *
     * @throws Throwable
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        $validatedData = $response?->validate();

        if (empty($validatedData)) {
            return;
        }

        $patient = $this->prepersonId !== null
            ? Preperson::findOrFail($this->prepersonId)
            : Person::findOrFail($this->personId);

        Repository::procedure()->sync($patient, $validatedData);
    }

    /**
     * {@inheritDoc}
     */
    protected function getNextEntityJob(): ?EHealthJob
    {
        return $this->standalone || !$this->nextEntity
            ? new CompleteSync(legalEntity: $this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->nextEntity;
    }

    /**
     * {@inheritDoc}
     */
    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('ehealth-procedure-get')
        ];
    }
}
