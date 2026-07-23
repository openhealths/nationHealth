@use(App\Enums\Person\ProcedureStatus)

@php
    $limit = $limit ?? null;
    $hasLimit = $limit && count($this->procedures) > $limit;
@endphp

<div @if($hasLimit) x-data="{ limit: {{ $limit }} }" @endif>
    @foreach($this->procedures as $index => $procedure)
        <div class="record-inner-card" @if($hasLimit) x-show="limit > {{ $index }}" @endif>
            <div class="record-inner-header">
                <div class="record-inner-checkbox-col">
                    <input type="checkbox" class="default-checkbox w-5 h-5">
                </div>

                <div class="record-inner-column flex-1">
                    <div class="record-inner-label">{{ __('patients.code_and_name') }}</div>
                    <div class="record-inner-value text-[16px]">
                        {{ data_get($procedure, 'code.displayValue')
                            ?: data_get($procedure, 'code.identifier.value', '-') }}
                    </div>
                </div>

                <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
                    <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                    <div>
                        @php($status = ProcedureStatus::from(data_get($procedure, 'status')))

                        <span @class([
                            'badge-green' => $status === ProcedureStatus::COMPLETED,
                            'badge-red' => $status === ProcedureStatus::ENTERED_IN_ERROR,
                            'badge-dark' => $status === ProcedureStatus::NOT_DONE,
                        ])>
                            {{ $status->label() }}
                        </span>
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
                    <div
                        class="grid grid-cols-2 xl:grid-cols-3 gap-y-4 gap-x-4 w-full [&>div]:min-w-0 [&_.record-inner-subvalue]:break-words">
                        <div>
                            <div class="record-inner-label">{{ __('forms.category') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get(
                                    $this->dictionaries,
                                    'eHealth/procedure_categories.' . data_get($procedure, 'category.coding.0.code'),
                                    '-'
                                ) }}
                            </div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('patients.performer') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($procedure, 'performer.displayValue', '-') }}
                            </div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('patients.created') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($procedure, 'performedDate') ?: data_get($procedure, 'performedPeriodStartDate', '-') }}
                            </div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('patients.referrals') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($procedure, 'paperReferral.requisition')
                                    ?: data_get($procedure, 'basedOn.identifier.value', '-') }}
                            </div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('patients.result') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get(
                                    $this->dictionaries,
                                    'eHealth/procedure_outcomes.' . data_get($procedure, 'outcome.coding.0.code'),
                                    '-'
                                ) }}
                            </div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('patients.notes') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($procedure, 'note', '-') }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="record-inner-id-col">
                    <div class="min-w-0">
                        <div class="record-inner-label">ID ECO3</div>
                        <div class="record-inner-id-value">
                            {{ data_get($procedure, 'uuid') }}
                        </div>
                    </div>

                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('patients.medical_record_id') }}</div>
                        <div class="record-inner-id-value">
                            {{ data_get($procedure, 'encounter.identifier.value', '-') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    @if($hasLimit)
        <div x-show="limit < {{ count($this->procedures) }}" class="flex justify-start mt-4">
            <button type="button"
                    @click="limit += 5"
                    class="item-add"
            >
                {{ __('patients.show_more') }}
            </button>
        </div>
    @endif
</div>