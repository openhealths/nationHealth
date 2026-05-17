<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Repositories\Repository;
use App\Traits\FormTrait;
use App\Traits\InteractsWithApprovals;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CarePlanApprovals extends Component
{
    use FormTrait;
    use InteractsWithApprovals;

    #[Locked]
    public int $carePlanId;

    public string $carePlanUuid = '';

    public string $patientUuid = '';

    public array $approvals = [];

    public bool $isLoading = false;

    // For creating new approval
    public array $newApproval = [
        'granted_to_legal_entity_id' => '',
        'reason' => '',
    ];

    public function mount(LegalEntity $legalEntity, CarePlan $carePlan): void
    {
        $this->carePlanId = $carePlan->id;
        $this->carePlanUuid = $carePlan->uuid ?? '';
        $this->patientUuid = $carePlan->person?->uuid ?? '';
        $this->fetchApprovals();
    }

    public function fetchApprovals(): void
    {
        $this->isLoading = true;

        try {
            $carePlan = CarePlan::findOrFail($this->carePlanId);
            Repository::approval()->syncApprovals($carePlan, 'care_plan');
            $this->approvals = $carePlan->approvals()->with('grantedTo')->latest()->get()->toArray();
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
            $carePlan = CarePlan::findOrFail($this->carePlanId);

            $payload = [
                'resources' => [
                    [
                        'identifier' => [
                            'type' => [
                                'coding' => [
                                    [
                                        'system' => 'eHealth/resources',
                                        'code' => 'care_plan',
                                    ]
                                ]
                            ],
                            'value' => $carePlan->uuid,
                        ]
                    ]
                ],
                'granted_to' => [
                    'identifier' => [
                        'type' => [
                            'coding' => [
                                [
                                    'system' => 'eHealth/resources',
                                    'code' => 'employee',
                                ]
                            ]
                        ],
                        // Using current employee who is creating the approval
                        'value' => \Illuminate\Support\Facades\Auth::user()?->getCarePlanWriterEmployee()?->uuid,
                    ]
                ],
                'access_level' => 'write',
            ];

            $response = EHealth::approval()->createApproval($this->patientUuid, $payload);
            $responseData = $response->getData();

            if (isset($responseData['urgent']['authentication_method_current']['type']) && $responseData['urgent']['authentication_method_current']['type'] === 'OTP') {
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
            $response = EHealth::approval()->verify($this->patientUuid, $this->approvalId, [
                'code' => (int) $this->verificationCode,
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
            EHealth::approval()->resendSms($this->patientUuid, $this->approvalId);
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
