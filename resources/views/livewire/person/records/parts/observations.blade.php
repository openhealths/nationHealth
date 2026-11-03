@use(App\Enums\Person\ObservationStatus)
@use(Carbon\CarbonImmutable)

@php
    $limit = $limit ?? null;
    $hasLimit = $limit && count($this->observations) > $limit;
@endphp

<div @if ($hasLimit) x-data="{ limit: {{ $limit }} }" @endif>
    @foreach ($this->observations as $index => $observation)
        <div class="record-inner-card" @if ($hasLimit) x-show="limit > {{ $index }}" @endif>
            <div class="record-inner-header">
                <div class="record-inner-checkbox-col">
                    <input type="checkbox" class="default-checkbox h-5 w-5" />
                </div>

                @php
                    $categorySystem = data_get($observation, 'categories.0.coding.0.system');
                    $categoryCode = data_get($observation, 'categories.0.coding.0.code');

                    $codeSystem = data_get($observation, 'code.coding.0.system');
                    $codeValue = data_get($observation, 'code.coding.0.code');

                    $categoryLabel = $this->dictionaries[$categorySystem][$categoryCode] ?? $categoryCode;
                    $codeLabel = $this->dictionaries[$codeSystem][$codeValue] ?? $codeValue;

                    $observationValue = collect([
                        data_get($observation, 'value'),
                        ...collect(data_get($observation, 'components', []))->pluck('value')
                    ])
                        ->filter()
                        ->map(fn (array $value): ?string => match (true) {
                            data_get($value, 'valueBoolean') !== null => data_get($value, 'valueBoolean') ? __('forms.yes') : __('forms.no'),
                            data_get($value, 'valueString') !== null => data_get($value, 'valueString'),
                            data_get($value, 'valueDateTime') !== null => CarbonImmutable::parse(data_get($value, 'valueDateTime'))
                                ->setTimezone(config('app.timezone'))
                                ->format('d.m.Y H:i'),
                            data_get($value, 'valueQuantity') !== null => collect([
                                data_get($value, 'valueQuantity.comparator'),
                                data_get($value, 'valueQuantity.value'),
                                data_get($value, 'valueQuantity.unit')
                            ])->filter()->implode(' '),
                            data_get($value, 'valueCodeableConcept') !== null => $this->dictionaryLabel($value, 'valueCodeableConcept'),
                            default => null
                        })
                        ->filter()
                        ->implode(', ');
                @endphp
                <div class="record-inner-column flex-1">
                    <div class="record-inner-label">{{ __('observations.category_and_code') }}</div>
                    <div class="record-inner-value text-[16px]">{{ $categoryLabel }} {{ $codeLabel }}</div>
                </div>

                <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                    <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                    <div>
                        @php($status = ObservationStatus::from(data_get($observation, 'status')))
                        <span @class([$status->color()])> {{ $status->label() }} </span>
                    </div>
                </div>

                <div class="record-inner-action-col">
                    <x-patient.record-actions
                        :href="route(
                            $prepersonId !== null ? 'prepersons.observations.view' : 'persons.observations.view',
                            [legalEntity(), $prepersonId !== null ? 'preperson' : 'person' => $prepersonId ?? $personId, 'observation' => data_get($observation, 'id')]
                        )"
                    />
                </div>
            </div>

            <div class="record-inner-body">
                <div class="record-inner-grid-container">
                    <div class="[&>div]:min-w-0 [&_.record-inner-subvalue]:wrap-break-word grid w-full grid-cols-2 gap-x-4 gap-y-4 xl:grid-cols-5">
                        <div>
                            <div class="record-inner-label">{{ __('medical-events.information_source') }}</div>
                            <div class="record-inner-subvalue">
                                {{ $this->dictionaryLabel($observation, 'reportOrigin') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('observations.method') }}</div>
                            <div class="record-inner-subvalue">
                                {{ $this->dictionaryLabel($observation, 'method') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('observations.value') }}</div>
                            <div class="record-inner-subvalue">{{ $observationValue ?: '-' }}</div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('observations.getting_indicators') }}</div>
                            <div class="record-inner-subvalue">
                                @if (data_get($observation, 'effectivePeriodStartDate'))
                                    {{ data_get($observation, 'effectivePeriodStartDate') }}
                                    &ndash;
                                    {{ data_get($observation, 'effectivePeriodEndDate') ?: '-' }}
                                @else
                                    {{ data_get($observation, 'effectiveDateTime') ? convertToAppDateFormat(data_get($observation, 'effectiveDateTime')) : '-' }}
                                @endif
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('observations.updated_at') }}</div>
                            <div class="record-inner-subvalue">{{ data_get($observation, 'ehealthUpdatedAt') }}</div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('observations.interpretation') }}</div>
                            <div class="record-inner-subvalue">
                                {{ $this->dictionaryLabel($observation, 'components.0.interpretation') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('observations.body_site') }}</div>
                            <div class="record-inner-subvalue">
                                {{ $this->dictionaryLabel($observation, 'bodySite') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('observations.doctor') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($observation, 'performer.displayValue') ?? '-' }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('observations.inserted_at') }}</div>
                            <div class="record-inner-subvalue">{{ data_get($observation, 'ehealthInsertedAt') }}</div>
                        </div>
                    </div>
                </div>
                <div class="record-inner-id-col">
                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('forms.ehealth_id') }}</div>
                        <div class="record-inner-id-value">{{ data_get($observation, 'uuid') }}</div>
                    </div>
                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('medical-events.medical_record_id') }}</div>
                        <div class="record-inner-id-value">
                            {{ data_get($observation, 'context.identifier.value', '-') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    @if ($hasLimit)
        <div x-show="limit < {{ count($this->observations) }}" class="mt-4 flex justify-start">
            <button type="button" @click="limit += 5" class="item-add">{{ __('general.show_more') }}</button>
        </div>
    @endif
</div>
