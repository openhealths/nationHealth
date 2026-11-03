<section
    class="section-form"
    data-livewire-background-actions="checkSubmissionStatus checkPlanSyncStatus checkApprovalJobStatus checkSmsResendStatus event:care-plan-approvals-changed"
>
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    <x-header-navigation class="breadcrumb-form">
        <x-slot name="title">
            {{ __('care-plan.prescriptions') }} — {{ __('care-plan.care_plan') }} №{{ $carePlan->requisition ?? $carePlan->id }}
        </x-slot>
    </x-header-navigation>

    @php
        $activitySubmissionPending = ($submissionStates[$activity->uuid]['status'] ?? null) === \App\Enums\JobStatus::PENDING->value;
        $activitySubmissionProcessed = ($submissionStates[$activity->uuid]['status'] ?? null) === \App\Enums\JobStatus::PROCESSED->value;
        $hasPendingSubmission = collect($submissionStates)->contains('status', \App\Enums\JobStatus::PENDING->value);
        $resolvedKind = $activity->resolvedKind();
        $activityStatus = is_array($activity->status) ? ($activity->status['coding'][0]['code'] ?? ($activity->status['text'] ?? '')) : $activity->status;
    @endphp

    <div
        x-data="{
        showEPrescriptionDrawer: @entangle('showEPrescriptionDrawer').live,
        showReferralDrawer: @entangle('showReferralDrawer').live,
    }"
        @close-drawers.window="
            showEPrescriptionDrawer = false;
            showReferralDrawer = false;
        "
        class="form shift-content space-y-6 px-4"
    >
        @include('livewire.care-plan.parts.activity.submission-status')

        @if (!$this->canManageActivities && !$this->isTerminalCarePlan)
            <p
                class="rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-800 dark:border-yellow-900 dark:bg-gray-800 dark:text-yellow-300"
                role="status"
            >
                {{ __('care-plan.prescribing_patient_access_required') }}
            </p>
        @endif

        @if ($planSyncBatchId !== null)
            <div wire:poll.3s="checkPlanSyncStatus" role="status" class="text-sm">
                {{ __('care-plan.sync_processing') }}
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-start gap-3">
            @if (filled($activity->uuid) || filled($carePlan->uuid))
                <button
                    type="button"
                    class="button-sync flex items-center gap-2"
                    wire:click="sync"
                    wire:loading.attr="disabled"
                    wire:target="sync"
                >
                    <span wire:loading.remove wire:target="sync">
                        @icon('refresh', 'w-4 h-4')
                    </span>
                    <span wire:loading wire:target="sync" class="animate-spin">
                        @icon('refresh', 'w-4 h-4')
                    </span>
                    <span>{{ __('forms.synchronise_with_eHealth') }}</span>
                </button>
            @endif

            @if ($this->canManageActivities && !$activitySubmissionPending && !$activitySubmissionProcessed && in_array(strtoupper($activityStatus), ['NEW', 'DRAFT']))
                <a
                    href="{{ route('care-plans.show', [legalEntity(), $carePlan->id, 'edit_activity' => $activity->id]) }}"
                    class="button-minor"
                    wire:navigate
                >
                    {{ __('forms.edit') }}
                </a>
                <button
                    type="button"
                    class="button-primary-outline"
                    wire:click="openSignatureModal('sign_activity', {{ $activity->id }})"
                >
                    {{ __('care-plan.sign_activity') }}
                </button>
            @elseif ($this->canManageActivities && !$hasPendingSubmission && in_array(strtoupper($activityStatus), ['ACTIVE', 'SCHEDULED', 'IN-PROGRESS', 'IN_PROGRESS', 'ON-HOLD', 'PROCESSED']))
                @if ($resolvedKind === 'medication_request')
                    <button
                        type="button"
                        class="button-primary"
                        wire:click="initEPrescriptionForm({{ $activity->id }})"
                    >
                        {{ __('care-plan.issue_eprescription') }}
                    </button>
                @endif
                @if ($resolvedKind === 'device_request')
                    <button type="button" class="button-primary" wire:click="initReferralForm({{ $activity->id }})">
                        {{ __('care-plan.issue_device_eprescription') }}
                    </button>
                @elseif ($resolvedKind === 'service_request')
                    <button type="button" class="button-primary" wire:click="initReferralForm({{ $activity->id }})">
                        {{ __('care-plan.create_referral') }}
                    </button>
                @endif
                <button
                    type="button"
                    class="button-primary"
                    wire:click="openSignatureModal('complete_activity', {{ $activity->id }})"
                >
                    {{ __('forms.complete') }}
                </button>
                <button
                    type="button"
                    class="button-minor border-red-200 text-red-500"
                    wire:click="openSignatureModal('cancel_activity', {{ $activity->id }})"
                >
                    {{ __('forms.cancel') }}
                </button>
            @endif
        </div>

        @include('livewire.care-plan.parts.activity.detail-card', [
                                                    'dictionaries' => $dictionaries,
                                                    'activityProductLabel' => $activityProductLabel,
                                                ])

        @if ($resolvedKind === 'medication_request')
            @include('livewire.care-plan.parts.activity.prescriptions-list')
        @elseif (in_array($resolvedKind, ['service_request', 'device_request'], true))
            @include('livewire.care-plan.parts.activity.referrals-list')
        @endif

        <div class="flex justify-start pt-4 pb-12">
            <a
                href="{{ route('care-plans.show', [legalEntity(), $carePlan->id]) }}"
                class="button-minor flex items-center gap-2"
                wire:navigate
            >
                @icon('arrow-left', 'w-4 h-4')
                <span>{{ __('forms.back') }}</span>
            </a>
        </div>

        @if ($actionType === 'cancel_activity')
            @include('livewire.care-plan.parts.modals.cancel-activity-modal', ['method' => 'sign'])
        @elseif ($actionType === 'complete_activity')
            @include('livewire.care-plan.parts.modals.complete-activity-modal', ['method' => 'sign'])
        @elseif (in_array($actionType, ['cancel_referral', 'recall_referral'], true))
            <x-signature-modal method="sign">
                <x-slot:customFields>
                    <x-referral-status-fields
                        reason-model="statusReason"
                        letter-model="referralExplanatoryLetter"
                        :reasons="$this->statusReasons"
                    />
                </x-slot:customFields>
            </x-signature-modal>
        @else
            @include('components.signature-modal', ['method' => 'sign'])
        @endif

        @if ($isPolling)
            <div wire:poll.2s="checkApprovalJobStatus" class="hidden"></div>
        @endif
        @if ($showAuthModal)
            @include('livewire.care-plan.modals.authentication')
        @endif
        @if ($showMethodSelectionModal)
            @include('livewire.care-plan.modals.method-selection')
        @endif

        @include('livewire.care-plan.parts.modals.eprescription-form-drawer')
        @include('livewire.care-plan.parts.modals.referral-form-drawer')
    </div>
</section>
