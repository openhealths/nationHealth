@use('App\Enums\ClinicalImpression\Status')

@php
    $limit = $limit ?? null;
    $hasLimit = $limit && count($this->clinicalImpressions) > $limit;
@endphp

<div @if ($hasLimit) x-data="{ limit: {{ $limit }} }" @endif>
    @foreach ($this->clinicalImpressions as $index => $clinicalImpression)
        <div class="record-inner-card" @if ($hasLimit) x-show="limit > {{ $index }}" @endif>
            <div class="record-inner-header">
                <div class="record-inner-checkbox-col">
                    <input type="checkbox" class="default-checkbox h-5 w-5" />
                </div>

                <div class="record-inner-column flex-1">
                    <div class="record-inner-label">{{ __('clinical-impressions.code') }}</div>
                    <div class="record-inner-value text-[16px] font-semibold dark:text-gray-100">
                        {{ $this->dictionaryLabel($clinicalImpression, 'code') }}
                    </div>
                </div>

                <div class="record-inner-column-bordered flex h-full w-full shrink-0 flex-col justify-center gap-1 md:w-36">
                    <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                    <div>
                        @php($status = Status::from(data_get($clinicalImpression, 'status')))
                        <span @class([$status->color()])> {{ $status->label() }} </span>
                    </div>
                </div>

                <div class="record-inner-action-col">
                    <x-patient.record-actions
                        :href="route(
                            $prepersonId !== null ? 'prepersons.clinical-impressions.view' : 'persons.clinical-impressions.view',
                            [legalEntity(), $prepersonId !== null ? 'preperson' : 'person' => $prepersonId ?? $personId, 'clinicalImpression' => data_get($clinicalImpression, 'id')]
                        )"
                    />
                </div>
            </div>

            <div class="record-inner-body">
                <div class="record-inner-grid-container">
                    <div class="[&>div]:min-w-0 [&_.record-inner-value]:wrap-break-word grid w-full grid-cols-2 gap-x-4 gap-y-4 xl:grid-cols-5">
                        <div>
                            <div class="record-inner-label">{{ __('clinical-impressions.inserted_at') }}</div>
                            <div class="record-inner-value">
                                {{ data_get($clinicalImpression, 'ehealthInsertedAt', '-') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">
                                {{ __('clinical-impressions.effective_period_start') }}
                            </div>
                            <div class="record-inner-value">
                                {{ data_get($clinicalImpression, 'effectivePeriod.start', '-') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('clinical-impressions.effective_period_end') }}</div>
                            <div class="record-inner-value">
                                {{ data_get($clinicalImpression, 'effectivePeriod.end', '-') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('clinical-impressions.assessor') }}</div>
                            <div class="record-inner-value">
                                {{ data_get($clinicalImpression, 'assessor.displayValue', '-') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('clinical-impressions.summary') }}</div>
                            <div class="record-inner-value">{{ data_get($clinicalImpression, 'summary', '-') }}</div>
                        </div>
                    </div>
                </div>

                <div class="record-inner-id-col">
                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('forms.ehealth_id') }}</div>
                        <div class="record-inner-id-value">{{ data_get($clinicalImpression, 'uuid', '-') }}</div>
                    </div>
                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('clinical-impressions.episode_id') }}</div>
                        <div class="record-inner-id-value">
                            @php
                                $episodeValue = '';
                                foreach (data_get($clinicalImpression, 'supportingInfo', []) as $info) {
                                    $typeCode = data_get($info, 'identifier.type.coding.0.code') ?? data_get($info, 'identifier.type.0.coding.0.code');
                                    if ($typeCode === 'episode_of_care') {
                                        $episodeValue = data_get($info, 'identifier.value', '');
                                        break;
                                    }
                                }
                            @endphp
                            {{ $episodeValue ?: data_get($clinicalImpression, 'uuid', '-') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    @if ($hasLimit)
        <div x-show="limit < {{ count($this->clinicalImpressions) }}" class="mt-4 flex justify-start">
            <button type="button" @click="limit += 5" class="item-add">{{ __('general.show_more') }}</button>
        </div>
    @endif
</div>
