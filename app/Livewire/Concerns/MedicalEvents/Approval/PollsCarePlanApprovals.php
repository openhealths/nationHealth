<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\Approval;

use App\Dto\CarePlanApproval\Response as ApprovalResponse;
use App\Dto\MedicalEvents\CarePlanApprovalJobStatusResult;
use App\Enums\MedicalEvents\CarePlanApprovalJobOutcome;
use App\Models\CarePlan;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

trait PollsCarePlanApprovals
{
    protected function resolveCarePlanApprovalJob(int $pollingLinkId, int $carePlanId): CarePlanApprovalJobStatusResult
    {
        $link = Repository::approval()->findCarePlanPollingLink($pollingLinkId, $carePlanId);

        if (!$link || !$link->job) {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Pending);
        }

        $status = strtoupper((string) ($link->job->status ?? ''));
        $jobResult = $link->processingData->sortByDesc('id')->first()?->response_data ?? $link->job->response_data ?? [];

        if (is_string($jobResult)) {
            $jobResult = json_decode($jobResult, true) ?? [];
        }

        if ($status === 'FAILED') {
            return new CarePlanApprovalJobStatusResult(
                CarePlanApprovalJobOutcome::Failed,
                errorMessage: $this->formatJobError($jobResult),
            );
        }

        if ($status !== 'PROCESSED') {
            return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Pending);
        }

        $mapped = app(ObjectMapperInterface::class)->map(new Collection($jobResult), ApprovalResponse::class);
        $realApprovalId = $mapped->approvalId;
        Repository::approval()->replaceProvisionalUuid($link, $realApprovalId);
        $authMethod = $mapped->authMethod;
        $isVerified = $mapped->isVerified;
        $carePlan = Repository::approval()->carePlanForPollingLink($link);
        if ($carePlan instanceof CarePlan && $this->skipsPatientOtp($carePlan, legalEntity() ?? $carePlan->legalEntity)) {
            return new CarePlanApprovalJobStatusResult(
                CarePlanApprovalJobOutcome::Granted,
                $realApprovalId,
            );
        }

        if ($isVerified === true) {
            return new CarePlanApprovalJobStatusResult(
                CarePlanApprovalJobOutcome::Granted,
                $realApprovalId,
            );
        }

        if ($isVerified === false) {
            return new CarePlanApprovalJobStatusResult(
                CarePlanApprovalJobOutcome::OtpRequired,
                $realApprovalId,
                $authMethod,
            );
        }

        $authType = is_array($authMethod) ? ($authMethod['type'] ?? null) : null;
        if (in_array($authType, ['OFFLINE', 'THIRD_PERSON'], true)) {
            return new CarePlanApprovalJobStatusResult(
                CarePlanApprovalJobOutcome::Granted,
                $realApprovalId,
            );
        }

        // Job is already PROCESSED. Missing is_verified must not leave the UI polling forever:
        // OTP create sends SMS when the job finishes, and the doctor needs the verification modal.
        if ($authType === 'OTP' || $authType === null) {
            return new CarePlanApprovalJobStatusResult(
                CarePlanApprovalJobOutcome::OtpRequired,
                $realApprovalId,
                $authMethod,
            );
        }

        return new CarePlanApprovalJobStatusResult(CarePlanApprovalJobOutcome::Pending);
    }

    protected function formatJobError(array $jobResult): string
    {
        if (isset($jobResult['error']['invalid']) && is_array($jobResult['error']['invalid'])) {
            $errors = [];

            foreach ($jobResult['error']['invalid'] as $invalid) {
                $entry = $invalid['entry'] ?? '';
                $rules = $invalid['rules'] ?? [];

                foreach ($rules as $rule) {
                    $errors[] = ($entry ? $entry.': ' : '').($rule['description'] ?? '');
                }
            }

            if ($errors !== []) {
                return 'Помилка від ЕСОЗ: '.implode(', ', $errors);
            }
        }

        if (isset($jobResult['error']['message'])) {
            return 'Помилка від ЕСОЗ: '.$jobResult['error']['message'];
        }

        return __('care-plan.approval_create_error');
    }
}
