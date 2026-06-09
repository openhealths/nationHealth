@php
    use App\Models\MedicalEvents\Sql\Encounter;
    use App\Enums\Person\ConditionClinicalStatus;
    use App\Enums\Person\ConditionVerificationStatus;
@endphp

<x-layouts.patient :personId="$personId" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', Encounter::class)
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

        <button wire:click.prevent="sync"
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
                <p>{{ __('patients.condition_search') }}</p>
            </div>

            <div class="form-row-3 mb-6"
                 x-data="{
                     dictionary: '',
                     filterCode: $wire.entangle('filterCode'),
                     icd10Results: $wire.entangle('icd10Results'),
                     showIcd10Results: false
                 }"
            >
                <div class="form-group group">
                    <select x-model="dictionary"
                            @change="filterCode = ''"
                            class="input-select peer w-full mb-1 text-sm"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        <option value="icd10">ICD-10-AM</option>
                        <option value="icpc2">ICPC-2</option>
                    </select>
                    <label class="label">{{ __('forms.type') }}</label>
                </div>

                <div class="form-group group" x-show="dictionary">
                    <div x-show="dictionary === 'icd10'" class="relative">
                        <input type="text"
                               :value="filterCode"
                               @input.debounce.300ms="
                                   filterCode = $event.target.value;
                                   let value = $event.target.value;
                                   let isEnglish = /^[a-zA-Z0-9.]+$/.test(value);
                                   if ((isEnglish && value.length >= 1) || (!isEnglish && value.length >= 3)) {
                                       $wire.searchICD10(value);
                                       showIcd10Results = true;
                                   }
                               "
                               @click.away="showIcd10Results = false"
                               id="filterCodeIcd10"
                               class="input-select peer w-full"
                               placeholder="{{ __('forms.type_to_search') }}"
                               autocomplete="off"
                        />
                        <div x-show="showIcd10Results && icd10Results.length > 0"
                             class="absolute left-0 top-full z-10 max-h-60 w-full overflow-auto rounded-lg border bg-white dark:bg-gray-800 p-1.5 shadow-lg"
                        >
                            <template x-for="result in icd10Results" :key="result.code">
                                <div @click="filterCode = result.code; showIcd10Results = false"
                                     class="cursor-pointer rounded-md px-2 py-1.5 text-sm dark:text-white hover:bg-gray-100 dark:hover:bg-gray-700"
                                     x-text="result.code + ' - ' + result.description"
                                ></div>
                            </template>
                        </div>
                    </div>
                    <div x-show="dictionary === 'icpc2'">
                        <x-select2 modelPath="filterCode"
                                   dictionaryName="eHealth/ICPC2/condition_codes"
                                   id="filterCodeIcpc2"
                                   class="input-select peer w-full"
                        />
                    </div>
                    <label class="label">{{ __('forms.code') }}</label>
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
                         class="absolute right-0 top-full mt-2 z-10 w-60 bg-white rounded-lg shadow-lg border border-gray-200 dark:bg-gray-700 dark:border-gray-600 overflow-hidden"
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

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="condition-search-filters">
                <div class="form-row-3 mb-6">
                    <x-forms.combobox :options="$encounters"
                                      bind="filterEncounterId"
                                      bindValue="uuid"
                                      bindParam="uuid"
                                      :label="__('patients.encounters')"
                    />

                    <x-forms.combobox :options="$episodes"
                                      bind="filterEpisodeId"
                                      bindValue="uuid"
                                      bindParam="name"
                                      :label="__('patients.episodes')"
                    />

                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="filterOnsetDateFrom"
                                   type="text"
                                   name="filterOnsetDateFrom"
                                   id="filterOnsetDateFrom"
                                   class="datepicker-input with-leading-icon input peer w-full"
                                   placeholder=" "
                                   autocomplete="off"
                            />

                            <label for="filterOnsetDateFrom" class="wrapped-label">
                                {{ __('patients.start_date') }}
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-row-3 mb-9">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="filterOnsetDateTo"
                                   type="text"
                                   name="filterOnsetDateTo"
                                   id="filterOnsetDateTo"
                                   class="datepicker-input with-leading-icon input peer w-full"
                                   placeholder=" "
                                   autocomplete="off"
                            />

                            <label for="filterOnsetDateTo" class="wrapped-label">
                                {{ __('patients.end_date') }}
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @forelse($this->paginatedConditions as $condition)
                    <div class="record-inner-card">
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <input type="checkbox" class="default-checkbox w-5 h-5">
                            </div>

                            @php
                                $conditionCodeSystem = data_get($condition, 'code.coding.0.system');
                                $conditionCode = data_get($condition, 'code.coding.0.code');
                            @endphp
                            <div class="record-inner-column flex-1">
                                <div class="record-inner-label">{{ __('patients.code_and_name') }}</div>
                                <div class="record-inner-value text-[16px]">
                                    {{ $conditionCode }}
                                    - {{ $this->dictionaries[$conditionCodeSystem][$conditionCode] }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
                                <div class="record-inner-label">{{ __('patients.status_clinical') }}</div>
                                <div>
                                    <span class="badge-green">
                                        {{ ConditionClinicalStatus::from(data_get($condition, 'clinicalStatus'))->label() }}
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
                                        <div class="record-inner-value text-[14px]">
                                            {{ $this->dictionaryLabel($condition, 'reportOrigin') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('patients.doctor') }}</div>
                                        <div class="record-inner-value text-[14px] wrap-break-word">
                                            {{ data_get($condition, 'asserter.displayValue') ?? '-'}}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('patients.verification_status') }}</div>
                                        <div class="record-inner-value text-[14px] uppercase">
                                            {{ ConditionVerificationStatus::from(data_get($condition, 'verificationStatus'))->label() }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('patients.condition') }}</div>
                                        <div class="record-inner-value text-[14px] wrap-break-word">
                                            {{ $this->dictionaryLabel($condition, 'severity') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('patients.body_part') }}</div>
                                        <div class="record-inner-value text-[14px] wrap-break-word">
                                            @forelse(data_get($condition, 'bodySites', []) as $bodySite)
                                                <div>{{ $this->dictionaryLabel($bodySite, 'coding.0') }}</div>
                                            @empty
                                                -
                                            @endforelse
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('patients.start_date') }}</div>
                                        <div class="record-inner-value text-[14px]">
                                            {{ data_get($condition, 'onsetDate') }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('patients.created') }}</div>
                                        <div class="record-inner-value text-[14px]">
                                            {{ data_get($condition, 'assertedDate') ?? '-' }}
                                        </div>
                                    </div>
                                </div>

                                <!-- Evidence Section -->
                                <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700/50">
                                    <div
                                        class="record-inner-label uppercase font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                        {{ __('patients.evidence') }}
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div class="min-w-0">
                                            <div class="text-[11px] text-gray-400 uppercase mb-1">
                                                {{ __('patients.conditions') }}
                                            </div>
                                            <div class="text-sm font-medium text-gray-800 dark:text-gray-200">
                                                @forelse(data_get($condition, 'evidences', []) as $evidence)
                                                    @forelse(data_get($evidence, 'codes', []) as $code)
                                                        <p>
                                                            {{ data_get($code, 'coding.0.code', '—') }}
                                                            - {{ $this->dictionaryLabel($code, 'coding.0') }}
                                                        </p>
                                                    @empty
                                                        <p>—</p>
                                                    @endforelse
                                                @empty
                                                    <p>—</p>
                                                @endforelse
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="text-[11px] text-gray-400 uppercase mb-1">
                                                {{ __('patients.evidence_observations') }}
                                            </div>
                                            <div
                                                class="text-sm font-medium text-gray-800 dark:text-gray-200 wrap-break-word leading-relaxed">
                                                @forelse(data_get($condition, 'evidences', []) as $evidence)
                                                    @forelse(data_get($evidence, 'details', []) as $detail)
                                                        <p>
                                                            {{ data_get($detail, 'displayValue') ?: data_get($detail, 'identifier.value', '-') }}
                                                        </p>
                                                    @empty
                                                        <p>—</p>
                                                    @endforelse
                                                @empty
                                                    <p>—</p>
                                                @endforelse
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="record-inner-id-col">
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('patients.ehealth_id') }}</div>
                                    <div class="record-inner-id-value">
                                        {{ data_get($condition, 'uuid') }}
                                    </div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('patients.medical_record_id') }}</div>
                                    <div class="record-inner-id-value">
                                        {{ data_get($condition, 'context.identifier.value') ?? '-' }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <x-nothing-found :description="null" />
                @endforelse
            </div>
            <div class="mt-8">
                {{ $this->paginatedConditions->links() }}
            </div>
        </div>
    </div>

    <x-forms.loading />
</x-layouts.patient>
