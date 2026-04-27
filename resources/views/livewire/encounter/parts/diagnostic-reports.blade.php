<div class="p-4 sm:p-8"
     id="diagnostic-reports-section"
     x-data="{
         diagnosticReports: $wire.entangle('form.diagnosticReports'),
         modalDiagnosticReport: new DiagnosticReport(),
         newDiagnosticReport: false,
         item: 0,
         diagnosticReportCategoriesDictionary: $wire.dictionaries['eHealth/diagnostic_report_categories'],
         servicesDictionary: $wire.dictionaries['custom/services']
     }"
>
    <h2 class="text-xl font-bold mb-6 text-gray-900 dark:text-white">
        {{ __('patients.diagnostic_reports') }}
    </h2>

        {{-- Show saved data in table --}}
        <table class="table-input w-inherit">
            <thead class="thead-input">
            <tr>
                <th scope="col" class="th-input">{{ __('patients.code_and_name') }}</th>
                <th scope="col" class="th-input">{{ __('forms.comment') }}</th>
                <th scope="col" class="th-input">{{ __('forms.date') }}</th>
                <th scope="col" class="th-input">{{ __('forms.action') }}</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="(diagnosticReport, index) in diagnosticReports">
                <tr>
                    <td class="td-input"
                        x-text="Object.values(servicesDictionary).find(service => service.id === diagnosticReport.code.identifier.value).name"
                    ></td>
                    <td class="td-input" x-text="diagnosticReport.conclusion"></td>
                    <td class="td-input" x-text="diagnosticReport.issuedDate"></td>
                    <td class="td-input">
                        {{-- That all that is needed for the dropdown --}}
                        <div x-data="{
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
                             @focusin.window="! $refs.panel.contains($event.target) && close()"
                             x-id="['dropdown-button']"
                             class="relative"
                        >
                            {{-- Dropdown Button --}}
                            <button x-ref="button"
                                    @click="toggle()"
                                    :aria-expanded="openDropdown"
                                    :aria-controls="$id('dropdown-button')"
                                    type="button"
                                    class="cursor-pointer"
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
                            <div class="absolute" style="left: 50%"> {{-- Center a dropdown panel --}}
                                <div x-ref="panel"
                                     x-show="openDropdown"
                                     x-transition.origin.top.left
                                     @click.outside="close($refs.button)"
                                     :id="$id('dropdown-button')"
                                     x-cloak
                                     class="dropdown-panel relative"
                                     style="left: -50%" {{-- Center a dropdown panel --}}
                                >

                                    <button @click.prevent="
                                                item = index; {{-- Identify the item we are corrently editing --}}
                                                {{-- Replace the previous diagnosticReport with the current, don't assign object directly (modalDiagnosticReport = diagnosticReport) to avoid reactiveness --}}
                                                modalDiagnosticReport = JSON.parse(JSON.stringify(diagnosticReports[index]));
                                                newDiagnosticReport = false; {{-- This diagnosticReport is already created --}}

                                                $nextTick(() => {
                                                    const drawer = document.getElementById('diagnostic-report-drawer-right');
                                                    drawer.classList.remove('translate-x-full'); {{-- Open manually --}}
                                                    drawer.scrollTop = 0; {{-- Move scroll to the top --}}
                                                });
                                            "
                                    >
                                        {{ __('forms.edit') }}
                                    </button>

                                    <button @click.prevent="diagnosticReports.splice(index, 1); close($refs.button)"
                                            class="dropdown-button dropdown-delete"
                                    >
                                        {{ __('forms.delete') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            </template>
            </tbody>
        </table>

        {{-- Button to trigger the drawer --}}
        <button @click.prevent="
                    newDiagnosticReport = true; {{-- We are adding a new diagnostic report --}}
                    modalDiagnosticReport = new DiagnosticReport(); {{-- Replace the data of the previous diagnostic report with a new one--}}

                    $nextTick(() => {
                        const drawer = document.getElementById('diagnostic-report-drawer-right');
                        drawer.scrollTop = 0; {{-- Move scroll to the top --}}
                    });
                "
                class="item-add my-5"
                data-drawer-target="diagnostic-report-drawer-right"
                data-drawer-show="diagnostic-report-drawer-right"
                data-drawer-placement="right"
                data-drawer-body-scrolling="false"
                aria-controls="diagnostic-report-drawer-right"
        >
            {{ __('forms.add') }}
        </button>

        {{-- Content --}}
        <template x-teleport="body"> {{-- This moves the drawer at the end of the body tag --}}
            <div id="diagnostic-report-drawer-right"
                 class="fixed top-0 right-0 z-40 h-screen pt-20 p-4 overflow-y-auto transition-transform translate-x-full bg-white w-4/5 dark:bg-gray-800"
                 tabindex="-1"
                 aria-labelledby="drawer-right-label"
                 wire:ignore {{-- To avoid hiding when searching for ICD-10 --}}
            >

                <h3 class="modal-header" :id="$id('modal-title')">{{ __('patients.diagnostic_report') }}</h3>

                <form>
                    @include('livewire.encounter.diagnostic-report-parts.main-information')
                    @include('livewire.encounter.diagnostic-report-parts.additional-information', ['context' => 'diagnostic-report'])

                    <div class="mt-6 flex justify-between space-x-2">
                        <button type="button"
                                class="button-minor"
                                data-drawer-hide="diagnostic-report-drawer-right"
                                aria-controls="diagnostic-report-drawer-right"
                        >
                            {{ __('forms.cancel') }}
                        </button>

                        <button @click.prevent="
                                    if (modalDiagnosticReport.referralType === 'electronic' || modalDiagnosticReport.referralType === '') {
                                        delete modalDiagnosticReport.paperReferral;
                                    }
                                    if (modalDiagnosticReport.referralType === 'paper' || modalDiagnosticReport.referralType === '') {
                                        delete modalDiagnosticReport.basedOn;
                                    }

                                    newDiagnosticReport !== false
                                        ? diagnosticReports.push(modalDiagnosticReport)
                                        : diagnosticReports[item] = modalDiagnosticReport;
                                "
                                class="button-primary"
                                data-drawer-hide="diagnostic-report-drawer-right"
                                :disabled="!(
                                    modalDiagnosticReport.category[0].coding[0].code.trim() &&
                                    modalDiagnosticReport.code.identifier.value.trim()
                                )"
                        >
                            {{ __('forms.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </template>
</div>

<script>
    /**
     * Representation of the user's personal diagnostic report.
     */
    class DiagnosticReport {
        category = [
            {
                coding: [{ system: 'eHealth/diagnostic_report_categories', code: '' }],
                text: ''
            }
        ];
        code = {
            identifier: {
                type: {
                    coding: [{ system: 'eHealth/resources', code: 'service' }],
                    text: ''
                },
                value: ''
            }
        };
        isReferralAvailable = false;
        referralType = '';
        query = '';
        basedOn = {
            identifier: {
                type: {
                    coding: [{ system: 'eHealth/resources', code: 'service_request' }],
                    text: ''
                }
            }
        };
        paperReferral = {
            requesterLegalEntityEdrpou: '',
            requesterLegalEntityName: '',
            serviceRequestDate: ''
        };
        conclusionCode = {
            coding: [{ system: 'eHealth/ICD10_AM/condition_codes', code: '' }]
        };
        primarySource = true;
        performer = {
            reference: {
                identifier: {
                    type: {
                        coding: [{ system: 'eHealth/resources', code: 'employee' }],
                        text: ''
                    }
                }
            }
        };
        reportOrigin = {
            coding: [{ system: 'eHealth/immunization_report_origins', code: '' }],
            text: ''
        };
        recordedBy = {
            identifier: {
                type: {
                    coding: [{ system: 'eHealth/resources', code: 'employee' }],
                    text: ''
                }
            }
        };
        division = {
            identifier: {
                type: {
                    coding: [{ system: 'eHealth/resources', code: 'division' }],
                    text: ''
                },
                value: ''
            }
        };
        resultsInterpreter = {
            reference: {
                identifier: {
                    type: {
                        coding: [{ system: 'eHealth/resources', code: 'employee' }],
                        text: ''
                    },
                    value: ''
                }
            }
        };

        // Create date
        #now = new Date();
        #endTime = new Date(this.#now.getTime() + 15 * 60 * 1000); // add 15 minutes

        issuedDate = this.#now.toISOString().split('T')[0];
        issuedTime = this.#now.toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit', hour12: false });
        effectivePeriodStartDate = this.#now.toISOString().split('T')[0];
        effectivePeriodStartTime = this.#now.toLocaleTimeString('uk-UA', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false
        });
        effectivePeriodEndDate = this.#endTime.toISOString().split('T')[0];
        effectivePeriodEndTime = this.#endTime.toLocaleTimeString('uk-UA', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false
        });

        constructor(obj = null) {
            if (obj) {
                this.diagnosticReports = JSON.parse(JSON.stringify(obj.diagnosticReports || obj));
            }
        }
    }
</script>
