@php
    $procedureErrorPath = 'procedureForm.procedures.*';
@endphp

<div
    x-show="modalProcedure.status === 'completed'"
    x-effect="
        if (modalProcedure.status !== 'completed' && modalProcedure.focalDevice.length) {
            modalProcedure.focalDevice = [];
        }
    "
    x-cloak
>
    <fieldset class="fieldset">
        <legend class="legend">{{ __('procedures.associated_medical_device') }}</legend>

        <div class="space-y-4">
            <template x-for="(focalDevice, index) in modalProcedure.focalDevice" :key="index">
                <div class="flex items-start gap-3">
                    <div class="form-row-2 flex-1">
                        <div
                            class="form-group group relative"
                            x-data="{ open: false }"
                            :style="{ zIndex: open ? 100 : 0 }"
                            @click.outside="open = false"
                            @keydown.escape.window="open = false"
                        >
                            <button
                                type="button"
                                :id="`procedureFocalDevice${index}`"
                                @click="open = ! open"
                                class="input-select peer w-full appearance-none bg-none text-left"
                            >
                                <span
                                    class="block truncate"
                                    x-text="focalDeviceById(focalDevice.manipulatedId)?.name || '{{ __('forms.select') }}'"
                                ></span>
                            </button>

                            @icon('chevron-down', 'pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-gray-400 dark:text-gray-500')

                            <label :for="`procedureFocalDevice${index}`" class="label">
                                {{ __('procedures.medical_device') }} *
                            </label>

                            <div
                                x-show="open"
                                x-cloak
                                class="absolute top-full left-0 z-50 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-gray-200 bg-white p-1.5 shadow-lg dark:border-gray-600 dark:bg-gray-700"
                            >
                                <template x-if="focalDeviceOptions().length === 0">
                                    <div class="px-3 py-2 text-sm text-gray-500 dark:text-gray-400">
                                        {{ __('procedures.medical_devices_not_found') }}
                                    </div>
                                </template>

                                <template x-for="device in focalDeviceOptions()" :key="device.uuid">
                                    <button
                                        type="button"
                                        @click="
                                            focalDevice.manipulatedId = device.uuid;
                                            open = false;
                                        "
                                        class="w-full cursor-pointer rounded-md px-3 py-2 text-left hover:bg-gray-100 dark:hover:bg-gray-600"
                                        :class="{
                                            'bg-gray-100 dark:bg-gray-600': focalDevice.manipulatedId === device.uuid,
                                        }"
                                    >
                                        <span
                                            class="block text-sm font-medium text-gray-900 dark:text-white"
                                            x-text="device.name"
                                        ></span>

                                        <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                                            <span
                                                x-show="device.serialNumber"
                                                x-text="`SN: ${device.serialNumber}. `"
                                            ></span>
                                            <span>
                                                {{ __('forms.status.label') }}: {{ __('devices.status.active') }}
                                            </span>
                                        </span>
                                    </button>
                                </template>
                            </div>

                            @error($procedureErrorPath . '.focalDevice.*.manipulatedId')
                                <p class="text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="form-group group">
                            <select
                                x-model="focalDevice.actionCode"
                                :id="`procedureFocalDeviceAction${index}`"
                                class="input-select peer"
                            >
                                <option value="">{{ __('forms.select') }}</option>
                                @foreach ($this->dictionaries['procedure_focal_device_actions'] as $code => $action)
                                    <option value="{{ $code }}">{{ $action }}</option>
                                @endforeach
                            </select>
                            <label :for="`procedureFocalDeviceAction${index}`" class="label">
                                {{ __('procedures.medical_device_action') }}
                            </label>
                            @error($procedureErrorPath . '.focalDevice.*.actionCode')
                                <p class="text-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <button
                        type="button"
                        @click.prevent="removeFocalDevice(index)"
                        class="text-error shrink-0 hover:opacity-80"
                    >
                        @icon('delete', 'w-5 h-5')
                    </button>
                </div>
            </template>
        </div>

        <button type="button" @click.prevent="addFocalDevice()" class="item-add mt-4">
            {{ __('procedures.add_associated_medical_device') }}
        </button>
    </fieldset>
</div>
