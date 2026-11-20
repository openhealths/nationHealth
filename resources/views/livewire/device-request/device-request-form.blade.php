@php
    // The form already supplies the catalog and the records available to this patient.
    $recordRows = [];
    foreach ($reasonOptions as $type => $choices) {
        foreach ($choices as $uuid => $label) {
            $parts = explode(' · ', $label);
            $date = count($parts) > 1 ? array_pop($parts) : '';
            $recordRows[$type.'|'.$uuid] = [
                'type' => $type, 'uuid' => $uuid, 'typeLabel' => __('device-requests.reason_types.'.$type),
                'label' => implode(' · ', $parts), 'date' => $date,
            ];
        }
    }

    $planChoices = $planChoices ?? [];
    $selectedProgram = collect($programs)->firstWhere('id', $request['program_id']) ?? [];
    $programDetails = ['funding_source' => $selectedProgram['funding_source'] ?? '—'];
    foreach (['employee_types_to_create_request', 'speciality_types_request_allowed', 'skip_treatment_period', 'request_max_period_day', 'skip_request_employee_declaration_verify', 'skip_request_legal_entity_declaration_verify'] as $field) {
        $value = $selectedProgram['medical_program_settings'][$field] ?? '—';
        $programDetails[$field] = is_bool($value) ? __($value ? 'forms.yes' : 'forms.no') : (is_array($value) ? implode(', ', $value) : $value);
    }

    $deviceLabel = $request['device_code_type'] === 'DEVICE_DEFINITION'
        ? (data_get($options, 'model.device_names.0.name') ?: data_get($options, 'model.model_number'))
        : ($types[$request['device_code_system'].'|'.$request['device_id']]['label'] ?? null);
    $deviceLabel = filled($deviceLabel) ? $deviceLabel : ($request['device_id'] ?? '');
@endphp
<div
    x-data="{
        deviceSearch: false, recordSearch: false, catalogQuery: '', programDetails: false,
        linkedPlan: @js((bool) $carePlanUuid), usePlan: @js((bool) $carePlanUuid),
        plans: @js($planChoices), plan: @js($carePlanUuid ?? ''), activity: @js($activityUuid ?? ''),
        isMedicalRecordAdded(uuid) {
            return ($wire.request.reason_reference || []).some(record => record.uuid === uuid);
        },
        addReasonRecord(record) {
            if (!this.isMedicalRecordAdded(record.uuid)) {
                $wire.$set('request.reason_reference', [...($wire.request.reason_reference || []), { type: record.type, uuid: record.uuid }]);
            }
            this.recordSearch = false;
        }
    }"
    @device-request-medical-record-selected.window="addReasonRecord($event.detail.record)"
>
    <x-layouts.patient :personId="$personId" :patientFullName="$person->fullName" :hideNavigation="true" :showLegacyMessages="false" :title="__('device-requests.form.title').' — '.$person->fullName">
        <x-slot name="headerActions"></x-slot>
        <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
        <x-forms.loading />
        <div class="breadcrumb-form shift-content p-4">
            <form @submit.prevent="if (!usePlan || linkedPlan) $wire.createDraft()" novalidate class="care-plan-service-form device-request-form mt-6 pb-10">
                @if ($errors->any())<div role="alert" class="mb-8 rounded-lg bg-red-50 p-4 text-sm text-red-800 dark:bg-gray-800 dark:text-red-300">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
                <fieldset>
                    <legend>{{ __('device-requests.care_plan') }}</legend>
                    <label class="flex items-center gap-3 text-sm">
                        <input id="request-use-care-plan" type="checkbox" class="default-checkbox" x-model="usePlan"
                            @change="if (!usePlan) { plan = ''; activity = ''; }" @checked($carePlanUuid) @disabled($carePlanUuid) aria-controls="request-plan-fields" />
                        {{ __('device-requests.create_care_plan') }}
                    </label>
                    <div id="request-plan-fields" x-show="usePlan" x-cloak class="mt-6 space-y-6 max-w-sm">
                        <div class="form-group group">
                            <select id="request-care-plan" class="input-select peer" x-model="plan" @change="activity = ''"
                                @disabled(!$planChoices || $carePlanUuid)>
                                <option value="{{ $carePlanUuid }}">{{ $carePlanUuid ?: __('device-requests.choose_care_plan') }}</option>
                                @foreach ($planChoices as $uuid => $choice)
                                    <option value="{{ $uuid }}">{{ $choice['label'] }}</option>
                                @endforeach
                            </select>
                            <label for="request-care-plan" class="label">{{ __('device-requests.care_plan') }}</label>
                            <x-input-error for="request.carePlan" />
                        </div>
                        <div class="form-group group">
                            <select id="request-care-plan-activity" class="input-select peer" x-model="activity"
                                @disabled(!$planChoices || $carePlanUuid)
                                @if ($planChoices && !$carePlanUuid) :disabled="!plan || !Object.keys(plans[plan]?.activities || {}).length" @endif>
                                <option value="{{ $activityUuid }}">{{ $activityUuid ?: __('forms.select') }}</option>
                                <template x-for="(label, uuid) in (plans[plan]?.activities || {})" :key="uuid">
                                    <option :value="uuid" x-text="label"></option>
                                </template>
                            </select>
                            <label for="request-care-plan-activity" class="label">{{ __('care-plan.activities') }}</label>
                            <x-input-error for="request.activity" />
                        </div>
                        @if (!$carePlanUuid && !$planChoices)
                            <p class="text-sm text-gray-500">{{ __('device-requests.open_from_plan') }}</p>
                        @endif
                    </div>
                </fieldset>
                <fieldset><legend>{{ __('device-requests.encounter') }}</legend>
                    <div class="form-group group max-w-sm"><select id="request-encounter" wire:model="request.encounter_uuid" class="input-select peer"><option value="">{{ __('device-requests.form.choose_encounter') }}</option>@foreach ($encounters as $encounter)<option value="{{ $encounter['uuid'] }}">{{ $encounter['label'] }}</option>@endforeach</select><label for="request-encounter" class="label">{{ __('device-requests.encounter') }}</label><x-input-error for="request.encounter_uuid" /></div>
                </fieldset>
                <fieldset><legend>{{ __('device-requests.program') }}</legend>
                    <div class="flex items-end gap-4 max-w-sm"><div class="form-group group flex-1"><select id="request-program" wire:model.live="request.program_id" @change="programDetails = false" class="input-select peer" @disabled($activityUuid)><option value="">{{ __('device-requests.without_program') }}</option>@foreach ($programs as $program)<option value="{{ $program['id'] }}">{{ $program['name'] }}</option>@endforeach</select><label for="request-program" class="label">{{ __('device-requests.medical_program') }}</label><x-input-error for="request.program_id" /></div>
                        @if ($request['program_id'])<button type="button" class="record-inner-action-btn p-1 mb-1" @click="programDetails = !programDetails" :aria-expanded="programDetails" aria-label="{{ __('care-plan.show_program_details') }}">@icon('question-mark-circle', 'w-4 h-4')</button>@endif
                    </div>
                    <fieldset class="care-plan-program-details relative !mt-8 !mb-0" x-show="programDetails" x-cloak><legend>{{ __('care-plan.program_details') }}</legend><button type="button" class="record-inner-action-btn absolute right-4 top-3 p-1" @click="programDetails = false" aria-label="{{ __('forms.close') }}">@icon('close', 'w-5 h-5')</button><dl class="text-gray-500 pr-4">@foreach ($programDetails as $field => $value)<div class="mb-1"><dt class="inline font-semibold">{{ __('dictionaries.medical_device.'.$field) }}:</dt> <dd class="inline">{{ $value }}</dd></div>@endforeach</dl></fieldset>
                </fieldset>
                <fieldset><legend>{{ __('device-requests.device') }}</legend>
                    <div class="mb-8 flex flex-wrap gap-x-8 gap-y-3">@foreach (['CLASSIFICATION_TYPE' => 'choose_type', 'DEVICE_DEFINITION' => 'choose_model'] as $mode => $label)<label class="flex items-center gap-2 text-sm"><input type="radio" class="default-radio" value="{{ $mode }}" wire:model.live="request.device_code_type" @disabled($activityUuid) />{{ __('device-requests.'.$label) }}</label>@endforeach</div>
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:gap-8">
                        <div class="form-group group max-w-sm w-full">
                            @if (!$activityUuid && $request['device_code_type'] === 'CLASSIFICATION_TYPE')
                                <select id="request-device" class="input-select peer" wire:change="selectType($event.target.value)"><option value="">{{ __('device-requests.choose_device') }}</option>@foreach ($types as $key => $type)<option value="{{ $key }}" @selected($request['device_code_system'].'|'.$request['device_id'] === $key)>{{ $type['label'] }} — {{ $type['code'] ?? $key }}</option>@endforeach</select>
                            @else
                                <button type="button" id="request-device" class="input-select peer w-full text-left" @click="deviceSearch = true" @disabled($activityUuid)>{{ filled($deviceLabel) ? $deviceLabel : __('device-requests.choose_device') }}</button>
                            @endif
                            <label for="request-device" class="label">{{ __('device-requests.device') }}*</label><x-input-error for="request.device_id" />
                        </div>
                        @if (!$activityUuid)<button type="button" class="care-plan-add flex items-center gap-2 mb-1" @click="deviceSearch = true">@icon('book-open', 'w-5 h-5') {{ __('device-requests.dictionary') }}</button>@endif
                    </div>
                </fieldset>
                <fieldset><legend>{{ __('device-requests.characteristics') }}</legend>
                    <div class="device-request-parameters max-w-sm space-y-6">@include('livewire.device-request.parameters')</div>
                    @if (empty($options['parameters']))<p class="text-sm text-gray-500">{{ __('device-requests.form.select_device_parameters') }}</p>@endif
                </fieldset>
                <fieldset><legend>{{ __('device-requests.quantity_period') }}</legend>
                    <div class="care-plan-service-fields max-w-sm">
                        <div class="form-group group">
                            @if ($request['program_id'])
                                <select id="request-quantity" class="input-select peer" wire:change="selectQuantity($event.target.value)"><option value="">{{ __('device-requests.form.choose_quantity') }}</option>@foreach ($quantityOptions as $key => $quantity)<option value="{{ $key }}" @selected($request['quantity_code'].'|'.$request['quantity'] === $key)>{{ $quantity['quantity'] }} {{ $units[$quantity['unit']] ?? $quantity['unit'] }}</option>@endforeach</select>
                                <button type="button" class="care-plan-add mt-3" wire:click="moreQuantities">{{ __('device-requests.form.more_quantities') }}</button>
                            @else
                                <div class="care-plan-service-quantity"><input id="request-quantity" type="number" min="1" step="1" class="input peer" wire:model="request.quantity" placeholder=" " /><select class="input-select peer" wire:model="request.quantity_code" aria-label="{{ __('device-requests.form.unit') }}"><option value="">{{ __('device-requests.form.choose_unit') }}</option>@foreach ($units as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select></div>
                            @endif
                            <label for="request-quantity" class="label">{{ __('device-requests.quantity') }}</label><x-input-error for="request.quantity" /><x-input-error for="request.quantity_code" />
                        </div>
                        @foreach (['started_at' => 'start_date', 'ended_at' => 'end_date'] as $field => $label)
                            <div class="form-group group"><div class="care-plan-service-period">@icon('calendar', 'w-4 h-4 text-gray-500')<input id="request-{{ $field }}" type="text" class="datepicker-input input peer" wire:model.lazy="request.{{ $field }}" datepicker-format="{{ frontendDateFormat() }}" datepicker-autohide datepicker-min-date="{{ today()->format(config('app.date_format')) }}" x-on:change-date.camel="$el.dispatchEvent(new Event('change', { bubbles: true }))" autocomplete="off" placeholder="дд.мм.рррр" />@icon('clock', 'w-4 h-4 text-gray-500')<input type="text" class="input peer" data-time inputmode="numeric" maxlength="5" disabled aria-describedby="request-time-hint" aria-label="{{ __('device-requests.'.($field === 'started_at' ? 'start_time' : 'end_time')) }}" placeholder="гг:хх" /></div><label for="request-{{ $field }}" class="label">{{ __('device-requests.'.$label) }}</label><x-input-error for="request.{{ $field }}" /></div>
                        @endforeach
                        <p id="request-time-hint" class="text-sm text-gray-500">{{ __('device-requests.date_only') }}</p>
                        <div class="form-group group"><select id="request-priority" class="input-select peer" disabled aria-describedby="request-priority-hint">@foreach (['routine', 'urgent'] as $priority)<option value="{{ $priority }}">{{ __('device-requests.'.$priority) }}</option>@endforeach</select><label for="request-priority" class="label">{{ __('device-requests.priority') }}</label><x-input-error for="request.priority" /><p id="request-priority-hint" class="mt-2 text-sm text-gray-500">{{ __('device-requests.routine_only') }}</p></div>
                    </div>
                </fieldset>
                @foreach (['reason_reference' => 'reason', 'supporting_info' => 'supporting'] as $group => $title)
                    @include('livewire.device-request.records', ['recordGroup' => $group, 'recordTitle' => $title])
                @endforeach
                <fieldset><legend>{{ __('device-requests.authentication') }}</legend>
                    <div class="form-group group max-w-sm"><select id="request-auth-method" class="input-select peer" wire:model.live="request.inform_with"><option value="">{{ __('device-requests.form.default_auth_method') }}</option>@foreach ($authMethods as $method)<option value="{{ $method['id'] }}">{{ __('device-requests.auth_types.'.($method['type'] ?? 'NA')) }} {{ $method['phone_number'] ?? '' }}</option>@endforeach</select><x-input-error for="request.inform_with" /></div>
                    @php $chosenMethod = !empty($request['inform_with']) ? (collect($authMethods)->firstWhere('id', $request['inform_with']) ?? []) : (collect($authMethods)->firstWhere('type', 'OTP') ?? ($authMethods[0] ?? [])); @endphp
                    @if (!empty($request['inform_with']) && $chosenMethod === [])<p class="text-error mb-3">{{ __('device-requests.validation.auth_method_unavailable') }}</p>@endif
                    @if (!empty($chosenMethod['phone_number']))<div class="mt-5 text-sm"><p class="mb-3">{{ __('device-requests.form.phone_hint') }} <span class="font-semibold">{{ $chosenMethod['phone_number'] }}</span></p><button type="button" wire:click="confirmPhone" class="button-minor">{{ __('device-requests.form.confirm_phone') }}</button>@if ($request['phone_confirmed'])<span class="ml-3 text-green-600">{{ __('device-requests.form.phone_confirmed') }}</span>@endif<x-input-error for="request.phone_confirmed" /></div>@endif
                </fieldset>
                <div class="flex flex-wrap items-center gap-4">
                    <button type="button" class="button-primary-outline-red disabled:opacity-50"
                        aria-haspopup="dialog" aria-controls="delete-request-dialog" @click="$refs.deleteDialog.showModal()" @disabled($draftId === null)>{{ __('forms.delete') }}</button>
                    <button type="submit" class="button-primary-outline flex items-center gap-2"
                        :disabled="usePlan && !linkedPlan" wire:loading.attr="disabled">@icon('archive', 'w-4 h-4') {{ __('forms.save') }}</button>
                    <button type="button" @click="if (!usePlan || linkedPlan) $wire.openSignatureModal()" class="button-primary"
                        :disabled="usePlan && !linkedPlan" wire:loading.attr="disabled">{{ __('device-requests.create_sign') }}</button>
                </div>
            </form>
        </div>
    </x-layouts.patient>
    <dialog id="delete-request-dialog" x-ref="deleteDialog" aria-labelledby="delete-request-title" aria-describedby="delete-request-hint"
        class="m-auto max-w-md rounded-lg bg-white p-6 text-gray-900 dark:bg-gray-800 dark:text-white backdrop:bg-black/50">
        <h2 id="delete-request-title" class="text-lg font-semibold">{{ __('device-requests.messages.confirm_delete_draft') }}</h2>
        <p id="delete-request-hint" class="mt-3 text-sm">{{ __('device-requests.delete_unavailable') }}</p>
        <form method="dialog" class="mt-6 flex flex-wrap gap-4">
            <button type="submit" class="button-minor" autofocus>{{ __('forms.cancel') }}</button>
            <button type="button" class="button-primary-outline-red disabled:opacity-50" disabled>{{ __('forms.delete') }}</button>
        </form>
    </dialog>
    <x-dialog-drawer x-model="deviceSearch" maxWidth="4/5" topClass="top-[57px]" zIndex="60" onCloseClick="deviceSearch = false" :title="__('device-requests.search')">
        @if ($request['device_code_type'] === 'CLASSIFICATION_TYPE')
            <div class="form-group group max-w-sm mb-8"><input id="request-type-search" type="search" class="input peer" x-model="catalogQuery" placeholder=" " /><label for="request-type-search" class="label">{{ __('forms.search') }}</label></div>
            <div class="overflow-x-auto"><table class="table-input w-full"><thead class="thead-input"><tr><th class="th-input">{{ __('device-requests.device') }}</th><th class="th-input">{{ __('device-requests.action') }}</th></tr></thead><tbody>@foreach ($types as $key => $type)<tr x-show="!catalogQuery || @js($type['label'].' '.($type['code'] ?? $key)).toLowerCase().includes(catalogQuery.toLowerCase())"><td class="td-input">{{ $type['label'] }} — {{ $type['code'] ?? $key }}</td><td class="td-input"><button type="button" wire:click="selectType(@js($key))" @click="deviceSearch = false" class="record-inner-action-btn" aria-label="{{ __('forms.select') }}: {{ $type['label'] }}">@icon('plus-circle', 'w-5 h-5')</button></td></tr>@endforeach</tbody></table></div>
        @else
            <div class="form-group group max-w-sm mb-6"><input id="request-model-search" class="input peer" wire:model="modelSearch" wire:keydown.enter.prevent="searchModels" placeholder=" " /><label for="request-model-search" class="label">{{ __('device-requests.search_placeholder') }}</label><x-input-error for="modelSearch" /></div><button type="button" class="button-primary mb-8" wire:click="searchModels">{{ __('forms.search') }}</button>
            <div class="overflow-x-auto"><table class="table-input w-full"><thead class="thead-input"><tr><th class="th-input">{{ __('device-requests.device') }}</th><th class="th-input">{{ __('device-requests.action') }}</th></tr></thead><tbody>@forelse ($models as $model)<tr><td class="td-input">{{ data_get($model, 'device_names.0.name', $model['model_number'] ?? $model['id']) }}</td><td class="td-input"><button type="button" wire:click="selectModel(@js($model['id']))" @click="deviceSearch = false" class="record-inner-action-btn" aria-label="{{ __('forms.select') }}">@icon('plus-circle', 'w-5 h-5')</button></td></tr>@empty<tr><td colspan="2" class="td-input">{{ __('device-requests.no_devices') }}</td></tr>@endforelse</tbody></table></div>
        @endif
        <button type="button" class="button-minor mt-8" @click="deviceSearch = false">{{ __('forms.close') }}</button>
    </x-dialog-drawer>
    <x-dialog-drawer x-model="recordSearch" maxWidth="4/5" topClass="top-[57px]" zIndex="60" onCloseClick="recordSearch = false" :title="__('encounters.search_medical_records')"><x-encounter.medical-record-picker :records="array_values($recordRows)" selection-event="device-request-medical-record-selected" is-added-check="isMedicalRecordAdded" /><button type="button" class="button-minor mt-8" @click="recordSearch = false">{{ __('forms.close') }}</button></x-dialog-drawer>
    <x-signature-modal method="sign" />
</div>
