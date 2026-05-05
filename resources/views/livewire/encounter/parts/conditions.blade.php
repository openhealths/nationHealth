{{-- Component to input values to the table through the Modal, built with Alpine --}}
<fieldset class="fieldset"
          {{-- Binding conditions to Alpine, it will be re-used in the modal.
            Note that it's necessary for modal to work properly --}}
          x-data="{
                  conditions: $wire.entangle('form.conditions'),
                  diagnoses: $wire.entangle('form.encounter.diagnoses'),
                  openModal: false,
                  showPrimaryWarning: false,
                  showDuplicateCodeWarning: false,
                  modalCondition: new Condition(),
                  modalDiagnosis: new Diagnosis(),
                  newCondition: false,
                  item: 0,
                  conditionCodesDictionary: $wire.dictionaries['eHealth/ICPC2/condition_codes'],
                  diagnosisRolesDictionary: $wire.dictionaries['eHealth/diagnosis_roles'],
                  conditionClinicalStatusesRolesDictionary: $wire.dictionaries['eHealth/condition_clinical_statuses'],
                  conditionVerificationStatusesDictionary: $wire.dictionaries['eHealth/condition_verification_statuses']
              }"
>
    <legend class="legend">
        <h2>{{ __('patients.diagnoses') }}</h2>
    </legend>

    <table class="table-input w-inherit">
        <thead class="thead-input">
        <tr>
            <th scope="col" class="th-input">{{ __('patients.code_and_name') }}</th>
            <th scope="col" class="th-input">{{ __('forms.type') }}</th>
            <th scope="col" class="th-input">{{ __('patients.clinical_status') }}</th>
            <th scope="col" class="th-input">{{ __('patients.verification_status') }}</th>
            <th scope="col" class="th-input">{{ __('forms.comment') }}</th>
            <th scope="col" class="th-input">{{ __('forms.action') }}</th>
        </tr>
        </thead>
        <tbody>
        <template x-for="(condition, index) in conditions" :key="index">
            <tr>
                <td class="td-input"
                    x-text="`${ condition.codeCode } - ${ conditionCodesDictionary[condition.codeCode] }`"
                ></td>
                <td class="td-input"
                    x-text="diagnosisRolesDictionary[diagnoses[index]?.roleCode]"
                ></td>
                <td class="td-input"
                    x-text="conditionClinicalStatusesRolesDictionary[condition.clinicalStatus]"
                ></td>
                <td class="td-input"
                    x-text="conditionVerificationStatusesDictionary[condition.verificationStatus]"
                ></td>
                <td class="td-input" x-text="condition.asserterText"></td>
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
                                            modalCondition = new Condition(condition);
                                            modalDiagnosis = new Diagnosis(diagnoses[index]);
                                            newCondition = false; {{-- This condition is already created --}}
                                        "
                                        class="dropdown-button"
                                >
                                    {{ __('forms.edit') }}
                                </button>

                                <button @click.prevent="
                                            conditions.splice(index, 1);
                                            diagnoses.splice(index, 1);
                                            close($refs.button);
                                        "
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
                        newCondition = true; {{-- We are adding a new condition --}}
                        modalCondition = new Condition(); {{-- Replace the data of the previous condition with a new one--}}
                        modalDiagnosis = new Diagnosis();
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
                        <h3 class="modal-header" :id="$id('modal-title')">{{ __('patients.diagnoses') }}</h3>

                        {{-- Content --}}
                        <form>
                            <div class="form-row-modal">
                                <div>
                                    <label for="codingSystem" class="label-modal">
                                        {{ __('patients.coding_system') }}
                                    </label>
                                    <select x-model="modalCondition.codeSystem"
                                            @change="modalCondition.codeCode = ''"
                                            id="codingSystem"
                                            class="input-modal"
                                            required
                                    >
                                        <option value="">{{ __('forms.select') }} {{ __('patients.coding_system') }}*</option>
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
                                    <p class="text-error text-xs"
                                       x-show="!modalCondition.codeSystem"
                                    >
                                        {{ __('forms.field_empty') }}
                                    </p>
                                </div>

                                <!-- ICPC2 Code Selection -->
                                <div x-show="modalCondition.codeSystem === 'eHealth/ICPC2/condition_codes'">
                                    <label for="conditionReasonCode" class="label-modal">
                                        {{ __('patients.icpc-2_status_code') }}
                                    </label>
                                    <x-select2 modelPath="modalCondition.codeCode"
                                               dictionaryName="eHealth/ICPC2/condition_codes"
                                               id="conditionReasonCode"
                                    />
                                    <p class="text-error text-xs"
                                       x-show="modalCondition.codeSystem === 'eHealth/ICPC2/condition_codes' && !modalCondition.codeCode"
                                    >
                                        {{ __('forms.field_empty') }}
                                    </p>
                                </div>

                                <!-- ICD10 Code Search -->
                                <div x-show="modalCondition.codeSystem === 'eHealth/ICD10_AM/condition_codes'"
                                     x-data="{
                                        selected: null,
                                        results: $wire.entangle('results'),
                                        showResults: false
                                    }"
                                     class="relative"
                                >
                                    <label for="icd10Code" class="label-modal">
                                        {{ __('patients.icd-10') }}
                                    </label>
                                    <input type="text"
                                           @input.debounce.300ms="
                                               let value = $event.target.value;
                                               let isEnglish = /^[a-zA-Z]+$/.test(value);

                                               if ((isEnglish && value.length >= 1) || (!isEnglish && value.length >= 3)) {
                                                   $wire.searchICD10(value);
                                                   showResults = true;
                                               }
                                           "
                                           @focus="if ((modalCondition.codeCode?.length ?? 0) >= 1) showResults = true"
                                           @click.away="showResults = false"
                                           x-model="modalCondition.codeCode"
                                           id="icd10Code"
                                           class="input-modal"
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

                                    <p class="text-error text-xs"
                                       x-show="modalCondition.codeSystem === 'eHealth/ICD10_AM/condition_codes' && !modalCondition.codeCode"
                                    >
                                        {{ __('forms.field_empty') }}
                                    </p>

                                    <x-forms.loading />
                                </div>

                                <div>
                                    <label for="diagnoseCode" class="label-modal">
                                        {{ __('forms.type') }}
                                    </label>
                                    <select x-model="modalDiagnosis.roleCode"
                                            id="diagnoseCode"
                                            class="input-modal"
                                            type="text"
                                            required
                                    >
                                        <option selected>{{ __('forms.select') }}</option>
                                        @foreach($this->dictionaries['eHealth/diagnosis_roles'] as $key => $diagnosisRole)
                                            <option value="{{ $key }}">{{ $diagnosisRole }}</option>
                                        @endforeach
                                    </select>

                                    <p class="text-error text-xs"
                                       x-show="!Object.keys(diagnosisRolesDictionary).includes(modalDiagnosis.roleCode)"
                                    >
                                        {{ __('forms.field_empty') }}
                                    </p>
                                </div>

                                <div>
                                    <label for="rank" class="label-modal">
                                        {{ __('patients.priority') }}
                                    </label>
                                    <select x-model.number="modalDiagnosis.rank"
                                            id="rank"
                                            class="input-modal"
                                            type="text"
                                            required
                                    >
                                        <option selected>{{ __('forms.select') }}</option>
                                        @for($i = 1; $i <= 10; $i++)
                                            <option value="{{ $i }}">{{ $i }}</option>
                                        @endfor
                                    </select>
                                </div>

                                <div>
                                    <label for="clinicalStatus" class="label-modal">
                                        {{ __('patients.clinical_status') }}
                                    </label>
                                    <select x-model="modalCondition.clinicalStatus"
                                            id="clinicalStatus"
                                            class="input-modal"
                                            type="text"
                                            required
                                    >
                                        <option value="" selected>{{ __('forms.select') }}</option>
                                        @foreach($this->dictionaries['eHealth/condition_clinical_statuses'] as $key => $clinicalStatus)
                                            <option value="{{ $key }}">{{ $clinicalStatus }}</option>
                                        @endforeach
                                    </select>

                                    <p class="text-error text-xs"
                                       x-show="!Object.keys(conditionClinicalStatusesRolesDictionary).includes(modalCondition.clinicalStatus)"
                                    >
                                        {{ __('forms.field_empty') }}
                                    </p>
                                </div>

                                <div>
                                    <label for="verificationStatus" class="label-modal">
                                        {{ __('patients.verification_status') }}
                                    </label>
                                    <select x-model="modalCondition.verificationStatus"
                                            id="verificationStatus"
                                            class="input-modal"
                                            type="text"
                                            required
                                    >
                                        <option value="" selected>{{ __('forms.select') }}</option>
                                        @foreach($this->dictionaries['eHealth/condition_verification_statuses'] as $key => $verificationStatus)
                                            <option value="{{ $key }}">{{ $verificationStatus }}</option>
                                        @endforeach
                                    </select>

                                    <p class="text-error text-xs"
                                       x-show="!Object.keys(conditionVerificationStatusesDictionary).includes(modalCondition.verificationStatus)"
                                    >
                                        {{ __('forms.field_empty') }}
                                    </p>
                                </div>

                                <div class="relative">
                                    @icon('calendar-week', 'svg-input absolute left-2.5 !top-2/3 transform -translate-y-1/2 pointer-events-none')
                                    <label for="onsetDate" class="label-modal">
                                        {{ __('forms.start_date') }}
                                    </label>
                                    <input x-model="modalCondition.onsetDate"
                                           datepicker-max-date="{{ now()->format('Y-m-d') }}"
                                           type="text"
                                           name="onsetDate"
                                           id="onsetDate"
                                           class="datepicker-input input-modal !pl-10"
                                           autocomplete="off"
                                           required
                                    >

                                    <p class="text-error text-xs"
                                       x-show="modalCondition.onsetDate.trim() === ''"
                                    >
                                        {{ __('forms.field_empty') }}
                                    </p>
                                </div>

                                <div class="relative" onclick="document.getElementById('onsetTime').showPicker()">
                                    @icon('mingcute-time-fill', 'svg-input left-2.5 !top-2/3 transform -translate-y-1/2')
                                    <label for="onsetTime" class="label-modal">
                                        {{ __('forms.start_time') }}
                                    </label>
                                    <input x-model="modalCondition.onsetTime"
                                           @input="$event.target.blur()"
                                           datepicker-max-date="{{ now()->format('Y-m-d') }}"
                                           type="time"
                                           name="onsetTime"
                                           id="onsetTime"
                                           class="input-modal !pl-10"
                                           autocomplete="off"
                                           required
                                    >

                                    <p class="text-error text-xs"
                                       x-show="modalCondition.onsetTime.trim() === ''"
                                    >
                                        {{ __('forms.field_empty') }}
                                    </p>
                                </div>

                                <div class="relative">
                                    @icon('calendar-week', 'svg-input absolute left-2.5 !top-2/3 transform -translate-y-1/2 pointer-events-none')
                                    <label for="assertedDate" class="label-modal">
                                        {{ __('patients.entry_date') }}
                                    </label>
                                    <input x-model="modalCondition.assertedDate"
                                           datepicker-max-date="{{ now()->format('Y-m-d') }}"
                                           type="text"
                                           name="assertedDate"
                                           id="assertedDate"
                                           class="datepicker-input input-modal !pl-10"
                                           autocomplete="off"
                                           required
                                    >
                                </div>

                                <div class="relative" onclick="document.getElementById('assertedTime').showPicker()">
                                    @icon('mingcute-time-fill', 'svg-input left-2.5 !top-2/3 transform -translate-y-1/2')
                                    <label for="assertedTime" class="label-modal">
                                        {{ __('patients.entry_time') }}
                                    </label>
                                    <input x-model="modalCondition.assertedTime"
                                           @input="$event.target.blur()"
                                           datepicker-max-date="{{ now()->format('Y-m-d') }}"
                                           type="time"
                                           name="assertedTime"
                                           id="assertedTime"
                                           class="input-modal !pl-10"
                                           autocomplete="off"
                                           required
                                    >
                                </div>

                                <div>
                                    <label for="severityCondition" class="label-modal">
                                        {{ __('patients.severity_of_the_condition') }}
                                    </label>
                                    <select x-model="modalCondition.severityCode"
                                            id="severityCondition"
                                            class="input-modal"
                                            type="text"
                                            required
                                    >
                                        <option selected>{{ __('forms.select') }}</option>
                                        @foreach($this->dictionaries['eHealth/condition_severities'] as $key => $conditionSeverity)
                                            <option value="{{ $key }}">{{ $conditionSeverity }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="mt-12">
                                <div class="flex gap-20 md:mb-5 mb-4">
                                    <h2 class="default-p">{{ __('patients.primary_source') }}</h2>
                                    <div class="flex items-center">
                                        <input @change="modalCondition.primarySource = true"
                                               x-model.boolean="modalCondition.primarySource"
                                               id="performer"
                                               type="radio"
                                               value="true"
                                               name="primarySource"
                                               class="default-radio"
                                               :checked="modalCondition.primarySource === true"
                                        >
                                        <label for="performer"
                                               class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300"
                                        >
                                            {{ __('patients.performer') }}
                                        </label>
                                    </div>

                                    <div class="flex items-center">
                                        <input @change="modalCondition.primarySource = false"
                                               x-model.boolean="modalCondition.primarySource"
                                               id="otherSource"
                                               type="radio"
                                               value="false"
                                               name="primarySource"
                                               class="default-radio"
                                               :checked="modalCondition.primarySource === false"
                                        >
                                        <label for="otherSource"
                                               class="ms-2 text-sm font-medium text-gray-900 dark:text-gray-300"
                                        >
                                            {{ __('patients.other_source') }}
                                        </label>
                                    </div>
                                </div>

                                <div x-show="modalCondition.primarySource === true">
                                    <div class="form-row-modal">
                                        <div class="form-group group">
                                            <textarea rows="4"
                                                      x-model="modalCondition.asserterText"
                                                      id="doctorComment"
                                                      name="doctorComment"
                                                      class="textarea"
                                                      placeholder="{{ __('forms.write_comment_here') }}"
                                            ></textarea>
                                        </div>
                                    </div>
                                </div>

                                <div x-show="modalCondition.primarySource === false">
                                    <div class="form-row-modal !mb-12">
                                        <div>
                                            <label for="reportOrigin" class="label-modal">
                                                {{ __('patients.information_source') }}
                                            </label>
                                            <select x-model="modalCondition.reportOriginCode"
                                                    id="reportOrigin"
                                                    class="input-modal"
                                                    type="text"
                                                    required
                                            >
                                                <option selected>{{ __('forms.select') }}</option>
                                                @foreach($this->dictionaries['eHealth/report_origins'] as $key => $reportOrigin)
                                                    <option value="{{ $key }}">{{ $reportOrigin }}</option>
                                                @endforeach
                                            </select>

                                            <p class="text-error text-xs"
                                               x-show="!Object.keys($wire.dictionaries['eHealth/report_origins']).includes(modalCondition.reportOriginCode)"
                                            >
                                                {{ __('forms.field_empty') }}
                                            </p>
                                        </div>
                                    </div>

                                    @include('livewire.encounter.condition-parts.evidence-codes')
                                    @include('livewire.encounter.condition-parts.evidence-details')
                                </div>
                            </div>

                            <div class="mt-6 flex justify-between space-x-2 items-start">
                                <button type="button"
                                        @click="
                                            openModal = false;
                                            showPrimaryWarning = false;
                                            showDuplicateCodeWarning = false;
                                        "
                                        class="button-minor w-auto"
                                >
                                    {{ __('forms.cancel') }}
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

                                            openModal = false;
                                            showPrimaryWarning = false;
                                            showDuplicateCodeWarning = false;
                                        "
                                        class="button-primary justify-end"
                                        :disabled="!(
                                            modalCondition.clinicalStatus.trim() &&
                                            modalCondition.verificationStatus.trim() &&
                                            modalCondition.codeCode.trim() &&
                                            modalDiagnosis.roleCode
                                        )"
                                >
                                    {{ __('forms.save') }}
                                </button>
                            </div>
                            <template x-if="showPrimaryWarning">
                                <p class="text-error text-right">
                                    {!! __('patients.new_primary_diagnose') !!}
                                </p>
                            </template>
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

<script>
    /**
     * Representation of the user's personal conditions
     */
    class Condition {
        constructor(obj = null) {
            const now = new Date();

            this.primarySource = true;
            this.codeSystem = '';
            this.codeCode = '';
            this.clinicalStatus = '';
            this.verificationStatus = '';
            this.onsetDate = now.toISOString().split('T')[0];
            this.onsetTime = now.toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit', hour12: false });
            this.assertedDate = now.toISOString().split('T')[0];
            this.assertedTime = now.toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit', hour12: false });
            this.severityCode = '';
            this.asserterText = '';
            this.reportOriginCode = '';
            this.evidenceCodes = [];
            this.evidenceDetails = [];

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
