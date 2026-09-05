<div>
    <section class="section-form p-6">
        <x-header-navigation class="breadcrumb-form" title="{{ data_get($device, 'name') }}">
            <x-slot name="title">{{ data_get($device, 'name') }}</x-slot>
        </x-header-navigation>

        <div class="form shift-content">
            <fieldset class="fieldset">
                <legend class="legend">{{ __('forms.main_information') }}</legend>
                
                <div class="form-row-2">
                    <div class="form-group group">
                        <input type="text" class="input peer" value="{{ data_get($device, 'type') }}" disabled />
                        <label class="label">{{ __('devices.type') }}*</label>
                    </div>
                    <div class="form-group group">
                        <input type="text" class="input peer" value="{{ data_get($device, 'name') }}" disabled />
                        <label class="label">{{ __('devices.name') }}*</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input type="text" class="input peer" value="{{ data_get($device, 'status') }}" disabled />
                        <label class="label">{{ __('forms.status.label') }}</label>
                    </div>
                    <div class="form-group group">
                        <input type="text" class="input peer" value="{{ data_get($device, 'error_reason') }}" disabled />
                        <label class="label">{{ __('devices.entered_in_error_reason') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input type="text" class="input peer" value="{{ data_get($device, 'encounter_id') }}" disabled />
                        <label class="label">{{ __('devices.encounter_id_label') }}</label>
                    </div>
                    <div class="form-group group">
                        <input type="text" class="input peer" value="{{ data_get($device, 'device_id') }}" disabled />
                        <label class="label">{{ __('devices.device_id_label') }}</label>
                    </div>
                </div>
            </fieldset>

            <fieldset class="fieldset mt-8">
                <legend class="legend">{{ __('forms.additional_information') }}</legend>
                
                <div class="form-row-2">
                    <div class="form-group group">
                        <input type="text" class="input peer" value="{{ data_get($device, 'model') }}" disabled />
                        <label class="label">{{ __('devices.model_number') }}</label>
                    </div>
                    <div class="form-group group">
                        <input type="text" class="input peer" value="{{ data_get($device, 'model_ref') }}" disabled />
                        <label class="label truncate max-w-full" title="{{ __('devices.definition_full') }}">{{ __('devices.definition_full') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input type="text" class="input peer" value="{{ data_get($device, 'manufacturer') }}" disabled />
                        <label class="label">{{ __('devices.manufacturer') }}</label>
                    </div>
                    <div class="form-group group">
                        <input type="text" class="input peer" value="{{ data_get($device, 'serial_number') }}" disabled />
                        <label class="label">{{ __('devices.serial_number') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input type="text" class="input peer" value="{{ data_get($device, 'lot_number') }}" disabled />
                        <label class="label">{{ __('devices.lot_number') }}</label>
                    </div>
                    <div class="form-group group">
                        <input type="text" class="input peer" value="{{ data_get($device, 'serial_number') }}" disabled />
                        <label class="label">{{ __('devices.serial_number') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input type="text" class="input peer" value="{{ data_get($device, 'manufacture_date') }}" disabled />
                        <label class="label">{{ __('devices.manufacture_date') }}</label>
                    </div>
                    <div class="form-group group">
                        <input type="text" class="input peer" value="{{ data_get($device, 'expiration_date') }}" disabled />
                        <label class="label">{{ __('devices.expiration_date') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input type="text" class="input peer" value="{{ data_get($device, 'external_id') }}" disabled />
                        <label class="label">{{ __('devices.external_system_identifier') }}</label>
                    </div>
                    <div class="form-group group">
                        <input type="text" class="input peer" value="{{ data_get($device, 'parent_device') }}" disabled />
                        <label class="label">{{ __('devices.parent') }}</label>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group group">
                        <input type="text" class="input peer" value="{{ data_get($device, 'additional_property') }}" disabled />
                        <label class="label">{{ __('devices.property') }}</label>
                    </div>
                    <div class="form-group group">
                        <input type="text" class="input peer" value="{{ data_get($device, 'practitioner') }}" disabled />
                        <label class="label">{{ __('devices.practitioner') }}</label>
                    </div>
                </div>

                <div class="form-row-2 mb-4" x-data="{ isOtherSource: false }">
                    <div class="form-group group">
                        <div class="flex items-center gap-4 pt-2">
                            <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('devices.source_data') }}</span>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" :checked="isOtherSource" @click="isOtherSource = true" class="default-radio cursor-pointer">
                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ __('devices.other_source') }}</span>
                            </label>
                        </div>
                    </div>
                    <div class="form-group group" x-show="isOtherSource">
                        <div class="flex items-end gap-2">
                            <div class="flex-1 relative">
                                <input type="text" class="input peer w-full" value="{{ data_get($device, 'source_ref') }}" disabled />
                                <label class="label">{{ __('devices.source_reference') }}</label>
                            </div>
                            <button type="button" @click="isOtherSource = false" class="mb-2 p-1 text-gray-400 hover:text-red-500 transition-colors" title="Видалити">
                                @icon('trash', 'w-5 h-5')
                            </button>
                        </div>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input type="text" class="datepicker-input with-leading-icon input peer" value="{{ data_get($device, 'created_at') }}" placeholder=" " disabled />
                            <label class="wrapped-label">{{ __('devices.created_at_system') }}</label>
                        </div>
                    </div>
                    <div class="form-group group !w-1/2">
                        <div class="relative flex items-center">
                            @icon('mingcute-time-fill', 'svg-input left-2.5')
                            <input type="text" class="input peer !pl-10" value="{{ data_get($device, 'created_time') }}" placeholder=" " disabled />
                        </div>
                    </div>
                </div>

                <div class="form-row-3">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input type="text" class="datepicker-input with-leading-icon input peer" value="{{ data_get($device, 'updated_at') }}" placeholder=" " disabled />
                            <label class="wrapped-label">{{ __('devices.updated_at_system') }}</label>
                        </div>
                    </div>
                    <div class="form-group group !w-1/2">
                        <div class="relative flex items-center">
                            @icon('mingcute-time-fill', 'svg-input left-2.5')
                            <input type="text" class="input peer !pl-10" value="{{ data_get($device, 'updated_time') }}" placeholder=" " disabled />
                        </div>
                    </div>
                </div>

                <div class="form-row mt-4">
                    <div class="form-group group">
                        <label class="label-modal mb-1">{{ __('devices.notes') }}</label>
                        <textarea class="textarea" disabled rows="4">{{ data_get($device, 'notes') }}</textarea>
                    </div>
                </div>
            </fieldset>

            <div class="mt-8">
                <a href="{{ $personId ? route('persons.devices', [legalEntity(), 'person' => $personId]) : route('prepersons.devices', [legalEntity(), 'preperson' => $prepersonId]) }}" class="button-minor px-6 py-2">{{ __('forms.back') }}</a>
            </div>
        </div>
    </section>
</div>
