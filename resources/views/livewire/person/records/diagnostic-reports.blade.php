<x-layouts.patient :id="$id" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', \App\Models\MedicalEvents\Sql\DiagnosticReport::class)
            <a href="{{ route('diagnostic-report.create', [legalEntity(), 'patientId' => $id]) }}"
               class="flex items-center gap-2 button-primary px-5 py-2 text-sm shadow-sm"
            >
                @icon('plus', 'w-4 h-4')
                {{ __('patients.starts_interacting') }}
            </a>
        @endcan

        <button type="button"
                class="button-primary-outline whitespace-nowrap px-5 py-2 text-sm"
        >
            {{ __('patients.data_access') }}
        </button>

        <button wire:click.prevent=""
                type="button"
                class="button-sync flex items-center gap-2 whitespace-nowrap px-5 py-2 text-sm shadow-sm"
        >
            @icon('refresh', 'w-4 h-4')
            {{ __('patients.sync_ehealth_data') }}
        </button>
    </x-slot>

    <div class="breadcrumb-form p-4 shift-content">
        <div class="w-full mt-6" x-data="{ showAdditionalParams: $wire.entangle('showAdditionalParams') }">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('patients.diagnostic_reports_search') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <select wire:model="filterService"
                            name="filterService"
                            id="filterService"
                            class="input-select peer w-full"
                    >
                        <option value="">{{ __('forms.select') }} ...</option>
                        <option value="56001-00">56001-00 - Комп'ютерна томографія...</option>
                    </select>
                    <label for="filterService" class="label">
                        {{ __('forms.service') }}
                    </label>
                </div>

                <div class="form-group group">
                    <div class="relative">
                        <input wire:model="filterEcozId"
                               type="text"
                               name="filterEcozId"
                               id="filterEcozId"
                               class="input peer w-full"
                               placeholder=" "
                               autocomplete="off"
                        />
                        <label for="filterEcozId" class="label">
                            {{ __('patients.filter_code') }}
                        </label>
                        <button type="button" wire:click="$set('filterEcozId', '')" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600" x-show="$wire.filterEcozId">
                            @icon('close', 'w-4 h-4')
                        </button>
                    </div>
                </div>

                <div class="form-group group">
                    <select wire:model="filterDoctor"
                            name="filterDoctor"
                            id="filterDoctor"
                            class="input-select peer w-full"
                    >
                        <option value="">{{ __('forms.select') }} ...</option>
                        <option value="1">Шевченко Т.Г.</option>
                    </select>
                    <label for="filterDoctor" class="label">
                        {{ __('patients.doctor') }}
                    </label>
                </div>
            </div>

            <div class="mb-9 flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="search"
                            class="flex items-center gap-2 button-primary px-5 py-2.5 text-sm shadow-sm"
                    >
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('patients.search_button') }}</span>
                    </button>
                    <button type="button" wire:click="resetFilters"
                            class="button-primary-outline-red px-5 py-2.5 text-sm"
                    >
                        {{ __('patients.reset_filters') }}
                    </button>
                    <button type="button"
                            class="flex items-center gap-2 button-minor px-5 py-2.5 text-sm whitespace-nowrap"
                            @click.prevent="showAdditionalParams = !showAdditionalParams"
                    >
                        @icon('adjustments', 'w-4 h-4 text-gray-500')
                        <span>{{ __('patients.additional_params') }}</span>
                    </button>
                </div>

                <div class="relative" x-data="{ openGroupActions: false }" @click.outside="openGroupActions = false">
                    <button type="button"
                            @click="openGroupActions = !openGroupActions"
                            class="button-primary-outline px-5 py-2.5 text-sm"
                    >
                        {{ __('patients.group_actions') }}
                    </button>

                    <div x-show="openGroupActions"
                         x-transition
                         x-cloak
                         class="absolute right-0 top-full mt-2 z-10 w-[240px] bg-white rounded-lg shadow-lg border border-gray-200 dark:bg-gray-700 dark:border-gray-600 overflow-hidden"
                    >
                        <div class="py-1">
                            <button type="button"
                                    @click="openGroupActions = false"
                                    class="dropdown-button !flex items-center gap-2.5 w-full px-4 py-2 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors text-left"
                            >
                                <span class="text-gray-500">
                                    @icon('close', 'w-4 h-4')
                                </span>
                                {{ __('patients.revoke_access') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="diagnostic-reports-search-filters">
                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="filterCreatedAtRange"
                                   type="text"
                                   name="filterCreatedAtRange"
                                   id="filterCreatedAtRange"
                                   class="datepicker-input with-leading-icon input peer w-full"
                                   placeholder=" "
                                   autocomplete="off"
                            />
                            <label for="filterCreatedAtRange" class="wrapped-label">
                                {{ __('patients.filter_created_at_range') }}
                            </label>
                        </div>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterStatus"
                                name="filterStatus"
                                id="filterStatus"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="final">{{ __('patients.status_completed') }}</option>
                        </select>
                        <label for="filterStatus" class="label">
                            {{ __('patients.status_label') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterCategory"
                                name="filterCategory"
                                id="filterCategory"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="1">{{ __('patients.visual_studies') }}</option>
                        </select>
                        <label for="filterCategory" class="label">
                            {{ __('patients.category') }}
                        </label>
                    </div>
                </div>

                <div class="form-row-3 mb-9">
                    <div class="form-group group">
                        <select wire:model="filterReferral"
                                name="filterReferral"
                                id="filterReferral"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                        </select>
                        <label for="filterReferral" class="label">
                            {{ __('patients.referral') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterConclusion"
                                name="filterConclusion"
                                id="filterConclusion"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="completed">{{ __('patients.performed_status') }}</option>
                        </select>
                        <label for="filterConclusion" class="label">
                            {{ __('patients.doctor_conclusion') }}
                        </label>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <div class="record-inner-card">
                    <div class="record-inner-header">
                        <div class="record-inner-checkbox-col">
                            <input type="checkbox" class="default-checkbox w-5 h-5">
                        </div>

                        <div class="record-inner-column !pl-4 flex-1">
                            <div class="record-inner-label">{{ __('patients.code_and_name') }}</div>
                            <div class="record-inner-value text-[17px] font-semibold text-gray-900 dark:text-gray-100">56001-00 | Комп'ютерна томографія головного мозку</div>
                        </div>

                        <div class="record-inner-column-bordered w-full md:w-[180px] shrink-0">
                            <div class="record-inner-label">{{ __('patients.status_label') }}</div>
                            <div>
                                <span class="badge-green">
                                    {{ __('patients.status.signed_status') }}
                                </span>
                            </div>
                        </div>

                        <div class="record-inner-action-col border-l border-gray-200 dark:border-gray-700 w-16 flex items-center justify-center shrink-0 h-full relative">
                            <div x-data="{
                                open: false,
                                toggle() {
                                    if (this.open) { return this.close(); }
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
                                        class="record-inner-action-btn transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/50 p-2 rounded-lg"
                                >
                                    @icon('edit-user-outline', 'w-6 h-6 text-gray-700 dark:text-gray-300')
                                </button>

                                <div x-show="open"
                                     x-cloak
                                     x-ref="panel"
                                     x-transition.origin.top.right
                                     @click.outside="close($refs.button)"
                                     :id="$id('dropdown-button')"
                                     class="absolute right-0 mt-2 w-56 rounded-md bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 shadow-lg z-50 py-1"
                                >
                                    <button @click="close($refs.button)"
                                            class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                                    >
                                        @icon('eye', 'w-5 h-5 text-gray-500')
                                        {{ __('patients.view_details') }}
                                    </button>

                                    <button @click="close($refs.button)"
                                            class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                                    >
                                        @icon('alert-circle', 'w-5 h-5 text-gray-500')
                                        {{ __('patients.status.entered_in_error') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="record-inner-body grid grid-cols-1 xl:grid-cols-[2.2fr_1.5fr] divide-y xl:divide-y-0 xl:divide-x divide-gray-200 dark:divide-gray-700 !p-0">
                        <div class="p-3.5 pl-4 overflow-hidden">
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-3">
                                <div class="space-y-2.5 min-w-0">
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">{{ __('patients.category') }}</div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">{{ __('patients.visual_studies') }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">{{ __('patients.referral') }}</div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">1232132131123</div>
                                    </div>
                                </div>

                                <div class="space-y-2.5 min-w-0">
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">{{ __('patients.performer') }}</div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words uppercase">Сидоренко О.В.</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">{{ __('patients.conclusion') }}</div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">{{ __('patients.performed_status') }}</div>
                                    </div>
                                </div>

                                <div class="space-y-2.5 min-w-0">
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">{{ __('patients.created') }}</div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">02.02.2025</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">{{ __('patients.doctor') }}</div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">Сидоренко І.В.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="p-3.5 px-4 overflow-hidden flex flex-col justify-center">
                            <div class="space-y-4">
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">ID ECO3</div>
                                    <div class="record-inner-id-value text-[13px] break-all whitespace-normal leading-normal">1231-adsadas-aqeqe-casdda</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">ID Мед. картки</div>
                                    <div class="record-inner-id-value text-[13px] break-all whitespace-normal leading-normal">1231-adsadas-aqeqe-casdda</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <x-forms.loading />
</x-layouts.patient>
