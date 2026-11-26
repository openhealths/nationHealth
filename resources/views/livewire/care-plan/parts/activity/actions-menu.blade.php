<div
    x-data="{
        open: false,
        toggle() {
            if (this.open) {
                return this.close();
            }
            this.$refs.button.focus();
            this.open = true;
        },
        close(focusAfter) {
            if (! this.open) return;
            this.open = false;
            focusAfter && focusAfter.focus();
        },
    }"
    @keydown.escape.prevent.stop="close($refs.button)"
    @focusin.window="! $refs.panel.contains($event.target) && close()"
    x-id="['dropdown-button']"
    class="relative inline-block text-left"
>
    <button
        @click="toggle()"
        x-ref="button"
        :aria-expanded="open"
        :aria-controls="$id('dropdown-button')"
        type="button"
        class="record-inner-action-btn inline-flex items-center justify-center rounded-lg p-2 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/50"
    >
        @icon('edit-user-outline', 'svg-hover-action w-6 h-6')
    </button>

    <div
        x-show="open"
        x-cloak
        x-ref="panel"
        x-transition.origin.top.right
        @click.outside="close($refs.button)"
        :id="$id('dropdown-button')"
        class="absolute right-0 z-50 mt-2 w-56 rounded-md border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-600 dark:bg-gray-700"
    >
        <a
            href="{{ route('care-plans.activities.show', [legalEntity(), $carePlan->id, $activity->id]) }}"
            class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
            wire:navigate
        >
            @icon('eye', 'w-5 h-5 text-gray-500')
            {{ __('forms.view_details') }}
        </a>

        @if ($this->canManageActivities && in_array(strtoupper($activityStatus), ['NEW', 'DRAFT']))
            <button
                type="button"
                @click="close()"
                wire:click="editActivity({{ $activity->id }})"
                class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
            >
                @icon('edit', 'w-5 h-5 text-gray-500')
                {{ __('forms.edit') }}
            </button>
            <button
                type="button"
                @click="close()"
                wire:click="openSignatureModal('sign_activity', {{ $activity->id }})"
                class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
            >
                @icon('check', 'w-5 h-5 text-gray-500')
                {{ __('care-plan.sign_activity') }}
            </button>
            <button
                type="button"
                @click="close()"
                wire:click="confirmDeleteActivity({{ $activity->id }})"
                class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
            >
                @icon('trash', 'w-5 h-5 text-gray-500')
                {{ __('forms.delete') }}
            </button>
        @elseif ($this->canManageActivities && in_array(strtoupper($activityStatus), ['ACTIVE', 'SCHEDULED', 'IN-PROGRESS', 'IN_PROGRESS', 'ON-HOLD', 'PROCESSED']))
            @if ($resolvedKind === 'medication_request')
                <button
                    type="button"
                    @click="
                        close();
                        activeTab = 'activities';
                    "
                    wire:click="initEPrescriptionForm({{ $activity->id }})"
                    class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                >
                    @icon('check', 'w-5 h-5 text-gray-500')
                    {{ __('care-plan.issue_eprescription') }}
                </button>
            @elseif ($resolvedKind === 'device_request')
                <button
                    type="button"
                    @click="
                        close();
                        activeTab = 'activities';
                    "
                    wire:click="initReferralForm({{ $activity->id }})"
                    class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                >
                    @icon('check', 'w-5 h-5 text-gray-500')
                    {{ __('care-plan.create_device_request') }}
                </button>
            @elseif ($resolvedKind === 'service_request')
                <button
                    type="button"
                    @click="
                        close();
                        activeTab = 'activities';
                    "
                    wire:click="initReferralForm({{ $activity->id }})"
                    class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                >
                    @icon('check', 'w-5 h-5 text-gray-500')
                    {{ __('care-plan.create_referral') }}
                </button>
            @endif
            <button
                type="button"
                @click="close()"
                wire:click="openSignatureModal('cancel_activity', {{ $activity->id }})"
                class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
            >
                @icon('close', 'w-5 h-5 text-gray-500')
                {{ __('care-plan.cancel_activity') }}
            </button>
            <button
                type="button"
                @click="close()"
                wire:click="openSignatureModal('complete_activity', {{ $activity->id }})"
                class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
            >
                @icon('check-circle', 'w-5 h-5 text-gray-500')
                {{ __('care-plan.complete_activity') }}
            </button>
        @endif
    </div>
</div>
