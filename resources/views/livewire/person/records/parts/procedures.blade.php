@use(App\Enums\Person\ProcedureStatus)
@use(App\Repositories\MedicalEvents\Repository)

@php
    $limit = $limit ?? null;
    $hasLimit = $limit && count($this->procedures) > $limit;
@endphp

<div @if ($hasLimit) x-data="{ limit: {{ $limit }} }" @endif>
    @foreach ($this->procedures as $index => $procedure)
        <div class="record-inner-card" @if ($hasLimit) x-show="limit > {{ $index }}" @endif>
            <div class="record-inner-header">
                <div class="record-inner-checkbox-col">
                    <input type="checkbox" class="default-checkbox h-5 w-5" />
                </div>

                <div class="record-inner-column flex-1">
                    <div class="record-inner-label">{{ __('medical-events.code_and_name') }}</div>
                    <div class="record-inner-value text-[16px]">
                        {{
                            data_get($procedure, 'code.displayValue')
                            ?: data_get($procedure, 'code.identifier.value', '-')
                        }}
                    </div>
                </div>

                <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                    <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                    <div>
                        @php($status = ProcedureStatus::from(data_get($procedure, 'status')))

                        <span class="{{ $status->color() }}">{{ $status->label() }}</span>
                    </div>
                </div>

                <div class="record-inner-action-col">
                    <x-patient.record-actions
                        :href="route(
                            $prepersonId !== null ? 'prepersons.procedure.view' : 'procedure.view',
                            [legalEntity(), $prepersonId !== null ? 'preperson' : 'person' => $prepersonId ?? $personId, 'procedure' => data_get($procedure, 'id')]
                        )"
                    />
                </div>
            </div>

            <div class="record-inner-body">
                <div class="record-inner-grid-container">
                    <div class="[&>div]:min-w-0 [&_.record-inner-subvalue]:wrap-break-word grid w-full grid-cols-2 gap-x-4 gap-y-4 xl:grid-cols-3">
                        <div>
                            <div class="record-inner-label">{{ __('procedures.category') }}</div>
                            <div class="record-inner-subvalue">
                                {{
                                    data_get(
                                        $this->dictionaries,
                                        'eHealth/procedure_categories.' . data_get($procedure, 'category.coding.0.code'),
                                        '-'
                                    )
                                }}
                            </div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('medical-events.performer') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($procedure, 'performer.displayValue', '-') }}
                            </div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('procedures.inserted_at') }}</div>
                            <div class="record-inner-subvalue">
                                @if (data_get($procedure, 'performedDate'))
                                    {{ data_get($procedure, 'performedDate') }} {{ data_get($procedure, 'performedTime') }}
                                @elseif (data_get($procedure, 'performedPeriodStartDate'))
                                    {{ data_get($procedure, 'performedPeriodStartDate') }}
                                    {{ data_get($procedure, 'performedPeriodStartTime') }}
                                    -
                                    {{ data_get($procedure, 'performedPeriodEndDate') }}
                                    {{ data_get($procedure, 'performedPeriodEndTime') }}
                                @else
                                    -
                                @endif
                            </div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('procedures.paper_referral_requisition') }}</div>
                            <div class="record-inner-subvalue">
                                {{ Repository::serviceRequest()->procedureReferralLabel($procedure) }}
                            </div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('procedures.outcome_result') }}</div>
                            <div class="record-inner-subvalue">
                                {{
                                    data_get(
                                        $this->dictionaries,
                                        'eHealth/procedure_outcomes.' . data_get($procedure, 'outcome.coding.0.code'),
                                        '-'
                                    )
                                }}
                            </div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('procedures.note') }}</div>
                            <div class="record-inner-subvalue">{{ data_get($procedure, 'note', '-') }}</div>
                        </div>
                    </div>
                </div>

                <div class="record-inner-id-col">
                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('forms.ehealth_id') }}</div>
                        <div class="record-inner-id-value">{{ data_get($procedure, 'uuid') }}</div>
                    </div>

                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('medical-events.medical_record_id') }}</div>
                        <div class="record-inner-id-value">
                            {{ data_get($procedure, 'encounter.identifier.value', '-') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    @if ($hasLimit)
        <div x-show="limit < {{ count($this->procedures) }}" class="mt-4 flex justify-start">
            <button type="button" @click="limit += 5" class="item-add">{{ __('general.show_more') }}</button>
        </div>
    @endif
</div>
