<div class="relative flex justify-center">
    <div
        x-data="{
            open: false,
            toggle() {
                if (this.open) return this.close();
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
        class="relative"
    >
        <button
            @click="toggle()"
            x-ref="button"
            :aria-expanded="open"
            :aria-controls="$id('dropdown-button')"
            type="button"
            data-referral-action
            class="hover:text-primary cursor-pointer"
            aria-label="{{ __('referrals.row_actions') }}"
            id="menu-{{ $referralId }}"
        >
            <svg
                class="svg-hover-action h-6 w-6 text-gray-800 dark:text-gray-300"
                aria-hidden="true"
                xmlns="http://www.w3.org/2000/svg"
                width="18"
                height="18"
                fill="none"
                viewBox="0 0 24 24"
            >
                <path
                    stroke="currentColor"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M7 19H5a1 1 0 0 1-1-1v-1a3 3 0 0 1 3-3h1m4-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm7.441 1.559a1.907 1.907 0 0 1 0 2.698l-6.069 6.069L10 19l.674-3.372 6.07-6.07a1.907 1.907 0 0 1 2.697 0Z"
                />
            </svg>
        </button>

        <div
            x-show="open"
            x-cloak
            x-ref="panel"
            x-transition.origin.top.left
            @click.outside="close($refs.button)"
            :id="$id('dropdown-button')"
            @click="close($refs.button)"
            class="absolute right-0 z-50 mt-2 w-64 rounded-md border border-gray-200 bg-white shadow-md dark:border-gray-600 dark:bg-gray-700"
            wire:key="referral-menu-{{ $referralId }}-{{ $status }}-{{ $programStatus }}"
        >
            @if ($isDraft)
                <button type="button" class="dropdown-item" disabled>
                    @icon('file-plus', 'w-5 h-5')
                    {{ __('referrals.actions.create_en') }}
                </button>
                <button type="button" class="dropdown-item" disabled>
                    @icon('edit', 'w-5 h-5')
                    {{ __('forms.edit') }}
                </button>
                <button type="button" class="dropdown-item dropdown-delete text-red-600! dark:text-red-400!" disabled>
                    @icon('trash', 'w-5 h-5')
                    {{ __('referrals.actions.delete_request') }}
                </button>
            @else
                @if ($this->canTakeIntoWork($referral))
                    @can('service_request:makeinprogress')
                        <button
                            type="button"
                            class="dropdown-item"
                            wire:click="process('{{ $referralId }}', '{{ data_get($referral, 'subject.identifier.value', '') }}')"
                        >
                            @icon('referrals', 'w-5 h-5')
                            {{ __('referrals.actions.take_in_work') }}
                        </button>
                    @endcan
                @endif
                <button type="button" class="dropdown-item" disabled>
                    @icon('eye', 'w-5 h-5')
                    {{ __('forms.view_details') }}
                </button>
                @if ($isIssuedReferral)
                    <button type="button" class="dropdown-item" disabled>
                        @icon('printer', 'w-5 h-5')
                        {{ __('referrals.actions.print_memo') }}
                    </button>
                    <button
                        type="button"
                        class="dropdown-item dropdown-delete text-red-600! dark:text-red-400!"
                        wire:click="openRecallModal('{{ $referralId }}')"
                    >
                        @icon('cancel', 'w-5 h-5')
                        {{ __('referrals.actions.cancel_referral') }}
                    </button>
                    <button
                        type="button"
                        class="dropdown-item dropdown-delete text-red-600! dark:text-red-400!"
                        wire:click="openErrorModal('{{ $referralId }}')"
                    >
                        @icon('cancel', 'w-5 h-5')
                        {{ __('referrals.actions.mark_as_error') }}
                    </button>
                @endif
                @if ($this->canManageUsage($referral))
                    @can('service_request:complete')
                        <button type="button" class="dropdown-item" wire:click="openCompleteModal('{{ $referralId }}')">
                            @icon('check', 'w-5 h-5')
                            {{ __('referrals.actions.complete') }}
                        </button>
                    @endcan
                    @can('service_request:use')
                        <button
                            type="button"
                            class="dropdown-item dropdown-delete border-t border-gray-100 text-red-600! dark:border-gray-600 dark:text-red-400!"
                            wire:click="openCancelModal('{{ $referralId }}')"
                        >
                            @icon('cancel', 'w-5 h-5')
                            {{ __('referrals.modals.cancel_usage.confirm') }}
                        </button>
                    @endcan
                @endif
            @endif
        </div>
    </div>
</div>
