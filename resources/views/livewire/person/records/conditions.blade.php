@php
    use App\Models\MedicalEvents\Sql\Encounter;
    use App\Enums\Person\ConditionClinicalStatus;
    use App\Enums\Person\ConditionVerificationStatus;
@endphp

<x-layouts.patient :personId="$personId" :prepersonId="$prepersonId" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', Encounter::class)
            <a
                href="{{
                    $prepersonId
                    ? route('prepersons.encounter.create', [legalEntity(), 'preperson' => $prepersonId])
                    : route('encounter.create', [legalEntity(), 'person' => $personId])
                }}"
                class="button-primary flex items-center gap-2 px-5 py-2 text-sm shadow-sm"
            >
                @icon('plus', 'w-4 h-4')
                {{ __('patients.start_interacting') }}
            </a>
        @endcan

        <button type="button" class="button-primary-outline px-5 py-2 text-sm whitespace-nowrap">
            {{ __('patients.data_access') }}
        </button>

        <button
            wire:click.prevent="sync"
            type="button"
            class="button-sync flex items-center gap-2 px-5 py-2 text-sm whitespace-nowrap shadow-sm"
        >
            @icon('refresh', 'w-4 h-4')
            {{ __('forms.synchronise_with_eHealth') }}
        </button>
    </x-slot>

    <div class="breadcrumb-form shift-content p-4">
        <div class="mt-6 w-full" x-data="{ showAdditionalParams: $wire.entangle('showAdditionalParams') }">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('conditions.search') }}</p>
            </div>

            <div
                class="form-row-3 mb-6"
                x-data="{
                    dictionary: '',
                    filterCode: $wire.entangle('filterCode'),
                    icd10Results: $wire.entangle('icd10Results'),
                    showIcd10Results: false,
                }"
            >
                <div class="form-group group">
                    <select
                        x-model="dictionary"
                        @change="filterCode = ''"
                        class="input-select peer mb-1 w-full text-sm"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        <option value="icd10">ICD-10-AM</option>
                        <option value="icpc2">ICPC-2</option>
                    </select>
                    <label class="label">{{ __('forms.type') }}</label>
                </div>

                <div class="form-group group" x-show="dictionary">
                    <div x-show="dictionary === 'icd10'" class="relative">
                        <input
                            type="text"
                            :value="filterCode"
                            @input.debounce.300ms="
                                filterCode = $event.target.value;
                                let value = $event.target.value;
                                let isEnglish = /^[a-zA-Z0-9.]+$/.test(value);
                                if ((isEnglish && value.length >= 1) || (! isEnglish && value.length >= 3)) {
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
                        <div
                            x-show="showIcd10Results && icd10Results.length > 0"
                            class="absolute top-full left-0 z-10 max-h-60 w-full overflow-auto rounded-lg border bg-white p-1.5 shadow-lg dark:bg-gray-800"
                        >
                            <template x-for="result in icd10Results" :key="result.code">
                                <div
                                    @click="
                                        filterCode = result.code;
                                        showIcd10Results = false;
                                    "
                                    class="cursor-pointer rounded-md px-2 py-1.5 text-sm hover:bg-gray-100 dark:text-white dark:hover:bg-gray-700"
                                    x-text="result.code + ' - ' + result.description"
                                ></div>
                            </template>
                        </div>
                    </div>
                    <div x-show="dictionary === 'icpc2'">
                        <x-select2
                            modelPath="filterCode"
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
                    <button
                        type="button"
                        wire:click="search"
                        class="button-primary flex items-center gap-2 px-5 py-2.5 text-sm shadow-sm"
                    >
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('forms.search') }}</span>
                    </button>
                    <button
                        type="button"
                        wire:click="resetFilters"
                        class="button-primary-outline-red px-5 py-2.5 text-sm"
                    >
                        {{ __('patients.reset_filters') }}
                    </button>
                    <button
                        type="button"
                        class="button-minor flex items-center gap-2 px-5 py-2.5 text-sm whitespace-nowrap"
                        @click.prevent="showAdditionalParams = ! showAdditionalParams"
                    >
                        @icon('adjustments', 'w-4 h-4 text-gray-500')
                        <span>{{ __('forms.additional_search_parameters') }}</span>
                    </button>
                </div>

                <div class="relative" x-data="{ openGroupActions: false }" @click.outside="openGroupActions = false">
                    <button
                        type="button"
                        @click="openGroupActions = ! openGroupActions"
                        class="button-primary-outline px-5 py-2.5 text-sm"
                    >
                        {{ __('patients.group_actions') }}
                    </button>

                    <div
                        x-show="openGroupActions"
                        x-transition
                        x-cloak
                        class="absolute top-full right-0 z-10 mt-2 w-60 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-600 dark:bg-gray-700"
                    >
                        <div class="py-1">
                            <button
                                type="button"
                                @click="openGroupActions = false"
                                class="dropdown-button !flex w-full items-center gap-2.5 px-4 py-2 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
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
                    <x-forms.combobox
                        :options="$encounters"
                        bind="filterEncounterId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('encounters.plural')"
                    />

                    <x-forms.combobox
                        :options="$episodes"
                        bind="filterEpisodeId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('episodes.plural')"
                    />

                    <div class="form-group group">
                        <div
                            class="datepicker-wrapper"
                            x-data="{
                                from: $wire.entangle('filterOnsetDateFrom'),
                                to: $wire.entangle('filterOnsetDateTo'),
                                rangeText: '',
                            }"
                            x-init="
                                if (from && to) rangeText = from + ' — ' + to;
                                $watch('from', (val) => {
                                    if (! val) {
                                        rangeText = '';
                                        const fp = $el.querySelector('input')._flatpickr;
                                        if (fp) fp.clear();
                                    }
                                });
                                $watch('to', (val) => {
                                    if (! val) {
                                        rangeText = '';
                                        const fp = $el.querySelector('input')._flatpickr;
                                        if (fp) fp.clear();
                                    }
                                });
                            "
                        >
                            <input
                                x-model="rangeText"
                                @change="
                                    const parts = $event.target.value.split(' — ');
                                    if (parts.length === 2) {
                                        from = parts[0];
                                        to = parts[1];
                                    } else if (! $event.target.value) {
                                        from = '';
                                        to = '';
                                    }
                                "
                                type="text"
                                class="daterangepicker-uk with-leading-icon input peer w-full"
                                placeholder=" "
                                autocomplete="off"
                            />

                            <label class="wrapped-label"> {{ __('conditions.filter_onset_date_range') }} </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @forelse ($this->paginatedConditions as $condition)
                    <div class="record-inner-card">
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <input type="checkbox" class="default-checkbox h-5 w-5" />
                            </div>

                            @php
                                $conditionCodeSystem = data_get($condition, 'code.coding.0.system');
                                $conditionCode = data_get($condition, 'code.coding.0.code');
                            @endphp
                            <div class="record-inner-column flex-1">
                                <div class="record-inner-label">{{ __('medical-events.code_and_name') }}</div>
                                <div class="record-inner-value text-[16px]">
                                    {{ $conditionCode }} - {{ $this->dictionaries[$conditionCodeSystem][$conditionCode] }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                                <div class="record-inner-label">{{ __('patients.status_clinical') }}</div>
                                <div>
                                    @php($status = ConditionClinicalStatus::from(data_get($condition, 'clinicalStatus')))
                                    <span @class([$status->color()])> {{ $status->label() ?? '-' }} </span>
                                </div>
                            </div>

                            <div class="record-inner-action-col">
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
                                    class="relative"
                                >
                                    <button
                                        @click="toggle()"
                                        x-ref="button"
                                        :aria-expanded="open"
                                        :aria-controls="$id('dropdown-button')"
                                        type="button"
                                        class="record-inner-action-btn cursor-pointer rounded-lg p-2 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                    >
                                        @icon('edit-user-outline', 'w-6 h-6 text-gray-700 dark:text-gray-300')
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
                                        <button
                                            @click="close($refs.button)"
                                            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                        >
                                            @icon('eye', 'w-5 h-5 text-gray-500')
                                            {{ __('patients.view_details') }}
                                        </button>

                                        <button
                                            @click="close($refs.button)"
                                            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                        >
                                            @icon('alert-circle', 'w-5 h-5 text-gray-500')
                                            {{ __('conditions.status.entered_in_error') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-body">
                            <div class="record-inner-grid-container">
                                <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('forms.type') }}</div>
                                        <div class="record-inner-value text-[14px]">
                                            {{ $this->dictionaryLabel($condition, 'reportOrigin') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('patients.doctor') }}</div>
                                        <div class="record-inner-value text-[14px] wrap-break-word">
                                            {{ data_get($condition, 'asserter.displayValue') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('patients.verification_status') }}</div>
                                        <div class="record-inner-value text-[14px] uppercase">
                                            @php($verificationStatus = ConditionVerificationStatus::from(data_get($condition, 'verificationStatus')))
                                            <span @class([$verificationStatus->color()])>
                                                {{ $verificationStatus->label() ?? '-' }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('conditions.label') }}</div>
                                        <div class="record-inner-value text-[14px] wrap-break-word">
                                            {{ $this->dictionaryLabel($condition, 'severity') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('patients.body_part') }}</div>
                                        <div class="record-inner-value text-[14px] wrap-break-word">
                                            @forelse (data_get($condition, 'bodySites', []) as $bodySite)
                                                <div>{{ $this->dictionaryLabel($bodySite, 'coding.0') }}</div>
                                            @empty
                                                -
                                            @endforelse
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('forms.start_date') }}</div>
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
                                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700/50">
                                    <div class="record-inner-label mb-2 font-semibold text-gray-700 uppercase dark:text-gray-300">
                                        {{ __('conditions.evidence') }}
                                    </div>
                                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <div class="min-w-0">
                                            <div class="mb-1 text-[11px] text-gray-400 uppercase">
                                                {{ __('conditions.plural') }}
                                            </div>
                                            <div class="text-sm font-medium text-gray-800 dark:text-gray-200">
                                                @forelse (data_get($condition, 'evidences', []) as $evidence)
                                                    @forelse (data_get($evidence, 'codes', []) as $code)
                                                        <p>
                                                            {{ data_get($code, 'coding.0.code', '—') }} - {{ $this->dictionaryLabel($code, 'coding.0') }}
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
                                            <div class="mb-1 text-[11px] text-gray-400 uppercase">
                                                {{ __('conditions.evidence_observations') }}
                                            </div>
                                            <div class="text-sm leading-relaxed font-medium wrap-break-word text-gray-800 dark:text-gray-200">
                                                @forelse (data_get($condition, 'evidences', []) as $evidence)
                                                    @forelse (data_get($evidence, 'details', []) as $detail)
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
                                    <div class="record-inner-id-value">{{ data_get($condition, 'uuid') }}</div>
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

            <div class="mt-8">{{ $this->paginatedConditions->links() }}</div>
        </div>
    </div>

    <x-forms.loading />
</x-layouts.patient>
