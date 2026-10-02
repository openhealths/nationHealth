@use(App\Enums\Person\ObservationStatus)
@use(App\Models\MedicalEvents\Sql\Encounter)

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
                {{ __('encounters.start') }}
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
                <p>{{ __('observations.plural') }}</p>
            </div>

            <div
                class="form-row-3 mb-6"
                x-data="{
                    dictionary: '',
                    filterCode: $wire.entangle('filterCode'),
                }"
            >
                <div class="form-group group">
                    <select
                        x-model="dictionary"
                        @change="filterCode = ''"
                        name="codeSystem"
                        id="codeSystem"
                        class="input-select peer mb-1 w-full text-sm"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        <option value="loinc">{{ __('observations.loinc') }}</option>
                        <option value="custom">{{ __('observations.loinc_custom') }}</option>
                        <option value="icf">{{ __('observations.icf') }}</option>
                    </select>
                    <label for="codeSystem" class="label">{{ __('observations.code_system') }}</label>
                </div>

                <div class="form-group group" x-show="dictionary">
                    <div x-show="dictionary === 'loinc'">
                        <x-select2
                            modelPath="filterCode"
                            dictionaryName="eHealth/LOINC/observation_codes"
                            id="filterCodeLoinc"
                            class="input-select peer w-full"
                        />
                    </div>

                    <div x-show="dictionary === 'custom'">
                        <x-select2
                            modelPath="filterCode"
                            dictionaryName="eHealth/custom/observation_codes"
                            id="filterCodeCustom"
                            class="input-select peer w-full"
                        />
                    </div>

                    <div x-show="dictionary === 'icf'" x-data="{ modalObservation: { categoryCode: '' } }">
                        <x-select2
                            modelPath="filterCode"
                            dictionaryName="eHealth/ICF/classifiers"
                            id="filterCodeIcf"
                            class="input-select peer w-full"
                        />
                    </div>

                    <label
                        :for="{ loinc: 'filterCodeLoinc', custom: 'filterCodeCustom', icf: 'filterCodeIcf' }[
                            dictionary
                        ]"
                        class="label"
                    >{{ __('observations.code') }}</label>
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
                        {{ __('forms.reset_all_filters') }}
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
                        {{ __('forms.group_actions') }}
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

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="observations-search-filters">
                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <div
                            class="datepicker-wrapper"
                            x-data="{
                                from: $wire.entangle('filterIssuedFrom'),
                                to: $wire.entangle('filterIssuedTo'),
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

                            <label class="wrapped-label"> {{ __('forms.filter_date_range') }} </label>
                        </div>
                    </div>

                    <x-forms.combobox
                        :options="$episodes"
                        bind="filterEpisodeId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('episodes.plural')"
                    />
                </div>

                <div class="form-row-3 mb-6">
                    <x-forms.combobox
                        :options="$encounters"
                        bind="filterEncounterId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('encounters.label')"
                    />

                    <x-forms.combobox
                        :options="$diagnosticReports"
                        bind="filterDiagnosticReportId"
                        bindValue="uuid"
                        bindParam="displayValue"
                        :label="__('diagnostic-reports.label')"
                    />

                    <x-forms.combobox
                        :options="$devices"
                        bind="filterDeviceId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('devices.plural')"
                    />
                </div>

                <div class="form-row-3 mb-9">
                    <x-forms.combobox
                        :options="$specimens"
                        bind="filterSpecimenId"
                        bindValue="uuid"
                        bindParam="uuid"
                        :label="__('observations.specimen_id')"
                    />
                </div>
            </div>

            <div class="space-y-4">
                @forelse ($this->paginatedObservations as $observation)
                    <div class="record-inner-card" wire:key="observation-{{ data_get($observation, 'uuid') }}">
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <input type="checkbox" class="default-checkbox h-5 w-5" />
                            </div>

                            <div class="record-inner-column flex-1 !pl-4">
                                <div class="record-inner-label">{{ __('observations.category_and_code') }}</div>
                                <div class="record-inner-value text-[17px] font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $this->dictionaryLabel($observation, 'categories.0') }} | {{ $this->dictionaryLabel($observation, 'code') }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                                <div>
                                    @php($status = ObservationStatus::from(data_get($observation, 'status')))
                                    <span @class([$status->color()])> {{ $status->label() }} </span>
                                </div>
                            </div>

                            <div class="record-inner-action-col">
                                <div class="relative flex justify-center">
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
                                            class="record-inner-action-btn cursor-pointer"
                                        >
                                            @icon('edit-user-outline', 'w-5 h-5')
                                        </button>

                                        <div
                                            x-show="open"
                                            x-cloak
                                            x-ref="panel"
                                            x-transition.origin.top.right
                                            @click.outside="close($refs.button)"
                                            :id="$id('dropdown-button')"
                                            class="absolute right-0 z-50 mt-2 w-56 rounded-md border border-gray-200 bg-white py-1 shadow-md dark:border-gray-600 dark:bg-gray-700"
                                        >
                                            <a
                                                href="{{ $prepersonId ? route('prepersons.observations.view', [legalEntity(), 'preperson' => $prepersonId, 'observationId' => data_get($observation, 'id') ?? 999999]) : route('persons.observations.view', [legalEntity(), 'person' => $personId, 'observationId' => data_get($observation, 'id') ?? 999999]) }}"
                                                class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                            >
                                                @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                {{ __('forms.view_details') }}
                                            </a>

                                            @if ($status !== ObservationStatus::ENTERED_IN_ERROR)
                                                <button
                                                    wire:click="openRecordCancellation('observations', '{{ data_get($observation, 'uuid') }}')"
                                                    @click="close($refs.button)"
                                                    class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                >
                                                    @icon('alert-circle', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                    {{ __('medical-events.mark_as_error') }}
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-body">
                            <div class="record-inner-grid-container">
                                <div class="flex flex-col gap-4">
                                    <div class="grid grid-cols-2 gap-2 overflow-hidden lg:grid-cols-5 xl:gap-4">
                                        <div>
                                            <div class="record-inner-label">
                                                {{ __('observations.primary_source') }}
                                            </div>
                                            <div class="record-inner-value">
                                                {{ data_get($observation, 'primarySource') ? 'Пацієнт' : '-' }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="record-inner-label">{{ __('observations.method') }}</div>
                                            <div class="record-inner-value">
                                                {{ $this->dictionaryLabel($observation, 'method') }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="record-inner-label">{{ __('observations.value') }}</div>
                                            <div class="record-inner-value">
                                                {{ $this->displayObservationValue($observation) }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="record-inner-label">Отримання показників</div>
                                            <div class="record-inner-value">
                                                @if (data_get($observation, 'effectivePeriod.start'))
                                                    {{ data_get($observation, 'effectivePeriod.start') }}
                                                    &ndash;
                                                    {{ data_get($observation, 'effectivePeriod.end') ?? '-' }}
                                                @else
                                                    {{ data_get($observation, 'effectiveDateTime') ?? '-' }}
                                                @endif
                                            </div>
                                        </div>
                                        <div>
                                            <div class="record-inner-label">{{ __('observations.updated_at') }}</div>
                                            <div class="record-inner-value">
                                                {{ data_get($observation, 'ehealthUpdatedAt') ?? '-' }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 gap-2 overflow-hidden lg:grid-cols-5 xl:gap-4">
                                        <div>
                                            <div class="record-inner-label">
                                                {{ __('observations.interpretation') }}
                                            </div>
                                            <div class="record-inner-value">
                                                {{ $this->dictionaryLabel($observation, 'interpretation') }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="record-inner-label">{{ __('observations.body_site') }}</div>
                                            <div class="record-inner-value">
                                                {{ $this->dictionaryLabel($observation, 'bodySite') }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="record-inner-label">{{ __('observations.doctor') }}</div>
                                            <div class="record-inner-value">
                                                {{ data_get($observation, 'performer.displayValue') ?? '-' }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="record-inner-label">{{ __('observations.inserted_at') }}</div>
                                            <div class="record-inner-value">
                                                {{ data_get($observation, 'ehealthInsertedAt') ?? '-' }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="record-inner-id-col">
                                <div class="mb-3 min-w-0">
                                    <div class="record-inner-label">{{ __('forms.ehealth_id') }}</div>
                                    <div class="record-inner-id-value">{{ data_get($observation, 'uuid') ?? '-' }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('medical-events.medical_record_id') }}</div>
                                    <div class="record-inner-id-value">
                                        {{ data_get($observation, 'context.identifier.value') ?? '-' }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <x-nothing-found :description="null" />
                @endforelse
            </div>

            <div class="mt-8">{{ $this->paginatedObservations->links() }}</div>
        </div>
    </div>

    @include('livewire.encounter.encounter-cancellation')

    <x-forms.loading />
</x-layouts.patient>
