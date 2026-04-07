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
                    {{ $this->dictionaries['eHealth/clinical_impression_patient_categories'][$clinicalImpression['code']['coding'][0]['code']] }}
                </div>
            </div>

            <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                <div>
                    <span class="record-inner-status-badge">
                        {{ ClinicalImpressionStatus::from($clinicalImpression['status'])->label() }}
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
                        <div class="record-inner-value">{{ $clinicalImpression['ehealthInsertedAt'] ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('forms.start') }}</div>
                        <div class="record-inner-value">
                            {{ $clinicalImpression['effectivePeriod']['start'] ?? '-' }}
                        </div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('forms.end') }}</div>
                        <div class="record-inner-value">
                            {{ $clinicalImpression['effectivePeriod']['end'] ?? '-' }}
                        </div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('patients.doctor') }}</div>
                        <div class="record-inner-value">
                            {{ $clinicalImpression['assessor']['display_value'] ?? '-' }}
                        </div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('patients.clinical_impression_conclusion') }}</div>
                        <div class="record-inner-value">{{ $clinicalImpression['summary'] ?? '-' }}</div>
                    </div>
                </div>
            </div>

            <div class="record-inner-id-col">
                <div class="min-w-0">
                    <div class="record-inner-label">ID ECO3</div>
                    <div class="record-inner-id-value">{{ $clinicalImpression['uuid'] }}</div>
                </div>
                <div class="min-w-0">
                    <div class="record-inner-label">ID Епізоду</div>
                    <div class="record-inner-id-value">
                        @php
                            $episodeValue = '';
                            foreach ($clinicalImpression['supportingInfo'] ?? [] as $info) {
                                if ($info['identifier']['type']['coding'][0]['code'] === 'episode_of_care') {
                                    $episodeValue = $info['identifier']['value'];
                                    break;
                                }
                            }
                        @endphp
                        {{ $episodeValue ?: '-' }}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endforeach
