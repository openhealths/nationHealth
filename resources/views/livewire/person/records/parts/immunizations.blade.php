@use(App\Enums\Person\ImmunizationStatus)

@foreach($this->immunizations as $immunization)
    <div class="record-inner-card">
        <div class="record-inner-header">
            <div class="record-inner-checkbox-col">
                <input type="checkbox" class="default-checkbox w-5 h-5">
            </div>

            <div class="record-inner-column flex-1">
                <div class="record-inner-label">{{ __('patients.vaccine') }}</div>
                <div class="record-inner-value text-[16px]">
                    {{ data_get($this->dictionaries, 'eHealth/vaccine_codes.' . data_get($immunization, 'vaccineCode.coding.0.code'), data_get($immunization, 'vaccineCode.coding.0.code', '-')) }}
                </div>
            </div>

            <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                <div>
                    <span class="badge-green">
                        {{ ImmunizationStatus::from($immunization['status'])->label() }}
                    </span>
                </div>
            </div>

            <div class="record-inner-action-col">
                <button class="record-inner-action-btn">
                    @icon('edit-user-outline', 'w-5 h-5')
                </button>
            </div>
        </div>

        <div class="record-inner-body">
            <div class="record-inner-grid-container">
                <div
                    class="grid grid-cols-2 xl:grid-cols-5 gap-y-4 gap-x-4 w-full [&>div]:min-w-0 [&_.record-inner-subvalue]:break-words">
                    <div>
                        <div class="record-inner-label">{{ __('patients.dosage') }}</div>
                        <div class="record-inner-subvalue">
                            {{ data_get($immunization, 'doseQuantity.value', '') . ' ' . data_get($immunization, 'doseQuantity.unit', '') ?: '-' }}
                        </div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('patients.route') }}</div>
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
                        <div class="record-inner-label">{{ __('patients.reactions') }}</div>
                        <div class="record-inner-subvalue">-</div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('patients.performer') }}</div>
                        <div class="record-inner-subvalue">
                            {{ data_get($immunization, 'performer.displayValue', '-') }}
                        </div>
                    </div>

                    <div>
                        <div class="record-inner-label">{{ __('patients.manufacturer_and_lot_number') }}</div>
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
                        <div class="record-inner-label">{{ __('patients.was_performed') }}</div>
                        <div class="record-inner-subvalue">
                            {{ data_get($immunization, 'notGiven') ? __('forms.no') : __('forms.yes') }}
                        </div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('patients.date_time_performed') }}</div>
                        <div class="record-inner-subvalue">
                            {{ data_get($immunization, 'date', '') . ' ' . data_get($immunization, 'time', '') ?: '-' }}
                        </div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('patients.date_time_entered') }}</div>
                        <div class="record-inner-subvalue">{{ data_get($immunization, 'ehealthInsertedAt', '-') }}</div>
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
