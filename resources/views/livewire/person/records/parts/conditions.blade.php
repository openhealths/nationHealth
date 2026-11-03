@php
    use App\Enums\Person\ConditionClinicalStatus;
    use App\Enums\Person\ConditionVerificationStatus;
@endphp

@php
    $limit = $limit ?? null;
    $hasLimit = $limit && count($this->conditions) > $limit;
@endphp

<div @if ($hasLimit) x-data="{ limit: {{ $limit }} }" @endif>
    @foreach ($this->conditions as $index => $condition)
        <div class="record-inner-card" @if ($hasLimit) x-show="limit > {{ $index }}" @endif>
            <div class="record-inner-header">
                <div class="record-inner-checkbox-col">
                    <input type="checkbox" class="default-checkbox h-5 w-5" />
                </div>

                @php
                    $system = data_get($condition, 'code.coding.0.system');
                    $code = data_get($condition, 'code.coding.0.code');

                    $codeLabel = $this->dictionaries[$system][$code] ?? $code;
                @endphp
                <div class="record-inner-column flex-1">
                    <div class="record-inner-label">{{ __('medical-events.code_and_name') }}</div>
                    <div class="record-inner-value text-[16px]">{{ $code }} - {{ $codeLabel }}</div>
                </div>

                <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                    <div class="record-inner-label">{{ __('conditions.clinical_status') }}</div>
                    <div>
                        @php($status = ConditionClinicalStatus::from(data_get($condition, 'clinicalStatus')))
                        <span @class([$status->color()])> {{ $status->label() ?? '-' }} </span>
                    </div>
                </div>

                <div class="record-inner-action-col">
                    <x-patient.record-actions
                        :href="route(
                            $prepersonId !== null ? 'prepersons.conditions.view' : 'persons.conditions.view',
                            [legalEntity(), $prepersonId !== null ? 'preperson' : 'person' => $prepersonId ?? $personId, 'condition' => data_get($condition, 'id')]
                        )"
                    />
                </div>
            </div>

            <div class="record-inner-body">
                <div class="record-inner-grid-container">
                    <div class="[&>div]:min-w-0 [&_.record-inner-subvalue]:wrap-break-word grid w-full grid-cols-2 gap-x-4 gap-y-4 xl:grid-cols-4">
                        <div>
                            <div class="record-inner-label">{{ __('conditions.report_origin') }}</div>
                            <div class="record-inner-subvalue">
                                {{ $this->dictionaryLabel($condition, 'reportOrigin') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('conditions.verification_status') }}</div>
                            <div class="record-inner-subvalue">
                                @php($verificationStatus = ConditionVerificationStatus::from(data_get($condition, 'verificationStatus')))
                                <span @class([$verificationStatus->color()])>
                                    {{ $verificationStatus->label() ?? '-' }}
                                </span>
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('conditions.body_sites') }}</div>
                            <div class="record-inner-subvalue">
                                {{ $this->dictionaryLabel($condition, 'bodySites.0') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('conditions.asserted_date') }}</div>
                            <div class="record-inner-subvalue">{{ data_get($condition, 'assertedDate', '-') }}</div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('conditions.doctor') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($condition, 'asserter.displayValue', '-') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('conditions.severity') }}</div>
                            <div class="record-inner-subvalue">
                                {{ $this->dictionaryLabel($condition, 'severity') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('conditions.onset_date') }}</div>
                            <div class="record-inner-subvalue">{{ data_get($condition, 'onsetDate') }}</div>
                        </div>
                    </div>
                </div>

                <div class="record-inner-id-col">
                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('forms.ehealth_id') }}</div>
                        <div class="record-inner-id-value">{{ $condition['uuid'] }}</div>
                    </div>
                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('medical-events.medical_record_id') }}</div>
                        <div class="record-inner-id-value">{{ $condition['context']['identifier']['value'] }}</div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    @if ($hasLimit)
        <div x-show="limit < {{ count($this->conditions) }}" class="mt-4 flex justify-start">
            <button type="button" @click="limit += 5" class="item-add">{{ __('general.show_more') }}</button>
        </div>
    @endif
</div>
