<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Services\MedicalEvents\CarePlanApprovalService;
use App\Traits\FormTrait;
use App\Traits\InteractsWithApprovals;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CarePlanApprovals extends Component
{
    use FormTrait;
    use InteractsWithApprovals;

    protected $listeners = ['refreshApprovals' => 'fetchApprovals'];

    #[Locked]
    public int $carePlanId;

    public string $carePlanUuid = '';

    public string $patientUuid = '';

    public array $approvals = [];

    public ?string $errorMessage = null;

    public bool $isLoading = false;

    public ?string $selectedAuthMethodUuid = null;
    public array $authMethods = [];

    /** Active employees of the current legal entity for the dropdown. */
    public array $employees = [];

    // For creating new approval
    public array $newApproval = [
        'employee_uuid' => '',  // UUID of the Employee (doctor) in eHealth to grant access to
    ];

    public function mount(LegalEntity $legalEntity, CarePlan $carePlan): void
    {
        $this->carePlanId = $carePlan->id;
        $this->carePlanUuid = $carePlan->uuid ?? '';
        $this->patientUuid = $carePlan->person?->uuid ?? '';
        $this->fetchApprovals();

        // Load active employees for the dropdown, filtered by the current active legal entity.
        // We must use the active legal entity (not the care plan's owner), because eHealth validates
        // that the granted employee belongs to the requesting clinic.
        $legalEntityId = legalEntity()->id;
        if ($legalEntityId) {
            $this->employees = \App\Models\Employee\Employee::where('legal_entity_id', $legalEntityId)
                ->where('status', 'APPROVED')
                ->where('is_active', true)
                ->whereIn('employee_type', [\App\Enums\User\Role::DOCTOR->value, \App\Enums\User\Role::SPECIALIST->value])
                ->with('party:id,first_name,last_name,second_name')
                ->select(['id', 'uuid', 'party_id', 'employee_type', 'position'])
                ->get()
                ->map(fn ($e) => [
                    'uuid' => $e->uuid,
                    'label' => trim($e->fullName) . ' (' . $e->employee_type . ')',
                ])
                ->toArray();
        }

        try {
            $this->authMethods = EHealth::person()->getAuthMethods($this->patientUuid)->getData();
            foreach ($this->authMethods as $method) {
                if (($method['type'] ?? '') === 'OTP') {
                    $this->selectedAuthMethodUuid = $method['id'] ?? $method['uuid'] ?? null;
                    break;
                }
            }
        } catch (\Exception $e) {
            Log::warning('CarePlanApprovals: failed to fetch patient auth methods: ' . $e->getMessage());
        }
    }

    /**
     * Sync from eHealth and refresh the local approvals list.
     */
    public function fetchApprovals(): void
    {
        $this->isLoading = true;

        try {
            $carePlan = CarePlan::findOrFail($this->carePlanId);
            app(CarePlanApprovalService::class)->syncForCarePlan($carePlan);
            $this->approvals = $carePlan->approvals()
                ->with(['grantedTo', 'reason'])
                ->latest()
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            Log::error('CarePlanApprovals: failed to fetch: ' . $e->getMessage());
            Session::flash('error', __('care-plan.approvals_fetch_error'));
        } finally {
            $this->isLoading = false;
        }
    }

    /**
     * Submit a new approval request to eHealth via CarePlanApprovalService.
     */
    public function createApproval(): void
    {
        $this->errorMessage = null;

        $this->validate([
            'newApproval.employee_uuid' => 'required|uuid',
        ]);

        try {
            $carePlan = CarePlan::findOrFail($this->carePlanId);
            $service = app(CarePlanApprovalService::class);

            $result = $service->create(
                carePlan: $carePlan,
                patientUuid: $this->patientUuid,
                employeeUuid: $this->newApproval['employee_uuid'],
                accessLevel: $service->resolveAccessLevel($carePlan),
                authorizeWith: $this->selectedAuthMethodUuid ?: null,
            );

            if ($result->isAsync()) {
                $this->pollingLinkId = $result->pollingLinkId;
                $this->approvalId = $result->approvalId;
                $this->isPolling = true;
                Session::flash('info', __('care-plan.approval_processing'));

                return;
            }

            if ($result->requiresOtp()) {
                $this->approvalId = $result->approvalId;
                $this->currentAuthMethod = $result->authMethod;
                $this->openAuthModal();

                return;
            }

            Session::flash('success', __('care-plan.approval_created'));
            $this->reset('newApproval');
            $this->fetchApprovals();
        } catch (EHealthValidationException|EHealthResponseException $e) {
            Log::error('CarePlanApprovals: eHealth error: ' . $e->getMessage());
            $this->errorMessage = $e instanceof EHealthValidationException
                ? $e->getFormattedMessage()
                : 'Помилка від ЕСОЗ: ' . $e->getMessage();
            Session::flash('error', $this->errorMessage);
        } catch (\Exception $e) {
            Log::error('CarePlanApprovals: failed to create: ' . $e->getMessage());
            $this->errorMessage = __('care-plan.approval_create_error');
            Session::flash('error', $this->errorMessage);
        }
    }

    /**
     * Called by wire:poll.2s while $isPolling === true.
     */
    public function checkApprovalJobStatus(): void
    {
        if (!$this->isPolling || !$this->pollingLinkId) {
            return;
        }

        $status = app(CarePlanApprovalService::class)->resolveAsyncJob($this->pollingLinkId);

        if ($status->isPending()) {
            return;
        }

        $this->isPolling = false;
        $this->pollingLinkId = null;

        if ($status->isFailed()) {
            $this->errorMessage = $status->errorMessage ?: __('care-plan.approval_create_error');
            Session::flash('error', $this->errorMessage);

            return;
        }

        if ($status->approvalId) {
            $this->approvalId = $status->approvalId;
        }

        if ($status->requiresOtp()) {
            $this->currentAuthMethod = $status->authMethod;
            $this->openAuthModal();

            return;
        }

        Session::flash('success', __('care-plan.approval_created'));
        $this->reset('newApproval');
        $this->fetchApprovals();
    }

    public function verifyExistingApproval(string $approvalUuid): void
    {
        $this->approvalId = $approvalUuid;
        $this->openAuthModal();
    }

    public function verify(): void
    {
        $this->validate($this->approvalVerificationRules());

        try {
            $response = app(CarePlanApprovalService::class)->verify(
                $this->patientUuid,
                $this->approvalId,
                (int) $this->verificationCode,
            );

            if ($response->successful()) {
                Session::flash('success', __('care-plan.approval_verified'));
                $this->closeAuthModal();
                $this->reset('newApproval');
                $this->fetchApprovals();
            }
        } catch (EHealthValidationException|EHealthResponseException $e) {
            Log::error('CarePlanApprovals: failed to verify: ' . $e->getMessage());
            $msg = $e instanceof EHealthValidationException
                ? $e->getFormattedMessage()
                : 'Помилка від ЕСОЗ: ' . $e->getMessage();
            $this->addError('verificationCode', $msg);
        } catch (\Exception $e) {
            Log::error('CarePlanApprovals: failed to verify: ' . $e->getMessage());
            $this->addError('verificationCode', __('care-plan.approval_verify_error'));
        }
    }

    public function resendSms(): void
    {
        if ($this->smsResent) {
            return;
        }

        try {
            app(CarePlanApprovalService::class)->resendSms($this->patientUuid, $this->approvalId);
            $this->smsResent = true;
            Session::flash('success', __('care-plan.sms_resent'));
        } catch (\Exception $e) {
            Log::error('CarePlanApprovals: failed to resend SMS: ' . $e->getMessage());
            Session::flash('error', __('care-plan.sms_resend_error'));
        }
    }

    public function cancelApproval(string $approvalUuid): void
    {
        try {
            EHealth::approval()->verify($this->patientUuid, $approvalUuid, [
                'status' => 'inactive',
            ]);
            Session::flash('success', __('care-plan.approval_cancelled'));
            $this->fetchApprovals();
        } catch (\Exception $e) {
            Log::error('CarePlanApprovals: failed to cancel: ' . $e->getMessage());
            Session::flash('error', __('care-plan.approval_cancel_error'));
        }
    }

    public function render()
    {
        return view('livewire.care-plan.care-plan-approvals');
    }
}
