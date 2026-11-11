@use('App\Enums\Person\DeviceRequestStatus')
<dl class="grid gap-3 md:grid-cols-2">
    @php
        $requesterEntityUuid = data_get($record, 'requester_legal_entity.identifier.value');
        $requesterEntityName = ($legalEntityNames[$requesterEntityUuid ?? ''] ?? null)
            ?: data_get($record, 'requester_legal_entity.display_value')
            ?: $requesterEntityUuid;
    @endphp
    @if ($requesterEntityName)
        <div>
            <dt class="font-semibold">{{ __('device-requests.labels.entity') }}</dt>
            <dd>{{ $requesterEntityName }}</dd>
        </div>
    @endif
    @if (isset($record['quantity']))
        <div>
            <dt class="font-semibold">{{ __('device-requests.labels.unit') }}</dt>
            <dd>{{ data_get($record, 'quantity.unit', data_get($record, 'quantity.code', '')) }}</dd>
        </div>
    @endif
    @foreach (['status' => __('device-requests.labels.status'), 'intent' => __('device-requests.labels.intent'), 'authored_on' => __('device-requests.labels.created_at'), 'occurrence_period.start' => __('device-requests.labels.period_start'), 'occurrence_period.end' => __('device-requests.labels.period_end'), 'dispense_valid_to' => __('device-requests.labels.dispense_until'), 'requester.display_value' => __('device-requests.labels.doctor'), 'subject.display_value' => __('device-requests.labels.patient'), 'program.display_value' => __('device-requests.labels.program'), 'quantity.value' => __('device-requests.labels.quantity'), 'quantity.unit' => __('device-requests.labels.unit_short'), 'note' => __('device-requests.labels.note'), 'status_reason.text' => __('device-requests.labels.status_explanation'), 'when_handed_over' => __('device-requests.labels.dispensed_at'), 'performer.display_value' => __('device-requests.labels.performer'), 'location.display_value' => __('device-requests.labels.dispense_location')] as $path => $label)
        @if (data_get($record, $path) !== null)
            <div>
                <dt class="font-semibold">{{ $label }}</dt>
                <dd>
                    {{ $path === 'status' ? DeviceRequestStatus::labelFor(data_get($record, $path)) : data_get($record, $path) }}
                </dd>
            </div>
        @endif
    @endforeach
    @foreach (['code' => __('device-requests.labels.device_type'), 'code_reference' => __('device-requests.labels.device_model'), 'status_reason' => __('device-requests.labels.status_reason'), 'reason' => __('device-requests.labels.reasons'), 'parameter' => __('device-requests.labels.parameters'), 'based_on' => __('device-requests.labels.based_on'), 'device' => __('device-requests.labels.device'), 'details' => __('device-requests.labels.dispense_cost'), 'performer_legal_entity' => __('device-requests.labels.dispense_entity'), 'encounter' => __('device-requests.labels.encounter'), 'subject' => __('device-requests.labels.patient'), 'supporting_info' => __('device-requests.labels.supporting_info')] as $key => $label)
        @if (!empty($record[$key]))
            <div>
                <dt class="font-semibold">{{ $label }}</dt>
                <dd>@include('livewire.device-request.record-value', ['value' => $record[$key]])</dd>
            </div>
        @endif
    @endforeach
</dl>
