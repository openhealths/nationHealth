<div class="p-4 sm:p-8"
     id="conditions-section"
     x-data="{
             conditions: $wire.entangle('form.conditions'),
             diagnoses: $wire.entangle('form.encounter.diagnoses'),
             showPrimaryWarning: false,
             showDuplicateCodeWarning: false,
             modalCondition: new Condition(),
             modalDiagnosis: new Diagnosis(),
             newCondition: false,
             openConditionDrawer: false,
             item: 0,
             conditionCodesDictionary: $wire.dictionaries['eHealth/ICPC2/condition_codes'],
             diagnosisRolesDictionary: $wire.dictionaries['eHealth/diagnosis_roles'],
             conditionClinicalStatusesRolesDictionary: $wire.dictionaries['eHealth/condition_clinical_statuses'],
             conditionVerificationStatusesDictionary: $wire.dictionaries['eHealth/condition_verification_statuses'],
             icd10Descriptions: {},

             openEvidenceDrawer: false,
             evidenceSelectedType: '',
             evidenceSelectedEpisodeId: '',
             evidenceIsLoading: false,
             evidenceSearchResults: [],

             fetchEvidenceRecords() {
                 if (!this.evidenceSelectedType) {
                     this.evidenceSearchResults = [];
                     return;
                 }
                 this.evidenceIsLoading = true;
                 $wire.searchConditionsOrObservations(this.evidenceSelectedType)
                     .then(() => {
                         this.evidenceSearchResults = JSON.parse(JSON.stringify($wire.evidenceDetails || []));
                     })
                     .finally(() => {
                         this.evidenceIsLoading = false;
                     });
             },
             filteredEvidenceRecords() {
                 return this.evidenceSearchResults.filter(rec => {
                     if (this.evidenceSelectedEpisodeId && rec.episodeId) {
                         return rec.episodeId === this.evidenceSelectedEpisodeId;
                     }
                     return true;
                 });
             },
             addEvidence(record) {
                 if (this.modalCondition) {
                     if (!this.modalCondition.evidenceDetails) {
                         this.modalCondition.evidenceDetails = [];
                     }
                     const existingIds = this.modalCondition.evidenceDetails.map(detail => detail.id);
                     if (!existingIds.includes(record.id)) {
                         this.modalCondition.evidenceDetails = [
                             ...this.modalCondition.evidenceDetails,
                             {
                                 id: record.id,
                                 ehealthInsertedAt: record.ehealthInsertedAt,
                                 codeCode: record.codeCode,
                                 type: this.evidenceSelectedType
                             }
                         ];
                     }
                 }
             },

             init() {
                 this.$watch('evidenceSelectedType', () => this.fetchEvidenceRecords());
                 this.$watch('openEvidenceDrawer', (val) => {
                     if (val) {
                         this.evidenceSelectedType = '';
                         this.evidenceSelectedEpisodeId = '';
                         this.evidenceSearchResults = [];
                         this.fetchEvidenceRecords();
                     }
                 });

                 const icd10Codes = this.conditions
                     .filter(condition => condition.codeSystem === 'eHealth/ICD10_AM/condition_codes' && condition.codeCode)
                     .map(condition => condition.codeCode);

                 if (icd10Codes.length === 0) return;

                 $wire.fetchIcd10Descriptions(icd10Codes).then(() => {
                     $wire.results.forEach(result => {
                         this.icd10Descriptions[result.code] = result.description;
                     });
                 });
             }
         }"
>
    <div class="space-y-4">
        <template x-for="(condition, index) in conditions" :key="index">
            <div class="record-inner-card">
                <div class="record-inner-header">
                    <div class="record-inner-checkbox-col">
                        <input type="checkbox" class="default-checkbox w-5 h-5" disabled>
                    </div>

                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">{{ __('patients.code_and_name') }}</div>
                        <div class="record-inner-value text-[16px]"
                             x-text="`${ condition.codeCode } - ${ condition.codeSystem === 'eHealth/ICD10_AM/condition_codes' ? icd10Descriptions[condition.codeCode] : conditionCodesDictionary[condition.codeCode] }`"
                        ></div>
                    </div>

                    <div class="record-inner-action-col">
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
                                        item = index;
                                        modalCondition = new Condition(condition);
                                        modalDiagnosis = new Diagnosis(diagnoses[index]);
                                        newCondition = false;
                                        openConditionDrawer = true;
                                        close($refs.button);
                                    "
                                    >
                                        {{ __('forms.edit') }}
                                    </button>

                                    <button class="dropdown-delete"
                                            @click.prevent="
                                                conditions.splice(index, 1);
                                                diagnoses.splice(index, 1);
                                                close($refs.button);
                                            "
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
                        <div class="grid grid-cols-2 xl:grid-cols-4 gap-y-4 gap-x-4 w-full">
                            <div>
                                <div class="record-inner-label">{{ __('forms.type') }}</div>
                                <div class="record-inner-subvalue"
                                     x-text="diagnosisRolesDictionary[diagnoses[index]?.roleCode] || '-'"
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('patients.clinical_status') }}</div>
                                <div class="record-inner-subvalue"
                                     x-text="conditionClinicalStatusesRolesDictionary[condition.clinicalStatus] || '-'"
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('patients.verification_status') }}</div>
                                <div class="record-inner-subvalue"
                                     x-text="conditionVerificationStatusesDictionary[condition.verificationStatus] || '-'"
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('forms.comment') }}</div>
                                <div class="record-inner-subvalue" x-text="condition.asserterText || '-'"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <div>
        {{-- Button to trigger the drawer --}}
        <button @click.prevent="
                    newCondition = true; {{-- We are adding a new condition --}}
                    modalCondition = new Condition(); {{-- Replace the data of the previous condition with a new one--}}
                    modalDiagnosis = new Diagnosis();
                    openConditionDrawer = true;
                "
                class="item-add my-5"
        >
            {{ __('forms.add') }}
        </button>

        <x-dialog-drawer x-model="openConditionDrawer" maxWidth="4/5" wire:ignore>
            <x-slot name="title">
                <span
                    x-text="newCondition ? '{{ __('patients.new_diagnose_state') }}' : '{{ __('patients.edit_diagnose_state') }}'"
                ></span>
            </x-slot>

            {{-- Content --}}
            <form class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                    <div>
                        <label for="codingSystem"
                               class="text-xs text-gray-500 dark:text-gray-400 font-medium block mb-1"
                        >
                            {{ __('patients.coding_system') }}<span class="text-red-600"> *</span>
                        </label>
                        <div class="relative">
                            <select x-model="modalCondition.codeSystem"
                                    @change="modalCondition.codeCode = ''"
                                    id="codingSystem"
                                    class="input-select w-full appearance-none bg-none"
                                    required
                            >
                                <option value="">{{ __('forms.select') }} {{ __('patients.coding_system') }}*
                                </option>
                                <option value="eHealth/ICPC2/condition_codes"
                                        x-show="($wire.allowedConditionCodesBySystem['eHealth/ICPC2/condition_codes']?.length ?? 1) > 0"
                                >
                                    ICPC-2
                                </option>
                                <option value="eHealth/ICD10_AM/condition_codes"
                                        x-show="($wire.allowedConditionCodesBySystem['eHealth/ICD10_AM/condition_codes']?.length ?? 1) > 0"
                                >
                                    ICD-10 AM
                                </option>
                            </select>
                            @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400
                            dark:text-gray-500 pointer-events-none')
                        </div>

                        <button type="button"
                                @click="modalCondition.codeSystem = (modalCondition.codeSystem === 'eHealth/ICPC2/condition_codes') ? 'eHealth/ICD10_AM/condition_codes' : 'eHealth/ICPC2/condition_codes'; modalCondition.codeCode = '';"
                                class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 font-medium text-xs mt-2.5 block text-left"
                        >
                            <span
                                x-text="modalCondition.codeSystem === 'eHealth/ICPC2/condition_codes' ? '{{ __('patients.add_icd10_code') }}' : '{{ __('patients.add_icpc2_code') }}'"></span>
                        </button>
                    </div>

                    <div>
                        <div x-show="modalCondition.codeSystem === 'eHealth/ICPC2/condition_codes'">
                            <label for="conditionReasonCode"
                                   class="text-xs text-gray-500 dark:text-gray-400 font-medium block mb-1"
                            >
                                {{ __('patients.icpc-2_status_code') }}<span class="text-red-600"> *</span>
                            </label>
                            <div class="relative">
                                <x-select2 modelPath="modalCondition.codeCode"
                                           dictionaryName="eHealth/ICPC2/condition_codes"
                                           id="conditionReasonCode"
                                           class="input w-full"
                                />
                                @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400
                                dark:text-gray-500 pointer-events-none')
                            </div>
                        </div>

                        <div x-show="modalCondition.codeSystem === 'eHealth/ICD10_AM/condition_codes'"
                             x-data="{
                                 selected: null,
                                 results: $wire.entangle('results'),
                                 showResults: false
                             }"
                             class="relative"
                        >
                            <label for="icd10Code"
                                   class="text-xs text-gray-500 dark:text-gray-400 font-medium block mb-1">
                                {{ __('patients.icd-10') }}<span class="text-red-600"> *</span>
                            </label>
                            <input type="text"
                                   @input.debounce.300ms="
                                       let value = $event.target.value;
                                       modalCondition.codeCode = value;
                                       let isEnglish = /^[a-zA-Z0-9.]+$/.test(value);

                                        if ((isEnglish && value.length >= 1) || (!isEnglish && value.length >= 3)) {
                                            $wire.searchICD10(value);
                                            showResults = true;
                                        } else {
                                            showResults = false;
                                        }
                                   "
                                   @focus="if ((modalCondition.codeCode?.length ?? 0) >= 1) showResults = true"
                                   @click.away="showResults = false"
                                   :value="modalCondition.codeCode && icd10Descriptions[modalCondition.codeCode] ? modalCondition.codeCode + ' - ' + icd10Descriptions[modalCondition.codeCode] : modalCondition.codeCode"
                                   id="icd10Code"
                                   class="input w-full"
                                   placeholder="{{ __('forms.select') }}"
                                   autocomplete="off"
                            />

                            <div x-show="showResults && results.length > 0"
                                 class="absolute left-0 top-full z-10 max-h-80 w-full overflow-auto overscroll-contain rounded-lg border dark:bg-gray-800 border-gray-200 bg-white p-1.5 shadow-lg"
                            >
                                <ul>
                                    <template x-for="(result, index) in results" :key="index">
                                        <li class="group flex w-full cursor-pointer items-center rounded-md px-2 py-1.5 transition-colors dark:bg-gray-800 dark:text-white"
                                            @click="
                                                selected = result;
                                                modalCondition.codeCode = result.code;
                                                showResults = false;
                                            "
                                        >
                                            <span x-text="result.code + ' - ' + result.description"></span>
                                        </li>
                                    </template>
                                </ul>
                            </div>

                            <p x-show="showResults && results.length == 0" class="px-2 py-1.5 text-gray-600">
                                {{ __('forms.nothing_found') }}
                            </p>

                            <x-forms.loading />
                        </div>

                        <div x-show="!modalCondition.codeSystem">
                            <label class="text-xs text-gray-500 dark:text-gray-400 font-medium block mb-1">
                                {{ __('forms.code') }}<span class="text-red-600"> *</span>
                            </label>
                            <div class="relative">
                                <input type="text"
                                       disabled
                                       class="input w-full opacity-50 cursor-not-allowed"
                                       placeholder="{{ __('patients.choose_coding_system') }}"
                                />
                                @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 pointer-events-none')
                            </div>
                        </div>
                    </div>

                    <div>
                        <label for="diagnoseCode"
                               class="text-xs text-gray-500 dark:text-gray-400 font-medium block mb-1"
                        >
                            {{ __('forms.type') }}<span class="text-red-600"> *</span>
                        </label>
                        <div class="relative">
                            <select x-model="modalDiagnosis.roleCode"
                                    id="diagnoseCode"
                                    class="input-select w-full appearance-none bg-none"
                                    type="text"
                                    required
                            >
                                <option value="" selected>{{ __('forms.select') }}</option>
                                @foreach($this->dictionaries['eHealth/diagnosis_roles'] as $key => $diagnosisRole)
                                    <option value="{{ $key }}">{{ $diagnosisRole }}</option>
                                @endforeach
                            </select>
                            @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 pointer-events-none')
                        </div>
                    </div>

                    <div>
                        <label for="verificationStatus"
                               class="text-xs text-gray-500 dark:text-gray-400 font-medium block mb-1"
                        >
                            {{ __('patients.verification_status') }}<span class="text-red-600"> *</span>
                        </label>
                        <div class="relative">
                            <select x-model="modalCondition.verificationStatus"
                                    id="verificationStatus"
                                    class="input-select w-full appearance-none bg-none"
                                    type="text"
                                    required
                            >
                                <option value="" selected>{{ __('forms.select') }}</option>
                                @foreach($this->dictionaries['eHealth/condition_verification_statuses'] as $key => $verificationStatus)
                                    <option value="{{ $key }}">{{ $verificationStatus }}</option>
                                @endforeach
                            </select>
                            @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400
                            dark:text-gray-500 pointer-events-none')
                        </div>
                    </div>

                    <div>
                        <label for="clinicalStatus"
                               class="text-xs text-gray-500 dark:text-gray-400 font-medium block mb-1"
                        >
                            {{ __('patients.clinical_status') }}<span class="text-red-600"> *</span>
                        </label>
                        <div class="relative">
                            <select x-model="modalCondition.clinicalStatus"
                                    id="clinicalStatus"
                                    class="input-select w-full appearance-none bg-none"
                                    type="text"
                                    required
                            >
                                <option value="" selected>{{ __('forms.select') }}</option>
                                @foreach($this->dictionaries['eHealth/condition_clinical_statuses'] as $key => $clinicalStatus)
                                    <option value="{{ $key }}">{{ $clinicalStatus }}</option>
                                @endforeach
                            </select>
                            @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 pointer-events-none')
                        </div>
                    </div>

                    <div></div>

                    <div>
                        <label for="onsetDate"
                               class="text-xs text-gray-500 dark:text-gray-400 font-medium block mb-1"
                        >
                            {{ __('patients.start_date') }}<span class="text-red-600"> *</span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none pl-1">
                                @icon('calendar-week', 'w-4 h-4 text-gray-400')
                            </div>
                            <input
                                x-model="modalCondition.onsetDate"
                                datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                                type="text"
                                name="onsetDate"
                                id="onsetDate"
                                class="datepicker-input input pl-7 w-full"
                                autocomplete="off"
                                required
                            >
                        </div>
                    </div>

                    <div>
                        <label class="text-xs block mb-1">&nbsp;</label>
                        <div class="relative" onclick="document.getElementById('onsetTime').showPicker()">
                            <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none pl-1">
                                @icon('mingcute-time-fill', 'w-4 h-4 text-gray-400')
                            </div>
                            <input
                                x-model="modalCondition.onsetTime"
                                @input="$event.target.blur()"
                                type="time"
                                name="onsetTime"
                                id="onsetTime"
                                class="input pl-7 w-full cursor-pointer"
                                autocomplete="off"
                                required
                            >
                        </div>
                    </div>

                    <div>
                        <label for="assertedDate"
                               class="text-xs text-gray-500 dark:text-gray-400 font-medium block mb-1"
                        >
                            {{ __('patients.entry_date') }}<span class="text-red-600"> *</span>
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none pl-1">
                                @icon('calendar-week', 'w-4 h-4 text-gray-400')
                            </div>
                            <input
                                x-model="modalCondition.assertedDate"
                                datepicker-max-date="{{ now()->format(config('app.date_format')) }}"
                                type="text"
                                name="assertedDate"
                                id="assertedDate"
                                class="datepicker-input input pl-7 w-full"
                                autocomplete="off"
                                required
                            >
                        </div>
                    </div>

                    <div>
                        <label class="text-xs block mb-1">&nbsp;</label>
                        <div class="relative" onclick="document.getElementById('assertedTime').showPicker()">
                            <div class="absolute inset-y-0 start-0 flex items-center pointer-events-none pl-1">
                                @icon('mingcute-time-fill', 'w-4 h-4 text-gray-400')
                            </div>
                            <input
                                x-model="modalCondition.assertedTime"
                                @input="$event.target.blur()"
                                type="time"
                                name="assertedTime"
                                id="assertedTime"
                                class="input pl-7 w-full cursor-pointer"
                                autocomplete="off"
                                required
                            >
                        </div>
                    </div>

                    <div class="col-span-1 md:col-span-2 space-y-4">
                        <template x-for="(bodySite, bsIndex) in modalCondition.bodySites" :key="bsIndex">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4 items-end">
                                <div>
                                    <template x-if="bsIndex === 0">
                                        <label class="text-xs text-gray-500 dark:text-gray-400 font-medium block mb-1">
                                            {{ __('patients.body_part') }}
                                        </label>
                                    </template>
                                    <div class="relative">
                                        <select x-model="bodySite.code"
                                                class="input-select w-full appearance-none bg-none"
                                        >
                                            <option value="" selected>{{ __('forms.select') }}</option>
                                            @foreach($this->dictionaries['eHealth/body_sites'] as $key => $bodySiteName)
                                                <option value="{{ $key }}">{{ $bodySiteName }}</option>
                                            @endforeach
                                        </select>
                                        @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2
                                        text-gray-400 dark:text-gray-500 pointer-events-none')
                                    </div>
                                </div>
                                <div class="flex items-center h-10">
                                    <button type="button"
                                            @click="modalCondition.bodySites.splice(bsIndex, 1)"
                                            class="cursor-pointer text-gray-500 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-500 transition-colors flex items-center justify-center shrink-0"
                                    >
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2"
                                             viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                  d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </template>
                        <button type="button"
                                @click="if (!modalCondition.bodySites) modalCondition.bodySites = []; modalCondition.bodySites.push({ code: '' })"
                                class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 flex items-center gap-1.5 font-medium text-xs transition-colors mt-3"
                        >
                            <span>{{ __('patients.add_body_part') }}</span>
                        </button>
                    </div>

                    <div>
                        <label for="severityCondition"
                               class="text-xs text-gray-500 dark:text-gray-400 font-medium block mb-1"
                        >
                            {{ __('patients.severity_of_the_condition') }}
                        </label>
                        <div class="relative">
                            <select x-model="modalCondition.severityCode"
                                    id="severityCondition"
                                    class="input-select w-full appearance-none bg-none"
                                    type="text"
                                    required
                            >
                                <option value="" selected>{{ __('forms.select') }}</option>
                                @foreach($this->dictionaries['eHealth/condition_severities'] as $key => $conditionSeverity)
                                    <option value="{{ $key }}">{{ $conditionSeverity }}</option>
                                @endforeach
                            </select>
                            @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400
                            dark:text-gray-500 pointer-events-none')
                        </div>
                    </div>

                    <div>
                        <label for="rank" class="text-xs text-gray-500 dark:text-gray-400 font-medium block mb-1">
                            {{ __('patients.priority') }}
                        </label>
                        <div class="relative">
                            <select x-model.number="modalDiagnosis.rank"
                                    id="rank"
                                    class="input-select w-full appearance-none bg-none"
                                    type="text"
                                    required
                            >
                                <option selected>{{ __('forms.select') }}</option>
                                @for($i = 1; $i <= 10; $i++)
                                    <option value="{{ $i }}">{{ $i }}</option>
                                @endfor
                            </select>
                            @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400
                            dark:text-gray-500 pointer-events-none')
                        </div>
                    </div>
                </div>

                <div class="mt-10 flex flex-col lg:flex-row lg:items-center justify-between gap-6">
                    <div class="flex flex-wrap items-center gap-6">
                        <span class="text-sm font-bold text-gray-900 dark:text-white">
                            {{ __('patients.primary_source') }}
                        </span>

                        <div class="flex items-center gap-2">
                            <input x-model.boolean="modalCondition.primarySource"
                                   @change="modalCondition.primarySource = true; modalCondition.asserterText = '';"
                                   id="performer"
                                   type="radio"
                                   value="true"
                                   name="primarySource"
                                   class="default-radio cursor-pointer text-blue-600 focus:ring-blue-500"
                                   :checked="modalCondition.primarySource === true"
                            >
                            <label for="performer"
                                   class="text-sm font-medium text-gray-900 dark:text-gray-300 cursor-pointer">
                                {{ __('patients.performer') }}
                            </label>
                        </div>

                        <div class="flex items-center gap-2">
                            <input x-model.boolean="modalCondition.primarySource"
                                   @change="modalCondition.primarySource = false; modalCondition.asserterText = '';"
                                   id="otherSource"
                                   type="radio"
                                   value="false"
                                   name="primarySource"
                                   class="default-radio cursor-pointer text-blue-600 focus:ring-blue-500"
                                   :checked="modalCondition.primarySource === false"
                            >
                            <label for="otherSource"
                                   class="text-sm font-medium text-gray-900 dark:text-gray-300 cursor-pointer"
                            >
                                {{ __('patients.other_source') }}
                            </label>
                        </div>
                    </div>

                    <div class="flex-1 max-w-md">
                        <input type="text"
                               x-model="modalCondition.asserterText"
                               :disabled="modalCondition.primarySource === true"
                               class="w-full text-sm p-2 px-3 rounded-lg border border-gray-200 bg-gray-50 dark:bg-gray-700 dark:border-gray-600 dark:text-white text-gray-900 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 disabled:opacity-50 disabled:bg-gray-100 dark:disabled:bg-gray-800 transition-colors"
                               placeholder="{{ __('patients.doctor_who_created_episode') }}"
                        />
                    </div>
                </div>

                <div x-show="modalCondition.primarySource === false" class="space-y-6 mt-6 transition-all">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="reportOrigin"
                                   class="text-xs text-gray-500 dark:text-gray-400 font-medium block mb-1"
                            >
                                {{ __('patients.information_source') }}
                            </label>
                            <div class="relative">
                                <select x-model="modalCondition.reportOriginCode"
                                        id="reportOrigin"
                                        class="input-select w-full appearance-none bg-none"
                                        required
                                >
                                    <option selected value="">{{ __('forms.select') }}</option>
                                    @foreach($this->dictionaries['eHealth/report_origins'] as $key => $reportOrigin)
                                        <option value="{{ $key }}">{{ $reportOrigin }}</option>
                                    @endforeach
                                </select>
                                @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 pointer-events-none')
                            </div>
                        </div>
                    </div>

                    @include('livewire.encounter.condition-parts.evidence-codes')
                    @include('livewire.encounter.condition-parts.evidence-details')
                </div>

                <div x-show="modalCondition.primarySource === true" class="mt-8 transition-all">
                    <div class="form-group group">
                        <label for="doctorComment" class="text-sm font-bold text-gray-900 dark:text-white block mb-3">
                            {{ __('forms.comment') }}
                        </label>
                        <textarea rows="4"
                                  x-model="modalCondition.asserterText"
                                  id="doctorComment"
                                  name="doctorComment"
                                  class="textarea w-full border border-gray-200 dark:border-gray-600 bg-gray-50 rounded-lg p-3 text-sm focus:ring-1 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white transition-colors"
                                  placeholder="{{ __('forms.write_comment_here') }}"
                        ></textarea>
                    </div>
                </div>

                <p class="text-sm text-gray-400 dark:text-gray-500 mt-6">{{ __('forms.form_required_note') }}</p>

                <div class="mt-8 flex justify-start items-center gap-4">
                    <button type="button"
                            @click="
                                showPrimaryWarning = false;
                                showDuplicateCodeWarning = false;
                                openEvidenceDrawer = false;
                                openConditionDrawer = false;
                            "
                            class="button-minor"
                    >
                        <span>{{ __('forms.cancel') }}</span>
                    </button>

                    <button @click.prevent="
                                if (modalDiagnosis.roleCode === 'primary') {
                                    const matchingPrimaryCount = diagnoses.filter((diagnose, index) => {
                                        if (newCondition === false && index === item) return false;
                                        return diagnose.roleCode === 'primary';
                                    }).length;

                                    if (matchingPrimaryCount >= 1) {
                                        showPrimaryWarning = true;
                                        return;
                                    }
                                }

                                const newConditionCode = modalCondition.codeCode;
                                const matchingCodesCount = conditions.filter((c, index) => {
                                    if (newCondition === false && index === item) return false;
                                    return c.codeCode === newConditionCode;
                                }).length;

                                if (matchingCodesCount >= 1) {
                                    showDuplicateCodeWarning = true;
                                    return;
                                }

                                const condition = JSON.parse(JSON.stringify(modalCondition));
                                const diagnosis = JSON.parse(JSON.stringify(modalDiagnosis));

                                if (newCondition) {
                                    conditions.push(condition);
                                    diagnoses.push(diagnosis);
                                } else {
                                    conditions[item] = condition;
                                    diagnoses[item] = diagnosis;
                                }

                                showPrimaryWarning = false;
                                showDuplicateCodeWarning = false;
                                openEvidenceDrawer = false;
                                openConditionDrawer = false;
                            "
                            class="cursor-pointer bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg text-sm px-5 py-2.5 shadow-sm focus:ring-4 focus:ring-blue-100 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                            :disabled="!(
                                modalCondition.clinicalStatus.trim() &&
                                modalCondition.verificationStatus.trim() &&
                                modalCondition.codeCode.trim() &&
                                modalDiagnosis.roleCode
                            )"
                    >
                        <span
                            x-text="newCondition ? '{{ __('patients.add_diagnose') }}' : '{{ __('forms.save') }}'"></span>
                    </button>
                </div>
                <div class="mt-2 text-left">
                    <template x-if="showPrimaryWarning">
                        <p class="text-error">
                            {!! __('patients.new_primary_diagnose') !!}
                        </p>
                    </template>
                    <template x-if="showDuplicateCodeWarning">
                        <p class="text-error">
                            {!! __('patients.duplicate_code_warning') !!}
                        </p>
                    </template>
                </div>
            </form>
        </x-dialog-drawer>
    </div>

    {{-- Evidence Search Drawer (Restored Essence) --}}
    <x-dialog-drawer x-model="openEvidenceDrawer"
                     maxWidth="3/5"
                     backdropClickThrough="true"
                     stopClickPropagation="true"
                     wire:ignore
    >
        <x-slot name="title">
            {{ __('patients.add_observations_reports_conditions') }}
        </x-slot>

        {{-- Section Title "Пошук" --}}
        <div class="mb-4 flex items-center gap-1.5 font-bold text-gray-900 dark:text-gray-100 pl-1 mt-2">
            @icon('search-outline', 'w-5 h-5 text-gray-800 dark:text-gray-200')
            <span class="text-base">{{ __('care-plan.search') }}</span>
        </div>

        {{-- Filters (Type & Episode Select from mock) --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div class="form-group group">
                <select x-model="evidenceSelectedType"
                        id="evidenceDrawerSelectedType"
                        class="input-select peer w-full"
                >
                    <option value="" selected>{{ __('forms.select') }}</option>
                    <option value="condition">{{ __('patients.condition_or_diagnosis') }}</option>
                    <option value="observation">{{ __('patients.evidence_observations') }}</option>
                </select>
                <label for="evidenceDrawerSelectedType" class="label">
                    {{ mb_ucfirst(__('patients.medical_records_type')) }}
                </label>
            </div>
        </div>

        <div class="relative">
            <div x-show="evidenceIsLoading"
                 class="absolute inset-0 flex items-center justify-center bg-white/70 dark:bg-gray-800/70 z-10"
                 x-cloak
            >
                <x-forms.loading />
            </div>

            <table class="table-input w-inherit">
                <thead class="thead-input">
                <tr>
                    <th scope="col" class="th-input">{{ __('forms.date') }}</th>
                    <th scope="col" class="th-input">{{ __('forms.type') }}</th>
                    <th scope="col" class="th-input">{{ __('patients.code_and_name') }}</th>
                    <th scope="col" class="th-input text-center">{{ __('forms.action') }}</th>
                </tr>
                </thead>
                <tbody>
                <template x-for="record in filteredEvidenceRecords()" :key="record.id">
                    <tr class="border-b border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
                        <td class="td-input text-[14px] text-gray-900 dark:text-gray-300"
                            x-text="record.ehealthInsertedAt || ''"
                        ></td>
                        <td class="td-input text-[14px] text-gray-900 dark:text-gray-300"
                            x-text="evidenceSelectedType === 'condition' ? '{{ __('patients.condition_or_diagnosis') }}' : '{{ __('patients.evidence_observations') }}'"
                        ></td>
                        <td class="td-input text-[14px] text-gray-900 dark:text-white" x-text="`${ record.codeCode } - ${
                                $wire.dictionaries['eHealth/LOINC/observation_codes'][record.codeCode] ||
                                $wire.dictionaries['eHealth/ICF/classifiers'][record.codeCode] ||
                                $wire.dictionaries['eHealth/ICD10_AM/condition_codes'][record.codeCode] ||
                                $wire.dictionaries['eHealth/ICPC2/condition_codes'][record.codeCode]
                            }`"
                        ></td>
                        <td class="td-input text-center">
                            <template x-if="!modalCondition.evidenceDetails.some(d => d.id === record.id)">
                                <button type="button"
                                        @click="addEvidence(record)"
                                        class="inline-flex items-center justify-center text-gray-900 hover:text-blue-600 dark:text-white dark:hover:text-blue-400 font-medium text-sm transition-colors cursor-pointer"
                                >
                                    @icon('plus', 'w-5 h-5')
                                </button>
                            </template>
                            <template x-if="modalCondition.evidenceDetails.some(d => d.id === record.id)">
                                <span
                                    class="inline-flex items-center text-green-600 dark:text-green-400 font-medium text-sm">
                                    @icon('check-circle', 'w-5 h-5')
                                    {{ __('patients.added') }}
                                </span>
                            </template>
                        </td>
                    </tr>
                </template>
                </tbody>
            </table>

            <div x-show="!evidenceIsLoading && filteredEvidenceRecords().length === 0"
                 class="text-center py-8 text-gray-500 dark:text-gray-400" x-cloak>
                {{ __('forms.nothing_found') }}
            </div>
        </div>

        <div class="mt-6 flex justify-between space-x-2">
            <button type="button"
                    @click="openEvidenceDrawer = false"
                    class="button-minor"
            >
                {{ __('forms.close') }}
            </button>
        </div>
    </x-dialog-drawer>
</div>

<script>
    /**
     * Representation of the user's personal conditions
     */
    class Condition {
        constructor(obj = null) {
            const now = new Date();
            const [yyyy, mm, dd] = now.toISOString().split('T')[0].split('-');
            const formattedDate = `${dd}.${mm}.${yyyy}`;
            const formattedTime = now.toLocaleTimeString('uk-UA', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            });

            this.primarySource = true;
            this.codeSystem = '';
            this.codeCode = '';
            this.clinicalStatus = '';
            this.verificationStatus = '';
            this.onsetDate = formattedDate;
            this.onsetTime = formattedTime;
            this.assertedDate = formattedDate;
            this.assertedTime = formattedTime;
            this.severityCode = '';
            this.asserterText = '';
            this.reportOriginCode = '';
            this.evidenceCodes = [];
            this.evidenceDetails = [];
            this.bodySites = [];

            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }
        }
    }

    class Diagnosis {
        roleCode = '';
        rank = '';

        constructor(obj = null) {
            if (obj) {
                this.roleCode = obj.roleCode || '';
                this.rank = obj.rank || '';
            }
        }
    }
</script>
