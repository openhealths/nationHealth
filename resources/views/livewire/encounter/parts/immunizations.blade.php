<div
    class="p-4 sm:p-8"
    id="immunizations-section"
    x-data="{
        immunizations: $wire.entangle('form.immunizations'),
        observations: $wire.entangle('form.observations'),
        selectedRecords: $wire.entangle('selectedRecords.immunizations'),
        cancelledRecords: $wire.cancelledRecords.immunizations,
        canCancelRecords: {{ ($canCancelRecords ?? false) ? 'true' : 'false' }},

        openModal: false,
        showDuplicateCodeWarning: false,
        modalImmunization: new Immunization(),
        newImmunization: false,
        item: 0,

        vaccineCodesDictionary: $wire.dictionaries['eHealth/vaccine_codes'],
        vaccineOptions: @js($this->vaccineOptions),

        vaccineSearch: { name: '', code: '', disease: ''},
        vaccineSearchResults: [],
        vaccineSearchPerformed: false,

        reasonExplanationsDictionary: $wire.dictionaries['eHealth/reason_explanations'],
        reasonNotGivenExplanationsDictionary: $wire.dictionaries['eHealth/reason_not_given_explanations'],

        normalizeSearchValue(value) {
            return String(value ?? '')
                .trim()
                .toLowerCase();
        },

        searchVaccines() {
            const name = this.normalizeSearchValue(this.vaccineSearch.name);
            const code = this.normalizeSearchValue(this.vaccineSearch.code);
            const disease = this.normalizeSearchValue(this.vaccineSearch.disease);

            this.vaccineSearchResults = this.vaccineOptions.filter(
                vaccine => {
                    const vaccineDiseases = Array.isArray(vaccine.targetDiseases) ? vaccine.targetDiseases : [];
                    const matchesName = name === '' || this.normalizeSearchValue(vaccine.name).includes(name);
                    const matchesCode = code === '' || this.normalizeSearchValue(vaccine.code).includes(code);
                    const matchesDisease = disease === '' || vaccineDiseases.some(targetDisease =>
                        this.normalizeSearchValue(targetDisease.code).includes(disease) || this.normalizeSearchValue(targetDisease.name).includes(disease));

                    return matchesName && matchesCode && matchesDisease;
                }
            );

            this.vaccineSearchPerformed = true;
        },

        resetVaccineSearch() {
            this.vaccineSearch = { name: '', code: '', disease: ''};
            this.vaccineSearchResults = [];
            this.vaccineSearchPerformed = false;
        },

        allowedTargetDiseases() {
            const vaccine = this.vaccineOptions.find(vaccine => vaccine.code === this.modalImmunization.vaccineCode);

            return Array.isArray(vaccine?.targetDiseases) ? vaccine.targetDiseases : [];
        },

        isTargetDiseaseAllowed(targetDiseaseCode) {
            if (! targetDiseaseCode) {
                return false;
            }

            return this.allowedTargetDiseases().some(
                targetDisease => targetDisease.code === targetDiseaseCode
            );
        },

        allSelectedTargetDiseaseCodes() {
            return this.modalImmunization
                .vaccinationProtocols
                .flatMap(protocol => Array.isArray(protocol.targetDiseaseCodes) ? protocol.targetDiseaseCodes.filter(Boolean) : []);
        },

        hasDuplicateTargetDiseasesInImmunization() {
            const targetDiseaseCodes = this.allSelectedTargetDiseaseCodes();

            return new Set(targetDiseaseCodes).size !== targetDiseaseCodes.length;
        },

        hasInvalidTargetDiseases() {
            return this.modalImmunization
                .vaccinationProtocols
                .some(protocol => {
                    const targetDiseaseCodes = Array.isArray(protocol.targetDiseaseCodes) ? protocol.targetDiseaseCodes : [];

                    return targetDiseaseCodes.length === 0 || targetDiseaseCodes.some(targetDiseaseCode => ! this.isTargetDiseaseAllowed(targetDiseaseCode));
                });
        },

        selectVaccine(vaccineCode) {
            if (this.modalImmunization.vaccineCode !== vaccineCode) {
                this.modalImmunization.vaccinationProtocols = [];
            }

            this.modalImmunization.vaccineCode = vaccineCode;

            this.resetVaccineSearch();
        },

        chooseAnotherVaccine() {
            this.modalImmunization.vaccineCode = '';
            this.modalImmunization.vaccinationProtocols = [];
            this.resetVaccineSearch();
        },

        removeImmunization(index) {
            const uuid = this.immunizations[index]?.uuid;

            if (uuid) {
                this.observations.forEach(observation => {
                    if (observation.reactionOn === uuid) {
                        observation.reactionOn = '';
                    }
                });
            }

            this.immunizations.splice(index, 1);
        }
     }"
>
    <div class="space-y-4">
        <template x-for="(immunization, index) in immunizations" :key="index">
            <div class="record-inner-card">
                <div class="record-inner-header">
                    <div class="record-inner-checkbox-col">
                        <input
                            type="checkbox"
                            class="default-checkbox h-5 w-5"
                            :value="immunization.uuid"
                            x-model="selectedRecords"
                            :disabled="! canCancelRecords ||
                            ! immunization.uuid ||
                            cancelledRecords.includes(immunization.uuid)"
                        />
                    </div>

                    <template x-if="cancelledRecords.includes(immunization.uuid)">
                        <span class="record-inner-badge-error">
                            {{ __('immunizations.status.entered_in_error') }}
                        </span>
                    </template>

                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">{{ __('immunizations.vaccine') }}</div>
                        <div
                            class="record-inner-value text-[16px]"
                            x-text="`${immunization.vaccineCode} - ${vaccineCodesDictionary[immunization.vaccineCode]}`"
                        ></div>
                    </div>

                    <div class="record-inner-column-bordered min-w-30">
                        <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                        <div class="record-inner-value">
                            <template x-if="immunization.notGiven === false">
                                <span class="inline-flex items-center rounded-md bg-green-50 px-2 py-1 text-xs font-medium text-green-700 ring-1 ring-green-600/20 ring-inset dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20">
                                    {{ __('patients.status_completed') }}
                                </span>
                            </template>
                            <template x-if="immunization.notGiven === true">
                                <span class="inline-flex items-center rounded-md bg-red-50 px-2 py-1 text-xs font-medium text-red-700 ring-1 ring-red-600/10 ring-inset dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/20">
                                    {{ __('forms.no') }}
                                </span>
                            </template>
                        </div>
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
                                        openModal = true;
                                        item = index;
                                        modalImmunization = JSON.parse(JSON.stringify(immunizations[index]));
                                        newImmunization = false;
                                        resetVaccineSearch();
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
                                            @click.prevent="
                                                openModal = true;
                                                item = index;
                                                modalImmunization = JSON.parse(JSON.stringify(immunizations[index]));
                                                newImmunization = false;
                                                resetVaccineSearch();
                                                close($refs.button);
                                            "
                                        >
                                            {{ __('forms.edit') }}
                                        </button>

                                        <button
                                            class="dropdown-delete"
                                            @click.prevent="
                                                removeImmunization(index);
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
                        <div class="grid w-full grid-cols-1 gap-6 lg:grid-cols-12">
                            <div class="grid grid-cols-2 gap-x-4 gap-y-4 lg:col-span-6">
                                <div>
                                    <div class="record-inner-label">{{ __('patients.dosage') }}</div>
                                    <div
                                        class="record-inner-subvalue"
                                        x-text="
                                            immunization.doseQuantityValue && immunization.doseQuantityUnit
                                                ? `${immunization.doseQuantityValue} ${immunization.doseQuantityUnit}`
                                                : '-'
                                        "
                                    ></div>
                                </div>
                                <div>
                                    <div class="record-inner-label">{{ __('immunizations.input_route') }}</div>
                                    <div
                                        class="record-inner-subvalue"
                                        x-text="
                                            $wire.dictionaries['eHealth/vaccination_routes'][immunization.routeCode] ||
                                            '-'
                                        "
                                    ></div>
                                </div>
                                <div>
                                    <div class="record-inner-label">{{ __('immunizations.reasons') }}</div>
                                    <div
                                        class="record-inner-subvalue"
                                        x-text="
                                            immunization.reasons?.[0]?.code
                                                ? reasonExplanationsDictionary[immunization.reasons[0].code]
                                                : reasonNotGivenExplanationsDictionary[
                                                      immunization.reasonNotGivenCode
                                                  ] || '-'
                                        "
                                    ></div>
                                </div>
                                <div>
                                    <div class="record-inner-label">{{ __('immunizations.manufacturer') }}</div>
                                    <div
                                        class="record-inner-subvalue"
                                        x-text="
                                            immunization.manufacturer
                                                ? immunization.lotNumber
                                                    ? `${immunization.manufacturer} (${immunization.lotNumber})`
                                                    : immunization.manufacturer
                                                : '-'
                                        "
                                    ></div>
                                </div>
                                <div>
                                    <div class="record-inner-label">{{ __('patients.body_part') }}</div>
                                    <div
                                        class="record-inner-subvalue"
                                        x-text="
                                            $wire.dictionaries['eHealth/immunization_body_sites'][
                                                immunization.siteCode
                                            ] || '-'
                                        "
                                    ></div>
                                </div>
                                <div>
                                    <div class="record-inner-label">{{ __('immunizations.was_performed') }}</div>
                                    <div
                                        class="record-inner-subvalue"
                                        x-text="immunization.notGiven === false ? '{{ __('forms.yes') }}' : '{{ __('forms.no') }}'"
                                    ></div>
                                </div>
                                <div>
                                    <div class="record-inner-label">{{ __('medical-events.performer') }}</div>
                                    <div
                                        class="record-inner-subvalue"
                                        x-text="immunization.primarySource ? '{{ __('medical-events.performer') }}' : '{{ __('forms.patient') }}'"
                                    ></div>
                                </div>
                                <div>
                                    <div class="record-inner-label">{{ __('forms.date') }}</div>
                                    <div
                                        class="record-inner-subvalue"
                                        x-text="`${immunization.date} ${immunization.time}`"
                                    ></div>
                                </div>
                            </div>

                            <div class="hidden h-full justify-self-center border-l border-gray-200 lg:col-span-1 lg:block dark:border-gray-700"></div>

                            <div class="flex flex-col justify-start lg:col-span-5">
                                <div class="mb-3 text-[12px] font-bold tracking-wider text-gray-700 uppercase dark:text-gray-300">
                                    {{ __('immunizations.vaccination_protocol') }}
                                </div>

                                <div class="space-y-4">
                                    <template
                                        x-if="
                                            immunization.vaccinationProtocols &&
                                            immunization.vaccinationProtocols.length > 0
                                        "
                                    >
                                        <div>
                                            <template
                                                x-for="(protocol, pIndex) in immunization.vaccinationProtocols"
                                                :key="pIndex"
                                            >
                                                <div class="mb-4 grid grid-cols-2 gap-x-4 gap-y-4 border-b border-gray-100 pb-4 last:mb-0 last:border-b-0 last:pb-0 dark:border-gray-700">
                                                    <div class="col-span-2">
                                                        <div class="record-inner-label">
                                                            {{ __('immunizations.target_diseases') }}
                                                        </div>
                                                        <div
                                                            class="record-inner-subvalue"
                                                            x-text="
                                                                $wire.dictionaries[
                                                                    'eHealth/vaccination_target_diseases'
                                                                ][protocol.targetDiseaseCodes?.[0]] || '-'
                                                            "
                                                        ></div>
                                                    </div>
                                                    <div>
                                                        <div class="record-inner-label">
                                                            {{ __('immunizations.dose_sequence') }}
                                                        </div>
                                                        <div
                                                            class="record-inner-subvalue"
                                                            x-text="protocol.doseSequence || '-'"
                                                        ></div>
                                                    </div>
                                                    <div>
                                                        <div class="record-inner-label">
                                                            {{ __('immunizations.series_of_doses_by_protocol') }}
                                                        </div>
                                                        <div
                                                            class="record-inner-subvalue"
                                                            x-text="protocol.seriesDoses || '-'"
                                                        ></div>
                                                    </div>
                                                    <div>
                                                        <div class="record-inner-label">
                                                            {{ __('immunizations.protocol_author') }}
                                                        </div>
                                                        <div
                                                            class="record-inner-subvalue"
                                                            x-text="
                                                                $wire.dictionaries['eHealth/vaccination_authorities'][
                                                                    protocol.authorityCode
                                                                ] || '-'
                                                            "
                                                        ></div>
                                                    </div>
                                                    <div>
                                                        <div class="record-inner-label">
                                                            {{ __('immunizations.series') }}
                                                        </div>
                                                        <div
                                                            class="record-inner-subvalue"
                                                            x-text="protocol.series || '-'"
                                                        ></div>
                                                    </div>
                                                    <div class="col-span-2">
                                                        <div class="record-inner-label">
                                                            {{ __('immunizations.protocol_description') }}
                                                        </div>
                                                        <div
                                                            class="record-inner-subvalue"
                                                            x-text="protocol.description || '-'"
                                                        ></div>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                    <template
                                        x-if="
                                            ! immunization.vaccinationProtocols ||
                                            immunization.vaccinationProtocols.length === 0
                                        "
                                    >
                                        <div class="text-sm text-gray-500 italic dark:text-gray-400">
                                            Немає протоколів
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div>
        {{-- Button to trigger the modal --}}
        @unless ($isReadonly)
            <button
                @click.prevent="
                    openModal = true;
                    newImmunization = true;
                    modalImmunization = new Immunization();
                    resetVaccineSearch();
                "
                class="item-add my-5"
            >
                {{ __('forms.add') }}
            </button>
        @endunless

        {{-- Modal --}}
        <template x-teleport="body">
            {{-- This moves the modal at the end of the body tag --}}
            <div
                x-show="openModal"
                style="display: none"
                @keydown.escape.prevent.stop="openModal = false"
                role="dialog"
                aria-modal="true"
                x-id="['modal-title']"
                :aria-labelledby="$id('modal-title')"
                {{-- This associates the modal with unique ID --}}
                class="modal"
            >
                {{-- Overlay --}}
                <div x-show="openModal" x-transition.opacity class="fixed inset-0 bg-black/25"></div>

                {{-- Panel --}}
                <div
                    x-show="openModal"
                    x-transition
                    @click="openModal = false"
                    class="relative flex min-h-screen items-center justify-center p-4"
                >
                    <div @click.stop x-trap.noscroll.inert="openModal" class="modal-content h-fit w-full lg:max-w-7xl">
                        {{-- Title --}}
                        <h3 class="modal-header" :id="$id('modal-title')">{{ __('immunizations.label') }}</h3>

                        {{-- Content --}}
                        <form>
                            <fieldset @disabled($isReadonly) @class(['pointer-events-none' => $isReadonly])>
                                @include('livewire.encounter.immunization-parts.data')
                                @include('livewire.encounter.immunization-parts.vaccine-search')
                                @include('livewire.encounter.immunization-parts.information-about')
                                @include('livewire.encounter.immunization-parts.vaccination-protocol')

                                <div class="mt-6 flex justify-between space-x-2">
                                    <button type="button" @click="openModal = false" class="button-minor">
                                        {{ $isReadonly ? __('forms.close') : __('forms.cancel') }}
                                    </button>

                                    @unless ($isReadonly)
                                        <button
                                            @click.prevent="
                                                const newImmunizationCode = modalImmunization.vaccineCode;

                                                // Check for duplicates, excluding the current item when editing
                                                let hasDuplicate = false;

                                                if (newImmunization) {
                                                    // For new immunization, check all existing ones
                                                    hasDuplicate = immunizations.some(
                                                        (immunization) =>
                                                            immunization.vaccineCode === newImmunizationCode,
                                                    );
                                                } else {
                                                    // For editing, check all except the current item
                                                    hasDuplicate = immunizations.some(
                                                        (immunization, index) =>
                                                            index !== item &&
                                                            immunization.vaccineCode === newImmunizationCode,
                                                    );
                                                }

                                                if (hasDuplicate) {
                                                    showDuplicateCodeWarning = true;
                                                    return;
                                                }

                                                if (modalImmunization.notGiven) {
                                                    modalImmunization.reasons = [];
                                                } else {
                                                    modalImmunization.reasonNotGivenCode = '';
                                                }

                                                newImmunization !== false
                                                    ? immunizations.push(modalImmunization)
                                                    : (immunizations[item] = modalImmunization);

                                                showDuplicateCodeWarning = false;
                                                openModal = false;
                                            "
                                            class="button-primary"
                                            :disabled="! (
                                                Object.keys(vaccineCodesDictionary).includes(
                                                    modalImmunization.vaccineCode,
                                                ) &&
                                                modalImmunization.date.trim() &&
                                                modalImmunization.time.trim() &&
                                                (modalImmunization.reasons?.[0]?.code?.trim?.() ||
                                                    modalImmunization.reasonNotGivenCode?.trim?.()) &&
                                                (modalImmunization.primarySource ||
                                                    modalImmunization.reportOriginCode?.trim?.()) &&
                                                (modalImmunization.notGiven ||
                                                    (modalImmunization.doseQuantityValue &&
                                                        modalImmunization.doseQuantityUnit?.trim?.())) &&
                                                (modalImmunization.notGiven ||
                                                    ! modalImmunization.primarySource ||
                                                    (modalImmunization.manufacturer?.trim?.() &&
                                                        modalImmunization.lotNumber?.trim?.() &&
                                                        modalImmunization.expirationDate?.trim?.() &&
                                                        modalImmunization.siteCode?.trim?.() &&
                                                        modalImmunization.routeCode?.trim?.() &&
                                                        modalImmunization.doseQuantityCode?.trim?.())) &&
                                                modalImmunization.vaccinationProtocols.length > 0 &&
                                                ! hasDuplicateTargetDiseasesInImmunization() &&
                                                ! hasInvalidTargetDiseases() &&
                                                (! modalImmunization.primarySource ||
                                                    modalImmunization.vaccinationProtocols.every(
                                                        (protocol) =>
                                                            protocol.doseSequence &&
                                                            protocol.series &&
                                                            protocol.seriesDoses,
                                                    ))
                                            )"
                                        >
                                            {{ __('forms.save') }}
                                        </button>
                                    @endunless
                                </div>
                                <template x-if="showDuplicateCodeWarning">
                                    <p class="text-error text-right">{!! __('patients.duplicate_code_warning') !!}</p>
                                </template>
                            </fieldset>
                        </form>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>

<script>
    /**
     * Representation of the user's personal immunization
     */
    class Immunization {
        constructor(obj = null) {
            this.uuid = crypto.randomUUID();
            const now = new Date();
            const [yyyy, mm, dd] = now.toISOString().split('T')[0].split('-');

            this.date = `${dd}.${mm}.${yyyy}`;
            this.time = now.toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit', hour12: false });
            this.notGiven = false;
            this.vaccineCode = '';
            this.primarySource = true;
            this.reasons = [{ code: '' }];
            this.reasonNotGivenCode = '';
            this.reportOriginCode = '';
            this.reportOriginText = '';
            this.manufacturer = null;
            this.lotNumber = null;
            this.expirationDate = null;
            this.siteCode = '';
            this.routeCode = '';
            this.doseQuantityValue = null;
            this.doseQuantityCode = '';
            this.doseQuantityUnit = '';
            this.vaccinationProtocols = [];

            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }
</script>
