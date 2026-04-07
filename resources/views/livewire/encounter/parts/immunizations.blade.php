<div class="relative" id="immunizations-section">
    <fieldset class="fieldset"
              x-data="{
                  immunizations: $wire.entangle('form.immunizations'),
                  openModal: false,
                  showDuplicateCodeWarning: false,
                  modalImmunization: new Immunization(),
                  newImmunization: false,
                  item: 0,
                  vaccineCodesDictionary: $wire.dictionaries['eHealth/vaccine_codes'],
                  reasonExplanationsDictionary: $wire.dictionaries['eHealth/reason_explanations'],
                  reasonNotGivenExplanationsDictionary: $wire.dictionaries['eHealth/reason_not_given_explanations']
              }"
    >
        <legend class="legend">
            <h2>{{ __('patients.immunizations') }}</h2>
        </legend>

        <table class="table-input w-inherit">
            <thead class="thead-input">
            <tr>
                <th scope="col" class="th-input">{{ __('patients.code_and_name') }}</th>
                <th scope="col" class="th-input">{{ __('patients.dosage') }}</th>
                <th scope="col" class="th-input">{{ __('patients.execution_state') }}</th>
                <th scope="col" class="th-input">{{ __('patients.reason') }}</th>
                <th scope="col" class="th-input">{{ __('forms.date') }}</th>
                <th scope="col" class="th-input">{{ __('forms.action') }}</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="(immunization, index) in immunizations">
                <tr>
                    <td class="td-input"
                        x-text="`${ immunization.vaccineCode.coding[0]['code'] } - ${ vaccineCodesDictionary[immunization.vaccineCode.coding[0]['code']] }`"
                    ></td>
                    <td class="td-input"
                        x-text="
                            immunization.doseQuantity?.value && immunization.doseQuantity?.unit
                                ? `${immunization.doseQuantity.value} ${immunization.doseQuantity.unit}`
                                : ''
                            "
                    ></td>
                    <td class="td-input"
                        x-text="immunization.notGiven === false ? 'проведена' : 'не проведена'"
                    ></td>
                    <td class="td-input"
                        x-text="
                            immunization.explanation.reasons?.[0]?.coding?.[0]?.code
                                ? reasonExplanationsDictionary[immunization.explanation.reasons[0].coding[0].code]
                                : reasonNotGivenExplanationsDictionary[immunization.explanation.reasonsNotGiven[0]?.coding?.[0]?.code]
                        "
                    ></td>
                    <td class="td-input" x-text="immunization.date"></td>
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
                                                openModal = true; {{-- Open the modal --}}
                                                item = index; {{-- Identify the item we are corrently editing --}}
                                                {{-- Replace the previous immunization with the current, don't assign object directly (modalImmunization = immunization) to avoid reactiveness --}}
                                                modalImmunization = JSON.parse(JSON.stringify(immunizations[index]));
                                                newImmunization = false; {{-- This immunization is already created --}}
                                            "
                                            class="dropdown-button"
                                    >
                                        {{ __('forms.edit') }}
                                    </button>

                                    <button @click.prevent="immunizations.splice(index, 1); close($refs.button)"
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
            {{-- Button to trigger the modal --}}
            <button @click.prevent="
                        openModal = true; {{-- Open the Modal --}}
                        newImmunization = true; {{-- We are adding a new immumization --}}
                        modalImmunization = new Immunization(); {{-- Replace the data of the previous immumization with a new one--}}
                    "
                    class="item-add my-5"
            >
                {{ __('forms.add') }}
            </button>

            {{-- Modal --}}
            <template x-teleport="body"> {{-- This moves the modal at the end of the body tag --}}
                <div x-show="openModal"
                     style="display: none"
                     @keydown.escape.prevent.stop="openModal = false"
                     role="dialog"
                     aria-modal="true"
                     x-id="['modal-title']"
                     :aria-labelledby="$id('modal-title')" {{-- This associates the modal with unique ID --}}
                     class="modal"
                >

                    {{-- Overlay --}}
                    <div x-show="openModal" x-transition.opacity class="fixed inset-0 bg-black/25"></div>

                    {{-- Panel --}}
                    <div x-show="openModal"
                         x-transition
                         @click="openModal = false"
                         class="relative flex min-h-screen items-center justify-center p-4"
                    >
                        <div @click.stop
                             x-trap.noscroll.inert="openModal"
                             class="modal-content h-fit w-full lg:max-w-7xl"
                        >
                            {{-- Title --}}
                            <h3 class="modal-header" :id="$id('modal-title')">{{ __('patients.immunization') }}</h3>

                            {{-- Content --}}
                            <form>
                                @include('livewire.encounter.immunization-parts.data')
                                @include('livewire.encounter.immunization-parts.information-about')
                                @include('livewire.encounter.immunization-parts.vaccination-protocol')

                                <div class="mt-6 flex justify-between space-x-2">
                                    <button type="button"
                                            @click="openModal = false"
                                            class="button-minor"
                                    >
                                        {{ __('forms.cancel') }}
                                    </button>

                                    <button @click.prevent="
                                                const newImmunizationCode = modalImmunization.vaccineCode.coding[0]?.code;

                                                // Check for duplicates, excluding the current item when editing
                                                let hasDuplicate = false;

                                                if (newImmunization) {
                                                    // For new immunization, check all existing ones
                                                    hasDuplicate = immunizations.some(
                                                        immunization => immunization.vaccineCode.coding[0]?.code === newImmunizationCode
                                                    );
                                                } else {
                                                    // For editing, check all except the current item
                                                    hasDuplicate = immunizations.some(
                                                        (immunization, index) => index !== item && immunization.vaccineCode.coding[0]?.code === newImmunizationCode
                                                    );
                                                }

                                                if (hasDuplicate) {
                                                    showDuplicateCodeWarning = true;
                                                    return;
                                                }

                                                newImmunization !== false
                                                    ? immunizations.push(modalImmunization)
                                                    : immunizations[item] = modalImmunization;

                                                showDuplicateCodeWarning = false;
                                                openModal = false;
                                            "
                                            class="button-primary"
                                            :disabled="!(
                                                modalImmunization.date.trim() &&
                                                modalImmunization.time.trim() &&
                                                (modalImmunization.explanation?.reasons?.[0]?.coding?.[0]?.code?.trim?.() || modalImmunization.explanation?.reasonsNotGiven[0]?.coding?.[0]?.code?.trim?.()))
                                            "
                                    >
                                        {{ __('forms.save') }}
                                    </button>
                                </div>
                                <template x-if="showDuplicateCodeWarning">
                                    <p class="text-error text-right">
                                        {!! __('patients.duplicate_code_warning') !!}
                                    </p>
                                </template>
                            </form>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </fieldset>
</div>

<script>
    /**
     * Representation of the user's personal immunization
     */
    class Immunization {
        date = new Date().toISOString().split('T')[0];
        time = new Date().toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit', hour12: false });
        notGiven = false;
        vaccineCode = {
            coding: [{ system: 'eHealth/vaccine_codes', code: '' }]
        };
        explanation = {
            reasons: [
                {
                    coding: [{ system: 'eHealth/reason_explanations', code: '' }]
                }
            ],
            reasonsNotGiven: [
                {
                    coding: [{ system: 'eHealth/reason_not_given_explanations', code: '' }]
                }
            ]
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
            coding: [{ system: 'eHealth/immunization_report_origins', code: '' }],
            text: ''
        };
        manufacturer = null;
        lotNumber = null;
        expirationDate = null;
        site = {
            coding: [{ system: 'eHealth/immunization_body_sites', code: '' }],
            text: ''
        };
        route = {
            coding: [{ system: 'eHealth/vaccination_routes', code: '' }],
            text: ''
        };
        doseQuantity = {
            value: null,
            unit: null,
            system: 'eHealth/immunization_dosage_units',
            code: ''
        };
        vaccinationProtocols = [];

        constructor(obj = null) {
            if (obj) {
                this.immunizations = JSON.parse(JSON.stringify(obj.immunizations || obj));
            }
        }
    }
</script>
