<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Enums\CarePlanStatus;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Services\MedicalEvents\CarePlanApprovalService;
use App\Traits\FormTrait;
use App\Traits\InteractsWithApprovals;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class CarePlanApprovals extends Component
{
    use FormTrait;
    use InteractsWithApprovals;

    #[Locked]
    public int $carePlanId;

    #[Locked]
    public string $carePlanUuid = '';

    #[Locked]
    public string $patientUuid = '';

    #[Locked]
    public array $approvals = [];

    #[Locked]
    public ?string $errorMessage = null;

    #[Locked]
    public bool $isLoading = false;

    public ?string $selectedAuthMethodUuid = null;

    public array $authMethods = [];

    /** Active employees of the current legal entity for the dropdown. */
    #[Locked]
    public array $employees = [];

    // For creating new approval
    public array $newApproval = [
        'employee_uuid' => '',  // UUID of the Employee (doctor) in eHealth to grant access to
    ];

    #[Locked]
    public bool $isReadOnly = false;

    #[Locked]
    public string $statusLabel = '';

    public function mount(LegalEntity $legalEntity, CarePlan $carePlan): void
    {
        $this->authorize('view', $carePlan);

        $this->carePlanId = $carePlan->id;
        $this->carePlanUuid = $carePlan->uuid ?? '';
        $this->patientUuid = $carePlan->person?->uuid ?? '';
        $this->isReadOnly = CarePlanStatus::fromStored($carePlan->status)->isTerminal();
        $this->statusLabel = CarePlanStatus::labelFor($carePlan->status);
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
    #[On('refreshApprovals')]
    public function fetchApprovals(): void
    {
        $this->isLoading = true;

        try {
            $carePlan = CarePlan::findOrFail($this->carePlanId);
            app(CarePlanApprovalService::class)->syncForCarePlan($carePlan);
            $this->approvals = $carePlan->approvals()
                ->withAllRelations()
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

        $carePlan = CarePlan::findOrFail($this->carePlanId);
        $this->authorize('manage', $carePlan);
        $this->isReadOnly = CarePlanStatus::fromStored($carePlan->status)->isTerminal();

        if ($this->isReadOnly) {
            $this->errorMessage = __('care-plan.cannot_grant_terminal', [
                'status' => CarePlanStatus::labelFor($carePlan->status),
            ]);
            Session::flash('error', $this->errorMessage);

            return;
        }

        try {
            if ($this->selectedAuthMethodUuid) {
                $this->currentAuthMethod = collect($this->authMethods)->first(function ($method) {
                    return ($method['id'] ?? $method['uuid'] ?? null) === $this->selectedAuthMethodUuid;
                });
            }

            $service = app(CarePlanApprovalService::class);

            $result = $service->create(
                carePlan: $carePlan,
                patientUuid: $this->patientUuid,
                employeeUuid: $this->newApproval['employee_uuid'],
                accessLevel: $service->resolveAccessLevel($carePlan),
                authorizeWith: $this->selectedAuthMethodUuid ?: null,
                user: Auth::user(),
                bearerToken: Session::get(config('ehealth.api.oauth.bearer_token')),
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
                $this->currentAuthMethod = $result->authMethod ?? $this->currentAuthMethod;
                $this->openAuthModal();

                return;
            }

            Session::flash('success', __('care-plan.approval_created'));
            $this->reset('newApproval');
            $this->fetchApprovals();
            $this->dispatch('care-plan-approvals-changed');
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
            $this->currentAuthMethod = $status->authMethod ?? $this->currentAuthMethod;
            $this->openAuthModal();

            return;
        }

        Session::flash('success', __('care-plan.approval_created'));
        $this->reset('newApproval');
        $this->fetchApprovals();
        $this->dispatch('care-plan-approvals-changed');
    }

    public function verifyExistingApproval(string $approvalUuid): void
    {
        if ($this->guardReadOnlyApprovals()) {
            return;
        }

        $this->approvalId = $approvalUuid;
        if (empty($this->currentAuthMethod)) {
            $this->currentAuthMethod = collect($this->authMethods)->first(function ($method) {
                return ($method['type'] ?? '') === 'OTP';
            });
        }
        $this->openAuthModal();
    }

    public function recreateApproval(string $oldApprovalUuid): void
    {
        $this->errorMessage = null;

        if ($this->guardReadOnlyApprovals()) {
            return;
        }

        try {
            try {
                app(\App\Services\MedicalEvents\CarePlanApprovalService::class)
                    ->deactivate($this->patientUuid, $oldApprovalUuid);
            } catch (\Exception $e) {
                // Ignore if it's already 404 or can't be cancelled
            }

            $carePlan = \App\Models\CarePlan::findOrFail($this->carePlanId);
            $oldApproval = $carePlan->approvals()->where('uuid', $oldApprovalUuid)->first();
            $employeeUuid = $oldApproval ? $oldApproval->granted_to : \Illuminate\Support\Facades\Auth::user()?->activeDoctorEmployee()?->uuid;

            if (!$employeeUuid) {
                \Illuminate\Support\Facades\Session::flash('error', __('care-plan.employee_not_found') ?? 'Працівника не знайдено');

                return;
            }

            $service = app(\App\Services\MedicalEvents\CarePlanApprovalService::class);
            $result = $service->create(
                carePlan: $carePlan,
                patientUuid: $this->patientUuid,
                employeeUuid: $employeeUuid,
                accessLevel: $service->resolveAccessLevel($carePlan),
                authorizeWith: $this->selectedAuthMethodUuid ?: null,
            );

            if ($result->isAsync()) {
                $this->pollingLinkId = $result->pollingLinkId;
                $this->approvalId = $result->approvalId;
                $this->isPolling = true;
                \Illuminate\Support\Facades\Session::flash('info', __('care-plan.approval_processing'));

                return;
            }

            if ($result->requiresOtp()) {
                $this->approvalId = $result->approvalId;
                $this->currentAuthMethod = $result->authMethod ?? $this->currentAuthMethod;
                $this->openAuthModal();

                return;
            }

            \Illuminate\Support\Facades\Session::flash('success', __('care-plan.approval_created'));
            $this->fetchApprovals();
            $this->dispatch('care-plan-approvals-changed');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('CarePlanApprovals: failed to recreate: ' . $e->getMessage());
            $this->errorMessage = 'Помилка при перестворенні: ' . $e->getMessage();
            \Illuminate\Support\Facades\Session::flash('error', $this->errorMessage);
        }
    }

    public function verify(): void
    {
        $this->validate($this->approvalVerificationRules());

        if ($this->isOfflineAuthMethod()) {
            Log::info('CarePlanApprovals: offline document verification confirmed for approval ID: ' . $this->approvalId);
            Session::flash('success', __('care-plan.approval_verified') ?: 'Дозвіл підтверджено.');
            $this->closeAuthModal();
            $this->reset('newApproval');
            $this->fetchApprovals();
            $this->dispatch('care-plan-approvals-changed');

            return;
        }

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
                $this->dispatch('care-plan-approvals-changed');
            }
        } catch (EHealthValidationException|EHealthResponseException $e) {
            \Illuminate\Support\Facades\Log::error('CarePlanApprovals: failed to verify: ' . $e->getMessage());

            if ($e->getCode() === 404 || str_contains($e->getMessage(), '404')) {
                $this->approvalsOfCurrentPlan()->where('uuid', $this->approvalId)->delete();
                \Illuminate\Support\Facades\Session::flash('error', __('care-plan.approval_expired_404') ?? 'Цей запит на дозвіл прострочено або не знайдено в ЕСОЗ. Його скасовано. Будь ласка, використайте кнопку "Запросити новий".');
                $this->closeAuthModal();
                $this->fetchApprovals();

                return;
            }

            $msg = $e instanceof EHealthValidationException
                ? $e->getFormattedMessage()
                : 'Помилка від ЕСОЗ: ' . $e->getMessage();
            \Illuminate\Support\Facades\Session::flash('error', $msg);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('CarePlanApprovals: failed to verify: ' . $e->getMessage());
            \Illuminate\Support\Facades\Session::flash('error', __('care-plan.approval_verify_error'));
        }
    }

    public function resendSms(): void
    {
        if ($this->smsResent) {
            return;
        }

        try {
            app(\App\Services\MedicalEvents\CarePlanApprovalService::class)->resendSms($this->patientUuid, $this->approvalId);
            $this->smsResent = true;
            \Illuminate\Support\Facades\Session::flash('success', __('care-plan.sms_resent'));
        } catch (\App\Exceptions\EHealth\EHealthResponseException $e) {
            \Illuminate\Support\Facades\Log::error('CarePlanApprovals: failed to resend SMS: ' . $e->getMessage());

            if ($e->getCode() === 404 || str_contains($e->getMessage(), '404')) {
                $this->approvalsOfCurrentPlan()->where('uuid', $this->approvalId)->delete();
                \Illuminate\Support\Facades\Session::flash('error', __('care-plan.approval_expired_404') ?? 'Цей запит на дозвіл прострочено або не знайдено в ЕСОЗ. Його скасовано. Будь ласка, використайте кнопку "Запросити новий".');
                $this->closeAuthModal();
                $this->fetchApprovals();

                return;
            }
            \Illuminate\Support\Facades\Session::flash('error', __('care-plan.sms_resend_error'));
        } catch (\RuntimeException $e) {
            \Illuminate\Support\Facades\Session::flash('error', $e->getMessage());
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('CarePlanApprovals: failed to resend SMS: ' . $e->getMessage());
            \Illuminate\Support\Facades\Session::flash('error', __('care-plan.sms_resend_error'));
        }
    }

    public function cancelApproval(string $approvalUuid): void
    {
        if ($this->guardReadOnlyApprovals()) {
            return;
        }

        $this->authorize('manage', $this->currentCarePlan());
        app(\App\Services\MedicalEvents\MedicalRequestOwnership::class)
            ->approvalForCarePlan($this->currentCarePlan(), $approvalUuid);

        try {
            app(\App\Services\MedicalEvents\CarePlanApprovalService::class)
                ->deactivate($this->patientUuid, $approvalUuid);
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

    private function currentCarePlan(): CarePlan
    {
        return CarePlan::query()->findOrFail($this->carePlanId);
    }

    private function approvalsOfCurrentPlan()
    {
        return $this->currentCarePlan()->approvals();
    }

    private function guardReadOnlyApprovals(): bool
    {
        $carePlan = CarePlan::findOrFail($this->carePlanId);
        $this->isReadOnly = CarePlanStatus::fromStored($carePlan->status)->isTerminal();
        $this->statusLabel = CarePlanStatus::labelFor($carePlan->status);

        if (!$this->isReadOnly) {
            return false;
        }

        $message = __('care-plan.cannot_mutate_terminal_care_plan', [
            'status' => CarePlanStatus::labelFor($carePlan->status),
        ]);
        $this->errorMessage = $message;
        Session::flash('error', $message);

        return true;
    }
}
