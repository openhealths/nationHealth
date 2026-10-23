<div class="shift-content space-y-5 p-6">
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    <x-forms.loading />
    <h1 class="text-xl font-bold">{{ __('device-requests.form.title') }} — {{ $person->fullName }}</h1>
    @if ($carePlanUuid)
        <p>{{ __('device-requests.plan.title') }}: {{ $carePlanUuid }} · {{ __('device-requests.form.activity_device') }}: {{ $activityUuid }}</p>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded bg-red-50 p-4 text-red-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif
    <form wire:submit="createDraft" novalidate class="space-y-5">
        <div class="grid gap-4 md:grid-cols-2">
            <label
                >{{ __('device-requests.form.encounter') }}
                <select wire:model="request.encounter_uuid" class="input-select w-full @error('request.encounter_uuid') input-error @enderror">
                    <option value="">{{ __('device-requests.form.choose_encounter') }}</option>
                    @foreach ($encounters as $encounter)
                        <option value="{{ $encounter['uuid'] }}">{{ $encounter['label'] }}</option>
                    @endforeach
                </select>
                <x-input-error for="request.encounter_uuid" />
            </label>
            <label
                >{{ __('device-requests.form.program') }}
                <select wire:model.live="request.program_id" class="input-select w-full @error('request.program_id') input-error @enderror" @disabled($activityUuid)>
                    <option value="">{{ __('device-requests.form.without_program') }}</option>
                    @foreach ($programs as $program)
                        <option value="{{ $program['id'] }}">{{ $program['name'] }}</option>
                    @endforeach
                </select>
                <x-input-error for="request.program_id" />
            </label>
            <label
                >{{ __('device-requests.form.device_selection') }}
                <select wire:model.live="request.device_code_type" class="input-select w-full @error('request.device_code_type') input-error @enderror" @disabled($activityUuid)>
                    <option value="CLASSIFICATION_TYPE">{{ __('device-requests.form.by_type') }}</option>
                    <option value="DEVICE_DEFINITION">{{ __('device-requests.form.by_model') }}</option>
                </select>
                <x-input-error for="request.device_code_type" />
            </label>
            @if (!$activityUuid && $request['device_code_type'] === 'CLASSIFICATION_TYPE')
                <label
                    >{{ __('device-requests.form.device_type') }}
                    <select class="input-select w-full @error('request.device_id') input-error @enderror" wire:change="selectType($event.target.value)">
                        <option value="">{{ __('device-requests.form.choose_type') }}</option>
                        @foreach ($types as $key => $type)
                            <option
                                value="{{ $key }}"
                                @selected($request['device_code_system'].'|'.$request['device_id'] === $key)
                            >
                                {{ $type['label'] }} · {{ $type['code'] }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <x-input-error for="request.device_id" />
            @elseif (!$activityUuid)
                <div>
                    <label>{{ __('device-requests.form.model_name') }}<input wire:model="modelSearch" class="input w-full" /></label
                    ><button type="button" wire:click="searchModels" class="button-minor">{{ __('device-requests.form.search_models') }}</button>
                    <x-input-error for="modelSearch" />
                    <select class="input-select w-full" wire:change="selectModel($event.target.value)">
                        <option value="">{{ __('device-requests.form.choose_model') }}</option>
                        @foreach ($models as $model)
                            <option value="{{ $model['id'] }}" @selected($request['device_id'] === $model['id'])>
                                {{ data_get($model, 'device_names.0.name', $model['model_number'] ?? $model['id']) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <x-input-error for="request.device_id" />
            @else
                <p>{{ __('device-requests.form.activity_device') }}: {{ $request['device_id'] }}</p>
            @endif
            @if (!empty($options['model']))
                <p>
                    {{ __('device-requests.form.selected_model') }}: {{ data_get($options, 'model.device_names.0.name', data_get($options, 'model.model_number', $request['device_id'])) }}
                </p>
            @endif
            @foreach (['started_at' => 'start', 'ended_at' => 'end'] as $field => $label)
                <div class="form-group group">
                    <div class="datepicker-wrapper" wire:key="request-date-{{ $field }}" x-data>
                        <input type="text" id="request-{{ $field }}" wire:model.lazy="request.{{ $field }}"
                               class="datepicker-input with-leading-icon input peer @error('request.'.$field) input-error @enderror"
                               placeholder=" " autocomplete="off" datepicker-autohide datepicker-format="{{ frontendDateFormat() }}"
                               datepicker-min-date="{{ today()->format(config('app.date_format')) }}"
                               x-on:change-date.camel="$el.dispatchEvent(new Event('change', { bubbles: true }))" />
                        <label for="request-{{ $field }}" class="wrapped-label">{{ __('device-requests.form.'.$label) }}</label>
                    </div>
                    <x-input-error for="request.{{ $field }}" />
                </div>
            @endforeach
            @if ($request['program_id'])
                <div>
                    <label
                        >{{ __('device-requests.form.package_quantity') }}
                        <select class="input-select w-full" wire:change="selectQuantity($event.target.value)">
                            <option value="">{{ __('device-requests.form.choose_quantity') }}</option>
                            @foreach ($quantityOptions as $key => $quantity)
                                <option
                                    value="{{ $key }}"
                                    @selected($request['quantity_code'].'|'.$request['quantity'] === $key)
                                >
                                    {{ $quantity['quantity'] }} {{ $units[$quantity['unit']] ?? $quantity['unit'] }}
                                </option>
                            @endforeach</select
                    ></label>
                    <button type="button" class="button-minor" wire:click="moreQuantities">
                        {{ __('device-requests.form.more_quantities') }}
                    </button>
                    <p>{{ __('device-requests.form.quantity_hint') }}</p>
                </div>
            @else
                <label
                    >{{ __('device-requests.form.optional_quantity') }}<input
                        type="number"
                        min="1"
                        step="1"
                        wire:model="request.quantity"
                        class="input w-full @error('request.quantity') input-error @enderror"
                /></label>
                <label>{{ __('device-requests.form.unit') }}
                    <select wire:model="request.quantity_code" class="input-select w-full @error('request.quantity_code') input-error @enderror">
                        <option value="">{{ __('device-requests.form.choose_unit') }}</option>
                        @foreach ($units as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                    </select>
                    <x-input-error for="request.quantity_code" />
                </label>
            @endif
            <x-input-error for="request.quantity" />
            <label
                >{{ __('device-requests.form.auth_method') }}
                <select wire:model.live="request.inform_with" class="input-select w-full @error('request.inform_with') input-error @enderror">
                    <option value="">{{ __('device-requests.form.default_auth_method') }}</option>
                    @foreach ($authMethods as $method)
                        <option value="{{ $method['id'] }}">
                            {{ __('device-requests.auth_types.'.($method['type'] ?? 'NA')) }} {{ $method['phone_number'] ?? '' }}
                        </option>
                    @endforeach
                </select>
                <x-input-error for="request.inform_with" />
            </label>
            <div>
                @php
                    $chosenMethod = collect($authMethods)->firstWhere('id', $request['inform_with']);
                    if (!$request['inform_with']) {
                        $chosenMethod = collect($authMethods)->firstWhere('type', 'OTP') ?? ($authMethods[0] ?? []);
                    }
                @endphp
                @if (!empty($chosenMethod['phone_number']))
                    <p>{{ __('device-requests.form.phone') }}: {{ $chosenMethod['phone_number'] }}</p>
                @endif
                @if (!empty($chosenMethod['phone_number']))
                <p>{{ __('device-requests.form.phone_hint') }}</p>
                <button type="button" wire:click="confirmPhone" class="button-minor">
                    {{ __('device-requests.form.confirm_phone') }}
                </button>
                @if ($request['phone_confirmed'])
                    <p>{{ __('device-requests.form.phone_confirmed') }}</p>
                @endif
                <x-input-error for="request.phone_confirmed" />
                @endif
            </div>
        </div>
        <fieldset class="space-y-3">
            <legend class="font-semibold">{{ __('device-requests.form.reasons') }}</legend>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('device-requests.form.reason_hint') }}</p>
            @foreach ($request['reason_reference'] as $index => $reason)
                <div wire:key="reason-{{ $index }}-{{ $reason['type'] }}" class="grid items-start gap-3 md:grid-cols-[1fr_2fr_auto]">
                    <div class="form-group group">
                        <select id="reason-type-{{ $index }}" wire:model.live="request.reason_reference.{{ $index }}.type" class="input-select peer @error('request.reason_reference.'.$index.'.type') input-error @enderror">
                            @foreach (__('device-requests.reason_types') as $type => $label)
                                <option value="{{ $type }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <label for="reason-type-{{ $index }}" class="label">{{ __('device-requests.form.reason_type') }}</label>
                        <x-input-error for="request.reason_reference.{{ $index }}.type" />
                    </div>
                    <div class="form-group group">
                        <select id="reason-record-{{ $index }}" wire:model="request.reason_reference.{{ $index }}.uuid" class="input-select peer @error('request.reason_reference.'.$index.'.uuid') input-error @enderror">
                            <option value="">{{ __('device-requests.form.choose_reason') }}</option>
                            @foreach ($reasonOptions[$reason['type']] ?? [] as $uuid => $label)
                                <option value="{{ $uuid }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <label for="reason-record-{{ $index }}" class="label">{{ __('device-requests.form.reason_record') }}</label>
                        <x-input-error for="request.reason_reference.{{ $index }}.uuid" />
                        @if (empty($reasonOptions[$reason['type']]))
                            <p class="text-sm text-gray-500">{{ __('device-requests.form.no_reasons') }}</p>
                        @endif
                    </div>
                    <button type="button" wire:click="removeReason({{ $index }})" class="button-minor">{{ __('device-requests.form.remove') }}</button>
                </div>
            @endforeach
            <button type="button" wire:click="addReason" class="button-minor">{{ __('device-requests.form.add_reason') }}</button>
        </fieldset>
        <fieldset class="grid gap-4 md:grid-cols-2">
            <legend class="font-semibold">{{ __('device-requests.form.parameters') }}</legend>
            @foreach ($options['parameters'] ?? [] as $key => $parameter)
                <label wire:key="parameter-{{ $key }}"
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
                            <select wire:model="request.parameter_values.{{ $key }}.code" class="input-select w-full" aria-label="{{ __('device-requests.form.unit') }}">
                                <option value="">{{ __('device-requests.form.choose_unit') }}</option>
                                @foreach ($measurementUnits as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach
                            </select>
                        @else
                            <input wire:model="request.parameter_values.{{ $key }}.code" class="input w-full" placeholder="{{ __('device-requests.form.unit') }} (cm, kg)" />
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
                                <select wire:model="request.parameter_values.{{ $key }}.{{ $bound }}.code" class="input-select w-full" aria-label="{{ $label }}: {{ __('device-requests.form.unit') }}">
                                    <option value="">{{ __('device-requests.form.choose_unit') }}</option>
                                    @foreach ($measurementUnits as $code => $unitLabel)<option value="{{ $code }}">{{ $unitLabel }}</option>@endforeach
                                </select>
                            @else
                                <input wire:model="request.parameter_values.{{ $key }}.{{ $bound }}.code" class="input w-full" placeholder="{{ __('device-requests.form.unit') }} (cm, kg)" />
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
        </fieldset>
        <p>{{ __('device-requests.form.review_hint') }}</p>
        <div class="flex gap-3">
            <button type="submit" class="button-primary" wire:loading.attr="disabled">
                {{ __('device-requests.form.save_draft') }}</button
            ><button type="button" wire:click="openSignatureModal" class="button-primary" wire:loading.attr="disabled">
                {{ __('device-requests.form.sign_create') }}</button
            ><a
                class="button-minor"
                href="{{ route('persons.device-requests', ['legalEntity' => $legalEntity, 'person' => $person]) }}"
                >{{ __('device-requests.form.back') }}</a>
        </div>
    </form>
    <x-signature-modal method="sign" />
</div>
