<div
    class="p-4 sm:p-8"
    id="diagnostic-reports-section"
    x-data="{
         diagnosticReports: $wire.entangle('form.diagnosticReports'),
         modalDiagnosticReport: new DiagnosticReport(),
         newDiagnosticReport: false,
         openDiagnosticReportDrawer: false,
         item: 0,
         diagnosticReportCategoriesDictionary: $wire.dictionaries['eHealth/diagnostic_report_categories'],
         servicesDictionary: $wire.dictionaries['custom/services'],
         equipmentOptions: @js($equipmentOptions),

        addUsedReference() {
            this.modalDiagnosticReport.usedReferences.push({
                id: ''
            });
        },

        removeUsedReference(index) {
            this.modalDiagnosticReport.usedReferences.splice(index, 1);
        },
     }"
>

    {{-- Show saved data in table --}}
    <div class="space-y-4">
        <template x-for="(diagnosticReport, index) in diagnosticReports" :key="index">
            <div class="record-inner-card">
                <div class="record-inner-header">
                    <div class="record-inner-checkbox-col">
                        <input type="checkbox" class="default-checkbox w-5 h-5" disabled>
                    </div>

                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">{{ __('patients.diagnostic_report') }}</div>
                        <div
                            class="record-inner-value text-[16px]"
                            x-text="Object.values(servicesDictionary).find(service => service.id === diagnosticReport.codeValue)?.name || ''"
                        ></div>
                    </div>

                    <div class="record-inner-action-col">
                        <div
                            x-data="{
                                openDropdown: false,
                                toggle() {
                                    if (this.openDropdown) {
                                        return this.close()
                                    }

                                    this.$refs.button.focus()

                                    this.openDropdown = true
                                },
                                close(focusAfter) {
                                    if (!this.openDropdown) return

                                    this.openDropdown = false

                                    focusAfter && focusAfter.focus()
                                }
                            }"
                            @keydown.escape.prevent.stop="close($refs.button)"
                            @focusin.window="!$refs.panel.contains($event.target) && close()"
                            x-id="['dropdown-button']"
                            class="relative"
                        >
                            {{-- Dropdown Button --}}
                            <button
                                x-ref="button"
                                @click="toggle()"
                                :aria-expanded="openDropdown"
                                :aria-controls="$id('dropdown-button')"
                                type="button"
                                class="record-inner-action-btn cursor-pointer"
                            >
                                <svg class="w-6 h-6 text-gray-800 dark:text-gray-200" aria-hidden="true"
                                     xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none"
                                     viewBox="0 0 24 24"
                                >
                                    <path stroke="currentColor" stroke-linecap="square" stroke-linejoin="round"
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
                                        @click.prevent="
                                            item = index;
                                            modalDiagnosticReport = JSON.parse(JSON.stringify(diagnosticReports[index]));
                                            newDiagnosticReport = false;
                                            openDiagnosticReportDrawer = true;
                                            close($refs.button);
                                        "
                                    >
                                        {{ __('forms.edit') }}
                                    </button>

                                    <button
                                        class="dropdown-delete"
                                        @click.prevent="diagnosticReports.splice(index, 1); close($refs.button)"
                                    >
                                        {{ __('forms.delete') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="record-inner-body">
                    <div class="record-inner-grid-container">
                        <div class="grid grid-cols-2 xl:grid-cols-3 gap-y-4 gap-x-4 w-full">
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

    {{-- Button to trigger the drawer --}}
    <button
        @click.prevent="
            newDiagnosticReport = true;
            modalDiagnosticReport = new DiagnosticReport();
            openDiagnosticReportDrawer = true;
        "
        class="item-add my-5"
    >
        {{ __('forms.add') }}
    </button>

    <x-dialog-drawer x-model="openDiagnosticReportDrawer" maxWidth="4/5" wire:ignore>
        <x-slot name="title">
            {{ __('patients.diagnostic_report') }}
        </x-slot>

        <form>
            @include('livewire.encounter.diagnostic-report-parts.main-information')
            @include('livewire.encounter.diagnostic-report-parts.additional-information', ['context' => 'diagnostic-report'])

            <div class="mt-6 flex justify-between space-x-2">
                <button type="button" @click="openDiagnosticReportDrawer = false" class="button-minor">
                    {{ __('forms.cancel') }}
                </button>

                <button
                    @click.prevent="
                        newDiagnosticReport !== false
                            ? diagnosticReports.push(modalDiagnosticReport)
                            : diagnosticReports[item] = modalDiagnosticReport;
                        openDiagnosticReportDrawer = false;
                    "
                    class="button-primary"
                    :disabled="!(
                        modalDiagnosticReport.categoryCode.trim() &&
                        modalDiagnosticReport.codeValue.trim()
                    )"
                >
                    {{ __('forms.save') }}
                </button>
            </div>
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
