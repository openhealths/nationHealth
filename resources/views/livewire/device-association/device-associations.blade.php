@php
    use App\Enums\DeviceAssociation\Status;
    use App\Models\MedicalEvents\Sql\Device;
    use App\Models\MedicalEvents\Sql\DeviceAssociation;
@endphp

<x-layouts.patient
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    :activeTab="'device-associations'"
>
    <x-slot name="headerActions">
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="button-primary-outline m-0! px-4 py-2 text-sm shadow-sm">
                {{ __('patients.data_access') }}
            </button>
            @can('viewAny', DeviceAssociation::class)
                <button
                    wire:click.prevent="sync"
                    type="button"
                    class="button-sync m-0! flex items-center gap-2 px-4 py-2 text-sm shadow-sm"
                >
                    @icon('refresh', 'w-4 h-4')
                    <span>{{ __('forms.synchronise_with_eHealth') }}</span>
                </button>
            @endcan
        </div>
    </x-slot>

    <div class="breadcrumb-form shift-content p-4">
        <div class="mt-6 w-full" x-data="{ showAdditionalParams: $wire.entangle('showAdditionalParams') }">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('device-associations.search') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <x-forms.combobox
                    :options="$devices"
                    bind="filterDeviceId"
                    bindValue="uuid"
                    bindParam="name"
                    :label="__('device-associations.device')"
                />

                <x-forms.combobox
                    :options="$encounters"
                    bind="filterEncounterId"
                    bindValue="uuid"
                    bindParam="name"
                    :label="__('encounters.plural')"
                />

                <div class="form-group group">
                    <select
                        name="filterStatus"
                        id="filterStatus"
                        class="input-select peer w-full"
                        wire:model="filterStatus"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach ($dictionaries['device_association_statuses'] as $statusCode => $statusName)
                            <option value="{{ $statusCode }}">{{ $statusName }}</option>
                        @endforeach
                    </select>
                    <label for="filterStatus" class="label">{{ __('device-associations.association_status') }}</label>
                </div>
            </div>

            <div class="mb-9 flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap gap-2">
                    @can('viewAny', DeviceAssociation::class)
                        <button
                            type="button"
                            wire:click="search"
                            class="button-primary flex items-center gap-2 px-5 py-2.5 text-sm shadow-sm"
                        >
                            @icon('search', 'w-4 h-4')
                            <span>{{ __('forms.search') }}</span>
                        </button>
                    @endcan
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
                                class="dropdown-button flex! w-full items-center gap-2.5 px-4 py-2 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
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

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="device-association-search-filters">
                <div class="form-row-3 mb-6">
                    <x-forms.combobox
                        :options="$episodes"
                        bind="filterEpisodeId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('episodes.plural')"
                    />

                    <x-forms.combobox
                        :options="$employees"
                        bind="filterRecorder"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('forms.employee')"
                    />

                    <div class="form-group group">
                        <select
                            name="filterBodySite"
                            id="filterBodySite"
                            class="input-select peer w-full"
                            wire:model="filterBodySite"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            @foreach ($dictionaries['eHealth/body_structures'] as $bodySiteCode => $bodySiteName)
                                <option value="{{ $bodySiteCode }}">{{ $bodySiteName }}</option>
                            @endforeach
                        </select>
                        <label for="filterBodySite" class="label">{{ __('device-associations.body_site') }}</label>
                    </div>
                </div>

                <div class="form-row-3 mb-9">
                    <div class="form-group group">
                        <div
                            class="datepicker-wrapper"
                            x-data="{
                                from: $wire.entangle('filterAssociationDateFrom'),
                                to: $wire.entangle('filterAssociationDateTo'),
                                rangeText: '',
                            }"
                            x-init="
                                if (from && to) rangeText = from + ' — ' + to;
                                $watch('from', (value) => {
                                    if (! value) {
                                        rangeText = '';
                                        const picker = $el.querySelector('input')._flatpickr;
                                        if (picker) picker.clear();
                                    }
                                });
                                $watch('to', (value) => {
                                    if (! value) {
                                        rangeText = '';
                                        const picker = $el.querySelector('input')._flatpickr;
                                        if (picker) picker.clear();
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
                                name="filterAssociationDate"
                                id="filterAssociationDate"
                                class="daterangepicker-uk with-leading-icon input peer w-full"
                                placeholder=" "
                                autocomplete="off"
                            />
                            <label for="filterAssociationDate" class="wrapped-label">
                                {{ __('device-associations.association_date_short') }}
                            </label>
                        </div>
                    </div>

                    <div class="form-group group">
                        <div
                            class="datepicker-wrapper"
                            x-data="{
                                from: $wire.entangle('filterRecordedFrom'),
                                to: $wire.entangle('filterRecordedTo'),
                                rangeText: '',
                            }"
                            x-init="
                                if (from && to) rangeText = from + ' — ' + to;
                                $watch('from', (value) => {
                                    if (! value) {
                                        rangeText = '';
                                        const picker = $el.querySelector('input')._flatpickr;
                                        if (picker) picker.clear();
                                    }
                                });
                                $watch('to', (value) => {
                                    if (! value) {
                                        rangeText = '';
                                        const picker = $el.querySelector('input')._flatpickr;
                                        if (picker) picker.clear();
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
                                name="filterRecorded"
                                id="filterRecorded"
                                class="daterangepicker-uk with-leading-icon input peer w-full"
                                placeholder=" "
                                autocomplete="off"
                            />
                            <label for="filterRecorded" class="wrapped-label">
                                {{ __('device-associations.recorded') }}
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @forelse ($this->paginatedAssociations as $association)
                    @php
                        $status = Status::from(data_get($association, 'status'));
                        $deviceId = data_get($association, 'device.identifier.value');
                    @endphp
                    <div class="record-inner-card" wire:key="device-association-{{ data_get($association, 'uuid') }}">
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <input
                                    type="checkbox"
                                    name="selectedAssociations[]"
                                    id="device-association-{{ data_get($association, 'uuid') }}"
                                    class="default-checkbox h-5 w-5"
                                />
                            </div>

                            <div class="record-inner-column flex-1">
                                <div class="record-inner-label">{{ __('device-associations.device_name') }}</div>
                                <div class="record-inner-value text-[16px] font-bold text-gray-900 dark:text-gray-100">
                                    {{ collect($devices)->firstWhere('uuid', $deviceId)['name'] ?? data_get($association, 'device.displayValue') ?? '-' }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                                <div class="record-inner-label">{{ __('device-associations.association_status') }}</div>
                                <div>
                                    <span @class([$status->color()])>
                                        {{ $dictionaries['device_association_statuses'][$status->value] }}
                                    </span>
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
                                        @can('view', DeviceAssociation::class)
                                            @if (data_get($association, 'id'))
                                                <a
                                                    href="{{
                                                        $prepersonId
                                                        ? route('prepersons.device-associations.view', [legalEntity(), 'preperson' => $prepersonId, 'deviceAssociation' => data_get($association, 'id')])
                                                        : route('persons.device-associations.view', [legalEntity(), 'person' => $personId, 'deviceAssociation' => data_get($association, 'id')])
                                                    }}"
                                                    class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                >
                                                    @icon('eye', 'w-5 h-5 text-gray-500')
                                                    {{ __('forms.view_details') }}
                                                </a>
                                            @else
                                                {{-- Found through the eHealth search: the record is stored on the way to its page --}}
                                                <button
                                                    type="button"
                                                    wire:click="view('{{ data_get($association, 'uuid') }}')"
                                                    class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                >
                                                    @icon('eye', 'w-5 h-5 text-gray-500')
                                                    {{ __('forms.view_details') }}
                                                </button>
                                            @endif
                                        @endcan

                                        @can('cancel', Device::class)
                                            @if (data_get($association, 'status') !== Status::ENTERED_IN_ERROR->value)
                                                <button
                                                    type="button"
                                                    wire:click="openRecordCancellation('deviceAssociations', '{{ data_get($association, 'uuid') }}')"
                                                    @click="close($refs.button)"
                                                    class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                >
                                                    @icon('alert-circle', 'w-5 h-5 text-gray-500')
                                                    {{ __('medical-events.mark_as_error') }}
                                                </button>
                                            @endif
                                        @endcan
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-body">
                            <div class="record-inner-grid-container">
                                <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('device-associations.device_id') }}</div>
                                        <div class="record-inner-value wrap-break-word">{{ $deviceId ?? '-' }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('device-associations.body_site') }}</div>
                                        <div class="record-inner-value wrap-break-word">
                                            {{ $this->dictionaryLabel($association, 'bodySite') }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">
                                            {{ __('device-associations.association_date_short') }}
                                        </div>
                                        <div class="record-inner-value">
                                            {{ data_get($association, 'associationDate') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('device-associations.recorded') }}</div>
                                        <div class="record-inner-value">
                                            {{ data_get($association, 'recorded') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('device-associations.recorder') }}</div>
                                        <div class="record-inner-value wrap-break-word">
                                            {{ data_get($association, 'recorder.displayValue') ?? '-' }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="record-inner-id-col">
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('device-associations.id') }}</div>
                                    <div class="record-inner-id-value">{{ data_get($association, 'uuid') }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('patients.encounter_id') }}</div>
                                    <div class="record-inner-id-value">
                                        {{ data_get($association, 'context.identifier.value') ?? '-' }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <x-nothing-found :description="null" />
                @endforelse
            </div>

            <div class="mt-8">{{ $this->paginatedAssociations->links() }}</div>
        </div>
    </div>

    @include('livewire.encounter-package.encounter-cancellation', [
        'note' => __('medical-events.messages.cancel_device_group_warning')
    ])

    <x-forms.loading />
</x-layouts.patient>
