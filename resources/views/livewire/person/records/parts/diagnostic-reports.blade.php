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
                    <x-patient.record-actions
                        :href="route(
                            $prepersonId !== null ? 'prepersons.diagnostic-report.view' : 'diagnostic-report.view',
                            [legalEntity(), $prepersonId !== null ? 'preperson' : 'person' => $prepersonId ?? $personId, 'diagnosticReport' => data_get($diagnosticReport, 'id')]
                        )"
                    />
                </div>
            </div>

            <div class="record-inner-body">
                <div class="record-inner-grid-container">
                    <div class="[&>div]:min-w-0 [&_.record-inner-subvalue]:wrap-break-word grid w-full grid-cols-2 gap-x-4 gap-y-4 xl:grid-cols-3">
                        <div>
                            <div class="record-inner-label">{{ __('diagnostic-reports.category') }}</div>
                            <div class="record-inner-subvalue">
                                {{ $this->dictionaryLabel($diagnosticReport, 'category.0') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('medical-events.performer') }}</div>
                            <div class="record-inner-subvalue">
                                {{ collect(data_get($diagnosticReport, 'performer', []))->pluck('reference.displayValue')->filter()->join(', ') ?: '-' }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('diagnostic-reports.inserted_at') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($diagnosticReport, 'ehealthInsertedAt') }}
                            </div>
                        </div>

                        <div>
                            <div class="record-inner-label">
                                {{ __('diagnostic-reports.paper_referral_requisition') }}
                            </div>
                            <div class="record-inner-subvalue">
                                {{ data_get($diagnosticReport, 'paperReferral.requisition', '-') }}
                            </div>
                        </div>
                        <div>
                            <div class="record-inner-label">{{ __('diagnostic-reports.conclusion') }}</div>
                            <div class="record-inner-subvalue">
                                {{ data_get($diagnosticReport, 'conclusion', '-') }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="record-inner-id-col">
                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('forms.ehealth_id') }}</div>
                        <div class="record-inner-id-value">{{ data_get($diagnosticReport, 'uuid') }}</div>
                    </div>
                    <div class="min-w-0">
                        <div class="record-inner-label">{{ __('medical-events.medical_record_id') }}</div>
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
            <button type="button" @click="limit += 5" class="item-add">{{ __('general.show_more') }}</button>
        </div>
    @endif
</div>
