<div class="relative" id="procedures-section">
    <fieldset class="fieldset"
              x-data="{
                  procedures: $wire.entangle('form.procedures'),
                  modalProcedure: new Procedure(),
                  newProcedure: false,
                  item: 0
              }"
    >
        <legend class="legend">
            <h2>{{ __('patients.procedures') }}</h2>
        </legend>

        <table class="table-input w-inherit">
            <thead class="thead-input">
            <tr>
                <th scope="col" class="th-input">{{ __('patients.code_and_name') }}</th>
                <th scope="col" class="th-input">{{ __('forms.date') }}</th>
                <th scope="col" class="th-input">{{ __('forms.action') }}</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="(procedure, index) in procedures">
                <tr>
                    <td class="td-input"
                        x-text="(() => {
                            const service = Object.values($wire.dictionaries['custom/services']).find(service => service.id === procedure.code.identifier.value);
                            return service ? `${service.code} / ${service.name}` : '';
                        })()"
                    ></td>
                    <td class="td-input" x-text="procedure.performedPeriodStartDate"></td>
                    <td class="td-input">
                        {{-- That all that is needed for the dropdown --}}
                        <div x-data="{
                                 openDropdown: false,
                                 toggle() {
                                     if (this.openDropdown) {
                                         return this.close();
                                     }

                                     this.$refs.button.focus();
                                     this.openDropdown = true;
                                 },
                                 close(focusAfter) {
                                     if (!this.openDropdown) return;

                                     this.openDropdown = false;
                                     focusAfter && focusAfter.focus();
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
                                                {{-- Replace the previous procedure with the current, don't assign object directly (modalProcedure = procedure) to avoid reactiveness --}}
                                                modalProcedure = JSON.parse(JSON.stringify(procedures[index]));
                                                newProcedure = false; {{-- This procedure is already created --}}

                                                $nextTick(() => {
                                                    const drawer = document.getElementById('procedure-drawer-right');
                                                    drawer.classList.remove('translate-x-full'); {{-- Open manually --}}
                                                    drawer.scrollTop = 0; {{-- Move scroll to the top --}}
                                                });
                                            "
                                            class="dropdown-button"
                                    >
                                        {{ __('forms.edit') }}
                                    </button>

                                    <button @click.prevent="procedures.splice(index, 1); close($refs.button)"
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

        <div>
            {{-- Button to trigger the drawer --}}
            <button @click.prevent="
                        newProcedure = true; {{-- We are adding a new procedure --}}
                        modalProcedure = new Procedure(); {{-- Replace the data of the previous procedure with a new one--}}

                        $nextTick(() => {
                            const drawer = document.getElementById('procedure-drawer-right');
                            drawer.scrollTop = 0; {{-- Move scroll to the top --}}
                        });
                    "
                    class="item-add my-5"
                    data-drawer-target="procedure-drawer-right"
                    data-drawer-show="procedure-drawer-right"
                    data-drawer-placement="right"
                    data-drawer-body-scrolling="false"
                    aria-controls="procedure-drawer-right"
            >
                {{ __('forms.add') }}
            </button>

            {{-- Content --}}
            <template x-teleport="body"> {{-- This moves the drawer at the end of the body tag --}}
                <div id="procedure-drawer-right"
                     class="fixed top-0 right-0 z-40 h-screen pt-20 p-4 overflow-y-auto transition-transform translate-x-full bg-white w-4/5 dark:bg-gray-800"
                     tabindex="-1"
                     aria-labelledby="drawer-right-label"
                     wire:ignore {{-- To avoid hiding when searching for reasons or complication details --}}
                >

                    <h3 class="modal-header" :id="$id('modal-title')">{{ __('patients.procedure') }}</h3>

                    {{-- Content --}}
                    <form>
                        @include('livewire.encounter.procedure-parts.main-information', ['context' => 'encounter'])
                        @include('livewire.encounter.procedure-parts.additional-information', ['context' => 'encounter'])
                        @include('livewire.encounter.procedure-parts.reason-references')
                        @include('livewire.encounter.procedure-parts.used-codes')
                        @include('livewire.encounter.procedure-parts.complication-details')

                        <div class="mt-6 flex justify-between space-x-2">
                            <button type="button"
                                    class="button-minor"
                                    data-drawer-hide="procedure-drawer-right"
                                    aria-controls="procedure-drawer-right"
                            >
                                {{ __('forms.cancel') }}
                            </button>

                            <button @click.prevent="
                                        newProcedure !== false
                                            ? procedures.push(modalProcedure)
                                            : procedures[item] = modalProcedure;
                                    "
                                    class="button-primary"
                                    data-drawer-hide="procedure-drawer-right"
                                    :disabled="!(
                                        modalProcedure.category.coding[0].code.trim() &&
                                        modalProcedure.code.identifier.value.trim()
                                    )"
                            >
                                {{ __('forms.save') }}
                            </button>
                        </div>
                    </form>
                </div>
            </template>
        </div>
    </fieldset>
</div>

<script>
    /**
     * Representation of the user's personal procedure
     */
    class Procedure {
        isReferralAvailable = false;
        referralType = '';
        paperReferral = {
            requesterLegalEntityEdrpou: '',
            requesterLegalEntityName: '',
            serviceRequestDate: ''
        };
        category = {
            coding: [{ system: 'eHealth/procedure_categories', code: '' }],
            text: ''
        };
        code = {
            identifier: {
                type: {
                    coding: [{ system: 'eHealth/resources', code: 'service' }],
                    text: ''
                },
                value: ''
            }
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
        outcome = {
            coding: [{ system: 'eHealth/procedure_outcomes', code: '' }],
            text: ''
        };
        primarySource = true;
        performer = {
            identifier: {
                type: {
                    coding: [{ system: 'eHealth/resources', code: 'employee' }],
                    text: ''
                }
            }
        };
        reportOrigin = {
            coding: [{ system: 'eHealth/report_origins', code: '' }],
            text: ''
        };
        reasonReferences = [];
        usedCodes = [];
        complicationDetails = [];

        // Create date
        #now = new Date();
        #endTime = new Date(this.#now.getTime() + 15 * 60 * 1000); // add 15 minutes

        performedPeriodStartDate = this.#now.toISOString().split('T')[0];
        performedPeriodStartTime = this.#now.toLocaleTimeString('uk-UA', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false
        });
        performedPeriodEndDate = this.#endTime.toISOString().split('T')[0];
        performedPeriodEndTime = this.#endTime.toLocaleTimeString('uk-UA', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false
        });

        constructor(obj = null) {
            if (obj) {
                this.procedures = JSON.parse(JSON.stringify(obj.procedures || obj));
            }
        }
    }
</script>
