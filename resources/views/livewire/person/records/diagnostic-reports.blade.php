@use(App\Enums\JobStatus)
@use(App\Enums\Person\DiagnosticReportStatus)
@use(App\Models\MedicalEvents\Sql\DiagnosticReport)

<x-layouts.patient :personId="$personId" :prepersonId="$prepersonId" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', DiagnosticReport::class)
            <a
                href="{{
                    $prepersonId
                    ? route('prepersons.diagnostic-report.create', [legalEntity(), 'preperson' => $prepersonId])
                    : route('diagnostic-report.create', [legalEntity(), 'person' => $personId])
                }}"
                class="button-primary flex items-center gap-2 px-5 py-2 text-sm shadow-sm"
            >
                @icon('plus', 'w-4 h-4')
                {{ __('patients.starts_interacting') }}
            </a>
        @endcan

        <button type="button" class="button-primary-outline px-5 py-2 text-sm whitespace-nowrap">
            {{ __('patients.data_access') }}
        </button>

        @php
            $isSyncing = $syncStatus === JobStatus::PROCESSING->value;
            $isRetryable = $syncStatus === JobStatus::PAUSED->value || $syncStatus === JobStatus::FAILED->value;
        @endphp
        <button
            @if (!$isSyncing) wire:click="sync" @endif
            type="button"
            @if ($isSyncing) disabled @endif
            class="flex items-center gap-2 px-5 py-2 text-sm whitespace-nowrap shadow-sm transition-colors
                @if ($isSyncing) button-sync-disabled cursor-not-allowed @else button-sync @endif"
        >
            @icon('refresh', 'w-4 h-4')
            <span>{{ $isRetryable ? __('forms.sync_retry') : __('forms.synchronise_with_eHealth') }}</span>
        </button>
    </x-slot>

    <div class="breadcrumb-form shift-content p-4">
        <div class="mt-6 w-full" x-data="{ showAdditionalParams: $wire.entangle('showAdditionalParams') }">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('diagnostic-reports.search') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <select
                        wire:model.live="filterCategory"
                        id="filterCategory"
                        name="filterCategory"
                        class="input-select peer w-full"
                    >
                        <option value="">{{ __('forms.select') }} {{ mb_strtolower(__('forms.category')) }}</option>

                        @foreach ($this->dictionaries['eHealth/diagnostic_report_categories'] as $key => $category)
                            <option value="{{ $key }}">{{ $category }}</option>
                        @endforeach
                    </select>

                    <label for="filterCategory" class="label pointer-events-none"> {{ __('forms.category') }} </label>
                </div>

                <x-forms.combobox
                    wire:key="filter-code-{{ $filterCategory }}"
                    :options="$services"
                    bind="filterCode"
                    bindValue="id"
                    bindParam="name"
                    :label="__('forms.services')"
                />
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

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="diagnostic-reports-search-filters">
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

                            <label class="wrapped-label"> {{ __('patients.filter_date_range') }} </label>
                        </div>
                    </div>

                    <x-forms.combobox
                        :options="$specimens"
                        bind="filterSpecimenId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('patients.specimen_id')"
                    />

                    <x-forms.combobox
                        :options="$basedOnRequests"
                        bind="filterBasedOn"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('patients.based_on')"
                    />
                </div>

                {{-- TODO: Фільтр по context_episode_id та origin_episode_id реалізований, але наразі у Diagnostic Report ці поля приходить null.
                    Коли в ЕСОЗ/тестових даних з’являться записи з contextEpisode або origin_episode_id, потрібно перевірити,
                    чи коректно API фільтрує Diagnostic Reports за цим параметром.
                --}}
                <div class="form-row-3 mb-9">
                    <x-forms.combobox
                        :options="$episodes"
                        bind="filterContextEpisodeId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('episodes.context_id')"
                    />

                    <x-forms.combobox
                        :options="$episodes"
                        bind="filterOriginEpisodeId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('episodes.origin_id')"
                    />

                    <x-forms.combobox
                        :options="$encounters"
                        bind="filterEncounterId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('patients.encounter_id')"
                    />
                </div>
            </div>

            <div class="space-y-4">
                @forelse ($this->paginatedDiagnosticReports as $diagnosticReport)
                    <div class="record-inner-card">
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <input type="checkbox" class="default-checkbox h-5 w-5" />
                            </div>

                            <div class="record-inner-column flex-1">
                                <div class="record-inner-label">{{ __('medical-events.code_and_name') }}</div>
                                <div class="record-inner-value text-[16px]">
                                    {{
                                        data_get($diagnosticReport, 'code.identifier.value') && data_get($diagnosticReport, 'code.displayValue')
                                        ? data_get($diagnosticReport, 'code.identifier.value') . ' | ' . data_get($diagnosticReport, 'code.displayValue')
                                        : '-'
                                    }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                                <div>
                                    @php
                                        $status = DiagnosticReportStatus::from(data_get($diagnosticReport, 'status'));
                                    @endphp
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
                                        class="absolute right-0 z-50 mt-2 w-56 rounded-md border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-600 dark:bg-gray-700"
                                    >
                                        <button
                                            type="button"
                                            @click="close($refs.button)"
                                            wire:click="openDiagnosticReportView('{{ data_get($diagnosticReport, 'uuid') }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="openDiagnosticReportView"
                                            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                        >
                                            @icon('eye', 'w-5 h-5 text-gray-500')
                                            {{ __('patients.view_details') }}
                                        </button>

                                        <button
                                            type="button"
                                            @click="close($refs.button)"
                                            wire:click="openDiagnosticReportCancellation('{{ data_get($diagnosticReport, 'uuid') }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="openDiagnosticReportCancellation"
                                            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                        >
                                            @icon('alert-circle', 'w-5 h-5 text-gray-500')
                                            {{ __('diagnostic-reports.status.entered_in_error') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-body">
                            <div class="record-inner-grid-container">
                                <div class="grid grid-cols-2 gap-x-4 gap-y-3 md:grid-cols-3">
                                    <div class="min-w-0 space-y-2.5">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('forms.category') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold wrap-break-word">
                                                {{
                                                    data_get(
                                                        $this->dictionaries,
                                                        'eHealth/diagnostic_report_categories.' . data_get($diagnosticReport, 'category.0.coding.0.code'),
                                                        data_get($diagnosticReport, 'category.0.text', data_get($diagnosticReport, 'category.0.coding.0.code', '—'))
                                                    )
                                                }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('patients.referrals') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold wrap-break-word">
                                                {{ data_get($diagnosticReport, 'paperReferral.requisition', '—') }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="min-w-0 space-y-2.5">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('medical-events.performer') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold wrap-break-word uppercase">
                                                {{ data_get($diagnosticReport, 'performer.reference.displayValue' ,'-') }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('medical-events.conclusion') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold wrap-break-word">
                                                {{ data_get($diagnosticReport, 'conclusion') ?? '-' }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="min-w-0 space-y-2.5">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('patients.created') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold wrap-break-word">
                                                {{ optional(\Carbon\Carbon::make(data_get($diagnosticReport, 'ehealthInsertedAt')))->format('d.m.Y H:i') ?? '-' }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('patients.doctor') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold wrap-break-word">
                                                {{ data_get($diagnosticReport, 'recordedBy.displayValue') ?? '-' }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="record-inner-id-col">
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">
                                        {{ __('patients.ehealth_id') }}
                                    </div>
                                    <div class="record-inner-id-value">
                                        {{ data_get($diagnosticReport, 'uuid') ?? '-' }}
                                    </div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">
                                        {{ __('patients.medical_record_id') }}
                                    </div>
                                    <div class="record-inner-id-value">
                                        {{ data_get($diagnosticReport, 'encounter.identifier.value') ?? '-' }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <x-nothing-found :description="null" />
                @endforelse
            </div>
            <div class="mt-8">{{ $this->paginatedDiagnosticReports->links() }}</div>
        </div>
    </div>
    @include('livewire.diagnostic-report.diagnostic-report-cancellation')
    <x-forms.loading />
</x-layouts.patient>
