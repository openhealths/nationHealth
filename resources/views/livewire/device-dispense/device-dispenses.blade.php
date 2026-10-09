@use(App\Enums\JobStatus)
@use(App\Enums\DeviceDispense\Status)

<x-layouts.patient
    :personId="$personId ?? null"
    :prepersonId="$prepersonId ?? null"
    :patientFullName="$patientFullName ?? ''"
    :activeTab="'device-dispenses'"
>
<x-slot name="headerActions">
    @php
        $isSyncing = $syncStatus === JobStatus::PROCESSING->value;
        $isRetryable = $syncStatus === JobStatus::PAUSED->value || $syncStatus === JobStatus::FAILED->value;
    @endphp

    <button
        @if (!$isSyncing) wire:click="sync" @endif
        type="button"
        @if ($isSyncing) disabled @endif
        class="flex items-center gap-2 whitespace-nowrap px-5 py-2 text-sm shadow-sm transition-colors
            @if($isSyncing) button-sync-disabled cursor-not-allowed @else button-sync @endif"
    >
        @icon('refresh', 'w-4 h-4')
        <span>{{ $isRetryable ? __('forms.sync_retry') : __('forms.synchronise_with_eHealth') }}</span>
    </button>
</x-slot>
    <div
        class="breadcrumb-form shift-content px-4 pt-4 pb-10"
        x-data="{
            showAdditionalParams: $wire.entangle('showAdditionalParams'),
            deviceId: $wire.entangle('filterDeviceId'),
            deviceCode: $wire.entangle('filterDeviceCode'),
            deviceIdSearch: '',
            deviceCodeSearch: ''
        }"
    >
        <h2 class="mb-6 flex items-center gap-2 text-xl font-bold text-gray-900 dark:text-white">
            @icon('search', 'w-5 h-5')
            <span>{{ __('device-dispenses.search') }}</span>
        </h2>

        <div class="mt-6 w-full">
            <div class="form-row-3 mb-6">
                <x-forms.combobox
                    :options="$devices"
                    bind="filterDeviceId"
                    bindValue="uuid"
                    bindParam="name"
                    :label="__('devices.device_id')"
                    x-on:input="deviceIdSearch = $event.target.value"
                    x-bind:inert="deviceCodeSearch.trim() !== '' || deviceCode !== ''"
                    x-bind:style="deviceCodeSearch.trim() !== '' || deviceCode !== '' ? 'opacity: 0.5' : ''"
                />

                <x-forms.combobox
                    :options="$deviceTypes"
                    bind="filterDeviceCode"
                    bindValue="code"
                    bindParam="name"
                    :label="__('device-dispenses.type')"
                    x-on:input="deviceCodeSearch = $event.target.value"
                    x-bind:inert="deviceIdSearch.trim() !== '' || deviceId !== ''"
                    x-bind:style="deviceIdSearch.trim() !== '' || deviceId !== '' ? 'opacity: 0.5' : ''"
                />

                <div class="form-group group">
                    <select class="input-select peer w-full" wire:model.defer="filterStatus">
                        <option value="">
                            {{ __('forms.select') }} {{ mb_strtolower(__('forms.status.label')) }}
                        </option>

                        @foreach (Status::cases() as $status)
                            <option value="{{ $status->value }}">{{ $status->label() }}</option>
                        @endforeach
                    </select>
                    <label class="label">{{ __('forms.status.label') }}</label>
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
                        x-on:click="
                            deviceIdSearch = '';
                            deviceCodeSearch = '';
                            $dispatch('reset-comboboxes');
                        "
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

            <div x-show="showAdditionalParams" x-transition x-cloak>
                <div class="form-row-3 mb-6">
                    <x-forms.combobox
                        :options="$episodes"
                        bind="filterEpisodeId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('episodes.id')"
                    />

                    <x-forms.combobox
                        :options="$organizations"
                        bind="filterOrganization"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('devices.legal_entity')"
                    />

                    <x-forms.combobox
                        :options="$encounters"
                        bind="filterEncounterId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('patients.encounter_id')"
                    />
                </div>

                <div class="form-row-3 mb-6">
                    <x-forms.combobox
                        :options="$procedures"
                        bind="filterProcedureId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('device-dispenses.procedure_id')"
                    />

                    <x-forms.combobox
                        :options="$carePlans"
                        bind="filterCarePlanId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('device-dispenses.care_plan_id')"
                    />

                    <x-forms.combobox
                        :options="$relatedEpisodes"
                        bind="filterRelatedEpisodeId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('device-dispenses.related_prescription_episode_id')"
                    />
                </div>

                <div class="form-row-3 mb-9">
                    <div class="form-group group">
                        <div
                            class="datepicker-wrapper"
                            x-data="{
                                from: $wire.entangle('filterDispenseDateFrom'),
                                to: $wire.entangle('filterDispenseDateTo'),
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
                            <label class="wrapped-label">{{ __('device-dispenses.filter_date_range') }}</label>
                        </div>
                    </div>
                    <div class="form-group group">
                        <div
                            class="datepicker-wrapper"
                            x-data="{
                                from: $wire.entangle('filterCreatedAtFrom'),
                                to: $wire.entangle('filterCreatedAtTo'),
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
                            <label class="wrapped-label">{{ __('forms.filter_created_at_range') }}</label>
                        </div>
                    </div>

                    <x-forms.combobox
                        :options="$practitioners"
                        bind="filterPractitioner"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('forms.employee')"
                    />
                </div>
            </div>

            <div class="space-y-4">
                @forelse ($this->paginatedDeviceDispenses as $dispense)
                    <div class="record-inner-card">
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <input type="checkbox" class="default-checkbox h-5 w-5" />
                            </div>

                            @php
                                $deviceId = data_get($dispense, 'details.0.device.identifier.value');
                                $deviceName = data_get($dispense, 'details.0.device.displayValue');
                                $deviceCode = data_get($dispense, 'details.0.deviceCode.coding.0.code');

                                if (!$deviceName && $deviceCode) {
                                    $deviceName = data_get(
                                        $this->dictionaries,
                                        'device_definition_classification_type.' . $deviceCode
                                    );
                                }

                                $deviceLabel = $deviceId ? __('device-dispenses.medical_device') : __('device-dispenses.type');
                            @endphp

                            <div class="record-inner-column flex-1">
                                <div class="record-inner-label">{{ $deviceLabel }}</div>

                                <div class="record-inner-value text-[16px] font-bold text-gray-900 dark:text-gray-100">
                                    {{ $deviceName ?: '-' }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                                <div>
                                    @php
                                        $status = Status::tryFrom(data_get($dispense, 'status'));
                                    @endphp

                                    <span class="badge-green">{{ $status?->label() ?? '-' }}</span>
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
                                            type="button"
                                            @click="close($refs.button)"
                                            wire:click="openDeviceDispenseView('{{ data_get($dispense, 'uuid') }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="openDeviceDispenseView"
                                            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                        >
                                            @icon('eye', 'w-5 h-5 text-gray-500')
                                            {{ __('forms.view_details') }}
                                        </button>

                                        <button
                                            type="button"
                                            @click="close($refs.button)"
                                            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                        >
                                            @icon('alert-circle', 'w-5 h-5 text-gray-500')
                                            {{ __('medical-events.mark_as_error') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-body">
                            <div class="record-inner-grid-container">
                                <div class="grid grid-cols-2 gap-x-4 gap-y-3 md:grid-cols-4">
                                    <div class="min-w-0 space-y-2.5">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('device-dispenses.date_and_time') }}
                                            </div>
                                            <div class="record-inner-value font-semibold">
                                                {{ data_get($dispense, 'whenHandedOver') ?: '-' }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('devices.legal_entity') }}
                                            </div>
                                            <div class="record-inner-value font-semibold">
                                                {{ data_get($dispense, 'performerLegalEntity.displayValue') ?: '-' }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="min-w-0 space-y-2.5">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('device-dispenses.procedure_id') }}
                                            </div>
                                            <div class="record-inner-value font-semibold">
                                                {{ data_get($dispense, 'procedureId') ?: '-' }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('forms.employee') }}
                                            </div>
                                            <div class="record-inner-value font-semibold">
                                                {{ data_get($dispense, 'performer.displayValue') ?: '-' }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="min-w-0 space-y-2.5">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('device-dispenses.care_plan_id') }}
                                            </div>
                                            <div class="record-inner-value font-semibold">
                                                {{ data_get($dispense, 'carePlanId') ?: '-' }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('devices.record_creation_date') }}
                                            </div>
                                            <div class="record-inner-value font-semibold">
                                                {{ data_get($dispense, 'createdAt') ? optional(\Carbon\Carbon::make(data_get($dispense, 'createdAt')))->format(config('app.date_format')) : '-' }}
                                            </div>
                                        </div>
                                    </div>
                                    <div class="min-w-0 space-y-2.5">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('device-dispenses.related_prescription_episode') }}
                                            </div>
                                            <div class="record-inner-value font-semibold">
                                                {{ data_get($dispense, 'originEpisodeId') ?: '-' }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="record-inner-id-col">
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">
                                        {{ __('device-dispenses.id') }}
                                    </div>
                                    <div class="record-inner-id-value">{{ data_get($dispense, 'uuid') ?: '-' }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">
                                        {{ __('patients.encounter_id') }}
                                    </div>
                                    <div class="record-inner-id-value">{{ data_get($dispense, 'encounterId') ?: '-' }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">{{ __('episodes.id') }}</div>
                                    <div class="record-inner-id-value">{{ data_get($dispense, 'contextEpisodeId') ?: '-' }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <x-nothing-found :description="null" />
                @endforelse
            </div>

            <div class="mt-8">{{ $this->paginatedDeviceDispenses->links() }}</div>
        </div>
    </div>
</x-layouts.patient>
