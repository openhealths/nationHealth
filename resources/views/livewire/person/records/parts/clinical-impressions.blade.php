@use('App\Enums\Person\ClinicalImpressionStatus')

@foreach($this->clinicalImpressions as $clinicalImpression)
    <div class="record-inner-card">
        <div class="record-inner-header">
            <div class="record-inner-checkbox-col">
                <input type="checkbox" class="default-checkbox w-5 h-5">
            </div>

            <div class="record-inner-column flex-1">
                <div class="record-inner-label">{{ __('forms.code') }}</div>
                <div class="record-inner-value text-[16px]">
                    {{ data_get($this->dictionaries, 'eHealth/clinical_impression_patient_categories.' . data_get($clinicalImpression, 'code.coding.0.code'), data_get($clinicalImpression, 'code.coding.0.code', '-')) }}
                </div>
            </div>

            <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                <div>
                    <span class="badge-green">
                        {{ ClinicalImpressionStatus::from(data_get($clinicalImpression, 'status'))->label() }}
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
                <div class="flex items-start justify-between gap-2 xl:gap-4 overflow-hidden">
                    <div>
                        <div class="record-inner-label">{{ __('patients.created') }}</div>
                        <div class="record-inner-value">{{ data_get($clinicalImpression, 'ehealthInsertedAt', '-') }}</div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('forms.start') }}</div>
                        <div class="record-inner-value">
                            {{ data_get($clinicalImpression, 'effectivePeriod.start', '-') }}
                        </div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('forms.end') }}</div>
                        <div class="record-inner-value">
                            {{ data_get($clinicalImpression, 'effectivePeriod.end', '-') }}
                        </div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('patients.doctor') }}</div>
                        <div class="record-inner-value">
                            {{ data_get($clinicalImpression, 'assessor.display_value', '-') }}
                        </div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('patients.clinical_impression_conclusion') }}</div>
                        <div class="record-inner-value">{{ data_get($clinicalImpression, 'summary', '-') }}</div>
                    </div>
                </div>
            </div>

            <div class="record-inner-id-col">
                <div class="min-w-0">
                    <div class="record-inner-label">ID ECO3</div>
                    <div class="record-inner-id-value">{{ data_get($clinicalImpression, 'uuid', '-') }}</div>
                </div>
                <div class="min-w-0">
                    <div class="record-inner-label">ID Епізоду</div>
                    <div class="record-inner-id-value">
                        @php
                            $clinicalImpressionValue = '';
                            foreach (data_get($clinicalImpression, 'supportingInfo', []) as $info) {
                                if (data_get($info, 'identifier.type.coding.0.code') === 'episode_of_care') {
                                    $clinicalImpressionValue = data_get($info, 'identifier.value', '');
                                    break;
                                }
                            }
                        @endphp
                        {{ $clinicalImpressionValue ?: '-' }}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endforeach
