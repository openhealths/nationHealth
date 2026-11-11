@use('App\Enums\Person\DeviceRequestStatus')
<x-layouts.patient
    :personId="$personId"
    :patientFullName="$person->fullName"
    :hideNavigation="true"
    :showLegacyMessages="false"
    :title="__('device-requests.patient.request').' '.($selected['requisition'] ?? '')"
>
    <x-slot name="headerActions"></x-slot>
    @assets
        <script src="{{ asset('js/print-sandboxed.js') }}"></script>
    @endassets
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    <div
        class="shift-content max-w-6xl p-4"
        x-data="{ deviceCatalog: $wire.entangle('showDeviceCatalog') }"
        @print-device-request.window="window.printSandboxedHtml($event.detail.html)"
    >
        @if ($selected)
            <fieldset class="fieldset rounded-2xl shadow-none">
                <legend class="legend">{{ __('device-requests.patient.general') }}</legend>
                <div class="form-row-2">
                    <x-device-request-field
                        :label="__('forms.status.label')"
                        :value="DeviceRequestStatus::tryFrom($selected['status'])?->label() ?? $selected['status']"
                    />
                    <x-device-request-field :label="__('device-requests.patient.status_reason')">
                        <x-device-request-value :value="$selected['status_reason'] ?? '-'" />
                    </x-device-request-field>
                    <x-device-request-field
                        :label="__('device-requests.labels.number')"
                        :value="$selected['requisition'] ?? '-'"
                    />
                </div>
            </fieldset>
            <fieldset class="fieldset rounded-2xl shadow-none">
                <legend class="legend">{{ __('device-requests.patient.device_info') }}</legend>
                <div class="form-row-2">
                    <div class="flex items-end gap-3">
                        <x-device-request-field
                            class="flex-1"
                            :label="__('device-requests.labels.device')"
                            :value="data_get($selected, 'code_reference.display_value') ?? (data_get($selected, 'code.text') ?? (data_get($selected, 'code.coding.0.display') ?? data_get($selected, 'code.coding.0.code', '-')))"
                        />
                        <button
                            type="button"
                            class="record-inner-action-btn mb-2 cursor-pointer rounded p-2"
                            @click="$wire.set('showDeviceCatalog', true)"
                            aria-label="{{ __('device-requests.patient.catalog') }}"
                            aria-haspopup="dialog"
                        >
                            @icon('question-mark-circle', 'w-5 h-5')
                        </button>
                    </div>
                    <x-device-request-field
                        :label="__('device-requests.labels.quantity')"
                        :value="data_get($selected, 'quantity.value')"
                    />
                </div>
                <h3 class="mb-5 font-semibold text-gray-900 dark:text-gray-100">
                    {{ __('device-requests.characteristics') }}
                </h3>
                <div class="form-row-2">
                    @forelse ($selected['parameter'] ?? [] as $parameter)
                        <x-device-request-field :label="data_get($parameter, 'code.text') ?? (data_get($parameter, 'code.coding.0.display') ?? ($parameterNames[data_get($parameter, 'code.coding.0.code')] ?? data_get($parameter, 'code.coding.0.code', __('device-requests.labels.parameters'))))">
                            <x-device-request-value :value="$parameter['value_codeable_concept'] ?? ($parameter['value_quantity'] ?? ($parameter['value_range'] ?? ($parameter['value_boolean'] ?? ($parameter['value_integer'] ?? ($parameter['value_decimal'] ?? ($parameter['value_string'] ?? '-'))))))" />
                        </x-device-request-field>
                    @empty
                        <span class="text-sm text-gray-500">-</span>
                    @endforelse
                </div>
            </fieldset>
            <fieldset class="fieldset rounded-2xl shadow-none">
                <legend class="legend">{{ __('device-requests.patient.additional') }}</legend>
                <div class="form-row-2">
                    @foreach (['created_at' => 'authored_on', 'dispense_until' => 'dispense_valid_to', 'period_start' => 'occurrence_period.start', 'period_end' => 'occurrence_period.end'] as $label => $path)
                        <x-device-request-field
                            :label="__('device-requests.labels.'.$label)"
                            :value="data_get($selected, $path)"
                            :date="true"
                        />
                    @endforeach
                    <x-device-request-field :label="__('device-requests.patient.reason')">
                        <x-device-request-value :value="$selected['reason'] ?? '-'" />
                    </x-device-request-field>
                    <x-device-request-field
                        :label="__('device-requests.labels.entity')"
                        :value="data_get($selected, 'requester_legal_entity.display_value')"
                    />
                    <x-device-request-field
                        :label="__('device-requests.labels.patient')"
                        :value="data_get($selected, 'subject.display_value', $person->fullName)"
                    />
                    <x-device-request-field
                        :label="__('device-requests.labels.doctor')"
                        :value="data_get($selected, 'requester.display_value')"
                    />
                </div>
            </fieldset>
            @php
                $isActive = $selected['status'] === 'active';
            @endphp
            <div class="mb-8 flex flex-wrap items-center gap-6">
                <a
                    href="{{ route('persons.device-requests', [legalEntity(), 'person' => $personId]) }}"
                    class="button-minor"
                >
                    {{ __('forms.back') }}
                </a>
                <button
                    type="button"
                    wire:click="loadDispenses"
                    class="button-primary-outline"
                    @disabled(!auth()->user()->can('device_dispense:read'))
                >
                    {{ __('device-requests.patient.actions.dispenses') }}
                </button>
                <button type="button" wire:click="printout" class="button-primary-outline flex items-center gap-2">
                    @icon('printer', 'w-4 h-4')
                    {{ __('device-requests.patient.actions.print') }}
                </button>
                <button
                    type="button"
                    wire:click="openSmsModal"
                    class="button-primary-outline flex items-center gap-2"
                    @disabled(!$isActive || !$phone || !auth()->user()->can('device_request:resend'))
                >
                    <span
                        class="[&>svg]:h-full [&>svg]:w-full flex h-4 w-4 shrink-0 items-center justify-center"
                        aria-hidden="true"
                    >
                        @icon('message-circle')
                    </span>
                    {{ __('device-requests.patient.actions.sms') }}
                </button>
                <button
                    type="button"
                    wire:click="complete"
                    wire:confirm="{{ __('device-requests.labels.complete_confirm') }}"
                    class="button-primary-outline-red"
                    @disabled(!$isActive || isset($selected['quantity']) || !auth()->user()->can('device_request:complete'))
                >
                    {{ __('device-requests.patient.actions.complete') }}
                </button>
            </div>
            <div class="mb-8 flex flex-wrap items-center gap-6">
                <button
                    type="button"
                    wire:click="startAction('mark_in_error')"
                    class="button-primary-outline-red"
                    @disabled(in_array($selected['status'], ['entered-in-error', 'entered_in_error']) || !auth()->user()->can('device_request:mark_in_error'))
                >
                    {{ __('device-requests.patient.actions.mark_in_error') }}
                </button>
                <button
                    type="button"
                    wire:click="startAction('revoke')"
                    class="button-primary-outline-red"
                    @disabled(!$isActive || !auth()->user()->can('device_request:revoke'))
                >
                    {{ __('device-requests.patient.actions.revoke') }}
                </button>
            </div>

            @can('device_request:resend')
                <x-dialog-modal wire:model="showSmsModal">
                    <x-slot name="title">{{ __('device-requests.patient.actions.sms') }}</x-slot>
                    <x-slot name="content">
                        <p class="mb-4">{{ __('device-requests.labels.sms_hint') }}: {{ $phone }}</p>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" class="default-checkbox" wire:model="phoneConfirmed" />
                            {{ __('device-requests.labels.phone_confirmed') }}
                        </label>
                    </x-slot>
                    <x-slot name="footer">
                        <div class="flex flex-wrap gap-3">
                            <button type="button" class="button-minor" wire:click="$set('showSmsModal', false)">
                                {{ __('forms.cancel') }}
                            </button>
                            <button type="button" wire:click="resendSms" class="button-primary-outline">
                                {{ __('device-requests.patient.actions.sms') }}
                            </button>
                        </div>
                    </x-slot>
                </x-dialog-modal>
            @endcan

            @if ($action)
                <x-dialog-modal wire:model="showStatusActionModal">
                    <x-slot name="title">
                        {{ $action === 'revoke' ? __('device-requests.patient.actions.revoke') : __('device-requests.patient.actions.mark_in_error') }}
                    </x-slot>
                    <x-slot name="content">
                        <div class="form-group group mt-4">
                            <select id="deviceRequestReason" class="input-select peer" wire:model="reason">
                                <option value="">{{ __('device-requests.labels.choose_reason') }}</option>
                                @foreach ($reasons as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <label
                                for="deviceRequestReason"
                                class="label"
                            >{{ __('device-requests.labels.reason') }}</label>
                        </div>
                        <label
                            for="deviceRequestExplanation"
                            class="default-label"
                        >{{ __('device-requests.labels.explanation') }}</label>
                        <textarea
                            id="deviceRequestExplanation"
                            wire:model="reasonText"
                            class="textarea w-full"
                        ></textarea>
                    </x-slot>
                    <x-slot name="footer">
                        <div class="flex flex-wrap gap-3">
                            <button
                                type="button"
                                class="button-minor"
                                wire:click="$set('showStatusActionModal', false)"
                            >
                                {{ __('forms.cancel') }}
                            </button>
                            <button type="button" wire:click="openSignatureModal" class="button-primary">
                                {{ __('device-requests.labels.sign_change') }}
                            </button>
                        </div>
                    </x-slot>
                </x-dialog-modal>
            @endif

            <x-dialog-modal wire:model="showDispenses" maxWidth="3xl">
                <x-slot name="title">{{ __('device-requests.patient.actions.dispenses') }}</x-slot>
                <x-slot name="content">
                    @forelse ($dispenses as $dispense)
                        <button
                            type="button"
                            wire:click="showDispense('{{ $dispense['id'] }}')"
                            class="button-minor mb-3"
                        >
                            {{ __('device-requests.labels.dispense') }} {{ $dispense['id'] }}
                        </button>
                    @empty
                        <x-nothing-found :description="null" />
                    @endforelse
                    @if ($dispenseDetails)
                        @include('livewire.device-request.record-details', ['record' => $dispenseDetails])
                    @endif
                </x-slot>
                <x-slot name="footer">
                    <button type="button" class="button-minor" wire:click="$set('showDispenses', false)">
                        {{ __('forms.back') }}
                    </button>
                </x-slot>
            </x-dialog-modal>
            <x-dialog-drawer
                x-model="deviceCatalog"
                maxWidth="4/5"
                topClass="top-[57px]"
                zIndex="44"
                :title="__('dictionaries.medical_device.search')"
            >
                @if ($showDeviceCatalog)
                    <h2 class="mb-4 font-semibold">{{ __('dictionaries.medical_device.page_title') }}</h2>
                    <livewire:dictionary.device-definition
                        :legal-entity="$this->legalEntity"
                        :medical-program-id="data_get($selected, 'program.identifier.value')"
                        :classification-type-code="data_get($selected, 'code.coding.0.code')"
                        :embedded="true"
                        :key="'patient-device-catalog-'.$selectedId"
                    />
                @endif
                <button type="button" class="button-minor mt-6" @click="deviceCatalog = false">
                    {{ __('forms.cancel') }}
                </button>
            </x-dialog-drawer>
        @else
            <x-nothing-found :description="null" />
            <a
                href="{{ route('persons.device-requests', [legalEntity(), 'person' => $personId]) }}"
                class="button-minor"
            >{{ __('forms.back') }}</a>
        @endif
        <x-signature-modal method="sign" />
    </div>
    <x-forms.loading />
</x-layouts.patient>
