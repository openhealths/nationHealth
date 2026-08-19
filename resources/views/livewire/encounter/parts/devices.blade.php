@php
    use App\Enums\Device\Status as DeviceStatus;
@endphp

<div
    class="p-4 sm:p-8"
    id="devices-section"
    x-data="{
        devices: $wire.entangle('form.devices'),
        deviceTypesDictionary: $wire.dictionaries['device_definition_classification_type'],
        deviceDefinitions: $wire.dictionaries['custom/device_definitions'],
        modalDevice: new Device(),
        newDevice: false,
        openDeviceDrawer: false,
        item: 0,

        definitionsForSelectedType() {
            return this.deviceDefinitions.filter(
                (deviceDefinition) => deviceDefinition.typeCodes.includes(this.modalDevice.typeCode)
            );
        },

        addProperty() {
            this.modalDevice.properties.push(newDeviceProperty());
        },

        removeProperty(index) {
            this.modalDevice.properties.splice(index, 1);
        },

        setPropertyValueType(property, valueType) {
            // A property carries exactly one value, so switching the type drops whatever was filled in before
            Object.assign(property, newDeviceProperty(), { code: property.code, valueType });

            if (valueType === 'boolean') {
                property.valueBoolean = false;
            }
        },

        addName() {
            this.modalDevice.names.push({ type: '', value: '' });
        },

        removeName(index) {
            this.modalDevice.names.splice(index, 1);
        },

        addIdentifier() {
            this.modalDevice.identifiers.push({ code: '', text: '', value: '' });
        },

        removeIdentifier(index) {
            this.modalDevice.identifiers.splice(index, 1);
        },
    }"
>
    <div class="space-y-4">
        <template x-for="(device, index) in devices" :key="index">
            <div class="record-inner-card">
                <div class="record-inner-header">
                    <div class="record-inner-checkbox-col">
                        <input type="checkbox" class="default-checkbox h-5 w-5" />
                    </div>

                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">{{ __('devices.name') }}</div>
                        <div class="record-inner-value text-[16px]" x-text="device.names?.[0]?.value || '-'"></div>
                    </div>

                    <div class="record-inner-action-col">
                        <div
                            x-data="{
                                openDropdown: false,
                                toggle() {
                                    if (this.openDropdown) {
                                        return this.close();
                                    }
                                    this.$refs.button.focus();
                                    this.openDropdown = true;
                                },
                                close(focusAfter) {
                                    if (! this.openDropdown) return;
                                    this.openDropdown = false;
                                    focusAfter && focusAfter.focus();
                                },
                            }"
                            @keydown.escape.prevent.stop="close($refs.button)"
                            @focusin.window="$refs.panel && ! $refs.panel.contains($event.target) && close()"
                            x-id="['dropdown-button']"
                            class="relative"
                        >
                            @if ($isReadonly ?? false)
                                <a
                                    href="#"
                                    @click.prevent="
                                        item = index;
                                        modalDevice = new Device(devices[index]);
                                        newDevice = false;
                                        openDeviceDrawer = true;
                                    "
                                    class="record-inner-action-btn cursor-pointer"
                                    title="{{ __('forms.view') }}"
                                >
                                    @icon('eye', 'w-6 h-6')
                                    <span class="sr-only">{{ __('forms.view') }}</span>
                                </a>
                            @else
                                <button
                                    x-ref="button"
                                    @click="toggle()"
                                    :aria-expanded="openDropdown"
                                    :aria-controls="$id('dropdown-button')"
                                    type="button"
                                    class="record-inner-action-btn cursor-pointer"
                                >
                                    <svg class="h-6 w-6 text-gray-800 dark:text-gray-200" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
                                        <path stroke="currentColor" stroke-linecap="square" stroke-linejoin="round" stroke-width="2" d="M7 19H5a1 1 0 0 1-1-1v-1a3 3 0 0 1 3-3h1m4-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm7.441 1.559a1.907 1.907 0 0 1 0 2.698l-6.069 6.069L10 19l.674-3.372 6.07-6.07a1.907 1.907 0 0 1 2.697 0Z" />
                                    </svg>
                                </button>

                                <div class="absolute right-0 z-50">
                                    <div
                                        x-ref="panel"
                                        x-show="openDropdown"
                                        x-transition.origin.top.left
                                        @click.outside="close($refs.button)"
                                        :id="$id('dropdown-button')"
                                        x-cloak
                                        class="dropdown-panel relative"
                                    >
                                        <button
                                            type="button"
                                            @click.prevent="
                                                item = index;
                                                modalDevice = new Device(devices[index]);
                                                newDevice = false;
                                                openDeviceDrawer = true;
                                                close($refs.button);
                                            "
                                        >
                                            {{ __('forms.edit') }}
                                        </button>

                                        <button
                                            type="button"
                                            class="dropdown-delete"
                                            @click.prevent="
                                                devices.splice(index, 1);
                                                close($refs.button);
                                            "
                                        >
                                            {{ __('forms.delete') }}
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="record-inner-body">
                    <div class="record-inner-grid-container">
                        <div class="grid w-full grid-cols-2 gap-x-4 gap-y-4 xl:grid-cols-4">
                            <div>
                                <div class="record-inner-label">{{ __('devices.model_number') }}</div>
                                <div class="record-inner-subvalue" x-text="device.modelNumber || '-'"></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('devices.type') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="deviceTypesDictionary[device.typeCode] || '-'"
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('forms.employee') }}</div>
                                <div class="record-inner-subvalue">{{ $employeeFullName }}</div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('forms.created_at') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="$wire.form.encounter.periodDate || '-'"
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('devices.sgusoz') }}</div>
                                <div class="record-inner-subvalue">{{ legalEntity()->name }}</div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('devices.manufacturer') }}</div>
                                <div class="record-inner-subvalue" x-text="device.manufacturer || '-'"></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('devices.serial_number') }}</div>
                                <div class="record-inner-subvalue" x-text="device.serialNumber || '-'"></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="{{ Js::from(DeviceStatus::options()) }}[device.status] || '-'"
                                ></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div>
        @unless ($isReadonly ?? false)
            <button
                type="button"
                @click.prevent="
                    newDevice = true;
                    modalDevice = new Device();
                    openDeviceDrawer = true;
                "
                class="item-add my-5 mt-5 flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
            >
                {{ __('devices.add') }}
            </button>
        @endunless
    </div>

    <x-dialog-drawer x-model="openDeviceDrawer" maxWidth="4/5" wire:ignore>
        <x-slot name="title">{{ __('devices.new') }}</x-slot>

        <form>
            <fieldset
                @disabled($isReadonly ?? false)
                @class([
                    'pointer-event-none' => $isReadonly ?? false
                ])
            >
                <fieldset class="fieldset">
                    <legend class="legend">{{ __('patients.main_info') }}</legend>

                    <div class="form-row-2">
                        <div class="form-group group">
                            <select
                                x-model="modalDevice.typeCode"
                                {{-- A definition belongs to its own types, so the chosen one no longer fits a new type --}}
                                @change="modalDevice.definitionId = ''"
                                id="deviceTypeCode"
                                class="input-select peer"
                                required
                            >
                                <option value="" selected>{{ __('forms.select') }}</option>
                                @foreach ($this->dictionaries['device_definition_classification_type'] as $code => $classificationType)
                                    <option value="{{ $code }}">{{ $classificationType }}</option>
                                @endforeach
                            </select>
                            <label for="deviceTypeCode" class="label">{{ __('devices.type') }}</label>
                        </div>
                    </div>

                    <template x-for="(name, nameIndex) in modalDevice.names" :key="nameIndex">
                        <div class="relative pr-10">
                            <div class="form-row-2">
                                <div class="form-group group">
                                    <select
                                        x-model="name.type"
                                        :id="`deviceNameType-${nameIndex}`"
                                        class="input-select peer"
                                        required
                                    >
                                        <option value="" selected>{{ __('forms.select') }}</option>
                                        @foreach ($this->dictionaries['device_name_type'] as $code => $nameType)
                                            <option value="{{ $code }}">{{ $nameType }}</option>
                                        @endforeach
                                    </select>
                                    <label :for="`deviceNameType-${nameIndex}`" class="label">
                                        {{ __('devices.name_type') }}
                                    </label>
                                </div>
                                <div class="form-group group relative">
                                    <input
                                        type="text"
                                        x-model="name.value"
                                        :id="`deviceNameValue-${nameIndex}`"
                                        class="input peer"
                                        placeholder=" "
                                        required
                                    />
                                    <label :for="`deviceNameValue-${nameIndex}`" class="label">
                                        {{ __('devices.name') }}
                                    </label>
                                </div>
                            </div>
                            <button
                                type="button"
                                x-show="modalDevice.names.length > 1"
                                @click.prevent="removeName(nameIndex)"
                                class="absolute top-3 right-0 text-gray-400 transition-colors hover:text-red-500 dark:text-gray-500"
                            >
                                @icon('delete', 'w-6 h-6')
                            </button>
                        </div>
                    </template>

                    <div class="mt-2 mb-6">
                        <button
                            type="button"
                            @click.prevent="addName()"
                            class="flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                        >
                            + {{ __('devices.add_name') }}
                        </button>
                    </div>

                    <div class="form-row-2">
                        <div class="form-group group">
                            <select
                                x-model="modalDevice.status"
                                id="deviceStatus"
                                class="input-select peer"
                                required
                            >
                                <option value="" selected>{{ __('forms.select') }}</option>
                                <option value="{{ DeviceStatus::ACTIVE->value }}">
                                    {{ __('patients.status.active') }}
                                </option>
                                <option value="{{ DeviceStatus::INACTIVE->value }}">
                                    {{ __('patients.status.inactive') }}
                                </option>
                            </select>
                            <label for="deviceStatus" class="label">{{ __('forms.status.label') }}</label>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="fieldset">
                    <legend class="legend">{{ __('forms.additional_information') }}</legend>

                    <div>
                        <div class="form-row-2">
                            <div class="form-group group relative">
                                <input
                                    type="text"
                                    x-model="modalDevice.modelNumber"
                                    id="deviceModelNumber"
                                    class="input peer"
                                    placeholder=" "
                                />
                                <label for="deviceModelNumber" class="label">
                                    {{ __('devices.model_number') }}
                                </label>
                            </div>
                            <div class="form-group group">
                                <select
                                    x-model="modalDevice.definitionId"
                                    :disabled="!modalDevice.typeCode"
                                    id="deviceDefinition"
                                    class="input-select peer"
                                >
                                    <option value="" selected>{{ __('forms.select') }}</option>
                                    <template
                                        x-for="deviceDefinition in definitionsForSelectedType()"
                                        :key="deviceDefinition.id"
                                    >
                                        <option :value="deviceDefinition.id" x-text="deviceDefinition.name"></option>
                                    </template>
                                </select>
                                <label for="deviceDefinition" class="label">
                                    {{ __('devices.definition') }}
                                </label>
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group group relative">
                                <input
                                    type="text"
                                    x-model="modalDevice.manufacturer"
                                    id="deviceManufacturer"
                                    class="input peer"
                                    placeholder=" "
                                />
                                <label for="deviceManufacturer" class="label">{{ __('devices.manufacturer') }}</label>
                            </div>
                            <div class="form-group group relative">
                                <input
                                    type="text"
                                    x-model="modalDevice.serialNumber"
                                    id="deviceSerialNumber"
                                    class="input peer"
                                    placeholder=" "
                                />
                                <label for="deviceSerialNumber" class="label">
                                    {{ __('devices.serial_number') }}
                                </label>
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group group relative">
                                <input
                                    type="text"
                                    x-model="modalDevice.lotNumber"
                                    id="deviceLotNumber"
                                    class="input peer"
                                    placeholder=" "
                                />
                                <label for="deviceLotNumber" class="label">
                                    {{ __('devices.lot_number') }}
                                </label>
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group group">
                                <div class="datepicker-wrapper">
                                    <input
                                        x-model="modalDevice.manufactureDate"
                                        datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                                        type="text"
                                        name="manufactureDate"
                                        id="manufactureDate"
                                        class="datepicker-input with-leading-icon input peer"
                                        placeholder=" "
                                        autocomplete="off"
                                    />
                                    <label for="manufactureDate" class="wrapped-label">
                                        {{ __('devices.manufacture_date') }}
                                    </label>
                                </div>
                            </div>
                            <div class="form-group group">
                                <div class="datepicker-wrapper">
                                    <input
                                        x-model="modalDevice.expirationDate"
                                        type="text"
                                        name="expirationDate"
                                        id="expirationDate"
                                        class="datepicker-input with-leading-icon input peer"
                                        placeholder=" "
                                        autocomplete="off"
                                    />
                                    <label for="expirationDate" class="wrapped-label">
                                        {{ __('devices.expiration_date') }}
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group group">
                                <select x-model="modalDevice.parentId" id="deviceParent" class="input-select peer">
                                    <option value="" selected>{{ __('forms.select') }}</option>
                                    {{-- A device cannot be its own parent, the rest of the package is fair game --}}
                                    <template x-for="(parentDevice, parentIndex) in devices" :key="parentIndex">
                                        <option
                                            x-show="newDevice || parentIndex !== item"
                                            :value="parentDevice.uuid"
                                            x-text="parentDevice.names[0].value"
                                        ></option>
                                    </template>
                                </select>
                                <label for="deviceParent" class="label">
                                    {{ __('devices.parent') }}
                                </label>
                            </div>
                        </div>

                        <template x-for="(identifier, identifierIndex) in modalDevice.identifiers" :key="identifierIndex">
                            <div class="relative pr-10">
                                <div class="form-row-2">
                                    <div class="form-group group">
                                        <select
                                            x-model="identifier.code"
                                            :id="`deviceIdentifierCode-${identifierIndex}`"
                                            class="input-select peer"
                                        >
                                            <option value="" selected>{{ __('forms.select') }}</option>
                                            @foreach ($this->dictionaries['external_system'] as $code => $externalSystem)
                                                <option value="{{ $code }}">{{ $externalSystem }}</option>
                                            @endforeach
                                        </select>
                                        <label :for="`deviceIdentifierCode-${identifierIndex}`" class="label">
                                            {{ __('devices.external_system') }}
                                        </label>
                                    </div>
                                    <div class="form-group group relative">
                                        <input
                                            type="text"
                                            x-model="identifier.value"
                                            :id="`deviceIdentifierValue-${identifierIndex}`"
                                            class="input peer"
                                            placeholder=" "
                                        />
                                        <label :for="`deviceIdentifierValue-${identifierIndex}`" class="label">
                                            {{ __('devices.external_system_identifier') }}
                                        </label>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    x-show="modalDevice.identifiers.length > 1"
                                    @click.prevent="removeIdentifier(identifierIndex)"
                                    class="absolute top-3 right-0 text-gray-400 transition-colors hover:text-red-500 dark:text-gray-500"
                                >
                                    @icon('delete', 'w-6 h-6')
                                </button>
                            </div>
                        </template>

                        <div class="mt-2 mb-6">
                            <button
                                type="button"
                                @click.prevent="addIdentifier()"
                                class="flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                            >
                                + {{ __('devices.add_external_system_identifier') }}
                            </button>
                        </div>
                    </div>

                    <template x-for="(property, propertyIndex) in modalDevice.properties" :key="propertyIndex">
                        <div :class="{ 'border-t border-gray-100 dark:border-gray-700 pt-6 mt-6': propertyIndex > 0 }">
                            <div class="relative pr-10">
                                <div class="form-row-2">
                                    <div class="form-group group">
                                        <select
                                            x-model="property.code"
                                            :id="`devicePropertyCode-${propertyIndex}`"
                                            class="input-select peer"
                                        >
                                            <option value="" selected>{{ __('forms.select') }}</option>
                                            @foreach ($this->dictionaries['device_properties'] as $code => $deviceProperty)
                                                <option value="{{ $code }}">{{ $deviceProperty }}</option>
                                            @endforeach
                                        </select>
                                        <label :for="`devicePropertyCode-${propertyIndex}`" class="label">
                                            {{ __('devices.property') }}
                                        </label>
                                    </div>
                                    <div class="form-group group">
                                        <select
                                            :value="property.valueType"
                                            @change="setPropertyValueType(property, $event.target.value)"
                                            :id="`devicePropertyValueType-${propertyIndex}`"
                                            class="input-select peer"
                                        >
                                            <option value="" selected>{{ __('forms.select') }}</option>
                                            @foreach (__('devices.value_types') as $valueType => $valueTypeLabel)
                                                <option value="{{ $valueType }}">{{ $valueTypeLabel }}</option>
                                            @endforeach
                                        </select>
                                        <label :for="`devicePropertyValueType-${propertyIndex}`" class="label">
                                            {{ __('devices.property_value_type') }}
                                        </label>
                                    </div>
                                </div>

                                <div class="form-row-2" x-show="property.valueType === 'codeable_concept'" x-cloak>
                                    <div class="form-group group">
                                        <input
                                            type="text"
                                            x-model="property.valueCodeableConceptSystem"
                                            :id="`devicePropertyConceptSystem-${propertyIndex}`"
                                            class="input peer"
                                            placeholder=" "
                                        />
                                        <label :for="`devicePropertyConceptSystem-${propertyIndex}`" class="label">
                                            {{ __('devices.value_system') }}
                                        </label>
                                    </div>
                                    <div class="form-group group">
                                        <input
                                            type="text"
                                            x-model="property.valueCodeableConceptCode"
                                            :id="`devicePropertyConceptCode-${propertyIndex}`"
                                            class="input peer"
                                            placeholder=" "
                                        />
                                        <label :for="`devicePropertyConceptCode-${propertyIndex}`" class="label">
                                            {{ __('devices.value_code') }}
                                        </label>
                                    </div>
                                </div>

                                <div class="form-row-3" x-show="property.valueType === 'quantity'" x-cloak>
                                    <div class="form-group group">
                                        <input
                                            type="number"
                                            x-model.number="property.valueQuantityValue"
                                            :id="`devicePropertyQuantityValue-${propertyIndex}`"
                                            class="input peer"
                                            placeholder=" "
                                        />
                                        <label :for="`devicePropertyQuantityValue-${propertyIndex}`" class="label">
                                            {{ __('devices.value') }}
                                        </label>
                                    </div>
                                    <div class="form-group group">
                                        <select
                                            x-model="property.valueQuantityComparator"
                                            :id="`devicePropertyQuantityComparator-${propertyIndex}`"
                                            class="input-select peer"
                                        >
                                            <option value="" selected>{{ __('forms.select') }}</option>
                                            @foreach (['>', '>=', '=', '<=', '<'] as $comparator)
                                                <option value="{{ $comparator }}">{{ $comparator }}</option>
                                            @endforeach
                                        </select>
                                        <label :for="`devicePropertyQuantityComparator-${propertyIndex}`" class="label">
                                            {{ __('devices.value_comparator') }}
                                        </label>
                                    </div>
                                    <div class="form-group group">
                                        <select
                                            x-model="property.valueQuantityUnit"
                                            :id="`devicePropertyQuantityUnit-${propertyIndex}`"
                                            class="input-select peer"
                                        >
                                            <option value="" selected>{{ __('forms.select') }}</option>
                                            @foreach ($this->dictionaries['eHealth/ucum/units'] as $code => $unit)
                                                <option value="{{ $code }}">{{ $unit }}</option>
                                            @endforeach
                                        </select>
                                        <label :for="`devicePropertyQuantityUnit-${propertyIndex}`" class="label">
                                            {{ __('devices.value_unit') }}
                                        </label>
                                    </div>
                                </div>

                                <div x-show="property.valueType === 'range'" x-cloak>
                                    <div class="form-row-2">
                                        <div class="form-group group">
                                            <input
                                                type="number"
                                                x-model.number="property.valueRangeLowValue"
                                                :id="`devicePropertyRangeLowValue-${propertyIndex}`"
                                                class="input peer"
                                                placeholder=" "
                                            />
                                            <label :for="`devicePropertyRangeLowValue-${propertyIndex}`" class="label">
                                                {{ __('devices.value_range_low') }}
                                            </label>
                                        </div>
                                        <div class="form-group group">
                                            <select
                                                x-model="property.valueRangeLowUnit"
                                                :id="`devicePropertyRangeLowUnit-${propertyIndex}`"
                                                class="input-select peer"
                                            >
                                                <option value="" selected>{{ __('forms.select') }}</option>
                                                @foreach ($this->dictionaries['eHealth/ucum/units'] as $code => $unit)
                                                    <option value="{{ $code }}">{{ $unit }}</option>
                                                @endforeach
                                            </select>
                                            <label :for="`devicePropertyRangeLowUnit-${propertyIndex}`" class="label">
                                                {{ __('devices.value_unit') }}
                                            </label>
                                        </div>
                                    </div>
                                    <div class="form-row-2">
                                        <div class="form-group group">
                                            <input
                                                type="number"
                                                x-model.number="property.valueRangeHighValue"
                                                :id="`devicePropertyRangeHighValue-${propertyIndex}`"
                                                class="input peer"
                                                placeholder=" "
                                            />
                                            <label :for="`devicePropertyRangeHighValue-${propertyIndex}`" class="label">
                                                {{ __('devices.value_range_high') }}
                                            </label>
                                        </div>
                                        <div class="form-group group">
                                            <select
                                                x-model="property.valueRangeHighUnit"
                                                :id="`devicePropertyRangeHighUnit-${propertyIndex}`"
                                                class="input-select peer"
                                            >
                                                <option value="" selected>{{ __('forms.select') }}</option>
                                                @foreach ($this->dictionaries['eHealth/ucum/units'] as $code => $unit)
                                                    <option value="{{ $code }}">{{ $unit }}</option>
                                                @endforeach
                                            </select>
                                            <label :for="`devicePropertyRangeHighUnit-${propertyIndex}`" class="label">
                                                {{ __('devices.value_unit') }}
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-row-2" x-show="property.valueType === 'boolean'" x-cloak>
                                    <div class="form-group group">
                                        <select
                                            x-model.boolean="property.valueBoolean"
                                            :id="`devicePropertyBoolean-${propertyIndex}`"
                                            class="input-select peer"
                                        >
                                            <option value="false" selected>{{ __('forms.no') }}</option>
                                            <option value="true">{{ __('forms.yes') }}</option>
                                        </select>
                                        <label :for="`devicePropertyBoolean-${propertyIndex}`" class="label">
                                            {{ __('devices.value') }}
                                        </label>
                                    </div>
                                </div>

                                <div class="form-row-2" x-show="property.valueType === 'integer'" x-cloak>
                                    <div class="form-group group">
                                        <input
                                            type="number"
                                            step="1"
                                            x-model.number="property.valueInteger"
                                            :id="`devicePropertyInteger-${propertyIndex}`"
                                            class="input peer"
                                            placeholder=" "
                                        />
                                        <label :for="`devicePropertyInteger-${propertyIndex}`" class="label">
                                            {{ __('devices.value') }}
                                        </label>
                                    </div>
                                </div>

                                <div class="form-row-2" x-show="property.valueType === 'string'" x-cloak>
                                    <div class="form-group group">
                                        <input
                                            type="text"
                                            x-model="property.valueString"
                                            :id="`devicePropertyString-${propertyIndex}`"
                                            class="input peer"
                                            placeholder=" "
                                        />
                                        <label :for="`devicePropertyString-${propertyIndex}`" class="label">
                                            {{ __('devices.value') }}
                                        </label>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    @click.prevent="removeProperty(propertyIndex)"
                                    class="absolute top-3 right-0 text-gray-400 transition-colors hover:text-red-500 dark:text-gray-500"
                                >
                                    @icon('delete', 'w-6 h-6')
                                </button>
                            </div>
                        </div>
                    </template>

                    <div class="mt-2 mb-6">
                        <button
                            type="button"
                            @click.prevent="addProperty()"
                            class="flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                        >
                            + {{ __('devices.add_property') }}
                        </button>
                    </div>

                    <div class="mt-6 border-t border-gray-100 pt-6 dark:border-gray-700">
                        <div class="mb-6 flex items-center gap-6">
                            <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('patients.information_source') }}</span>
                            <div class="flex items-center gap-4">
                                <label class="flex cursor-pointer items-center gap-2">
                                    <input
                                        type="radio"
                                        name="primarySource"
                                        x-model.boolean="modalDevice.primarySource"
                                        :checked="modalDevice.primarySource === true"
                                        @change="
                                            modalDevice.reportOriginCode = '';
                                            modalDevice.reportOriginText = '';
                                        "
                                        value="true"
                                        class="default-radio"
                                    />
                                    <span class="text-sm">{{ __('patients.performer') }}</span>
                                </label>
                                <label class="flex cursor-pointer items-center gap-2">
                                    <input
                                        type="radio"
                                        name="primarySource"
                                        x-model.boolean="modalDevice.primarySource"
                                        :checked="modalDevice.primarySource === false"
                                        value="false"
                                        class="default-radio"
                                    />
                                    <span class="text-sm">{{ __('patients.other_source') }}</span>
                                </label>
                            </div>
                        </div>

                        <div class="form-row-2" x-show="modalDevice.primarySource === false" x-cloak>
                            <div class="form-group group">
                                <select
                                    x-model="modalDevice.reportOriginCode"
                                    id="deviceReportOrigin"
                                    class="input-select peer"
                                    required
                                >
                                    <option value="" selected>{{ __('forms.select') }}</option>
                                    @foreach ($this->dictionaries['eHealth/report_origins'] as $code => $reportOrigin)
                                        <option value="{{ $code }}">{{ $reportOrigin }}</option>
                                    @endforeach
                                </select>
                                <label for="deviceReportOrigin" class="label">{{ __('patients.source_link') }}</label>
                            </div>
                        </div>

                        <div class="form-row-1 mt-4">
                            <div>
                                <label for="deviceNote" class="label-modal mb-2 block">{{ __('forms.comment') }}</label>
                                <div>
                                    <textarea
                                        x-model="modalDevice.note"
                                        rows="4"
                                        id="deviceNote"
                                        name="deviceNote"
                                        class="textarea"
                                        placeholder="{{ __('forms.write_comment_here') }}"
                                    ></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </fieldset>
                <div class="mt-6 flex w-full justify-start space-x-4">
                    <button type="button" @click="openDeviceDrawer = false" class="button-minor">
                        {{ __('forms.cancel') }}
                    </button>

                    @unless ($isReadonly ?? false)
                        <button
                            type="button"
                            @click.prevent="
                                newDevice !== false
                                    ? devices.push(modalDevice)
                                    : devices[item] = modalDevice;
                                openDeviceDrawer = false;
                            "
                            class="button-primary"
                            :disabled="!(
                                modalDevice.typeCode?.trim?.() &&
                                modalDevice.status?.trim?.() &&
                                modalDevice.names.every(
                                    (name) => name.type?.trim?.() && name.value?.trim?.()
                                ) &&
                                (modalDevice.primarySource || modalDevice.reportOriginCode?.trim?.())
                            )"
                        >
                            {{ __('forms.add') }}
                        </button>
                    @endunless
                </div>
            </fieldset>
        </form>
    </x-dialog-drawer>
</div>

<script>
    /**
     * The value a device property carries, by the type it was given
     */
    const DEVICE_PROPERTY_VALUE_KEYS = {
        codeable_concept: 'valueCodeableConceptCode',
        quantity: 'valueQuantityValue',
        range: 'valueRangeLowValue',
        boolean: 'valueBoolean',
        integer: 'valueInteger',
        string: 'valueString',
    };

    /**
     * An empty device property, carrying no value yet
     */
    function newDeviceProperty() {
        return {
            code: '',
            valueType: '',
            valueCodeableConceptSystem: null,
            valueCodeableConceptCode: null,
            valueQuantityValue: null,
            valueQuantityComparator: null,
            valueQuantityUnit: null,
            valueRangeLowValue: null,
            valueRangeLowUnit: null,
            valueRangeHighValue: null,
            valueRangeHighUnit: null,
            valueBoolean: null,
            valueInteger: null,
            valueString: null,
        };
    }

    /**
     * Read back the type of a stored property from the value it was saved with
     */
    function devicePropertyValueType(property) {
        return Object.keys(DEVICE_PROPERTY_VALUE_KEYS)
            .find(valueType => property[DEVICE_PROPERTY_VALUE_KEYS[valueType]] !== null
                && property[DEVICE_PROPERTY_VALUE_KEYS[valueType]] !== undefined) ?? '';
    }

    /**
     * Representation of the patient's associated medical device
     */
    class Device {
        constructor(obj = null) {
            this.uuid = obj?.uuid || crypto.randomUUID();
            this.status = 'active';
            this.typeCode = '';
            this.names = [];
            this.modelNumber = '';
            this.lotNumber = '';
            this.manufacturer = '';
            this.serialNumber = '';
            this.manufactureDate = '';
            this.expirationDate = '';
            this.note = '';
            this.primarySource = true;
            this.reportOriginCode = '';
            this.reportOriginText = '';
            this.properties = [];
            this.definitionId = '';
            this.parentId = '';
            this.identifiers = [];

            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }

            // A device is named at least once, and the identifier row is always offered to fill in
            if (this.names.length === 0) {
                this.names.push({ type: '', value: '' });
            }

            if (this.identifiers.length === 0) {
                this.identifiers.push({ code: '', text: '', value: '' });
            }

            this.properties = this.properties.map(property => ({
                ...newDeviceProperty(),
                ...property,
                valueType: devicePropertyValueType(property),
            }));
        }
    }
</script>
