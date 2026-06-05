<div class="p-4 sm:p-8"
     id="observations-section"
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

    <div class="space-y-4">
        <template x-for="(observation, index) in observations" :key="index">
            <div class="record-inner-card">
                <div class="record-inner-header">
                    <div class="record-inner-checkbox-col">
                        <input type="checkbox" class="default-checkbox w-5 h-5" disabled>
                    </div>

                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">{{ __('forms.code') }}</div>
                        <div class="record-inner-value text-[16px]" x-text="
                                observationCodesDictionary[observation.codeCode] ||
                                icfObservationCodesDictionary[observation.codeCode] ||
                                customObservationCodesDictionary[observation.codeCode] ||
                                observation.codeCode
                            "></div>
                    </div>

                    <div class="record-inner-action-col">
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
                                <div x-ref="panel"
                                     x-show="openDropdown"
                                     x-transition.origin.top.left
                                     @click.outside="close($refs.button)"
                                     :id="$id('dropdown-button')"
                                     x-cloak
                                     class="dropdown-panel relative"
                                >
                                    <button @click.prevent="
                                        openModal = true;
                                        item = index;
                                        modalObservation = JSON.parse(JSON.stringify(observations[index]));
                                        newObservation = false;
                                        close($refs.button);
                                    "
                                    >
                                        {{ __('forms.edit') }}
                                    </button>

                                    <button class="dropdown-delete"
                                            @click.prevent="observations.splice(index, 1); close($refs.button)">
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
                                <div class="record-inner-subvalue" x-text="
                                        observationCategoriesDictionary[observation.categoryCode] ||
                                        icfObservationCategoriesDictionary[observation.categoryCode] ||
                                        '-'
                                    "></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('patients.value') }}</div>
                                <div class="record-inner-subvalue" x-text="
                                        observation.valueBoolean !== undefined
                                            ? (observation.valueBoolean ? '{{ __('forms.yes') }}' : '{{ __('forms.no') }}')
                                        : observation.valueString !== undefined
                                            ? observation.valueString
                                        : (observation.valueDate !== undefined && observation.valueTime !== undefined)
                                            ? observation.valueDate + ' ' + observation.valueTime
                                        : observation.valueQuantityValue !== ''
                                            ? observation.valueQuantityValue
                                        : observation.dictionaryName !== ''
                                            ? $wire.dictionaries[observation.dictionaryName]?.[observation.valueCodeableConcept]
                                        : '-'
                                    "></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('forms.date') }}</div>
                                <div class="record-inner-subvalue" x-text="observation.issuedDate || '-'"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div>
        {{-- Button to trigger the modal --}}
        <button @click.prevent="
            openModal = true;
            newObservation = true;
            modalObservation = new Observation();
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
        issuedDate = '';
        issuedTime = '';
        effectiveDate = '';
        effectiveTime = '';
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
            const now = new Date();
            const [yyyy, mm, dd] = now.toISOString().split('T')[0].split('-');
            const formattedDate = `${dd}.${mm}.${yyyy}`;
            const formattedTime = now.toLocaleTimeString('uk-UA', {hour: '2-digit', minute: '2-digit', hour12: false});

            this.issuedDate = formattedDate;
            this.issuedTime = formattedTime;
            this.effectiveDate = formattedDate;
            this.effectiveTime = formattedTime;

            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }
</script>
