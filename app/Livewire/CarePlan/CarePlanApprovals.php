<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Models\CarePlan;
use App\Models\Person\Person;
use App\Traits\FormTrait;
use App\Traits\InteractsWithApprovals;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

class CarePlanApprovals extends Component
{
    use FormTrait;
    use InteractsWithApprovals;

    public CarePlan $carePlan;
    public Person $patient;
    public Collection $approvals;
    public bool $isLoading = false;

    // For creating new approval
    public array $newApproval = [
        'granted_to_legal_entity_id' => '',
        'reason' => '',
    ];

    public function mount(CarePlan $carePlan): void
    {
        $this->carePlan = $carePlan;
        $this->patient = $carePlan->person;
        $this->approvals = new Collection();
        $this->fetchApprovals();
    }

    public function fetchApprovals(): void
    {
        $this->isLoading = true;
        try {
            app(\App\Repositories\ApprovalRepository::class)->syncApprovals($this->carePlan, 'care_plan');
            $this->approvals = $this->carePlan->approvals()->with('grantedTo')->latest()->get();
        } catch (\Exception $e) {
            Log::error('CarePlanApprovals: failed to fetch: ' . $e->getMessage());
            Session::flash('error', __('care-plan.approvals_fetch_error'));
        } finally {
            $this->isLoading = false;
        }
    }

    public function createApproval(): void
    {
        $this->validate([
            'newApproval.granted_to_legal_entity_id' => 'required|uuid',
            'newApproval.reason' => 'required|string',
        ]);

        try {
            $payload = [
                'granted_resource_id' => $this->carePlan->uuid,
                'granted_resource_type' => 'care_plan',
                'granted_to_id' => $this->newApproval['granted_to_legal_entity_id'],
                'granted_to_type' => 'legal_entity',
                // other fields if required by eHealth
            ];

            $response = EHealth::approval()->createApproval($this->patient->uuid, $payload);
            $responseData = $response->getData();

            if (isset($responseData['urgent']['authentication_method_current']['type']) && $responseData['urgent']['authentication_method_current']['type'] === 'OTP') {
                $this->patientId = $this->patient->uuid;
                $this->approvalId = $responseData['id'];
                $this->openAuthModal();
            } else {
                Session::flash('success', __('care-plan.approval_created'));
                $this->reset('newApproval');
                $this->fetchApprovals();
            }
        } catch (\Exception $e) {
            Log::error('CarePlanApprovals: failed to create: ' . $e->getMessage());
            Session::flash('error', __('care-plan.approval_create_error'));
        }
    }

    public function verify(): void
    {
        $this->validate($this->approvalVerificationRules());

        try {
            $response = EHealth::approval()->verify($this->patientId, $this->approvalId, [
                'code' => (int) $this->verificationCode
            ]);

            if ($response->getStatusCode() === 200) {
                Session::flash('success', __('care-plan.approval_verified'));
                $this->closeAuthModal();
                $this->reset('newApproval');
                $this->fetchApprovals();
            }
        } catch (\Exception $e) {
            Log::error('CarePlanApprovals: failed to verify: ' . $e->getMessage());
            Session::flash('error', __('care-plan.approval_verify_error'));
        }
    }

    public function resendSms(): void
    {
        if ($this->smsResent) {
            return;
        }

        try {
            EHealth::approval()->resendSms($this->patientId, $this->approvalId);
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
            EHealth::approval()->cancelApproval($approvalUuid);
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
