<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Models\CarePlan;
use App\Models\EhealthJob;
use App\Models\EhealthLink;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval;
use App\Repositories\MedicalEvents\Repository;
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

    /**
     * Tracks whether a background approval job is currently being processed.
     * When true, wire:poll.2s will check the EhealthJob status.
     */
    public bool $isPolling = false;

    /** EhealthLink id being polled (null when not polling). */
    public ?int $pollingLinkId = null;

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

        // Load active employees for the dropdown, filtered by this care plan's legal entity.
        $legalEntityId = $carePlan->legalEntity?->id ?? $legalEntity->id ?? null;
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
     * Uses the new MedicalEvents\ApprovalRepository via MedicalEvents\Repository::approval().
     */
    public function fetchApprovals(): void
    {
        $this->isLoading = true;

        try {
            $carePlan = CarePlan::findOrFail($this->carePlanId);
            Repository::approval()->syncApprovals($carePlan, 'care_plan');
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
     * Submit a new approval request to eHealth.
     *
     * The eHealth API expects:
     *   resources[].identifier.type.coding[].code = 'care_plan'
     *   resources[].identifier.value = Care Plan UUID
     *   granted_to.identifier.type.coding[].code = 'employee'
     *   granted_to.identifier.value = Employee UUID (doctor being granted access)
     *   access_level = 'read' | 'write'
     *   authorize_with = auth method UUID (OTP)
     *
     * Note: 'granted_to_type' and 'reason' are NOT allowed at the root level.
     *
     * On 202: dispatches RemoteEHealthLinksProcessing and starts wire:poll.2s.
     * On 200/201 + OTP: opens the auth modal directly.
     */
    public function createApproval(): void
    {
        $this->errorMessage = null;

        $this->validate([
            'newApproval.employee_uuid' => 'required|uuid',
        ]);

        try {
            $carePlan = CarePlan::findOrFail($this->carePlanId);

            $payload = [
                'resources' => [
                    [
                        'identifier' => [
                            'type' => [
                                'coding' => [['system' => 'eHealth/resources', 'code' => 'care_plan']],
                            ],
                            'value' => $carePlan->uuid,
                        ],
                    ],
                ],
                'granted_to' => [
                    'identifier' => [
                        'type' => [
                            'coding' => [['system' => 'eHealth/resources', 'code' => 'employee']],
                        ],
                        'value' => $this->newApproval['employee_uuid'],
                    ],
                ],
                'access_level' => 'write',
                'authorize_with' => $this->selectedAuthMethodUuid ?: null,
            ];

            $response = EHealth::approval()->createApproval($this->patientUuid, $payload);
            $responseData = $response->getData();
            $statusCode = $response->getStatusCode();

            if ($statusCode === 202) {
                // Async job — create an EhealthLink and dispatch the polling job
                $href = $responseData['links'][0]['href'] ?? null;

                if ($href) {
                    $approvalRepo = Repository::approval();

                    // Create a provisional local Approval so we have a linkable_id
                    $localApproval = Approval::firstOrCreate(
                        ['uuid' => $responseData['id'] ?? (string) \Illuminate\Support\Str::uuid()],
                        [
                            'approvable_type' => CarePlan::class,
                            'approvable_id' => $carePlan->id,
                            'status' => 'NEW',
                        ]
                    );

                    $link = $approvalRepo->attachEhealthLink($localApproval, ['href' => $href]);

                    $this->pollingLinkId = $link->id;
                    $this->approvalId = $localApproval->uuid;
                    $this->isPolling = true;

                    RemoteEHealthLinksProcessing::dispatch($link);

                    Session::flash('info', __('care-plan.approval_processing'));

                    return;
                }
            }

            // Synchronous response (200/201) — check for OTP
            if (isset($responseData['urgent']['authentication_method_current']['type'])
                && $responseData['urgent']['authentication_method_current']['type'] === 'OTP'
            ) {
                $this->approvalId = $responseData['id'];
                $this->openAuthModal();
            } else {
                Session::flash('success', __('care-plan.approval_created'));
                $this->reset('newApproval');
                $this->fetchApprovals();
            }
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
     * Checks the EhealthJob status on the tracked EhealthLink.
     * Once the job reaches PROCESSED or FAILED, stops polling and acts.
     */
    public function checkApprovalJobStatus(): void
    {
        if (!$this->isPolling || !$this->pollingLinkId) {
            return;
        }

        $link = EhealthLink::with(['job', 'linkable'])->find($this->pollingLinkId);

        if (!$link || !$link->job) {
            return;
        }

        $status = strtoupper((string) ($link->job->status ?? ''));

        if ($status === 'PROCESSED') {
            $this->isPolling = false;
            $this->pollingLinkId = null;

            // Determine if OTP is required from the job response_data
            $jobResult = $link->job->response_data ?? [];

            // Extract the real approval UUID from eHealth response and update local record & state
            $realApprovalId = $jobResult['response_data']['id']
                ?? $jobResult['data']['id']
                ?? $jobResult['id']
                ?? null;

            if ($realApprovalId) {
                if ($link->linkable && $link->linkable instanceof \App\Models\MedicalEvents\Sql\Approval) {
                    $link->linkable->update(['uuid' => $realApprovalId]);
                }
                $this->approvalId = $realApprovalId;
            }

            // Extract is_verified status (check response_data nesting first)
            $isVerified = $jobResult['response_data']['is_verified']
                ?? $jobResult['data']['is_verified']
                ?? $jobResult['is_verified']
                ?? $jobResult['urgent']['is_verified']
                ?? true;

            $authMethod = $jobResult['response_data']['authentication_method_current']
                ?? $jobResult['data']['authentication_method_current']
                ?? $jobResult['authentication_method_current']
                ?? $jobResult['urgent']['authentication_method_current']
                ?? null;

            if (!$isVerified || (isset($authMethod['type']) && $authMethod['type'] === 'OTP')) {
                $this->openAuthModal();
            } else {
                Session::flash('success', __('care-plan.approval_created'));
                $this->reset('newApproval');
                $this->fetchApprovals();
            }
        } elseif ($status === 'FAILED') {
            $this->isPolling = false;
            $this->pollingLinkId = null;

            // Extract validation/response errors from eHealth job response_data
            $jobResult = $link->job->response_data ?? [];
            $errorMessage = null;

            if (isset($jobResult['error']['invalid'])) {
                $errors = [];
                foreach ($jobResult['error']['invalid'] as $invalid) {
                    $entry = $invalid['entry'] ?? '';
                    $rules = $invalid['rules'] ?? [];
                    foreach ($rules as $rule) {
                        $errors[] = ($entry ? $entry . ': ' : '') . ($rule['description'] ?? '');
                    }
                }
                if (!empty($errors)) {
                    $errorMessage = 'Помилка від ЕСОЗ: ' . implode(', ', $errors);
                }
            }

            if (!$errorMessage && isset($jobResult['error']['message'])) {
                $errorMessage = 'Помилка від ЕСОЗ: ' . $jobResult['error']['message'];
            }

            $this->errorMessage = $errorMessage ?: __('care-plan.approval_create_error');
            Session::flash('error', $this->errorMessage);
        }
        // PROCESSING: do nothing — poll will fire again in 2 s
    }

    public function verify(): void
    {
        $this->validate($this->approvalVerificationRules());

        try {
            $response = EHealth::approval()->verify($this->patientUuid, $this->approvalId, [
                'code' => (int) $this->verificationCode,
            ]);

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
            Session::flash('error', $msg);
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
            EHealth::approval()->verify($this->patientUuid, $approvalUuid, [
                'status' => 'inactive'
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
