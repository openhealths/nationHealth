<div
    class="p-4 sm:p-8"
    id="diagnostic-reports-section"
    x-on:encounter-division-changed.window="divisionId = $event.detail.divisionId"
    x-data="{
         diagnosticReports: $wire.entangle('diagnosticReportForm.diagnosticReports'),
         selectedRecords: $wire.entangle('selectedRecords.diagnosticReports'),
         cancelledRecords: $wire.cancelledRecords.diagnosticReports,
         canCancelRecords: {{ ($canCancelRecords ?? false) ? 'true' : 'false' }},
         modalDiagnosticReport: new DiagnosticReport(),
         newDiagnosticReport: false,
         openDiagnosticReportDrawer: false,
         item: 0,
         diagnosticReportCategoriesDictionary: $wire.dictionaries['eHealth/diagnostic_report_categories'],
         servicesDictionary: $wire.dictionaries['custom/services'],
         equipmentOptions: @js($equipmentOptions),
         diagnosticReportEmployees: @js($diagnosticReportEmployees),
         divisionId: @js(data_get($this->form->encounter, 'divisionId', '')),

        encounterPeriodDate: $wire.entangle('form.encounter.periodDate'),
        encounterPeriodStart: $wire.entangle('form.encounter.periodStart'),
        encounterPeriodEnd: $wire.entangle('form.encounter.periodEnd'),
        issuedDateTimeInvalid: false,

        parseDateTime(date, time) {
            if (! date || ! time) {
                return null;
            }

            const [day, month, year] = date.split('.').map(Number);
            const [hours, minutes] = time.split(':').map(Number);

            if (! [day, month, year, hours, minutes].every(Number.isFinite)) {
                return null;
            }

            return new Date(year, month - 1, day, hours, minutes);
        },

        validateIssuedDateTime() {
            const issued = this.parseDateTime(
                this.modalDiagnosticReport.issuedDate,
                this.modalDiagnosticReport.issuedTime
            );

            const encounterStart = this.parseDateTime(
                this.encounterPeriodDate,
                this.encounterPeriodStart
            );

            const encounterEnd = this.parseDateTime(
                this.encounterPeriodDate,
                this.encounterPeriodEnd
            );

            this.issuedDateTimeInvalid = ! issued
                || ! encounterStart
                || ! encounterEnd
                || issued < encounterStart
                || issued > encounterEnd;

            return ! this.issuedDateTimeInvalid;
        },

        syncDiagnosticReportParticipants() {
            const performers = this.diagnosticReports
                .filter(diagnosticReport => diagnosticReport.primarySource === true)
                .flatMap(diagnosticReport => [
                    ...(Array.isArray(diagnosticReport.performerEmployeeIds)
                        ? diagnosticReport.performerEmployeeIds
                        : []),
                    diagnosticReport.resultsInterpreterEmployeeId,
                ])
                .filter(Boolean)
                .map(employeeId => {
                    const employee = this.diagnosticReportEmployees.find(
                        employee => String(employee.uuid) === String(employeeId)
                    );

                    return {
                        uuid: employeeId,
                        name: employee?.name || employeeId,
                    };
                });

            this.syncLocalEncounterParticipants('diagnosticReport', performers);
        },

        addUsedReference() {
            this.modalDiagnosticReport.usedReferences.push({
                id: ''
            });
        },

        removeUsedReference(index) {
            this.modalDiagnosticReport.usedReferences.splice(index, 1);
        },

        setEffectiveType(type) {
            const now = new Date();

            const startTime = new Date(
                now.getTime() - 15 * 60 * 1000
            );

            const toFormattedDate = (date) => {
                const [yyyy, mm, dd] = date
                    .toISOString()
                    .split('T')[0]
                    .split('-');

                return `${dd}.${mm}.${yyyy}`;
            };

            const timeOptions = {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            };

            this.modalDiagnosticReport.effectiveType = type;

            if (type === 'date_time') {
                this.modalDiagnosticReport.effectiveDate =
                    this.modalDiagnosticReport.issuedDate
                    || toFormattedDate(now);

                this.modalDiagnosticReport.effectiveTime =
                    this.modalDiagnosticReport.issuedTime
                    || now.toLocaleTimeString(
                        'uk-UA',
                        timeOptions
                    );

                this.modalDiagnosticReport.effectivePeriodStartDate = '';
                this.modalDiagnosticReport.effectivePeriodStartTime = '';
                this.modalDiagnosticReport.effectivePeriodEndDate = '';
                this.modalDiagnosticReport.effectivePeriodEndTime = '';

                return;
            }

            if (type === 'period') {
                this.modalDiagnosticReport.effectiveDate = '';
                this.modalDiagnosticReport.effectiveTime = '';

                this.modalDiagnosticReport.effectivePeriodStartDate =
                    toFormattedDate(startTime);

                this.modalDiagnosticReport.effectivePeriodStartTime =
                    startTime.toLocaleTimeString(
                        'uk-UA',
                        timeOptions
                    );

                this.modalDiagnosticReport.effectivePeriodEndDate =
                    toFormattedDate(now);

                this.modalDiagnosticReport.effectivePeriodEndTime =
                    now.toLocaleTimeString(
                        'uk-UA',
                        timeOptions
                    );

                return;
            }

            this.modalDiagnosticReport.effectiveDate = '';
            this.modalDiagnosticReport.effectiveTime = '';
            this.modalDiagnosticReport.effectivePeriodStartDate = '';
            this.modalDiagnosticReport.effectivePeriodStartTime = '';
            this.modalDiagnosticReport.effectivePeriodEndDate = '';
            this.modalDiagnosticReport.effectivePeriodEndTime = '';
        }
     }"
>
    {{-- Show saved data in table --}}
    <div class="space-y-4">
        <template x-for="(diagnosticReport, index) in diagnosticReports" :key="index">
            <div class="record-inner-card">
                <div class="record-inner-header">
                    <div class="record-inner-checkbox-col">
                        <input
                            type="checkbox"
                            class="default-checkbox h-5 w-5"
                            :value="diagnosticReport.uuid"
                            x-model="selectedRecords"
                            :disabled="! canCancelRecords ||
                            ! diagnosticReport.uuid ||
                            cancelledRecords.includes(diagnosticReport.uuid)"
                        />
                    </div>

                    <template x-if="cancelledRecords.includes(diagnosticReport.uuid)">
                        <span class="record-inner-badge-error">
                            {{ __('diagnostic-reports.status.entered_in_error') }}
                        </span>
                    </template>

                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">{{ __('diagnostic-reports.label') }}</div>
                        <div
                            class="record-inner-value text-[16px]"
                            x-text="
                                Object.values(servicesDictionary).find(
                                    (service) => service.id === diagnosticReport.codeValue,
                                )?.name || ''
                            "
                        ></div>
                    </div>

                    <div class="record-inner-action-col">
                        <div
                            x-data="{
                                openDropdown: false,
                                toggle() {
                                    if (this.openDropdown) {
                                        return this.close();
                                    }

                                    this.$refs.button.focus();

                                    this.openDropdown = true;
                                },
                                close(focusAfter) {
                                    if (! this.openDropdown) return;

                                    this.openDropdown = false;

                                    focusAfter && focusAfter.focus();
                                },
                            }"
                            @keydown.escape.prevent.stop="close($refs.button)"
                            @focusin.window="$refs.panel && ! $refs.panel.contains($event.target) && close()"
                            x-id="['dropdown-button']"
                            class="relative"
                        >
                            @if ($isReadonly)
                                <a
                                    href="#"
                                    @click.prevent="
                                        item = index;
                                        modalDiagnosticReport = JSON.parse(JSON.stringify(diagnosticReports[index]));
                                        newDiagnosticReport = false;
                                        openDiagnosticReportDrawer = true;
                                    "
                                    class="record-inner-action-btn cursor-pointer"
                                    title="{{ __('forms.view') }}"
                                >
                                    @icon('eye', 'w-6 h-6')
                                    <span class="sr-only"> {{ __('forms.view') }} </span>
                                </a>
                            @else
                                {{-- Dropdown Button --}}
                                <button
                                    x-ref="button"
                                    @click="toggle()"
                                    :aria-expanded="openDropdown"
                                    :aria-controls="$id('dropdown-button')"
                                    type="button"
                                    class="record-inner-action-btn cursor-pointer"
                                >
                                    <svg
                                        class="h-6 w-6 text-gray-800 dark:text-gray-200"
                                        aria-hidden="true"
                                        xmlns="http://www.w3.org/2000/svg"
                                        width="24"
                                        height="24"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke="currentColor"
                                            stroke-linecap="square"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M7 19H5a1 1 0 0 1-1-1v-1a3 3 0 0 1 3-3h1m4-6a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm7.441 1.559a1.907 1.907 0 0 1 0 2.698l-6.069 6.069L10 19l.674-3.372 6.07-6.07a1.907 1.907 0 0 1 2.697 0Z"
                                        />
                                    </svg>
                                </button>

                                {{-- Dropdown Panel --}}
                                <div class="absolute right-0 z-50">
                                    <div
                                        x-ref="panel"
                                        x-show="openDropdown"
                                        x-transition.origin.top.left
                                        @click.outside="close($refs.button)"
                                        :id="$id('dropdown-button')"
                                        x-cloak
                                        class="dropdown-panel relative"
                                    >
                                        <button
                                            @click.prevent.stop="
                                                item = index;
                                                modalDiagnosticReport = new DiagnosticReport(diagnosticReports[index]);
                                                newDiagnosticReport = false;
                                                issuedDateTimeInvalid = false;

                                                close();

                                                $nextTick(() => {
                                                    openDiagnosticReportDrawer = true;
                                                });
                                            "
                                        >
                                            {{ __('forms.edit') }}
                                        </button>

                                        <button
                                            class="dropdown-delete"
                                            @click.prevent="
                                                diagnosticReports.splice(index, 1);
                                                syncDiagnosticReportParticipants();
                                                close($refs.button);
                                            "
                                        >
                                            {{ __('forms.delete') }}
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="record-inner-body">
                    <div class="record-inner-grid-container">
                        <div class="grid w-full grid-cols-2 gap-x-4 gap-y-4 xl:grid-cols-3">
                            <div>
                                <div class="record-inner-label">{{ __('forms.category') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="diagnosticReportCategoriesDictionary[diagnosticReport.categoryCode] || '-'"
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('forms.date') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="`${diagnosticReport.issuedDate} ${diagnosticReport.issuedTime}`"
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('forms.comment') }}</div>
                                <div class="record-inner-subvalue" x-text="diagnosticReport.conclusion || '-'"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div
        x-show="! divisionId"
        x-cloak
        class="my-5 flex items-center gap-3 rounded-xl border border-[#d2e4f9] bg-[#e8f1fc] p-5 dark:border-blue-900 dark:bg-blue-950/40"
    >
        <span class="shrink-0 text-[#2563eb] dark:text-[#60a5fa]">
            @icon('info-circle', 'w-5 h-5')
        </span>

        <p class="text-sm font-semibold text-[#2563eb] dark:text-[#60a5fa]">
            {{ __('diagnostic-reports.select_division_to_create') }}
        </p>
    </div>

    {{-- Button to trigger the drawer --}}
    @unless ($isReadonly)
        <button
            x-show="divisionId"
            x-cloak
            @click.prevent="
                newDiagnosticReport = true;
                modalDiagnosticReport = new DiagnosticReport();
                issuedDateTimeInvalid = false;
                openDiagnosticReportDrawer = true;
            "
            class="item-add my-5"
        >
            {{ __('forms.add') }}
        </button>
    @endunless

    <x-dialog-drawer x-model="openDiagnosticReportDrawer" maxWidth="4/5" wire:ignore>
        <x-slot name="title">{{ __('diagnostic-reports.label') }}</x-slot>

        <form>
            <fieldset @disabled($isReadonly) @class(['pointer-event-none' => $isReadonly])>
                @include('livewire.encounter.diagnostic-report-parts.main-information')
                @include('livewire.encounter.diagnostic-report-parts.additional-information', ['context' => 'diagnostic-report', 'isEncounterContext' => true])

                <div class="mt-6 flex justify-between space-x-2">
                    <button type="button" @click="openDiagnosticReportDrawer = false" class="button-minor">
                        {{ $isReadonly ? __('forms.close') : __('forms.cancel') }}
                    </button>

                    @unless ($isReadonly)
                        <button
                            @click.prevent="
                                if (! validateIssuedDateTime()) {
                                    return;
                                }

                                newDiagnosticReport !== false
                                    ? diagnosticReports.push(modalDiagnosticReport)
                                    : (diagnosticReports[item] = modalDiagnosticReport);
                                syncDiagnosticReportParticipants();
                                openDiagnosticReportDrawer = false;
                            "
                            class="button-primary"
                            :disabled="! (
                                String(modalDiagnosticReport.categoryCode ?? '').trim() &&
                                String(modalDiagnosticReport.codeValue ?? '').trim()
                            )"
                        >
                            {{ __('forms.save') }}
                        </button>
                    @endunless
                </div>
            </fieldset>
        </form>
    </x-dialog-drawer>
</div>

<script>
    /**
     * Representation of the user's personal diagnostic report.
     */
    class DiagnosticReport {
        constructor(obj = null) {
            const now = new Date();
            const startTime = new Date(now.getTime() - 15 * 60 * 1000);
            const toFormattedDate = (date) => {
                const [yyyy, mm, dd] = date.toISOString().split('T')[0].split('-');
                return `${dd}.${mm}.${yyyy}`;
            };
            const timeOptions = { hour: '2-digit', minute: '2-digit', hour12: false };

            this.categoryCode = '';
            this.codeValue = '';
            this.isReferralAvailable = false;
            this.referralType = '';
            this.query = '';
            this.paperReferralRequisition = '';
            this.paperReferralRequesterEmployeeName = '';
            this.paperReferralRequesterLegalEntityEdrpou = '';
            this.paperReferralRequesterLegalEntityName = '';
            this.paperReferralServiceRequestDate = '';
            this.paperReferralNote = '';
            this.conclusionCode = '';
            this.conclusion = '';
            this.primarySource = true;
            this.reportOriginCode = '';
            this.reportOriginText = '';
            this.divisionId = '';
            this.performerEmployeeIds = [];
            this.effectiveType = 'period';
            this.effectiveDate = '';
            this.effectiveTime = '';
            this.usedReferences = [];
            this.resultsInterpreterEmployeeId = '';
            this.issuedDate = toFormattedDate(now);
            this.issuedTime = now.toLocaleTimeString('uk-UA', timeOptions);
            this.effectivePeriodStartDate = toFormattedDate(startTime);
            this.effectivePeriodStartTime = startTime.toLocaleTimeString('uk-UA', timeOptions);
            this.effectivePeriodEndDate = toFormattedDate(now);
            this.effectivePeriodEndTime = now.toLocaleTimeString('uk-UA', timeOptions);

            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }
</script>
