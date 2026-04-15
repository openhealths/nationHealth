@use(App\Enums\Person\DiagnosticReportStatus)

@foreach($this->diagnosticReports as $diagnosticReport)
    <div class="record-inner-card">
        <div class="record-inner-header">
            <div class="record-inner-checkbox-col">
                <input type="checkbox" class="default-checkbox w-5 h-5">
            </div>

            <div class="record-inner-column flex-1">
                <div class="record-inner-label">{{ __('patients.code_and_name') }}</div>
                <div class="record-inner-value text-[16px]">{{ data_get($diagnosticReport, 'code.dislayValue') }}</div>
            </div>

            <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                <div>
                    <span class="badge-yellow">
                         {{ DiagnosticReportStatus::from(data_get($diagnosticReport, 'status'))->label() }}
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
                    class="grid grid-cols-2 xl:grid-cols-3 gap-y-4 gap-x-4 w-full [&>div]:min-w-0 [&_.record-inner-subvalue]:break-words">
                    <div>
                        <div class="record-inner-label">{{ __('forms.category') }}</div>
                        <div class="record-inner-subvalue">
                            {{ data_get($this->dictionaries, 'eHealth/diagnostic_report_categories.' . data_get($diagnosticReport, 'category.0.coding.0.code'), '-') }}
                        </div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('patients.performer') }}</div>
                        <div class="record-inner-subvalue">
                            {{ data_get($diagnosticReport, 'performer.displayValue', '-') }}
                        </div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('patients.created') }}</div>
                        <div class="record-inner-subvalue">{{ data_get($diagnosticReport, 'ehealthInsertedAt') }}</div>
                    </div>

                    <div>
                        <div class="record-inner-label">{{ __('patients.referrals') }}</div>
                        <div class="record-inner-subvalue">
                            {{ data_get($diagnosticReport, 'paperReferral.requisition', '-') }}
                        </div>
                    </div>
                    <div>
                        <div class="record-inner-label">{{ __('patients.conclusion') }}</div>
                        <div class="record-inner-subvalue">{{ data_get($diagnosticReport, 'conclusion', '-') }}</div>
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
