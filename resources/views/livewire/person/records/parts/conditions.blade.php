@php
    use App\Enums\Person\ConditionClinicalStatus;
    use App\Enums\Person\ConditionVerificationStatus;
@endphp

@foreach($this->conditions as $condition)
    <div class="record-inner-card">
        <div class="record-inner-header">
            <div class="record-inner-checkbox-col">
                <input type="checkbox" class="default-checkbox w-5 h-5">
            </div>

            @php
                $system = data_get($condition, 'code.coding.0.system');
                $code = data_get($condition, 'code.coding.0.code');

                $codeLabel = $this->dictionaries[$system][$code];
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
                    <span class="badge-green">
                        {{ ConditionClinicalStatus::from(data_get($condition, 'clinicalStatus'))->label() }}
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
                            {{ ConditionClinicalStatus::from(data_get($condition, 'clinicalStatus'))->label() }}
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
                        <div class="record-inner-label">{{ __('patients.start_date') }}</div>
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
