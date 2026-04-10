@use(App\Enums\Person\ObservationStatus)

@foreach($this->observations as $observation)
    <div class="record-inner-card">
        <div class="record-inner-header">
            <div class="record-inner-checkbox-col">
                <input type="checkbox" class="default-checkbox w-5 h-5">
            </div>

            @php
                $categorySystem = data_get($observation, 'categories.0.coding.0.system');
                $categoryCode = data_get($observation, 'categories.0.coding.0.code');

                $codeSystem = data_get($observation, 'code.coding.0.system');
                $codeValue = data_get($observation, 'code.coding.0.code');

                $categoryLabel = $this->dictionaries[$categorySystem][$categoryCode];
                $codeLabel = $this->dictionaries[$codeSystem][$codeValue];
            @endphp
            <div class="record-inner-column flex-1">
                <div class="record-inner-label">{{ __('patients.category_and_code') }}</div>
                <div class="record-inner-value text-[16px]">
                    {{ $categoryLabel }} {{ $codeLabel }}
                </div>
            </div>

            <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                <div>
                    <span class="badge-green">
                        {{ ObservationStatus::from(data_get($observation, 'status'))->label() }}
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
                        <div class="record-inner-label">{{ __('patients.information_source') }}</div>
                        <div class="record-inner-subvalue">
                            {{ data_get($this->dictionaries, 'eHealth/report_origins.' . data_get($observation, 'reportOrigin.coding.0.code')) }}
                        </div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('patients.method') }}</div>
                        <div class="record-inner-subvalue">
                            {{ data_get($this->dictionaries, 'eHealth/observation_methods.' . data_get($observation, 'method.coding.0.code')) }}
                        </div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('patients.value') }}</div>
                        <div class="record-inner-subvalue">5 мкмоль/л</div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('patients.getting_indicators') }}</div>
                        <div class="record-inner-subvalue">
                            {{ data_get($observation, 'effectiveDate', '-') }}
                        </div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('patients.updated') }}</div>
                        <div class="record-inner-subvalue">{{ data_get($observation, 'ehealthUpdatedAt') }}</div>
                    </div>

                    <div>
                        <div class="record-inner-label">{{ __('patients.interpretation') }}</div>
                        <div class="record-inner-subvalue">
                            {{ data_get($this->dictionaries, 'eHealth/observation_interpretations.' . data_get($observation, 'components.0.interpretation.coding.0.code'), '-') }}
                        </div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('patients.body_part') }}</div>
                        <div class="record-inner-subvalue">
                            {{ data_get($this->dictionaries, 'eHealth/body_sites.' . data_get($observation, 'bodySite.coding.0.code'), '-') }}
                        </div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('patients.doctor') }}</div>
                        <div class="record-inner-subvalue">
                            {{ data_get($observation, 'performer.displayValue', '-') }}
                        </div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('patients.created') }}</div>
                        <div class="record-inner-subvalue">{{ data_get($observation, 'ehealthInsertedAt') }}</div>
                    </div>
                </div>
            </div>
            <div class="record-inner-id-col">
                <div class="min-w-0">
                    <div class="record-inner-label">ID ECO3</div>
                    <div class="record-inner-id-value">{{ data_get($observation, 'uuid') }}</div>
                </div>
                <div class="min-w-0">
                    <div class="record-inner-label">{{ __('patients.medical_record_id') }}</div>
                    <div class="record-inner-id-value">
                        {{ data_get($observation, 'context.identifier.value', '-') }}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endforeach
