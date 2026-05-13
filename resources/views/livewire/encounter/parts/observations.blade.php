<div class="relative" id="observations-section">
    <fieldset class="fieldset"
              x-data="{
                  observations: $wire.entangle('form.observations'),
                  openModal: false,
                  showDuplicateCodeWarning: false,
                  modalObservation: new Observation(),
                  newObservation: false,
                  item: 0,
                  valueMap: $wire.entangle('observationValueMap'),
                  observationCategoriesDictionary: $wire.dictionaries['eHealth/observation_categories'],
                  icfObservationCategoriesDictionary: $wire.dictionaries['eHealth/ICF/observation_categories'],
                  observationCodesDictionary: $wire.dictionaries['eHealth/LOINC/observation_codes'],
                  icfObservationCodesDictionary: $wire.dictionaries['eHealth/ICF/classifiers'],
                  customObservationCodesDictionary: $wire.dictionaries['eHealth/custom/observation_codes'],
                  observationInterpretationsDictionary: $wire.dictionaries['eHealth/observation_interpretations']
              }"
    >
        <legend class="legend">
            <h2>{{ __('patients.observation') }}</h2>
        </legend>

        <table class="table-input w-inherit">
            <thead class="thead-input">
            <tr>
                <th scope="col" class="th-input">{{ __('forms.category') }}</th>
                <th scope="col" class="th-input">{{ __('forms.code') }}</th>
                <th scope="col" class="th-input">{{ __('patients.value') }}</th>
                <th scope="col" class="th-input">{{ __('forms.date') }}</th>
                <th scope="col" class="th-input">{{ __('forms.action') }}</th>
            </tr>
            </thead>
            <tbody>
            <template x-for="(observation, index) in observations">
                <tr>
                    <td class="td-input"
                        x-text="
                            observationCategoriesDictionary[observation.categoryCode] ||
                            icfObservationCategoriesDictionary[observation.categoryCode]
                        "
                    ></td>
                    <td class="td-input"
                        x-text="
                            observationCodesDictionary[observation.codeCode] ||
                            icfObservationCodesDictionary[observation.codeCode] ||
                            customObservationCodesDictionary[observation.codeCode]
                        "
                    ></td>
                    <td class="td-input"
                        x-text="
                            observation.valueBoolean !== undefined
                                ? (observation.valueBoolean ? 'Так' : 'Ні')
                            : observation.valueString !== undefined
                                ? observation.valueString
                            : (observation.valueDate !== undefined && observation.valueTime !== undefined)
                                ? observation.valueDate + ' ' + observation.valueTime
                            : observation.valueQuantityValue !== ''
                                ? observation.valueQuantityValue
                            : observation.dictionaryName !== ''
                                ? $wire.dictionaries[observation.dictionaryName]?.[observation.valueCodeableConcept]
                            : '-'
                        "
                    ></td>
                    <td class="td-input" x-text="observation.issuedDate"></td>
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
                             @focusin.window="!$refs.panel.contains($event.target) && close()"
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
                                                {{-- Replace the previous observation with the current, don't assign object directly (modalObservation = observation) to avoid reactiveness --}}
                                                modalObservation = JSON.parse(JSON.stringify(observations[index]));
                                                newObservation = false; {{-- This observation is already created --}}
                                            "
                                            class="dropdown-button"
                                    >
                                        {{ __('forms.edit') }}
                                    </button>

                                    <button @click.prevent="observations.splice(index, 1); close($refs.button)"
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
                        newObservation = true; {{-- We are adding a new observation --}}
                        modalObservation = new Observation(); {{-- Replace the data of the previous observation with a new one--}}
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
                            <h3 class="modal-header" :id="$id('modal-title')">{{ __('patients.observation') }}</h3>

                            {{-- Content --}}
                            <form>
                                @include('livewire.encounter.observation-parts.coding-system')
                                @include('livewire.encounter.observation-parts.main-information')
                                @include('livewire.encounter.observation-parts.additional-information')

                                <div class="mt-6 flex justify-between space-x-2">
                                    <button type="button"
                                            @click="openModal = false"
                                            class="button-minor"
                                    >
                                        {{ __('forms.cancel') }}
                                    </button>

                                    <button @click.prevent="
                                                const selectedValueType = valueMap[modalObservation.codeCode]?.[1];

                                                const fieldsToDelete = [
                                                    'valueQuantity',
                                                    'valueCodeableConcept',
                                                    'valueString',
                                                    'valueBoolean',
                                                    'valueDateTime'
                                                ];

                                                fieldsToDelete.forEach(field => {
                                                    if (field !== selectedValueType) {
                                                        if (field === 'valueQuantity') {
                                                            modalObservation.valueQuantityValue = '';
                                                            modalObservation.valueQuantityComparator = '';
                                                            modalObservation.valueQuantityUnit = '';
                                                            modalObservation.valueQuantitySystem = '';
                                                            modalObservation.valueQuantityCode = '';
                                                        } else if (field === 'valueDateTime') {
                                                            delete modalObservation.valueDate;
                                                            delete modalObservation.valueTime;
                                                        } else {
                                                            delete modalObservation[field];
                                                        }
                                                    }
                                                });

                                                modalObservation.dictionaryName = $wire.observationValueMap[modalObservation.codeCode]?.[0];

                                                newObservation !== false
                                                    ? observations.push(modalObservation)
                                                    : observations[item] = modalObservation;

                                                showDuplicateCodeWarning = false;
                                                openModal = false;
                                            "
                                            class="button-primary"
                                            :disabled="!(
                                                modalObservation.issuedDate.trim() &&
                                                modalObservation.issuedTime.trim() &&
                                                modalObservation.categoryCode.trim() &&
                                                modalObservation.codeCode.trim()
                                            )"
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
     * Representation of the user's personal observation
     */
    class Observation {
        codingSystem = 'loinc';
        categorySystem = 'eHealth/observation_categories';
        codeSystem = 'eHealth/LOINC/observation_codes';
        dictionaryName = '';
        primarySource = true;
        reportOriginCode = '';
        categoryCode = '';
        codeCode = '';
        methodCode = '';
        interpretationCode = '';
        bodySiteCode = '';
        valueQuantityValue = '';
        valueQuantityComparator = '';
        valueQuantityUnit = '';
        valueQuantitySystem = '';
        valueQuantityCode = '';
        comment = '';
        issuedDate = new Date().toISOString().split('T')[0];
        issuedTime = new Date().toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit', hour12: false });
        effectiveDate = new Date().toISOString().split('T')[0];
        effectiveTime = new Date().toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit', hour12: false });
        components = [
            {
                codeCode: '',
                codeSystem: 'eHealth/ICF/qualifiers',
                valueCode: '',
                valueSystem: '',
                interpretationCode: ''
            }
        ];

        constructor(obj = null) {
            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }
</script>
