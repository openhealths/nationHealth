@php
    use App\Enums\Device\Status;
    use App\Models\MedicalEvents\Sql\DeviceProperty;
@endphp

<x-layouts.patient
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    :activeTab="'devices'"
>
    <x-slot name="headerActions">
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="button-primary-outline m-0! px-4 py-2 text-sm shadow-sm">
                {{ __('patients.data_access') }}
            </button>
            <button
                wire:click.prevent="sync"
                type="button"
                class="button-sync m-0! flex items-center gap-2 px-4 py-2 text-sm shadow-sm"
            >
                @icon('refresh', 'w-4 h-4')
                <span>{{ __('forms.synchronise_with_eHealth') }}</span>
            </button>
        </div>
    </x-slot>

    <div class="breadcrumb-form shift-content p-4">
        <div class="mt-6 w-full" x-data="{ showAdditionalParams: $wire.entangle('showAdditionalParams') }">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('devices.search') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <input
                        type="text"
                        name="filterName"
                        id="filterName"
                        class="input peer"
                        wire:model="filterName"
                        placeholder=" "
                    />
                    <label for="filterName" class="label">{{ __('devices.name') }}</label>
                </div>

                <div class="form-group group">
                    <x-select2
                        modelPath="filterType"
                        dictionaryName="device_definition_classification_type"
                        id="filterType"
                        class="input-select peer w-full"
                    />
                    <label class="label">{{ __('devices.type') }}</label>
                </div>

                <div class="form-group group">
                    <select
                        name="filterStatus"
                        id="filterStatus"
                        class="input-select peer w-full"
                        wire:model="filterStatus"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach (Status::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </select>
                    <label for="filterStatus" class="label">{{ __('forms.status.label') }}</label>
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

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="device-search-filters">
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

                    <x-forms.combobox
                        :options="$employees"
                        bind="filterRecorder"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('devices.recorder')"
                    />
                </div>

                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="filterModelNumber"
                            id="filterModelNumber"
                            class="input peer"
                            wire:model="filterModelNumber"
                            placeholder=" "
                        />
                        <label for="filterModelNumber" class="label">{{ __('devices.model_number') }}</label>
                    </div>

                    <div class="form-group group">
                        <input
                            type="text"
                            name="filterManufacturer"
                            id="filterManufacturer"
                            class="input peer"
                            wire:model="filterManufacturer"
                            placeholder=" "
                        />
                        <label for="filterManufacturer" class="label">{{ __('devices.manufacturer') }}</label>
                    </div>

                    <div class="form-group group">
                        <input
                            type="text"
                            name="filterSerialNumber"
                            id="filterSerialNumber"
                            class="input peer"
                            wire:model="filterSerialNumber"
                            placeholder=" "
                        />
                        <label for="filterSerialNumber" class="label">{{ __('devices.serial_number') }}</label>
                    </div>
                </div>

                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <input
                            type="text"
                            name="filterDefinition"
                            id="filterDefinition"
                            class="input peer"
                            wire:model="filterDefinition"
                            placeholder=" "
                        />
                        <label for="filterDefinition" class="label">{{ __('devices.definition') }}</label>
                    </div>

                    <div class="form-group group">
                        <div
                            class="datepicker-wrapper"
                            x-data="{
                                from: $wire.entangle('filterInsertedAtFrom'),
                                to: $wire.entangle('filterInsertedAtTo'),
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
                                name="filterInsertedAt"
                                id="filterInsertedAt"
                                class="daterangepicker-uk with-leading-icon input peer w-full"
                                placeholder=" "
                                autocomplete="off"
                            />

                            <label for="filterInsertedAt" class="wrapped-label">{{ __('devices.inserted_at') }}</label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @forelse ($this->paginatedDevices as $device)
                    @php
                        // The local record keeps these under their relation names, the API returns them in singular
                        $deviceNames = data_get($device, 'names') ?: data_get($device, 'name', []);
                        $deviceProperties = data_get($device, 'properties') ?: data_get($device, 'property', []);
                        $deviceIdentifiers = data_get($device, 'identifiers') ?: data_get($device, 'identifier', []);
                        $status = Status::from(data_get($device, 'status'));
                    @endphp
                    <div class="record-inner-card" wire:key="device-{{ data_get($device, 'uuid') }}">
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <input
                                    type="checkbox"
                                    name="selectedDevices[]"
                                    id="device-{{ data_get($device, 'uuid') }}"
                                    class="default-checkbox h-5 w-5"
                                />
                            </div>

                            <div class="record-inner-column flex-1">
                                <div class="record-inner-label">{{ __('devices.name') }}</div>
                                <div class="record-inner-value text-[16px] font-bold text-gray-900 dark:text-gray-100">
                                    {{ data_get($deviceNames, '0.value') ?? '-' }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                                <div>
                                    <span @class([$status->color()])>{{ $status->label() }}</span>
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
                                        @if (data_get($device, 'id'))
                                            <a
                                                href="{{
                                                    $prepersonId
                                                    ? route('prepersons.devices.view', [legalEntity(), 'preperson' => $prepersonId, 'device' => data_get($device, 'id')])
                                                    : route('persons.devices.view', [legalEntity(), 'person' => $personId, 'device' => data_get($device, 'id')])
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
                                                wire:click="view('{{ data_get($device, 'uuid') }}')"
                                                class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                            >
                                                @icon('eye', 'w-5 h-5 text-gray-500')
                                                {{ __('forms.view_details') }}
                                            </button>
                                        @endif

                                        @if (data_get($device, 'status') !== Status::ENTERED_IN_ERROR->value)
                                            <button
                                                type="button"
                                                wire:click="openRecordCancellation('devices', '{{ data_get($device, 'uuid') }}')"
                                                @click="close($refs.button)"
                                                class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                            >
                                                @icon('alert-circle', 'w-5 h-5 text-gray-500')
                                                {{ __('medical-events.mark_as_error') }}
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-body">
                            <div class="record-inner-grid-container">
                                <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('devices.type') }}</div>
                                        <div class="record-inner-value wrap-break-word">
                                            {{ $this->dictionaryLabel($device, 'type') }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('devices.model_number') }}</div>
                                        <div class="record-inner-value">
                                            {{ data_get($device, 'modelNumber') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('devices.manufacturer') }}</div>
                                        <div class="record-inner-value wrap-break-word">
                                            {{ data_get($device, 'manufacturer') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('devices.serial_number') }}</div>
                                        <div class="record-inner-value">
                                            {{ data_get($device, 'serialNumber') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('devices.lot_number') }}</div>
                                        <div class="record-inner-value">
                                            {{ data_get($device, 'lotNumber') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('devices.manufacture_date') }}</div>
                                        <div class="record-inner-value">
                                            {{ data_get($device, 'manufactureDate') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('devices.expiration_date') }}</div>
                                        <div class="record-inner-value">
                                            {{ data_get($device, 'expirationDate') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('devices.recorder') }}</div>
                                        <div class="record-inner-value wrap-break-word">
                                            {{ data_get($device, 'recorder.displayValue') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('devices.report_origin') }}</div>
                                        <div class="record-inner-value wrap-break-word">
                                            {{ $this->dictionaryLabel($device, 'reportOrigin') }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('devices.inserted_at') }}</div>
                                        <div class="record-inner-value">
                                            {{ data_get($device, 'ehealthInsertedAt') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('devices.updated_at') }}</div>
                                        <div class="record-inner-value">
                                            {{ data_get($device, 'ehealthUpdatedAt') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('devices.primary_source') }}</div>
                                        <div class="record-inner-value">
                                            {{ data_get($device, 'primarySource') ? __('forms.yes') : __('forms.no') }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">
                                            {{ __('devices.entered_in_error_reason') }}
                                        </div>
                                        <div class="record-inner-value wrap-break-word">
                                            {{ $this->dictionaryLabel($device, 'statusReason') }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('devices.definition') }}</div>
                                        <div class="record-inner-value wrap-break-word">
                                            {{ data_get($device, 'definition.identifier.value') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('devices.parent') }}</div>
                                        <div class="record-inner-value wrap-break-word">
                                            {{ data_get($device, 'parent.identifier.value') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('devices.property') }}</div>
                                        <div class="record-inner-value wrap-break-word">
                                            @forelse ($deviceProperties as $property)
                                                <div>
                                                    {{ $this->dictionaryLabel($property, 'code') }} : {{ DeviceProperty::displayValue($property) }}
                                                </div>
                                            @empty
                                                -
                                            @endforelse
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">
                                            {{ __('devices.external_system_identifier') }}
                                        </div>
                                        <div class="record-inner-value wrap-break-word">
                                            @forelse ($deviceIdentifiers as $identifier)
                                                <div>
                                                    {{ data_get($identifier, 'identifier.value') ?? data_get($identifier, 'value') }}
                                                </div>
                                            @empty
                                                -
                                            @endforelse
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('devices.notes') }}</div>
                                        <div class="record-inner-value wrap-break-word">
                                            {{ data_get($device, 'note') ?? '-' }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="record-inner-id-col">
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('devices.device_id_label') }}</div>
                                    <div class="record-inner-id-value">{{ data_get($device, 'uuid') }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('devices.context_id') }}</div>
                                    <div class="record-inner-id-value">
                                        {{ data_get($device, 'context.identifier.value') ?? '-' }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <x-nothing-found :description="null" />
                @endforelse
            </div>

            <div class="mt-8">{{ $this->paginatedDevices->links() }}</div>
        </div>
    </div>

    @include('livewire.encounter-package.encounter-cancellation', [
        'note' => __('medical-events.messages.cancel_device_group_warning')
    ])

    <x-forms.loading />
</x-layouts.patient>
