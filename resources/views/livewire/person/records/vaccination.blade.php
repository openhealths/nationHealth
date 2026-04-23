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

        <button wire:click.prevent="syncVaccinations"
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
                <p>{{ __('patients.vaccination_search') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <select wire:model="filterVaccine"
                            name="filterVaccine"
                            id="filterVaccine"
                            class="input-select peer w-full"
                    >
                        <option value="">{{ __('forms.select') }} ...</option>
                        <option value="SarsCov2_Pr">SarsCov2_Pr</option>
                    </select>
                    <label for="filterVaccine" class="label">
                        {{ __('patients.vaccine') }}
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
                        <button type="button" wire:click="$set('filterMedicalRecordId', '')" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600" x-show="$wire.filterMedicalRecordId">
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

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="vaccination-search-filters">
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
                        <div class="datepicker-wrapper">
                            <input wire:model="filterEnteredAtRange"
                                   type="text"
                                   name="filterEnteredAtRange"
                                   id="filterEnteredAtRange"
                                   class="datepicker-input with-leading-icon input peer w-full"
                                   placeholder=" "
                                   autocomplete="off"
                            />
                            <label for="filterEnteredAtRange" class="wrapped-label">
                                {{ __('patients.date_entered') }}
                            </label>
                        </div>
                    </div>

                    <div class="form-group group">
                        <div class="relative">
                            <select wire:model="filterPerformer"
                                    name="filterPerformer"
                                    id="filterPerformer"
                                    class="input-select peer w-full"
                            >
                                <option value="">{{ __('forms.select') }} ...</option>
                                <option value="1">Шевченко Т.Г.</option>
                            </select>
                            <label for="filterPerformer" class="label">
                                {{ __('patients.performer') }}
                            </label>
                            <button type="button" wire:click="$set('filterPerformer', '')" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600" x-show="$wire.filterPerformer">
                                @icon('close', 'w-4 h-4')
                            </button>
                        </div>
                    </div>
                </div>

                <div class="form-row-3 mb-6">
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

                    <div class="form-group group">
                        <select wire:model="filterStatus"
                                name="filterStatus"
                                id="filterStatus"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="done">{{ __('patients.status_done') }}</option>
                        </select>
                        <label for="filterStatus" class="label">
                            {{ __('forms.status.label') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterDosage"
                                name="filterDosage"
                                id="filterDosage"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="3ml">3 ML</option>
                        </select>
                        <label for="filterDosage" class="label">
                            {{ __('patients.dosage') }}
                        </label>
                    </div>
                </div>

                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <select wire:model="filterManufacturer"
                                name="filterManufacturer"
                                id="filterManufacturer"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="denmark">Данія (55998)</option>
                        </select>
                        <label for="filterManufacturer" class="label">
                            {{ __('patients.manufacturer_and_batch') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterReason"
                                name="filterReason"
                                id="filterReason"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="calendar">Згідно календаря щеплень</option>
                        </select>
                        <label for="filterReason" class="label">
                            {{ __('patients.reason') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterBodyPart"
                                name="filterBodyPart"
                                id="filterBodyPart"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="left_arm">Ліва рука</option>
                        </select>
                        <label for="filterBodyPart" class="label">
                            {{ __('patients.body_part') }}
                        </label>
                    </div>
                </div>

                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <select wire:model="filterWasPerformed"
                                name="filterWasPerformed"
                                id="filterWasPerformed"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="yes">Так</option>
                        </select>
                        <label for="filterWasPerformed" class="label">
                            {{ __('patients.was_performed') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterTargetDisease"
                                name="filterTargetDisease"
                                id="filterTargetDisease"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="1">Кір</option>
                        </select>
                        <label for="filterTargetDisease" class="label">
                            {{ __('patients.target_diseases') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterProtocolAuthor"
                                name="filterProtocolAuthor"
                                id="filterProtocolAuthor"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="1">Міністерство Охорони Здоров'я</option>
                        </select>
                        <label for="filterProtocolAuthor" class="label">
                            {{ __('patients.protocol_author') }}
                        </label>
                    </div>
                </div>

                <div class="form-row-3 mb-9">
                    <div class="form-group group">
                        <select wire:model="filterDoseSequence"
                                name="filterDoseSequence"
                                id="filterDoseSequence"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="1">1</option>
                        </select>
                        <label for="filterDoseSequence" class="label">
                            {{ __('patients.dose_sequence') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterDoseCount"
                                name="filterDoseCount"
                                id="filterDoseCount"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="1">1</option>
                        </select>
                        <label for="filterDoseCount" class="label">
                            {{ __('patients.series_of_doses_by_protocol') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterImmunizationStage"
                                name="filterImmunizationStage"
                                id="filterImmunizationStage"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="1">1</option>
                        </select>
                        <label for="filterImmunizationStage" class="label">
                            {{ __('patients.immunization_series') }}
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
                            <div class="record-inner-label">{{ __('patients.vaccine') }}</div>
                            <div class="record-inner-value text-[17px] font-semibold text-gray-900 dark:text-gray-100">SarsCov2_Pr</div>
                        </div>

                        <div class="record-inner-column-bordered w-full md:w-[180px] shrink-0">
                            <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                            <div>
                                <span class="badge-green">
                                    {{ __('patients.status_done') }}
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

                    <div class="record-inner-body grid grid-cols-1 xl:grid-cols-[1.5fr_minmax(340px,1fr)_160px] divide-y xl:divide-y-0 xl:divide-x divide-gray-200 dark:divide-gray-700 !p-0">
                        <div class="p-3.5 pl-4 overflow-hidden">
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3">
                                <div class="space-y-2.5 min-w-0">
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px]">{{ __('patients.dosage') }}</div>
                                        <div class="record-inner-value text-[14px] font-semibold">3 ML</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px]">{{ __('patients.manufacturer_and_batch') }}</div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">Данія (55998)</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px]">{{ __('patients.performer') }}</div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">Шевченко Т.Г.</div>
                                    </div>
                                </div>

                                <div class="space-y-2.5 min-w-0">
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px]">{{ __('patients.route') }}</div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">Внутрішньом'язево</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px]">{{ __('patients.body_part') }}</div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">Праве плече</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px]">{{ __('patients.date_time_entered') }}</div>
                                        <div class="record-inner-value text-[14px] font-semibold">12:00 03.04.2025</div>
                                    </div>
                                </div>

                                <div class="space-y-2.5 min-w-0">
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px]">{{ __('patients.reason') }}</div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">Згідно календаря щеплень</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px]">{{ __('patients.was_performed') }}</div>
                                        <div class="record-inner-value text-[14px] font-semibold">Так</div>
                                    </div>
                                </div>

                                <div class="space-y-2.5 min-w-0">
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px]">{{ __('patients.reactions') }}</div>
                                        <div class="record-inner-value text-[14px]">-</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px]">{{ __('patients.date_time_performed') }}</div>
                                        <div class="record-inner-value text-[14px] font-semibold">10:00 02.04.2025</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="p-3.5 bg-gray-50/5 dark:bg-gray-800/20">
                            <div class="record-inner-label font-bold text-gray-900 dark:text-gray-100 mb-2">{{ __('patients.vaccination_protocol') }}:</div>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-x-4 gap-y-3">
                                <ul class="space-y-2.5">
                                    <li class="flex items-start gap-1.5 text-[13px] leading-tight">
                                        <span class="w-1 h-1 rounded-full bg-gray-400 mt-1.5 shrink-0"></span>
                                        <div class="min-w-0">
                                            <div class="text-gray-500 dark:text-gray-400 text-[10px] mb-0">{{ __('patients.target_diseases') }}:</div>
                                            <div class="text-gray-800 dark:text-gray-200 font-semibold break-words">Кір, краснуха</div>
                                        </div>
                                    </li>
                                    <li class="flex items-start gap-1.5 text-[13px] leading-tight">
                                        <span class="w-1 h-1 rounded-full bg-gray-400 mt-1.5 shrink-0"></span>
                                        <div class="min-w-0">
                                            <div class="text-gray-500 dark:text-gray-400 text-[10px] mb-0">{{ __('patients.protocol_author') }}:</div>
                                            <div class="text-gray-800 dark:text-gray-200 font-semibold uppercase tracking-wide text-[11px] break-words">МОЗ України</div>
                                        </div>
                                    </li>
                                </ul>
                                <ul class="space-y-2.5">
                                    <li class="flex items-start gap-1.5 text-[13px] leading-tight">
                                        <span class="w-1 h-1 rounded-full bg-gray-400 mt-1.5 shrink-0"></span>
                                        <div class="min-w-0">
                                            <div class="text-gray-500 dark:text-gray-400 text-[10px] mb-0">{{ __('patients.dose_sequence') }}:</div>
                                            <div class="text-gray-800 dark:text-gray-200 font-semibold">1</div>
                                        </div>
                                    </li>
                                    <li class="flex items-start gap-1.5 text-[13px] leading-tight">
                                        <span class="w-1 h-1 rounded-full bg-gray-400 mt-1.5 shrink-0"></span>
                                        <div class="min-w-0">
                                            <div class="text-gray-500 dark:text-gray-400 text-[10px] mb-0">{{ __('patients.immunization_series') }}:</div>
                                            <div class="text-gray-800 dark:text-gray-200 font-semibold">1</div>
                                        </div>
                                    </li>
                                </ul>
                                <ul class="space-y-2.5">
                                    <li class="flex items-start gap-1.5 text-[13px] leading-tight">
                                        <span class="w-1 h-1 rounded-full bg-gray-400 mt-1.5 shrink-0"></span>
                                        <div class="min-w-0">
                                            <div class="text-gray-500 dark:text-gray-400 text-[10px] mb-0">{{ __('patients.series_of_doses_by_protocol') }}:</div>
                                            <div class="text-gray-800 dark:text-gray-200 font-semibold">1</div>
                                        </div>
                                    </li>
                                    <li class="flex items-start gap-1.5 text-[13px] leading-tight">
                                        <span class="w-1 h-1 rounded-full bg-gray-400 mt-1.5 shrink-0"></span>
                                        <div class="min-w-0">
                                            <div class="text-gray-500 dark:text-gray-400 text-[10px] mb-0">{{ __('patients.protocol_description') }}:</div>
                                            <div class="text-gray-800 dark:text-gray-200 font-semibold break-words">Опис</div>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <div class="p-3.5 px-4 overflow-hidden">
                            <div class="space-y-3.5">
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px]">ID ECO3</div>
                                    <div class="record-inner-id-value text-[13px] break-all whitespace-normal">1231-adsadas-aqeqe-casdda</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px]">{{ __('patients.medical_record_id') }}</div>
                                    <div class="record-inner-id-value text-[13px] break-all whitespace-normal">1231-adsadas-aqeqe-casdda</div>
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
