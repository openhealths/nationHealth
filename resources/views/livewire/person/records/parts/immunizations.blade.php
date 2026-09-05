@use(App\Enums\Person\ImmunizationStatus)

@php
    $limit = $limit ?? null;
    $hasLimit = $limit && count($this->immunizations) > $limit;
@endphp

<div @if ($hasLimit) x-data="{ limit: {{ $limit }} }" @endif>
    @foreach ($this->immunizations as $index => $immunization)
        <div class="record-inner-card" @if ($hasLimit) x-show="limit > {{ $index }}" @endif>
            <div class="record-inner-header">
                <div class="record-inner-checkbox-col">
                    <input type="checkbox" class="default-checkbox h-5 w-5" />
                </div>

                <div class="record-inner-column flex-1">
                    <div class="record-inner-label">{{ __('immunizations.vaccine') }}</div>
                    <div class="record-inner-value text-[16px]">
                        {{ data_get($this->dictionaries, 'eHealth/vaccine_codes.' . data_get($immunization, 'vaccineCode.coding.0.code'), data_get($immunization, 'vaccineCode.coding.0.code', '-')) }}
                    </div>
                </div>

                <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                    <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                    <div>
                        @php($status = ImmunizationStatus::from(data_get($immunization, 'status')))
                        <span @class([$status->color()])> {{ $status->label() }} </span>
                    </div>
                </div>

                <div class="record-inner-action-col">
                    <button class="record-inner-action-btn cursor-pointer">
                        @icon('edit-user-outline', 'w-5 h-5')
                    </button>
                </div>
            </div>

            <div class="record-inner-body">
                <div class="record-inner-grid-container">
                    <div class="[&>div]:min-w-0 [&_.record-inner-subvalue]:break-words grid w-full grid-cols-2 gap-x-4 gap-y-4 xl:grid-cols-5">
                        <div>
                            <div class="record-inner-label">{{ __('patients.dosage') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($immunization, 'doseQuantity.value', '') . ' ' . data_get($immunization, 'doseQuantity.unit', '') ?: '-' }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('immunizations.route') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($this->dictionaries, 'eHealth/vaccination_routes.' . data_get($immunization, 'route.coding.0.code'), '-') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('patients.reason') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($this->dictionaries, 'eHealth/reason_explanations.' . data_get($immunization, 'explanation.reasons.0.coding.0.code'), '-') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('immunizations.reactions') }}</div>
                            <div class="record-inner-subvalue">-</div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('medical-events.performer') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($immunization, 'performer.displayValue', '-') }}
                            </div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('immunizations.manufacturer_and_lot_number') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($immunization, 'manufacturer', '') . ' ' . data_get($immunization, 'lotNumber', '') ?: '-' }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('patients.body_part') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($this->dictionaries, 'eHealth/vaccination_routes.' . data_get($immunization, 'site.coding.0.code'), '-') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('immunizations.was_performed') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($immunization, 'notGiven') ? __('forms.no') : __('forms.yes') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('immunizations.date_time_performed') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($immunization, 'date', '') . ' ' . data_get($immunization, 'time', '') ?: '-' }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('patients.date_time_entered') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($immunization, 'ehealthInsertedAt', '-') }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="record-inner-id-col">
                    <div class="min-w-0">
                        <div class="record-inner-label">ID ECO3</div>
                        <div class="record-inner-id-value">{{ data_get($immunization, 'uuid', '-') }}</div>
                    </div>
                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('patients.medical_record_id') }}</div>
                        <div class="record-inner-id-value">
                            {{ data_get($immunization, 'context.identifier.value', '-') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    @if ($hasLimit)
        <div x-show="limit < {{ count($this->immunizations) }}" class="mt-4 flex justify-start">
            <button type="button" @click="limit += 5" class="item-add">{{ __('patients.show_more') }}</button>
        </div>
    @endif
</div>
