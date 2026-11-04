@use(App\Enums\Device\Status)
@use(App\Models\MedicalEvents\Sql\DeviceProperty)

@php
    $limit = $limit ?? null;
    $hasLimit = $limit && count($this->devices) > $limit;
@endphp

<div @if ($hasLimit) x-data="{ limit: {{ $limit }} }" @endif>
    @foreach ($this->devices as $index => $device)
        <div class="record-inner-card" @if ($hasLimit) x-show="limit > {{ $index }}" @endif>
            <div class="record-inner-header">
                <div class="record-inner-checkbox-col">
                    <input type="checkbox" class="default-checkbox h-5 w-5" />
                </div>

                <div class="record-inner-column flex-1">
                    <div class="record-inner-label">{{ __('devices.name') }}</div>
                    <div class="record-inner-value text-[16px]">{{ data_get($device, 'names.0.value') ?? '-' }}</div>
                </div>

                <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                    <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                    <div>
                        @php($status = Status::from(data_get($device, 'status')))

                        <span @class([$status->color()])>{{ $status->label() }}</span>
                    </div>
                </div>

                <div class="record-inner-action-col">
                    <x-patient.record-actions
                        :href="route(
                            $prepersonId !== null ? 'prepersons.devices.view' : 'persons.devices.view',
                            [legalEntity(), $prepersonId !== null ? 'preperson' : 'person' => $prepersonId ?? $personId, 'device' => data_get($device, 'id')]
                        )"
                    />
                </div>
            </div>

            <div class="record-inner-body">
                <div class="record-inner-grid-container">
                    <div class="[&>div]:min-w-0 [&_.record-inner-subvalue]:wrap-break-word grid w-full grid-cols-2 gap-x-4 gap-y-4 xl:grid-cols-5">
                        <div>
                            <div class="record-inner-label">{{ __('devices.model_number') }}</div>
                            <div class="record-inner-subvalue">{{ data_get($device, 'modelNumber') ?? '-' }}</div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('forms.type') }}</div>
                            <div class="record-inner-subvalue">{{ $this->dictionaryLabel($device, 'type') }}</div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('devices.lot_number') }}</div>
                            <div class="record-inner-subvalue">{{ data_get($device, 'lotNumber') ?? '-' }}</div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('devices.manufacture_date') }}</div>
                            <div class="record-inner-subvalue">{{ data_get($device, 'manufactureDate') ?? '-' }}</div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('devices.notes') }}</div>
                            <div class="record-inner-subvalue">{{ data_get($device, 'note') ?? '-' }}</div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('devices.properties') }}</div>
                            <div class="record-inner-subvalue">
                                @forelse (data_get($device, 'properties', []) as $property)
                                    <div>
                                        {{
                                            data_get(
                                                $this->dictionaries,
                                                'device_properties.' . data_get($property, 'code.coding.0.code'),
                                                '-'
                                            )
                                        }}: {{ DeviceProperty::displayValue($property) }}
                                    </div>
                                @empty
                                    -
                                @endforelse
                            </div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('devices.manufacturer_and_serial') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($device, 'manufacturer') ?? '-' }} <br />
                                {{ data_get($device, 'serialNumber') ?? '-' }}
                            </div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('devices.doctor') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($device, 'recorder.displayValue') ?? '-' }}
                            </div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('devices.expiration_date') }}</div>
                            <div class="record-inner-subvalue">{{ data_get($device, 'expirationDate') ?? '-' }}</div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('patients.status_change_reason') }}</div>
                            <div class="record-inner-subvalue">
                                {{ $this->dictionaryLabel($device, 'statusReason') }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="record-inner-id-col">
                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('forms.ehealth_id') }}</div>
                        <div class="record-inner-id-value">{{ data_get($device, 'uuid') }}</div>
                    </div>

                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('medical-events.medical_record_id') }}</div>
                        <div class="record-inner-id-value">
                            {{ data_get($device, 'context.identifier.value') ?? '-' }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    @if ($hasLimit)
        <div x-show="limit < {{ count($this->devices) }}" class="mt-4 flex justify-start">
            <button type="button" @click="limit += 5" class="item-add">{{ __('general.show_more') }}</button>
        </div>
    @endif
</div>
