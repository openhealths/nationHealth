<x-layouts.patient :personId="$personId" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', \App\Models\MedicalEvents\Sql\Encounter::class)
            <a href="{{ route('encounter.create', [legalEntity(), 'personId' => $personId]) }}"
               class="flex items-center gap-2 button-primary px-5 py-2 text-sm shadow-sm"
            >
                @icon('plus', 'w-4 h-4')
                {{ __('patients.start_interacting') }}
            </a>
        @endcan

        <button type="button"
                class="button-primary-outline whitespace-nowrap px-5 py-2 text-sm"
        >
            {{ __('patients.data_access') }}
        </button>

        <button wire:click.prevent="syncDiagnoses"
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
                <p>{{ __('patients.diagnoses_search') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <select wire:model="filterCode"
                            name="filterCode"
                            id="filterCode"
                            class="input-select peer w-full"
                    >
                        <option value="">{{ __('forms.select') }} ...</option>
                        <option value="2A00.00">2A00.00 Гліобластома головного мозку</option>
                    </select>
                    <label for="filterCode" class="label">
                        {{ __('patients.code_and_name') }}
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
                        <button type="button" wire:click="$set('filterEcozId', '')"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                x-show="$wire.filterEcozId">
                            @icon('close', 'w-4 h-4')
                        </button>
                    </div>
                </div>

                <div class="form-group group">
                    <div class="relative">
                        <input wire:model="filterMedicalRecordId"
                               type="text"
                               name="filterMedicalRecordId"
                               id="filterMedicalRecordId"
                               class="input peer w-full"
                               placeholder=" "
                               autocomplete="off"
                        />
                        <label for="filterMedicalRecordId" class="label">
                            {{ __('patients.medical_record_id') }}
                        </label>
                        <button type="button" wire:click="$set('filterMedicalRecordId', '')"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                x-show="$wire.filterMedicalRecordId">
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
                        <span>{{ __('patients.search') }}</span>
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

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="diagnoses-search-filters">
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
                        <select wire:model="filterClinicalStatus"
                                name="filterClinicalStatus"
                                id="filterClinicalStatus"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="active">{{ __('patients.active_status') }}</option>
                        </select>
                        <label for="filterClinicalStatus" class="label">
                            {{ __('patients.status_clinical') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterSeverity"
                                name="filterSeverity"
                                id="filterSeverity"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="moderate">{{ __('patients.moderate_severity') }}</option>
                        </select>
                        <label for="filterSeverity" class="label">
                            {{ __('patients.condition') }}
                        </label>
                    </div>
                </div>

                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="filterStartedAtRange"
                                   type="text"
                                   name="filterStartedAtRange"
                                   id="filterStartedAtRange"
                                   class="datepicker-input with-leading-icon input peer w-full"
                                   placeholder=" "
                                   autocomplete="off"
                            />
                            <label for="filterStartedAtRange" class="wrapped-label">
                                {{ __('patients.start_date') }}
                            </label>
                        </div>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterVerificationStatus"
                                name="filterVerificationStatus"
                                id="filterVerificationStatus"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="final">{{ __('patients.final') }}</option>
                        </select>
                        <label for="filterVerificationStatus" class="label">
                            {{ __('patients.verification_status') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterBodyPart"
                                name="filterBodyPart"
                                id="filterBodyPart"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="head">{{ __('patients.head') }}</option>
                        </select>
                        <label for="filterBodyPart" class="label">
                            {{ __('patients.body_part') }}
                        </label>
                    </div>
                </div>

                <div class="form-row-3 mb-9">
                    <div class="form-group group">
                        <select wire:model="filterPerformer"
                                name="filterPerformer"
                                id="filterPerformer"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="1">Шевченко Т.Г.</option>
                        </select>
                        <label for="filterPerformer" class="label">
                            {{ __('patients.doctor') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterSource"
                                name="filterSource"
                                id="filterSource"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="patient">{{ __('patients.patient') }}</option>
                        </select>
                        <label for="filterSource" class="label">
                            {{ __('patients.source_label') }}
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

                        <div class="record-inner-column flex-1">
                            <div class="record-inner-label">{{ __('patients.code_and_name') }}</div>
                            <div class="record-inner-value text-[16px]">2A00.00 Гліобластома головного мозку</div>
                        </div>

                        <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
                            <div class="record-inner-label">{{ __('patients.status_clinical') }}</div>
                            <div>
                                <span class="badge-green">
                                    {{ __('patients.active_status') }}
                                </span>
                            </div>
                        </div>

                        <div class="record-inner-action-col">
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

                    <div class="record-inner-body">
                        <div class="record-inner-grid-container">
                            <div class="grid grid-cols-2 xl:grid-cols-4 gap-4">
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('forms.type') }}</div>
                                    <div class="record-inner-value text-[14px]">{{ __('patients.basic') }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('patients.doctor') }}</div>
                                    <div class="record-inner-value text-[14px] break-words">Шевченко Т.Г.</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('patients.verification_status') }}</div>
                                    <div
                                        class="record-inner-value text-[14px] uppercase">{{ __('patients.final') }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('patients.condition') }}</div>
                                    <div
                                        class="record-inner-value text-[14px] break-words">{{ __('patients.moderate_severity') }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('patients.body_part') }}</div>
                                    <div
                                        class="record-inner-value text-[14px] break-words">{{ __('patients.head') }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('patients.start_date') }}</div>
                                    <div class="record-inner-value text-[14px]">02.02.2025</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('patients.created') }}</div>
                                    <div class="record-inner-value text-[14px]">04.02.2026</div>
                                </div>
                            </div>

                            <!-- Evidence Section -->
                            <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700/50">
                                <div
                                    class="record-inner-label uppercase font-semibold text-gray-700 dark:text-gray-300 mb-2">{{ __('patients.evidence') }}</div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="min-w-0">
                                        <div
                                            class="text-[11px] text-gray-400 uppercase mb-1">{{ __('patients.conditions') }}</div>
                                        <div class="text-sm font-medium text-gray-800 dark:text-gray-200">- А01 - Кома
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div
                                            class="text-[11px] text-gray-400 uppercase mb-1">{{ __('patients.evidence_observations') }}</div>
                                        <div
                                            class="text-sm font-medium text-gray-800 dark:text-gray-200 break-words leading-relaxed whitespace-pre-line">
                                            - 1231-adsadas-aqeqe-casdda
                                            - 1231-adsadas-aqeqe-casdda
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-id-col">
                            <div class="min-w-0">
                                <div class="record-inner-label">ID ECO3</div>
                                <div class="record-inner-id-value">1231-adsadas-aqeqe-casdda</div>
                            </div>
                            <div class="min-w-0">
                                <div class="record-inner-label">{{ __('patients.medical_record_id') }}</div>
                                <div class="record-inner-id-value">1231-adsadas-aqeqe-casdda</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <x-forms.loading/>
</x-layouts.patient>
