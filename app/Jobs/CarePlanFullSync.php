<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\EHealthJob;
use App\Models\LegalEntity;
use App\Classes\eHealth\EHealth;
use App\Repositories\CarePlanRepository;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\EHealthResponse;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class CarePlanFullSync extends EHealthJob
{
    public const string BATCH_NAME = 'CarePlanFullSync';

    public const string SCOPE_REQUIRED = 'care_plan:read';

    public const string ENTITY = LegalEntity::ENTITY_CARE_PLAN;

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
        return EHealth::carePlan()
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

        app(CarePlanRepository::class)->syncCarePlans($validatedData, $this->personId);
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
            new RateLimited('ehealth-encounter-get') // Or a specific rate limiter if we have one for care plans
        ];
    }
}
