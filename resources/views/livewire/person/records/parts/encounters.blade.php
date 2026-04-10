@use('App\Enums\Person\EncounterStatus')

@foreach($this->encounters as $encounter)
    <div class="record-inner-card">
        <div class="record-inner-header">
            <div class="record-inner-checkbox-col">
                <input type="checkbox" class="default-checkbox w-5 h-5">
            </div>

            <div class="record-inner-column flex-1">
                <div class="record-inner-label">{{ __('forms.date') }}</div>
                <div class="record-inner-value text-[20px] font-semibold">
                    {{ data_get($encounter, 'period.start', '-') }}
                </div>
            </div>

            <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                <div>
                    <span class="badge-green">
                        {{ EncounterStatus::from(data_get($encounter, 'status'))->label() }}
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
                    <div class="flex-1 min-w-0">
                        <div class="record-inner-label">{{ __('patients.class') }}</div>
                        <div class="record-inner-value truncate">
                            {{ data_get($this->dictionaries, 'eHealth/encounter_classes.' . data_get($encounter, 'class.code'), data_get($encounter, 'class.code', '-')) }}
                        </div>
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="record-inner-label">{{ __('forms.type') }}</div>
                        <div class="record-inner-value truncate">
                            {{ data_get($this->dictionaries, 'eHealth/encounter_types.' . data_get($encounter, 'type.coding.0.code'), data_get($encounter, 'type.coding.0.code', '-')) }}
                        </div>
                    </div>

                    <div class="flex-1 min-w-0">
                        <div class="record-inner-label">{{ __('patients.doctor_speciality') }}</div>
                        <div class="record-inner-value truncate">
                            {{ $this->dictionaries['SPECIALITY_TYPE'][data_get($encounter, 'performer_speciality.coding.0.code')] ?? '-' }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="record-inner-id-col">
                <div class="min-w-0">
                    <div class="record-inner-label">ID ECO3</div>
                    <div class="record-inner-id-value">{{ data_get($encounter, 'uuid', '-') }}</div>
                </div>
                <div class="min-w-0">
                    <div class="record-inner-label">ID Епізоду</div>
                    <div class="record-inner-id-value">{{ data_get($encounter, 'episode.identifier.value', '-') }}</div>
                </div>
            </div>
        </div>
    </div>
@endforeach
