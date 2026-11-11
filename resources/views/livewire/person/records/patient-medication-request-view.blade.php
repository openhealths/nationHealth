@use('App\Enums\Person\MedicationRequestStatus')

<x-layouts.patient
    :showLegacyMessages="false"
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
>
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    <div class="space-y-6 p-6" x-data>
        <a
            class="button-minor"
            href="{{ route('persons.medication-requests', [legalEntity(), 'person' => $personId]) }}"
        >{{ __('forms.back') }}</a>
        @if ($detail !== [])
            <h1 class="text-xl font-bold">
                {{ __('medication-requests.nomer_retseptu') }}: {{ $detail['request_number'] ?? $requestId }}
            </h1>
            <dl class="form-row-2 rounded-xl border p-6">
                @foreach (['status', 'created_at', 'started_at', 'ended_at', 'dispense_valid_from', 'dispense_valid_to', 'intent', 'category', 'note', 'reject_reason', 'reject_reason_code', 'block_reason', 'block_reason_code', 'rejected_at', 'blocked_to'] as $field)
                    @if (isset($detail[$field]))
                        <div>
                            <dt class="font-semibold">{{ __('medication-requests.fields.'.$field) }}</dt>
                            <dd>
                                {{ $field === 'status' ? (MedicationRequestStatus::resolve($detail[$field])?->label() ?? $detail[$field]) : (is_array($detail[$field]) ? data_get($detail, $field.'.text', '') : $detail[$field]) }}
                            </dd>
                        </div>
                    @endif
                @endforeach
                @foreach (['person' => 'short_name', 'employee' => 'name', 'rejected_by' => 'name', 'blocked_by_legal_entity' => 'name', 'division' => 'name', 'legal_entity' => 'name', 'medical_program' => 'name', 'medication_info' => 'medication_name'] as $field => $name)
                    @if (isset($detail[$field]))
                        <div>
                            <dt class="font-semibold">{{ __('medication-requests.fields.'.$field) }}</dt>
                            <dd>{{ data_get($detail, $field.'.'.$name) ?? data_get($detail, $field.'.id') }}</dd>
                        </div>
                    @endif
                @endforeach
                @foreach (['person.age', 'employee.position', 'medical_program.funding_source', 'medical_program.mr_blank_type', 'medication_info.form'] as $field)
                    @if (data_get($detail, $field) !== null)
                        <div>
                            <dt class="font-semibold">
                                {{ __('medication-requests.fields.'.str_replace('.', '_', $field)) }}
                            </dt>
                            <dd>{{ data_get($detail, $field) }}</dd>
                        </div>
                    @endif
                @endforeach
                @if (isset($detail['container_dosage']))
                    <div>
                        <dt class="font-semibold">{{ __('medication-requests.primary_container') }}</dt>
                        <dd>
                            {{ data_get($detail, 'container_dosage.value') }} {{ data_get($detail, 'container_dosage.code') }}
                        </dd>
                    </div>
                @endif
                <div>
                    <dt>{{ __('care-plan.quantity') }}</dt>
                    <dd>{{ data_get($detail, 'medication_info.medication_qty') }}</dd>
                </div>
                @foreach ($detail['based_on'] ?? [] as $reference)
                    <div>
                        <dt>{{ __('medication-requests.fields.based_on') }}</dt>
                        <dd>{{ data_get($reference, 'identifier.value') }}</dd>
                    </div>
                @endforeach
                <div>
                    <dt>{{ __('medication-requests.fields.context') }}</dt>
                    <dd>{{ data_get($detail, 'context.identifier.value') }}</dd>
                </div>
            </dl>
            @if (data_get($detail, 'medication_info.ingredients', []) !== [])
                <table class="w-full border">
                    <thead>
                        <tr>
                            <th>{{ __('medication-requests.dose_ingredient') }}</th>
                            <th>{{ __('medication-requests.fields.dosage') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($detail['medication_info']['ingredients'] as $ingredient)
                            <tr>
                                <td>{{ $ingredient['name'] ?? $ingredient['id'] }}</td>
                                <td>
                                    {{ data_get($ingredient, 'dosage.numerator_value') }} {{ data_get($ingredient, 'dosage.numerator_unit') }} / {{ data_get($ingredient, 'dosage.denumerator_value') }} {{ data_get($ingredient, 'dosage.denumerator_unit') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            <dl class="rounded-xl border p-6">
                @foreach ($programSettings as $setting => $value)
                    <div class="mb-2">
                        <dt class="font-semibold">{{ __('medication-requests.program_settings.'.$setting) }}</dt>
                        <dd>
                            {{ is_array($value) ? collect($value)->map(static fn ($item) => is_scalar($item) ? $item : data_get($item, 'name', data_get($item, 'code', '')))->filter()->implode(', ') : (is_bool($value) ? ($value ? __('forms.yes') : __('forms.no')) : $value) }}
                        </dd>
                    </div>
                @endforeach
            </dl>
            @foreach ($detail['dosage_instruction'] ?? [] as $dosage)
                <div class="rounded-xl border p-6">
                    <p>{{ $dosage['text'] ?? '' }}</p>
                    <p>{{ $dosage['patient_instruction'] ?? ($dosage['patientInstruction'] ?? '') }}</p>
                    @foreach (['route', 'site'] as $field)
                        @if (isset($dosage[$field]))
                            <p>
                                {{ __('medication-requests.fields.'.$field) }}: {{ data_get($dosage, $field.'.text') ?? data_get($dosage, $field.'.coding.0.code') }}
                            </p>
                        @endif
                    @endforeach
                    @foreach ($dosage['additional_instruction'] ?? [] as $instruction)
                        <p>
                            {{ __('medication-requests.fields.additional_instruction') }}: {{ $instruction['text'] ?? data_get($instruction, 'coding.0.code') }}
                        </p>
                    @endforeach
                    @foreach ($dosage['timing']['event'] ?? [] as $event)
                        <p>{{ __('medication-requests.fields.timing') }}: {{ $event }}</p>
                    @endforeach
                    @if (isset($dosage['timing']['repeat']))
                        @foreach ($dosage['timing']['repeat'] as $field => $value)
                            @if (is_scalar($value) || (is_array($value) && array_is_list($value)))
                                <p>
                                    {{ __('medication-requests.timing.'.$field) }}: {{ is_array($value) ? collect($value)->map(static fn ($item) => is_scalar($item) ? $item : data_get($item, 'name', data_get($item, 'code', '')))->filter()->implode(', ') : $value }}
                                </p>
                            @endif
                        @endforeach
                    @endif
                    @foreach (['dose_quantity', 'rate_quantity'] as $dose)
                        @if (isset($dosage['dose_and_rate'][$dose]))
                            <p>
                                {{ __('medication-requests.fields.'.$dose) }}: {{ data_get($dosage, 'dose_and_rate.'.$dose.'.value') }} {{ data_get($dosage, 'dose_and_rate.'.$dose.'.unit') ?? data_get($dosage, 'dose_and_rate.'.$dose.'.code') }}
                            </p>
                        @endif
                    @endforeach
                    @foreach (['max_dose_per_administration', 'max_dose_per_period', 'max_dose_per_lifetime'] as $dose)
                        @if (isset($dosage[$dose]))
                            <p>
                                {{ __('medication-requests.fields.'.$dose) }}: {{ data_get($dosage, $dose.'.value') ?? data_get($dosage, $dose.'.numerator.value') }} {{ data_get($dosage, $dose.'.unit') ?? data_get($dosage, $dose.'.numerator.unit') }}
                            </p>
                        @endif
                    @endforeach
                </div>
            @endforeach
            <div class="flex flex-wrap gap-3">
                <button class="button-sync" wire:click="refreshDetail">
                    {{ __('medication-requests.detail_sync') }}
                </button>
                <button class="button-minor" wire:click="checkValidity">
                    {{ __('medication-requests.check_validity') }}
                </button>
                @if (strtolower($detail['status']) === 'new')
                    <button class="button-primary" wire:click="openSigning">
                        {{ __('medication-requests.resume_signing') }}
                    </button>
                @endif
                @if (in_array(strtolower($detail['status']), ['new', 'active'], true))
                    <input
                        class="input"
                        wire:model="statusReason"
                        placeholder="{{ __('medication-requests.fields.reject_reason_code') }}"
                    />
                    @error('statusReason')
                        <p class="text-error">{{ $message }}</p>
                    @enderror
                    <input
                        class="input"
                        wire:model="rejectReason"
                        placeholder="{{ __('medication-requests.fields.reject_reason') }}"
                    />
                    <button class="button-primary-outline-red" wire:click="reject">{{ __('forms.reject') }}</button>
                @endif
                @if (in_array(strtolower($detail['status']), ['active', 'completed', 'expired', 'blocked', 'signed'], true))
                    <button class="button-minor" wire:click="loadPrintout">{{ __('forms.print') }}</button>
                    <button class="button-minor" wire:click="loadDispenses">
                        {{ __('medication-requests.dispense_history') }}
                    </button>
                    <button class="button-minor" wire:click="loadPriceCatalogue">
                        {{ __('medication-requests.price_catalogue') }}
                    </button>
                    <a
                        class="button-minor"
                        href="{{ $priceCatalogueUrl !== '' ? $priceCatalogueUrl : config('ehealth.national_price_catalogue_url') }}"
                        target="_blank"
                        rel="noopener"
                    >{{ __('medication-requests.price_catalogue') }}</a>
                @endif
            </div>
            @if (strtolower($detail['status']) === 'active' && !($programSettings['request_notification_disabled'] ?? false))
                <label class="flex items-center gap-2"
                    ><input
                        type="checkbox"
                        wire:model="smsConfirmed"
                    />{{ __('medication-requests.sms_confirmation') }}</label>
                @error('smsConfirmed')
                    <p class="text-error">{{ $message }}</p>
                @enderror
                <button class="button-minor" wire:click="resendSms">
                    {{ __('medication-requests.sms_confirmation') }}
                </button>
            @endif
            @include('livewire.medication-request.dispenses', ['items' => $dispenses])
            @if ($printoutHtml !== '')
                <iframe
                    x-ref="prescriptionPrintout"
                    class="h-[700px] w-full"
                    sandbox="allow-same-origin allow-modals"
                    srcdoc="{{ $printoutHtml }}"
                    title="{{ __('forms.print') }}"
                ></iframe>
                <button class="button-primary" @click="$refs.prescriptionPrintout.contentWindow.print()">
                    {{ __('forms.print') }}
                </button>
            @endif
        @endif
    </div>
    <x-signature-modal method="sign" />
</x-layouts.patient>
