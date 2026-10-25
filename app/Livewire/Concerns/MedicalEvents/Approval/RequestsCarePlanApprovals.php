<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\Approval;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Dto\CarePlanApproval\Ehealth as ApprovalEhealthData;
use App\Dto\CarePlanApproval\Response as ApprovalResponse;
use App\Dto\MedicalEvents\CarePlanApprovalCreateResult;
use App\Enums\MedicalEvents\CarePlanApprovalCreateOutcome;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

trait RequestsCarePlanApprovals
{
    protected function buildCarePlanApprovalPayload(CarePlan $carePlan, string $employeeUuid, string $accessLevel = 'write', ?string $authorizeWith = null, ?LegalEntity $legalEntity = null): array
    {
        $authorizeWith = $this->skipsPatientOtp($carePlan, $legalEntity) ? null : $authorizeWith;

        return app(ObjectMapperInterface::class)->map($carePlan, new ApprovalEhealthData($employeeUuid, $accessLevel, $authorizeWith))->toArray();
    }

    protected function createCarePlanApproval(
        CarePlan $carePlan,
        string $patientUuid,
        string $employeeUuid,
        string $accessLevel = 'write',
        ?string $authorizeWith = null,
        ?LegalEntity $legalEntity = null,
        ?User $user = null,
        ?string $bearerToken = null,
    ): CarePlanApprovalCreateResult {
        $legalEntity ??= legalEntity();

        $payload = $this->buildCarePlanApprovalPayload($carePlan, $employeeUuid, $accessLevel, $authorizeWith, $legalEntity);
        $response = EHealth::approval()->createApproval($patientUuid, $payload);
        $responseData = $response->getData();
        $statusCode = $response->getStatusCode();

        if ($statusCode === 202) {
            return $this->queueCarePlanApproval($carePlan, $responseData, $legalEntity, $user, $bearerToken, $employeeUuid);
        }

        if (!in_array($statusCode, [200, 201], true)) {
            throw new RuntimeException('Unexpected eHealth approval create status: '.$statusCode);
        }

        $mapped = app(ObjectMapperInterface::class)->map(new Collection($responseData), ApprovalResponse::class);
        $approvalId = $mapped->approvalId;
        $authMethod = $mapped->authMethod;
        $urgentOtp = ($authMethod['type'] ?? null) === 'OTP';

        if ($this->skipsPatientOtp($carePlan, $legalEntity)) {
            return new CarePlanApprovalCreateResult(
                CarePlanApprovalCreateOutcome::Granted,
                $approvalId,
            );
        }

        if (($authorizeWith || $urgentOtp) && $approvalId) {
            return new CarePlanApprovalCreateResult(
                CarePlanApprovalCreateOutcome::OtpRequired,
                $approvalId,
                null,
                $authMethod,
            );
        }

        return new CarePlanApprovalCreateResult(
            CarePlanApprovalCreateOutcome::Granted,
            $approvalId,
        );
    }

    protected function resendCarePlanApprovalSms(string $patientUuid, string $approvalId): EHealthResponse
    {
        $key = 'care-plan-otp-resend:'.$patientUuid.':'.$approvalId;

        if (!Cache::add($key, true, now()->addMinutes(10))) {
            throw new RuntimeException(__('validation.sms_already_resent'));
        }

        return EHealth::approval()->resendSms($patientUuid, $approvalId);
    }
}
