<?php

declare(strict_types=1);

namespace App\Traits\MedicalEvents;

use App\Classes\eHealth\EHealth;
use App\Dto\ServiceRequest\EhealthComplete;
use App\Dto\ServiceRequest\EhealthProcess;
use App\Enums\MedicalEvents\ReferralCompletionResourceType;
use App\Enums\Person\ServiceRequestStatus;
use App\Repositories\MedicalEvents\Repository;
use App\Repositories\EmployeeRepository;
use App\Models\Employee\Employee;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

/** Shared by the Livewire and HTTP entry points; it has no component state. */
trait UpdatesReferralExecution
{
    protected function takeReferralIntoWork(string $uuid, Employee $employee, ?string $patientUuid = null, array $input = []): array
    {
        $repository = Repository::serviceRequest();
        $model = $repository->findByUuid($uuid);
        $programId = $model?->programId ?? $input['program_id'] ?? data_get($input, 'program.identifier.value');
        $programId = is_string($programId) ? (trim($programId) !== '' ? $programId : null) : ($programId ?: null);
        $source = app(EmployeeRepository::class)->referralExecutorContext($employee, $programId);
        $payload = app(ObjectMapperInterface::class)->map($source, EhealthProcess::class)->toArray();
        $api = EHealth::serviceRequest();

        if ($programId) {
            $api->qualifyAndValidate($uuid, $programId);
        }

        $response = $api->processAndResolve($uuid, $payload);
        $repository->persistExecution($uuid, $employee, $patientUuid, $programId, $response);

        return $response;
    }

    protected function completeReferral(string $referralUuid, string $resourceUuid, string $resourceType = 'encounter'): array
    {
        $type = ReferralCompletionResourceType::tryFrom($resourceType)
            ?? throw new \InvalidArgumentException(__('care-plan.referral_complete_invalid_emz_type'));

        if ($resourceUuid === '') {
            throw new \InvalidArgumentException(__('care-plan.referral_complete_emz_required'));
        }

        Repository::serviceRequest()->assertCompletionResourceOwned($referralUuid, $resourceUuid, $type);
        $source = (object) ['basedOn' => [(object) ['type' => $type->value, 'uuid' => $resourceUuid]]];
        $payload = app(ObjectMapperInterface::class)->map($source, EhealthComplete::class)->toArray();
        $response = EHealth::serviceRequest()->completeAndResolve($referralUuid, $payload);
        Repository::serviceRequest()->setExecutionStatus($referralUuid, ServiceRequestStatus::COMPLETED);

        return $response;
    }

    protected function cancelReferralUsage(string $referralUuid, string $patientUuid, array $payload = []): array
    {
        $response = EHealth::serviceRequest()->cancelUsage($referralUuid, $patientUuid, $payload)->getData();
        Repository::serviceRequest()->setExecutionStatus($referralUuid, ServiceRequestStatus::ACTIVE);

        return $response;
    }
}
