@use(App\Enums\Person\DiagnosticReportStatus)

@php
    $limit = $limit ?? null;
    $hasLimit = $limit && count($this->diagnosticReports) > $limit;
@endphp

<div @if ($hasLimit) x-data="{ limit: {{ $limit }} }" @endif>
    @foreach ($this->diagnosticReports as $index => $diagnosticReport)
        <div class="record-inner-card" @if ($hasLimit) x-show="limit > {{ $index }}" @endif>
            <div class="record-inner-header">
                <div class="record-inner-checkbox-col">
                    <input type="checkbox" class="default-checkbox h-5 w-5" />
                </div>

                <div class="record-inner-column flex-1">
                    <div class="record-inner-label">{{ __('medical-events.code_and_name') }}</div>
                    <div class="record-inner-value text-[16px]">
                        {{ data_get($diagnosticReport, 'code.displayValue') }}
                    </div>
                </div>

                <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                    <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                    <div>
                        @php($status = DiagnosticReportStatus::from(data_get($diagnosticReport, 'status')))
                        <span @class([$status->color()])> {{ $status->label() }} </span>
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
                    <div class="[&>div]:min-w-0 [&_.record-inner-subvalue]:break-words grid w-full grid-cols-2 gap-x-4 gap-y-4 xl:grid-cols-3">
                        <div>
                            <div class="record-inner-label">{{ __('forms.category') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($this->dictionaries, 'eHealth/diagnostic_report_categories.' . data_get($diagnosticReport, 'category.0.coding.0.code'), '-') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('medical-events.performer') }}</div>
                            <div class="record-inner-subvalue">
                                {{ collect(data_get($diagnosticReport, 'performer', []))->pluck('reference.displayValue')->filter()->join(', ') ?: '-' }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('patients.created') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($diagnosticReport, 'ehealthInsertedAt') }}
                            </div>
                        </div>

                        <div>
                            <div class="record-inner-label">{{ __('patients.referrals') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($diagnosticReport, 'paperReferral.requisition', '-') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('medical-events.conclusion') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($diagnosticReport, 'conclusion', '-') }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="record-inner-id-col">
                    <div class="min-w-0">
                        <div class="record-inner-label">ID ECO3</div>
                        <div class="record-inner-id-value">{{ data_get($diagnosticReport, 'uuid') }}</div>
                    </div>
                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('patients.medical_record_id') }}</div>
                        <div class="record-inner-id-value">
                            {{ data_get($diagnosticReport, 'encounter.identifier.value', '-') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    @if ($hasLimit)
        <div x-show="limit < {{ count($this->diagnosticReports) }}" class="mt-4 flex justify-start">
            <button type="button" @click="limit += 5" class="item-add">{{ __('patients.show_more') }}</button>
        </div>
    @endif
</div>
