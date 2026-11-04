<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LegalEntity;
use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Core\EHealthJob;
use App\Core\PatientEHealthJob;
use App\Repositories\MedicalEvents\Repository;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

class ApprovalSync extends PatientEHealthJob
{
    public const string BATCH_NAME = 'ApprovalSync';

    public const string SCOPE_REQUIRED = 'approval:read';

    public const string ENTITY = LegalEntity::ENTITY_APPROVAL;

    /**
     * {@inheritDoc}
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse
    {
        $query = [
            'page' => $this->page
        ];

        if ($employeeUuid = $this->batch()->options['employee_uuid']) {
            $query['granted_to'] = $employeeUuid;
        }

        return EHealth::approval()->withToken($token)->getPatientApprovals($this->patientUuid, $query);
    }

    /**
     * {@inheritDoc}
     *
     * @throws Throwable
     */
    protected function processResponse(?EHealthResponse $response): void
    {
        $approvals = [];
        $employeeUuid = $this->batch()->options['employee_uuid'];

        foreach ($response?->validate() ?? [] as $approval) {
            if ($employeeUuid !== null && data_get($approval, 'granted_to.identifier.value') !== $employeeUuid) {
                continue;
            }

            $approvals[] = EHealth::approval()
                ->withToken($this->token)
                ->getApprovalDetails($this->patientUuid, $approval['id'])
                ->validate();
        }

        Repository::approval()->syncMany($this->patient(), $approvals);
    }

    /**
     * {@inheritDoc}
     */
    protected function getNextEntityJob(): ?EHealthJob
    {
        return $this->standalone || !$this->nextEntity ? new CompleteSync(legalEntity: $this->legalEntity, isFirstLogin: $this->isFirstLogin) : $this->nextEntity;
    }

    /**
     * {@inheritDoc}
     */
    protected function getAdditionalMiddleware(): array
    {
        return [new RateLimited('ehealth-approval-get')];
    }
}
