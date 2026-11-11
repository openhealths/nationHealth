@use('App\Enums\Person\EncounterStatus')

@php
    $limit = $limit ?? null;
    $hasLimit = $limit && count($this->encounters) > $limit;
@endphp

<div @if ($hasLimit) x-data="{ limit: {{ $limit }} }" @endif>
    @foreach ($this->encounters as $index => $encounter)
        <div class="record-inner-card" @if ($hasLimit) x-show="limit > {{ $index }}" @endif>
            <div class="record-inner-header">
                <div class="record-inner-checkbox-col">
                    <input type="checkbox" class="default-checkbox h-5 w-5" />
                </div>

                <div class="record-inner-column flex-1">
                    <div class="record-inner-label">{{ __('encounters.period') }}</div>
                    <div class="record-inner-value text-[20px] font-semibold">
                        {{ data_get($encounter, 'period.start', '-') }}
                    </div>
                </div>

                <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                    <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                    <div>
                        @php($status = EncounterStatus::from(data_get($encounter, 'status')))
                        <span @class([$status->color()])> {{ $status->label() }} </span>
                    </div>
                </div>

                <div class="record-inner-action-col">
                    <x-patient.record-actions
                        :href="route(
                            $prepersonId !== null ? 'prepersons.encounters.show' : 'persons.encounters.show',
                            [legalEntity(), $prepersonId !== null ? 'preperson' : 'person' => $prepersonId ?? $personId, 'encounter' => data_get($encounter, 'id')]
                        )"
                    />
                </div>
            </div>

            <div class="record-inner-body">
                <div class="record-inner-grid-container">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="min-w-0">
                            <div class="record-inner-label">{{ __('encounters.interaction_class') }}</div>
                            <div class="record-inner-value truncate">
                                {{ $this->dictionaryLabel($encounter, 'class') }}
                            </div>
                        </div>

                        <div class="min-w-0">
                            <div class="record-inner-label">{{ __('encounters.interaction_type') }}</div>
                            <div class="record-inner-value truncate">
                                {{ $this->dictionaryLabel($encounter, 'type') }}
                            </div>
                        </div>

                        <div class="min-w-0">
                            <div class="record-inner-label">{{ __('encounters.performer_speciality') }}</div>
                            <div class="record-inner-value truncate">
                                {{ $this->dictionaries['SPECIALITY_TYPE'][data_get($encounter, 'performerSpeciality.coding.0.code')] ?? '-' }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="record-inner-id-col">
                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('forms.ehealth_id') }}</div>
                        <div class="record-inner-id-value">{{ data_get($encounter, 'uuid', '-') }}</div>
                    </div>
                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('encounters.episode_id') }}</div>
                        <div class="record-inner-id-value">
                            {{ data_get($encounter, 'episode.identifier.value', '-') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    @if ($hasLimit)
        <div x-show="limit < {{ count($this->encounters) }}" class="mt-4 flex justify-start">
            <button type="button" @click="limit += 5" class="item-add">{{ __('general.show_more') }}</button>
        </div>
    @endif
</div>
