@php
    use App\Enums\Person\ConditionClinicalStatus;
    use App\Enums\Person\ConditionVerificationStatus;
@endphp

@php
    $limit = $limit ?? null;
    $hasLimit = $limit && count($this->conditions) > $limit;
@endphp

<div @if($hasLimit) x-data="{ limit: {{ $limit }} }" @endif>
    @foreach($this->conditions as $index => $condition)
        <div class="record-inner-card" @if($hasLimit) x-show="limit > {{ $index }}" @endif>
            <div class="record-inner-header">
                <div class="record-inner-checkbox-col">
                    <input type="checkbox" class="default-checkbox w-5 h-5">
                </div>

                @php
                    $system = data_get($condition, 'code.coding.0.system');
                    $code = data_get($condition, 'code.coding.0.code');

                    $codeLabel = $this->dictionaries[$system][$code] ?? $code;
                @endphp
                <div class="record-inner-column flex-1">
                    <div class="record-inner-label">{{ __('patients.code_and_name') }}</div>
                    <div class="record-inner-value text-[16px]">
                        {{ $code }} - {{ $codeLabel }}
                    </div>
                </div>

                <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
                    <div class="record-inner-label">{{ __('patients.status_clinical') }}</div>
                    <div>
                        @php($status = ConditionClinicalStatus::from(data_get($condition, 'clinicalStatus')))
                        <span @class([$status->color()])>
                            {{ $status->label() ?? '-' }}
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
                        class="grid grid-cols-2 xl:grid-cols-4 gap-y-4 gap-x-4 w-full [&>div]:min-w-0 [&_.record-inner-subvalue]:break-words">
                        <div>
                            <div class="record-inner-label">{{ __('forms.type') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($this->dictionaries, 'eHealth/report_origins.' . data_get($condition, 'reportOrigin.coding.0.code'), '-') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('patients.verification_status') }}</div>
                            <div class="record-inner-subvalue">
                                @php($verificationStatus = ConditionVerificationStatus::from(data_get($condition, 'verificationStatus')))
                                <span @class([$verificationStatus->color()])>
                                    {{ $verificationStatus->label() ?? '-' }}
                                </span>
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('patients.body_part') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($condition, 'bodySites.0.coding.0.code', '-') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('patients.created') }}</div>
                            <div class="record-inner-subvalue">{{ data_get($condition, 'assertedDate', '-') }}</div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('patients.doctor') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($condition, 'asserter.displayValue', '-') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('patients.severity') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($this->dictionaries, 'eHealth/condition_severities.' . data_get($condition, 'severity.coding.0.code'), '-') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('forms.start_date') }}</div>
                            <div class="record-inner-subvalue">{{ data_get($condition, 'ehealthInsertedAt') }}</div>
                        </div>
                    </div>
                </div>

                <div class="record-inner-id-col">
                    <div class="min-w-0">
                        <div class="record-inner-label">ID ECO3</div>
                        <div class="record-inner-id-value">{{ $condition['uuid'] }}</div>
                    </div>
                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('patients.medical_record_id') }}</div>
                        <div class="record-inner-id-value">{{ $condition['context']['identifier']['value'] }}</div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    @if($hasLimit)
        <div x-show="limit < {{ count($this->conditions) }}" class="flex justify-start mt-4">
            <button type="button"
                    @click="limit += 5"
                    class="item-add"
            >
                {{ __('patients.show_more') }}
            </button>
        </div>
    @endif
</div>
