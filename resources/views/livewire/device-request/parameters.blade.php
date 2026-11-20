            @foreach ($options['parameters'] ?? [] as $key => $parameter)
                <label class="block text-xs text-gray-500 dark:text-gray-400" wire:key="parameter-{{ $key }}"
                    >{{ $parameter['label'] ?? $parameter['code'] }} {{ $parameter['required'] ? '*' : '' }}
                    @if ($parameter['type'] === 'boolean')
                        <select wire:model="request.parameter_values.{{ $key }}" class="input-select w-full">
                            <option value="">{{ __('device-requests.form.unset') }}</option>
                            <option value="1">{{ __('device-requests.form.yes') }}</option>
                            <option value="0">{{ __('device-requests.form.no') }}</option>
                        </select>
                    @elseif ($parameter['type'] === 'codeable_concept')
                        <select wire:model="request.parameter_values.{{ $key }}" class="input-select w-full">
                            <option value="">{{ __('device-requests.form.choose_value') }}</option>
                            @foreach ($parameter['values'] as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @elseif ($parameter['type'] === 'quantity')
                        <input
                            type="number"
                            step="any"
                            wire:model="request.parameter_values.{{ $key }}.value"
                            class="input w-full"
                            aria-label="{{ $parameter['label'] ?? $parameter['code'] }}: {{ __('device-requests.form.value') }}"
                        />
                        @if ($measurementUnits)
                            <select
                                wire:model="request.parameter_values.{{ $key }}.code"
                                class="input-select w-full"
                                aria-label="{{ __('device-requests.form.unit') }}"
                            >
                                <option value="">{{ __('device-requests.form.choose_unit') }}</option>
                                @foreach ($measurementUnits as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        @else
                            <input
                                wire:model="request.parameter_values.{{ $key }}.code"
                                class="input w-full"
                                placeholder="{{ __('device-requests.form.unit') }} (cm, kg)"
                            />
                        @endif
                    @elseif ($parameter['type'] === 'range')
                        @foreach (['low' => __('device-requests.form.minimum'), 'high' => __('device-requests.form.maximum')] as $bound => $label)
                            <span>{{ $label }}</span
                            ><input
                                type="number"
                                step="any"
                                wire:model="request.parameter_values.{{ $key }}.{{ $bound }}.value"
                                class="input w-full"
                                aria-label="{{ $parameter['label'] ?? $parameter['code'] }}: {{ $label }}"
                            />
                            @if ($measurementUnits)
                                <select
                                    wire:model="request.parameter_values.{{ $key }}.{{ $bound }}.code"
                                    class="input-select w-full"
                                    aria-label="{{ $label }}: {{ __('device-requests.form.unit') }}"
                                >
                                    <option value="">{{ __('device-requests.form.choose_unit') }}</option>
                                    @foreach ($measurementUnits as $code => $unitLabel)
                                        <option value="{{ $code }}">{{ $unitLabel }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input
                                    wire:model="request.parameter_values.{{ $key }}.{{ $bound }}.code"
                                    class="input w-full"
                                    placeholder="{{ __('device-requests.form.unit') }} (cm, kg)"
                                />
                            @endif
                        @endforeach
                    @else
                        <input wire:model="request.parameter_values.{{ $key }}" class="input w-full" />
                    @endif
                    <x-input-error for="request.parameter_values.{{ $key }}" />
                    @foreach (['value', 'code', 'system', 'low.value', 'low.code', 'low.system', 'high.value', 'high.code', 'high.system'] as $part)
                        <x-input-error for="request.parameter_values.{{ $key }}.{{ $part }}" />
                    @endforeach
                </label>
            @endforeach
