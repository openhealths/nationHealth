<x-layouts.patient :id="$id" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', \App\Models\MedicalEvents\Sql\Encounter::class)
            <a href="{{ route('encounter.create', [legalEntity(), 'patientId' => $id]) }}"
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
                <p>{{ __('patients.encounter_search') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <div class="datepicker-wrapper">
                        <input wire:model="filterStartDateRange"
                               type="text"
                               name="filterStartDateRange"
                               id="filterStartDateRange"
                               class="datepicker-input with-leading-icon input peer w-full"
                               placeholder=" "
                               autocomplete="off"
                        />
                        <label for="filterStartDateRange" class="wrapped-label">
                            Дата початку від - до
                        </label>
                    </div>
                </div>

                <div class="form-group group">
                    <div class="datepicker-wrapper">
                        <input wire:model="filterEndDateRange"
                               type="text"
                               name="filterEndDateRange"
                               id="filterEndDateRange"
                               class="datepicker-input with-leading-icon input peer w-full"
                               placeholder=" "
                               autocomplete="off"
                        />
                        <label for="filterEndDateRange" class="wrapped-label">
                            Дата завершення від - до
                        </label>
                    </div>
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
                            ID ЕСОЗ
                        </label>
                        <button type="button" wire:click="$set('filterEcozId', '')" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600" x-show="$wire.filterEcozId">
                            @icon('close', 'w-4 h-4')
                        </button>
                    </div>
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

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="encounters-search-filters">
                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <select wire:model="filterReferral"
                                name="filterReferral"
                                id="filterReferral"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                        </select>
                        <label for="filterReferral" class="label">
                            {{ __('patients.referrals') }}
                        </label>
                        <button type="button" wire:click="$set('filterReferral', '')" class="absolute right-8 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600" x-show="$wire.filterReferral">
                            @icon('close', 'w-4 h-4')
                        </button>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterStatus"
                                name="filterStatus"
                                id="filterStatus"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                        </select>
                        <label for="filterStatus" class="label">
                            {{ __('patients.status_clinical') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterClass"
                                name="filterClass"
                                id="filterClass"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                        </select>
                        <label for="filterClass" class="label">
                            {{ __('patients.class') }}
                        </label>
                    </div>
                </div>

                <div class="form-row-3 mb-9">
                    <div class="form-group group">
                        <select wire:model="filterSpeciality"
                                name="filterSpeciality"
                                id="filterSpeciality"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                        </select>
                        <label for="filterSpeciality" class="label">
                            {{ __('patients.doctor_speciality') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterType"
                                name="filterType"
                                id="filterType"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                        </select>
                        <label for="filterType" class="label">
                            {{ __('forms.type') }}
                        </label>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <div class="record-inner-card !rounded-[20px]">
                    <div class="record-inner-header !grid grid-cols-[64px_1fr_180px_64px] items-center !p-0 border-b border-gray-200 dark:border-gray-700">
                        <div class="record-inner-checkbox-col h-full flex items-center justify-center border-r border-gray-200 dark:border-gray-700">
                            <input type="checkbox" class="default-checkbox w-5 h-5">
                        </div>

                        <div class="record-inner-column !pl-6 py-2">
                            <div class="record-inner-label">{{ __('forms.date') }}</div>
                            <div class="record-inner-value text-[17px] font-semibold text-gray-900 dark:text-gray-100">02.04.2025-03.04.2025</div>
                        </div>

                        <div class="record-inner-column flex flex-col gap-1 !px-6 py-2 h-full justify-center border-l border-gray-200 dark:border-gray-700">
                            <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                            <div>
                                <span class="badge-green">
                                    {{ __('patients.active_status') }}
                                </span>
                            </div>
                        </div>

                        <div class="record-inner-action-col flex items-center justify-center shrink-0 h-full relative border-l border-gray-200 dark:border-gray-700">
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

                    <div class="record-inner-body !grid grid-cols-[64px_1fr_244px] !p-0">
                        <div class="h-full"></div>

                        <div class="p-3.5 pl-6 grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div class="min-w-0">
                                <div class="record-inner-label text-[10px] uppercase">{{ __('patients.class') }}</div>
                                <div class="record-inner-value text-[14px] font-semibold break-words leading-tight">{{ __('patients.inpatient_care') }}</div>
                            </div>
                            <div class="min-w-0">
                                <div class="record-inner-label text-[10px] uppercase">{{ __('forms.type') }}</div>
                                <div class="record-inner-value text-[14px] font-semibold break-words leading-tight">{{ __('patients.health_facility_interaction') }}</div>
                            </div>
                            <div class="min-w-0">
                                <div class="record-inner-label text-[10px] uppercase">{{ __('patients.doctor_speciality') }}</div>
                                <div class="record-inner-value text-[14px] font-semibold break-words leading-tight">Хірургія</div>
                            </div>
                            <div class="min-w-0">
                                <div class="record-inner-label text-[10px] uppercase">{{ __('patients.referrals') }}</div>
                                <div class="record-inner-value text-[14px] font-semibold break-words leading-tight">1231-adsadas-aqeqe-casdda</div>
                            </div>
                        </div>

                        <div class="p-3.5 pl-6 flex flex-col gap-4 border-l border-gray-200 dark:border-gray-700">
                            <div class="min-w-0">
                                <div class="record-inner-label text-[10px] uppercase">{{ __('patients.filter_code') }}</div>
                                <div class="record-inner-value text-[14px] font-semibold break-words leading-tight">1231-adsadas-aqeqe-casdda</div>
                            </div>
                            <div class="min-w-0">
                                <div class="record-inner-label text-[10px] uppercase">ID {{ __('patients.episode') }}</div>
                                <div class="record-inner-value text-[14px] font-semibold break-words leading-tight">1231-adsadas-aqeqe-casdda</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <x-forms.loading />
</x-layouts.patient>
