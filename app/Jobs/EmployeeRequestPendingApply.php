<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Core\EHealthJob;
use App\Enums\Employee\RequestStatus as LocalStatus;
use App\Enums\Employee\RevisionStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Services\Employee\EmployeeRequestMatcher;
use App\Services\Employee\EmployeeRequestProcessor;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Rate-limited per-request apply for pending edit EmployeeRequests.
 * One eHealth getDetails call per job — never call getDetails in a login listener loop.
 */
class EmployeeRequestPendingApply extends EHealthJob
{
    use Dispatchable;
    use SerializesModels;

    public const string BATCH_NAME = 'EmployeeRequestPendingApply';

    public const string SCOPE_REQUIRED = 'employee_request:read';

    public const string ENTITY = LegalEntity::ENTITY_EMPLOYEE_REQUEST;

    protected const int RATE_LIMIT_DELAY = 3;

    public function __construct(
        public EmployeeRequest $employeeRequest,
        public ?LegalEntity $legalEntity,
        protected ?EHealthJob $nextEntity = null,
        public bool $standalone = false,
    ) {
        parent::__construct(legalEntity: $legalEntity, nextEntity: $nextEntity, standalone: $standalone);
    }

    /**
     * @throws EHealthConnectionException
     */
    protected function sendRequest(string $token): PromiseInterface|EHealthResponse|null
    {
        if (blank($this->employeeRequest->uuid)) {
            return null;
        }

        return EHealth::employeeRequest()
            ->withToken($token)
            ->getDetails($this->employeeRequest->uuid);
    }

    protected function processResponse(?EHealthResponse $response): void
    {
        if ($response === null) {
            return;
        }

        $this->employeeRequest->loadMissing(['revision', 'employee', 'party', 'division']);

        $remoteData = $response->validate();
        $remoteStatus = $remoteData['status'] instanceof \BackedEnum
            ? $remoteData['status']->value
            : $remoteData['status'];

        if (in_array($remoteStatus, ['REJECTED', 'EXPIRED'], true)) {
            $newStatus = $remoteStatus === 'REJECTED' ? LocalStatus::REJECTED : LocalStatus::EXPIRED;
            $this->employeeRequest->update([
                'status' => $newStatus,
                'applied_at' => now(),
            ]);
            $this->employeeRequest->revision?->update(['status' => RevisionStatus::OUTDATED]);

            Log::info('[EmployeeRequestPendingApply] Request marked terminal without apply.', [
                'request_id' => $this->employeeRequest->id,
                'remote_status' => $remoteStatus,
            ]);

            return;
        }

        // Still awaiting email confirmation — do not touch Employee/Party.
        if (EmployeeRequestMatcher::isRemoteStillPending($remoteStatus)) {
            Log::info('[EmployeeRequestPendingApply] Remote still pending; skip apply.', [
                'request_id' => $this->employeeRequest->id,
                'remote_status' => $remoteStatus,
            ]);

            return;
        }

        if ($remoteStatus !== 'APPROVED') {
            Log::warning('[EmployeeRequestPendingApply] Unexpected remote status; skip apply.', [
                'request_id' => $this->employeeRequest->id,
                'remote_status' => $remoteStatus,
            ]);

            return;
        }

        $applyPayload = $remoteData;
        if (!empty($remoteData['employee_id'])) {
            $applyPayload['employee_id'] = $remoteData['employee_id'];
            $applyPayload['status'] = 'APPROVED';
        }
        $applyPayload['legal_entity_id'] = $applyPayload['legal_entity_id']
            ?? $this->legalEntity?->uuid;

        app(EmployeeRequestProcessor::class)->applyApprovedRequest(
            $this->employeeRequest,
            $applyPayload
        );

        Log::info('[EmployeeRequestPendingApply] Approved request applied.', [
            'request_id' => $this->employeeRequest->id,
        ]);
    }

    protected function getAdditionalMiddleware(): array
    {
        return [
            new RateLimited('ehealth-employee-request-get'),
        ];
    }

    protected function getNextEntityJob(): ?EHealthJob
    {
        return $this->standalone || !$this->nextEntity
            ? new CompleteSync($this->legalEntity, isFirstLogin: $this->isFirstLogin)
            : $this->nextEntity;
    }
}
