<div class="record-inner-card">
    <div class="record-inner-header">
        <div class="record-inner-checkbox-col">
            <input type="checkbox" class="default-checkbox w-5 h-5">
        </div>

        <div class="record-inner-column flex-1">
            <div class="record-inner-label">{{ __('patients.code_and_name') }}</div>
            <div class="record-inner-value text-[16px]">030.2 | Чотириплідна вагітність</div>
        </div>

        <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
            <div class="record-inner-label">{{ __('patients.status_label') }}</div>
            <div>
                <span class="record-inner-status-badge">
                    {{ __('patients.active_status') }}
                </span>
            </div>
        </div>

        <div class="record-inner-action-col">
            <div class="flex justify-center relative">
                <div x-data="{
                open: false,
                toggle() {
                if (this.open) {

                return this.close();
                }
                this.$refs.button.focus();
                this.open = true;
                },
                close(focusAfter) {
                if (!this.open) return;
                this.open = false;
                focusAfter && focusAfter.focus()
                }
                }"
                     @keydown.escape.prevent.stop="close($refs.button)"
                     @focusin.window="!$refs.panel.contains($event.target) && close()"
                     x-id="['dropdown-button']"
                     class="relative"
                >
                    <button @click="toggle()"
                            x-ref="button"
                            :aria-expanded="open"
                            :aria-controls="$id('dropdown-button')"
                            type="button"
                            class="record-inner-action-btn"
                    >
                        @icon('edit-user-outline', 'w-5 h-5')
                    </button>

                    <div x-show="open"
                         x-cloak
                         x-ref="panel"
                         x-transition.origin.top.right
                         @click.outside="close($refs.button)"
                         :id="$id('dropdown-button')"
                         class="absolute right-0 mt-2 w-56 rounded-md bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 shadow-md z-50 py-1"
                    >
                        <button @click="close($refs.button)"
                                class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                        >
                            @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                            {{ __('patients.view_details') }}
                        </button>

                        <button @click="close($refs.button)"
                                class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                        >
                            @icon('edit', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                            {{ __('forms.edit') }}
                        </button>

                        <button @click="close($refs.button)"
                                class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                        >
                            @icon('close', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                            {{ __('forms.close') }}
                        </button>

                        <button @click="close($refs.button)"
                                class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                        >
                            @icon('alert-circle', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                            {{ __('patients.status.entered_in_error') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="record-inner-body">
        <div class="record-inner-grid-container">
            <div class="flex items-start justify-between gap-2 xl:gap-4 overflow-hidden">
                <div>
                    <div class="record-inner-label">{{ __('patients.date_opened') }}</div>
                    <div class="record-inner-value">02.04.2025</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.date_closed') }}</div>
                    <div class="record-inner-value">02.04.2025</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.date_updated') }}</div>
                    <div class="record-inner-value">02.02.2025</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.doctor') }}</div>
                    <div class="record-inner-value">Сидоренко І.В.</div>
                </div>
            </div>
        </div>
        <div class="record-inner-id-col">
            <div class="min-w-0">
                <div class="record-inner-label">{{ __('patients.filter_code') }}</div>
                <div class="record-inner-id-value">1231-adsadas-aqeqe-casdda</div>
            </div>
        </div>
    </div>
</div>

<div class="record-inner-card">
    <div class="record-inner-header">
        <div class="record-inner-checkbox-col">
            <input type="checkbox" class="default-checkbox w-5 h-5">
        </div>

        <div class="record-inner-column flex-1">
            <div class="record-inner-label">{{ __('patients.code_and_name') }}</div>
            <div class="record-inner-value text-[16px]">030.2 | Чотириплідна вагітність</div>
        </div>

        <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
            <div class="record-inner-label">{{ __('patients.status_label') }}</div>
            <div>
                                                <span class="record-inner-status-badge">
                                                    {{ __('patients.active_status') }}
                                                </span>
            </div>
        </div>

        <div class="record-inner-action-col">
            <div class="flex justify-center relative">
                <div x-data="{
                                                         open: false,
                                                         toggle() {
                                                             if (this.open) {
                                                                 return this.close();
                                                             }
                                                             this.$refs.button.focus();
                                                             this.open = true;
                                                         },
                                                         close(focusAfter) {
                                                             if (!this.open) return;
                                                             this.open = false;
                                                             focusAfter && focusAfter.focus()
                                                         }
                                                    }"
                     @keydown.escape.prevent.stop="close($refs.button)"
                     @focusin.window="!$refs.panel.contains($event.target) && close()"
                     x-id="['dropdown-button']"
                     class="relative"
                >
                    <button @click="toggle()"
                            x-ref="button"
                            :aria-expanded="open"
                            :aria-controls="$id('dropdown-button')"
                            type="button"
                            class="record-inner-action-btn"
                    >
                        @icon('edit-user-outline', 'w-5 h-5')
                    </button>

                    <div x-show="open"
                         x-cloak
                         x-ref="panel"
                         x-transition.origin.top.right
                         @click.outside="close($refs.button)"
                         :id="$id('dropdown-button')"
                         class="absolute right-0 mt-2 w-56 rounded-md bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 shadow-md z-50 py-1"
                    >
                        <button @click="close($refs.button)"
                                class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                        >
                            @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                            {{ __('patients.view_details') }}
                        </button>

                        <button @click="close($refs.button)"
                                class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                        >
                            @icon('edit', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                            {{ __('forms.edit') }}
                        </button>

                        <button @click="close($refs.button)"
                                class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                        >
                            @icon('close', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                            {{ __('forms.close') }}
                        </button>

                        <button @click="close($refs.button)"
                                class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                        >
                            @icon('alert-circle', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                            {{ __('patients.status.entered_in_error') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="record-inner-body">
        <div class="record-inner-grid-container">
            <div class="flex items-start justify-between gap-2 xl:gap-4 overflow-hidden">
                <div>
                    <div class="record-inner-label">{{ __('patients.date_opened') }}</div>
                    <div class="record-inner-value">02.04.2025</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.date_closed') }}</div>
                    <div class="record-inner-value">02.04.2025</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.date_updated') }}</div>
                    <div class="record-inner-value">02.02.2025</div>
                </div>
                <div>
                    <div class="record-inner-label">{{ __('patients.doctor') }}</div>
                    <div class="record-inner-value">Сидоренко І.В.</div>
                </div>
            </div>
        </div>
        <div class="record-inner-id-col">
            <div class="min-w-0">
                <div class="record-inner-label">{{ __('patients.filter_code') }}</div>
                <div class="record-inner-id-value">1231-adsadas-aqeqe-casdda</div>
            </div>
        </div>
    </div>
</div>
