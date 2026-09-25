@use('App\Enums\Person\ConditionVerificationStatus')

<div
    class="p-4 sm:p-8"
    id="conditions-section"
    @condition-evidence-selected.window="addEvidence($event.detail.record)"
    @registered-condition-selected.window="selectRegisteredCondition($event.detail.record)"
    x-data="{
        conditions: $wire.entangle('conditionForm.conditions'),
        diagnoses: $wire.entangle('form.encounter.diagnoses'),
        encounter: $wire.entangle('form.encounter'),
        episodeName: $wire.entangle('form.episode.name'),
        showPrimaryWarning: false,
        showPrimaryChangeWarning: false,
        showDuplicateCodeWarning: false,
        conditionMode: 'create',

        conditionToSave() {
            if (this.conditionMode !== 'select') {
                return this.modalCondition;
            }

            return {
                uuid: this.registeredCondition?.id,
                isRegistered: true,
                fromEpisode: this.registeredCondition?.fromEpisode === true,
                codeCode: this.registeredCondition?.codeCode,
                codeSystem: this.registeredCondition?.codeSystem,
                clinicalStatus: this.registeredCondition?.clinicalStatus,
                verificationStatus: this.registeredCondition?.verificationStatus,
                onsetDate: this.registeredCondition?.onsetDate,
                episodeName: this.registeredCondition?.episodeName,
            };
        },

        saveCondition(bypassWarning = false) {
            const conditionToSave = this.conditionToSave();

            if (this.modalDiagnosis.roleCode === 'primary') {
                const matchingPrimaryCount = this.diagnoses.filter((diagnose, index) => {
                    if (this.newCondition === false && index === this.item) return false;
                    return diagnose.roleCode === 'primary';
                }).length;

                if (matchingPrimaryCount >= 1) {
                    this.showPrimaryWarning = true;
                    return;
                }

                const episodePrimaryCode = $wire.episodePrimaryDiagnosisCode;

                if (
                    episodePrimaryCode &&
                    conditionToSave.codeCode !== episodePrimaryCode &&
                    ! bypassWarning
                ) {
                    this.showPrimaryChangeWarning = true;
                    return;
                }
            }

            const exclusiveRoleCodes = ['primary', 'comorbidity', 'complication'];

            const isCodeUsedInAnotherRole =
                exclusiveRoleCodes.includes(this.modalDiagnosis.roleCode) &&
                this.conditions.some(
                    (condition, index) =>
                        ! (this.newCondition === false && index === this.item) &&
                        condition.codeSystem === conditionToSave.codeSystem &&
                        condition.codeCode === conditionToSave.codeCode &&
                        exclusiveRoleCodes.includes(this.diagnoses[index]?.roleCode) &&
                        this.diagnoses[index].roleCode !== this.modalDiagnosis.roleCode,
                );

            if (isCodeUsedInAnotherRole) {
                this.showDuplicateCodeWarning = true;
                return;
            }

            const condition = JSON.parse(JSON.stringify(conditionToSave));
            const diagnosis = JSON.parse(JSON.stringify(this.modalDiagnosis));

            if (condition.isRegistered && condition.codeSystem === 'eHealth/ICD10_AM/condition_codes') {
                this.icd10Descriptions[condition.codeCode] =
                    $wire.dictionaries['eHealth/ICD10_AM/condition_codes'][condition.codeCode] ||
                    this.registeredCondition?.description;
            }

            if (this.newCondition) {
                this.conditions.push(condition);
                this.diagnoses.push(diagnosis);
            } else {
                this.conditions[this.item] = condition;
                this.diagnoses[this.item] = diagnosis;
            }

            this.showPrimaryWarning = false;
            this.showDuplicateCodeWarning = false;
            this.showPrimaryChangeWarning = false;
            this.openConditionDrawer = false;
            this.syncDiagnosisParticipants();
        },

        saveConditionLabel() {
            return this.newCondition ? '{{ __('conditions.add_diagnose') }}' : '{{ __('forms.save') }}';
        },

        modalCondition: new Condition(),
        modalDiagnosis: new Diagnosis(),
        newCondition: false,
        openConditionDrawer: false,
        openConditionSearchDrawer: false,
        registeredCondition: null,

        registeredConditionLabel(record) {
            const name =
                $wire.dictionaries['eHealth/ICD10_AM/condition_codes'][record.codeCode] ||
                $wire.dictionaries['eHealth/ICPC2/condition_codes'][record.codeCode] ||
                record.description ||
                '';

            return `${record.codeCode} ${name}`;
        },

        isRegisteredConditionSelected(recordId) {
            return this.registeredCondition?.id === recordId;
        },

        selectRegisteredCondition(record) {
            this.registeredCondition = JSON.parse(JSON.stringify(record));
            this.openConditionSearchDrawer = false;
        },

        item: 0,
        conditionCodesDictionary: $wire.dictionaries['eHealth/ICPC2/condition_codes'],
        diagnosisRolesDictionary: $wire.dictionaries['eHealth/diagnosis_roles'],
        conditionClinicalStatusesRolesDictionary: $wire.dictionaries['eHealth/condition_clinical_statuses'],
        conditionVerificationStatusesDictionary: $wire.dictionaries['eHealth/condition_verification_statuses'],
        icd10Descriptions: {},

        syncDiagnosisParticipants() {
            const performers = this.conditions.some((condition) => condition.primarySource === true)
                ? [this.conditionPerformer]
                : [];
            this.syncLocalEncounterParticipants('diagnosis', performers);
        },

        openEvidenceDrawer: false,

        isEvidenceAdded(recordId) {
            return (this.modalCondition?.evidenceDetails ?? []).some((detail) => detail.id === recordId);
        },

        addEvidence(record) {
            if (this.modalCondition) {
                if (! this.modalCondition.evidenceDetails) {
                    this.modalCondition.evidenceDetails = [];
                }
                const existingIds = this.modalCondition.evidenceDetails.map((detail) => detail.id);
                if (! existingIds.includes(record.id)) {
                    this.modalCondition.evidenceDetails = [
                        ...this.modalCondition.evidenceDetails,
                        {
                            id: record.id,
                            ehealthInsertedAt: record.ehealthInsertedAt,
                            codeCode: record.codeCode,
                            type: record.type,
                            description: record.description,
                        },
                    ];
                }
            }
        },

        parseConditionDateTime(date, time) {
            const dateParts = String(date ?? '')
                .split('.')
                .map(Number);

            const timeParts = String(time ?? '')
                .split(':')
                .map(Number);

            if (dateParts.length !== 3 || timeParts.length !== 2) {
                return null;
            }

            const [day, month, year] = dateParts;
            const [hours, minutes] = timeParts;

            const dateTime = new Date(year, month - 1, day, hours, minutes, 0, 0);

            if (
                dateTime.getFullYear() !== year ||
                dateTime.getMonth() !== month - 1 ||
                dateTime.getDate() !== day ||
                dateTime.getHours() !== hours ||
                dateTime.getMinutes() !== minutes
            ) {
                return null;
            }

            return dateTime;
        },

        conditionOnsetAfterEncounterEnd() {
            const onsetDateTime = this.parseConditionDateTime(
                this.modalCondition.onsetDate,
                this.modalCondition.onsetTime,
            );
            const encounterEndDateTime = this.parseConditionDateTime(
                this.encounter.periodDate,
                this.encounter.periodEnd,
            );

            return Boolean(onsetDateTime && encounterEndDateTime && onsetDateTime > encounterEndDateTime);
        },

        conditionAssertedOutsideEncounterPeriod() {
            const assertedDateTime = this.parseConditionDateTime(
                this.modalCondition.assertedDate,
                this.modalCondition.assertedTime,
            );
            const encounterStartDateTime = this.parseConditionDateTime(
                this.encounter.periodDate,
                this.encounter.periodStart,
            );
            const encounterEndDateTime = this.parseConditionDateTime(
                this.encounter.periodDate,
                this.encounter.periodEnd,
            );

            if (! assertedDateTime || ! encounterStartDateTime || ! encounterEndDateTime) {
                return false;
            }

            return assertedDateTime < encounterStartDateTime || assertedDateTime > encounterEndDateTime;
        },

        conditionDatesAreValid() {
            return ! this.conditionOnsetAfterEncounterEnd() && ! this.conditionAssertedOutsideEncounterPeriod();
        },

        init() {
            const icd10Codes = this.conditions
                .filter(
                    (condition) => condition.codeSystem === 'eHealth/ICD10_AM/condition_codes' && condition.codeCode,
                )
                .map((condition) => condition.codeCode);

            if (icd10Codes.length === 0) return;

            $wire.fetchIcd10Descriptions(icd10Codes).then(() => {
                $wire.results.forEach((result) => {
                    this.icd10Descriptions[result.code] = result.description;
                });
            });
        },
    }"
    {{-- Outside primary health care only ICD10_AM codes are allowed, so a code picked from ICPC-2 before the class
        was changed is dropped here instead of being rejected on submission --}}
    x-effect="
        if (encounter.classCode && encounter.classCode !== 'PHC') {
            if (conditions.some((condition) => condition.codeSystem === 'eHealth/ICPC2/condition_codes')) {
                conditions = conditions.map((condition) =>
                    condition.codeSystem === 'eHealth/ICPC2/condition_codes'
                        ? { ...condition, codeSystem: '', codeCode: '' }
                        : condition,
                );
            }

            if (modalCondition.codeSystem === 'eHealth/ICPC2/condition_codes') {
                modalCondition.codeSystem = '';
                modalCondition.codeCode = '';
            }
        }
    "
>
    <div class="space-y-4">
        <template x-for="(condition, index) in conditions" :key="index">
            <div class="record-inner-card">
                <div class="record-inner-header">
                    <div class="record-inner-checkbox-col">
                        <label :for="`conditionRecord${index}`" class="sr-only">{{ __('forms.select') }}</label>
                        <input
                            type="checkbox"
                            :id="`conditionRecord${index}`"
                            class="default-checkbox h-5 w-5"
                            disabled
                        />
                    </div>

                    <div class="record-inner-column flex-1">
                        <div class="record-inner-label">{{ __('medical-events.code_and_name') }}</div>
                        <div
                            class="record-inner-value text-[16px]"
                            x-text="
                                `${condition.codeCode} - ${condition.codeSystem === 'eHealth/ICD10_AM/condition_codes' ? icd10Descriptions[condition.codeCode] : conditionCodesDictionary[condition.codeCode]}`
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
                                        modalCondition = new Condition(condition);
                                        modalDiagnosis = new Diagnosis(diagnoses[index]);
                                        conditionMode = condition.isRegistered ? 'select' : 'create';
                                        registeredCondition = condition.isRegistered
                                            ? { ...condition, id: condition.uuid }
                                            : null;
                                        newCondition = false;
                                        showPrimaryChangeWarning = false;
                                        openConditionDrawer = true;
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
                                                item = index;
                                                modalCondition = new Condition(condition);
                                                modalDiagnosis = new Diagnosis(diagnoses[index]);
                                                conditionMode = condition.isRegistered ? 'select' : 'create';
                                                registeredCondition = condition.isRegistered
                                                    ? { ...condition, id: condition.uuid }
                                                    : null;
                                                newCondition = false;
                                                showPrimaryChangeWarning = false;
                                                openConditionDrawer = true;
                                                close($refs.button);
                                            "
                                        >
                                            {{ __('forms.edit') }}
                                        </button>

                                        <button
                                            class="dropdown-delete"
                                            @click.prevent="
                                                conditions.splice(index, 1);
                                                diagnoses.splice(index, 1);
                                                syncDiagnosisParticipants();
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
                        <div class="grid w-full grid-cols-2 gap-x-4 gap-y-4 xl:grid-cols-4">
                            <div>
                                <div class="record-inner-label">{{ __('conditions.role') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="diagnosisRolesDictionary[diagnoses[index]?.roleCode] || '-'"
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('conditions.clinical_status') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="conditionClinicalStatusesRolesDictionary[condition.clinicalStatus] || '-'"
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('conditions.verification_status') }}</div>
                                <div
                                    class="record-inner-subvalue"
                                    x-text="
                                        conditionVerificationStatusesDictionary[condition.verificationStatus] || '-'
                                    "
                                ></div>
                            </div>
                            <div>
                                <div class="record-inner-label">{{ __('conditions.asserter_text') }}</div>
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
        <button
            @click.prevent="
                    newCondition = true; {{-- We are adding a new condition --}}
                    modalCondition = new Condition(null, encounter); {{-- Replace the data of the previous condition with a new one--}}
                    modalDiagnosis = new Diagnosis();
                    conditionMode = 'create';
                    registeredCondition = null;
                    showPrimaryChangeWarning = false;
                    openConditionDrawer = true;
                "
            class="item-add my-5"
        >
            {{ __('forms.add') }}
        </button>

        <x-dialog-drawer x-model="openConditionDrawer" maxWidth="4/5" wire:ignore>
            <x-slot name="title">
                <span x-text="newCondition ? '{{ __('conditions.new_diagnose_state') }}' : '{{ __('conditions.edit_diagnose_state') }}'"></span>
            </x-slot>

            {{-- Content --}}
            <form class="space-y-6">
                <fieldset @disabled($isReadonly) @class(['pointer-events-none' => $isReadonly])>
                    <div class="mb-6 flex flex-wrap items-center gap-6" x-show="newCondition">
                        <div class="flex items-center gap-2">
                            <input
                                type="radio"
                                id="modeCreateCondition"
                                name="conditionMode"
                                value="create"
                                x-model="conditionMode"
                                class="default-radio cursor-pointer text-blue-600 focus:ring-blue-500"
                            />
                            <label
                                for="modeCreateCondition"
                                class="cursor-pointer text-sm font-medium text-gray-900 dark:text-gray-300"
                            >
                                {{ __('conditions.create_new_condition') }}
                            </label>
                        </div>
                        <div class="flex items-center gap-2">
                            <input
                                type="radio"
                                id="modeSelectCondition"
                                name="conditionMode"
                                value="select"
                                x-model="conditionMode"
                                class="default-radio cursor-pointer text-blue-600 focus:ring-blue-500"
                            />
                            <label
                                for="modeSelectCondition"
                                class="cursor-pointer text-sm font-medium text-gray-900 dark:text-gray-300"
                            >
                                {{ __('conditions.select_previously_registered_condition') }}
                            </label>
                        </div>
                    </div>
                    <div x-show="conditionMode === 'create'" class="space-y-6">
                        <div class="grid grid-cols-1 gap-x-8 gap-y-6 md:grid-cols-2">
                            <div>
                                <label
                                    for="codingSystem"
                                    class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                                >
                                    {{ __('conditions.coding_system') }}<span class="text-red-600"> *</span>
                                </label>
                                <div class="relative">
                                    <select
                                        x-model="modalCondition.codeSystem"
                                        @change="modalCondition.codeCode = ''"
                                        id="codingSystem"
                                        class="input-select w-full appearance-none bg-none"
                                        required
                                    >
                                        <option value="">
                                            {{ __('forms.select') }} {{ __('conditions.coding_system') }}*
                                        </option>
                                        <option
                                            value="eHealth/ICPC2/condition_codes"
                                            x-show="
                                                encounter.classCode === 'PHC' &&
                                                ($wire.allowedConditionCodesBySystem['eHealth/ICPC2/condition_codes']
                                                    ?.length ?? 1) > 0
                                            "
                                        >
                                            {{ __('conditions.icpc-2') }}
                                        </option>
                                        <option
                                            value="eHealth/ICD10_AM/condition_codes"
                                            x-show="
                                                ($wire.allowedConditionCodesBySystem['eHealth/ICD10_AM/condition_codes']
                                                    ?.length ?? 1) > 0
                                            "
                                        >
                                            {{ __('conditions.icd-10') }}
                                        </option>
                                    </select>
                                    @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 pointer-events-none')
                                </div>

                                {{-- ICPC-2 exists only in primary health care, so outside it the switch always
                                offers ICD-10 and hides once it is already the chosen system --}}
                                <button
                                    type="button"
                                    x-show="
                                        encounter.classCode === 'PHC' ||
                                        modalCondition.codeSystem !== 'eHealth/ICD10_AM/condition_codes'
                                    "
                                    @click="
                                        modalCondition.codeSystem =
                                            encounter.classCode === 'PHC' &&
                                            modalCondition.codeSystem !== 'eHealth/ICPC2/condition_codes'
                                                ? 'eHealth/ICPC2/condition_codes'
                                                : 'eHealth/ICD10_AM/condition_codes';
                                        modalCondition.codeCode = '';
                                    "
                                    class="mt-2.5 block text-left text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                >
                                    <span
                                        x-text="
                                        encounter.classCode === 'PHC' &&
                                        modalCondition.codeSystem !== 'eHealth/ICPC2/condition_codes'
                                            ? '{{ __('conditions.add_icpc2_code') }}'
                                            : '{{ __('conditions.add_icd10_code') }}'
                                    "
                                    ></span>
                                </button>
                            </div>

                            <div>
                                <div x-show="modalCondition.codeSystem === 'eHealth/ICPC2/condition_codes'">
                                    <label
                                        for="conditionReasonCode"
                                        class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                                    >
                                        {{ __('medical-events.icpc2_status_code') }}<span class="text-red-600"> *</span>
                                    </label>
                                    <div class="relative">
                                        <x-select2
                                            modelPath="modalCondition.codeCode"
                                            dictionaryName="eHealth/ICPC2/condition_codes"
                                            id="conditionReasonCode"
                                            class="input w-full"
                                        />
                                        @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 pointer-events-none')
                                    </div>
                                </div>

                                <div
                                    x-show="modalCondition.codeSystem === 'eHealth/ICD10_AM/condition_codes'"
                                    x-data="{
                                        selected: null,
                                        results: $wire.entangle('results'),
                                        showResults: false,
                                    }"
                                    class="relative"
                                >
                                    <label
                                        for="icd10Code"
                                        class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                                    >
                                        {{ __('conditions.icd-10') }}<span class="text-red-600"> *</span>
                                    </label>
                                    <input
                                        type="text"
                                        @input.debounce.300ms="
                                            let value = $event.target.value;
                                            modalCondition.codeCode = value;
                                            let isEnglish = /^[a-zA-Z0-9.]+$/.test(value);

                                            if ((isEnglish && value.length >= 1) || (! isEnglish && value.length >= 3)) {
                                                $wire.searchICD10(value);
                                                showResults = true;
                                            } else {
                                                showResults = false;
                                            }
                                        "
                                        @focus="if ((modalCondition.codeCode?.length ?? 0) >= 1) showResults = true;"
                                        @click.away="showResults = false"
                                        :value="modalCondition.codeCode && icd10Descriptions[modalCondition.codeCode]
                                            ? modalCondition.codeCode +
                                              ' - ' +
                                              icd10Descriptions[modalCondition.codeCode]
                                            : modalCondition.codeCode"
                                        id="icd10Code"
                                        class="input w-full"
                                        placeholder="{{ __('forms.select') }}"
                                        autocomplete="off"
                                    />

                                    <div
                                        x-show="showResults && results.length > 0"
                                        class="absolute top-full left-0 z-10 max-h-80 w-full overflow-auto overscroll-contain rounded-lg border border-gray-200 bg-white p-1.5 shadow-lg dark:bg-gray-800"
                                    >
                                        <ul>
                                            <template x-for="(result, index) in results" :key="index">
                                                <li
                                                    class="group flex w-full cursor-pointer items-center rounded-md px-2 py-1.5 transition-colors dark:bg-gray-800 dark:text-white"
                                                    @click="
                                                        selected = result;
                                                        modalCondition.codeCode = result.code;
                                                        icd10Descriptions[result.code] = result.description;
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

                                <div x-show="! modalCondition.codeSystem">
                                    <label
                                        for="conditionCodePlaceholder"
                                        class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                                    >
                                        {{ __('conditions.code') }}<span class="text-red-600"> *</span>
                                    </label>
                                    <div class="relative">
                                        <input
                                            type="text"
                                            id="conditionCodePlaceholder"
                                            disabled
                                            class="input w-full cursor-not-allowed opacity-50"
                                            placeholder="{{ __('conditions.choose_coding_system') }}"
                                        />
                                        @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 pointer-events-none')
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label
                                    for="diagnoseCode"
                                    class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                                >
                                    {{ __('conditions.role') }}<span class="text-red-600"> *</span>
                                </label>
                                <div class="relative">
                                    <select
                                        x-model="modalDiagnosis.roleCode"
                                        id="diagnoseCode"
                                        class="input-select w-full appearance-none bg-none"
                                        type="text"
                                        required
                                    >
                                        <option value="" selected>{{ __('forms.select') }}</option>
                                        @foreach ($this->dictionaries['eHealth/diagnosis_roles'] as $key => $diagnosisRole)
                                            <option value="{{ $key }}">{{ $diagnosisRole }}</option>
                                        @endforeach
                                    </select>
                                    @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 pointer-events-none')
                                </div>
                            </div>

                            <div>
                                <label
                                    for="verificationStatus"
                                    class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                                >
                                    {{ __('conditions.verification_status') }}<span class="text-red-600"> *</span>
                                </label>
                                <div class="relative">
                                    <select
                                        x-model="modalCondition.verificationStatus"
                                        id="verificationStatus"
                                        class="input-select w-full appearance-none bg-none"
                                        type="text"
                                        required
                                    >
                                        <option value="" selected>{{ __('forms.select') }}</option>
                                        @foreach ($this->dictionaries['eHealth/condition_verification_statuses'] as $key => $verificationStatus)
                                            @if ($key === ConditionVerificationStatus::ENTERED_IN_ERROR->value)
                                                {{-- A condition added to the encounter as a diagnosis has to stay
                                                 active, so the status stays out of reach unless the condition
                                                 already carries it. The option itself is always in the DOM, since
                                                 x-model reads it before any x-if of its own would run and would
                                                 otherwise fall back to an empty select --}}
                                                <option
                                                    value="{{ $key }}"
                                                    :disabled="modalCondition.verificationStatus !== '{{ $key }}'"
                                                >
                                                    {{ $verificationStatus }}
                                                </option>

                                                @continue
                                            @endif

                                            <option value="{{ $key }}">{{ $verificationStatus }}</option>
                                        @endforeach
                                    </select>
                                    @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 pointer-events-none')
                                </div>
                            </div>

                            <div>
                                <label
                                    for="clinicalStatus"
                                    class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                                >
                                    {{ __('conditions.clinical_status') }}<span class="text-red-600"> *</span>
                                </label>
                                <div class="relative">
                                    <select
                                        x-model="modalCondition.clinicalStatus"
                                        id="clinicalStatus"
                                        class="input-select w-full appearance-none bg-none"
                                        type="text"
                                        required
                                    >
                                        <option value="" selected>{{ __('forms.select') }}</option>
                                        @foreach ($this->dictionaries['eHealth/condition_clinical_statuses'] as $key => $clinicalStatus)
                                            <option value="{{ $key }}">{{ $clinicalStatus }}</option>
                                        @endforeach
                                    </select>
                                    @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 pointer-events-none')
                                </div>
                            </div>

                            <div></div>

                            <div>
                                <label
                                    for="onsetDate"
                                    class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                                >
                                    {{ __('conditions.onset_date') }}<span class="text-red-600"> *</span>
                                </label>
                                <div class="relative">
                                    <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center pl-1">
                                        @icon('calendar-week', 'w-4 h-4 text-gray-400')
                                    </div>
                                    <input
                                        x-model="modalCondition.onsetDate"
                                        :datepicker-max-date="encounter.periodDate"
                                        type="text"
                                        name="onsetDate"
                                        id="onsetDate"
                                        class="datepicker-input input w-full pl-7"
                                        autocomplete="off"
                                        required
                                    />
                                </div>
                            </div>

                            <div>
                                <label for="onsetTime" class="mb-1 block text-xs">
                                    &nbsp;<span class="sr-only">{{ __('forms.time') }}</span>
                                </label>
                                <div class="relative">
                                    <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center pl-1">
                                        @icon('mingcute-time-fill', 'w-4 h-4 text-gray-400')
                                    </div>
                                    <input
                                        x-model="modalCondition.onsetTime"
                                        type="text"
                                        name="onsetTime"
                                        id="onsetTime"
                                        class="timepicker-uk input w-full cursor-pointer pl-7"
                                        autocomplete="off"
                                        required
                                    />
                                </div>
                                <p class="text-error mt-1 text-xs" x-show="conditionOnsetAfterEncounterEnd()" x-cloak>
                                    {{ __('conditions.onset_after_encounter_end') }}
                                </p>
                            </div>

                            <div>
                                <label
                                    for="assertedDate"
                                    class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                                >
                                    {{ __('conditions.entry_date') }}<span class="text-red-600"> *</span>
                                </label>
                                <div class="relative">
                                    <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center pl-1">
                                        @icon('calendar-week', 'w-4 h-4 text-gray-400')
                                    </div>
                                    <input
                                        x-model="modalCondition.assertedDate"
                                        :datepicker-min-date="encounter.periodDate"
                                        :datepicker-max-date="encounter.periodDate"
                                        type="text"
                                        name="assertedDate"
                                        id="assertedDate"
                                        class="datepicker-input input w-full pl-7"
                                        autocomplete="off"
                                        required
                                    />
                                </div>
                            </div>

                            <div>
                                <label for="assertedTime" class="mb-1 block text-xs">
                                    &nbsp;<span class="sr-only">{{ __('forms.time') }}</span>
                                </label>
                                <div class="relative">
                                    <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center pl-1">
                                        @icon('mingcute-time-fill', 'w-4 h-4 text-gray-400')
                                    </div>
                                    <input
                                        x-model="modalCondition.assertedTime"
                                        type="text"
                                        name="assertedTime"
                                        id="assertedTime"
                                        class="timepicker-uk input w-full cursor-pointer pl-7"
                                        autocomplete="off"
                                        required
                                    />
                                </div>
                                <p
                                    class="text-error mt-1 text-xs"
                                    x-show="conditionAssertedOutsideEncounterPeriod()"
                                    x-cloak
                                >
                                    {{ __('conditions.asserted_outside_encounter_period') }}
                                </p>
                            </div>

                            <div class="col-span-1 space-y-4 md:col-span-2">
                                <template x-for="(bodySite, bsIndex) in modalCondition.bodySites" :key="bsIndex">
                                    <div class="grid grid-cols-1 items-end gap-x-8 gap-y-4 md:grid-cols-2">
                                        <div>
                                            <label
                                                :for="`conditionBodySite${bsIndex}`"
                                                class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                                                :class="{ 'sr-only': bsIndex > 0 }"
                                            >
                                                {{ __('conditions.body_sites') }}
                                            </label>
                                            <div class="relative">
                                                <select
                                                    x-model="bodySite.code"
                                                    :id="`conditionBodySite${bsIndex}`"
                                                    class="input-select w-full appearance-none bg-none"
                                                >
                                                    <option value="" selected>{{ __('forms.select') }}</option>
                                                    @foreach ($this->dictionaries['eHealth/body_sites'] as $key => $bodySiteName)
                                                        <option value="{{ $key }}">{{ $bodySiteName }}</option>
                                                    @endforeach
                                                </select>
                                                @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 pointer-events-none')
                                            </div>
                                        </div>
                                        <div class="flex h-10 items-center">
                                            <button
                                                type="button"
                                                @click="modalCondition.bodySites.splice(bsIndex, 1)"
                                                class="flex shrink-0 cursor-pointer items-center justify-center text-gray-500 transition-colors hover:text-red-600 dark:text-gray-400 dark:hover:text-red-500"
                                            >
                                                <svg
                                                    class="h-5 w-5"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    stroke-width="2"
                                                    viewBox="0 0 24 24"
                                                >
                                                    <path
                                                        stroke-linecap="round"
                                                        stroke-linejoin="round"
                                                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                                    />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </template>
                                <button
                                    type="button"
                                    @click="
                                        if (! modalCondition.bodySites) modalCondition.bodySites = [];
                                        modalCondition.bodySites.push({ code: '' });
                                    "
                                    class="mt-3 flex items-center gap-1.5 text-xs font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                >
                                    <span>{{ __('conditions.add_body_part') }}</span>
                                </button>
                            </div>

                            <div>
                                <label
                                    for="severityCondition"
                                    class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                                >
                                    {{ __('conditions.severity_of_the_condition') }}
                                </label>
                                <div class="relative">
                                    <select
                                        x-model="modalCondition.severityCode"
                                        id="severityCondition"
                                        class="input-select w-full appearance-none bg-none"
                                        type="text"
                                        required
                                    >
                                        <option value="" selected>{{ __('forms.select') }}</option>
                                        @foreach ($this->dictionaries['eHealth/condition_severities'] as $key => $conditionSeverity)
                                            <option value="{{ $key }}">{{ $conditionSeverity }}</option>
                                        @endforeach
                                    </select>
                                    @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 pointer-events-none')
                                </div>
                            </div>

                            <div>
                                <label
                                    for="rank"
                                    class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                                >
                                    {{ __('conditions.rank') }}
                                </label>
                                <div class="relative">
                                    <select
                                        x-model.number="modalDiagnosis.rank"
                                        id="rank"
                                        class="input-select w-full appearance-none bg-none"
                                        type="text"
                                        required
                                    >
                                        <option selected>{{ __('forms.select') }}</option>
                                        @for ($i = 1; $i <= 10; $i++)
                                            <option value="{{ $i }}">{{ $i }}</option>
                                        @endfor
                                    </select>
                                    @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 pointer-events-none')
                                </div>
                            </div>

                            <div>
                                <label
                                    for="stageCondition"
                                    class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                                >
                                    {{ __('conditions.stage') }}
                                </label>
                                <div class="relative">
                                    <select
                                        x-model="modalCondition.stageCode"
                                        id="stageCondition"
                                        class="input-select w-full appearance-none bg-none"
                                    >
                                        <option value="" selected>{{ __('forms.select') }}</option>
                                        @foreach ($this->dictionaries['eHealth/condition_stages'] as $key => $conditionStage)
                                            <option value="{{ $key }}">{{ $conditionStage }}</option>
                                        @endforeach
                                    </select>
                                    @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 pointer-events-none')
                                </div>
                            </div>
                        </div>
                    </div>
                </fieldset>

                <div x-show="conditionMode === 'create'" class="space-y-6">
                    <div class="mt-10 flex flex-col justify-between gap-6 lg:flex-row lg:items-center">
                        <div class="flex flex-wrap items-center gap-6">
                            <span class="text-sm font-bold text-gray-900 dark:text-white">
                                {{ __('conditions.primary_source') }}
                            </span>

                            <div class="flex items-center gap-2">
                                <input
                                    x-model.boolean="modalCondition.primarySource"
                                    @change="
                                        modalCondition.primarySource = true;
                                        modalCondition.asserterText = '';
                                    "
                                    id="conditionSourcePerformer"
                                    type="radio"
                                    value="true"
                                    name="primarySource"
                                    class="default-radio cursor-pointer text-blue-600 focus:ring-blue-500"
                                    :checked="modalCondition.primarySource === true"
                                />
                                <label
                                    for="conditionSourcePerformer"
                                    class="cursor-pointer text-sm font-medium text-gray-900 dark:text-gray-300"
                                >
                                    {{ __('medical-events.performer') }}
                                </label>
                            </div>

                            @unless (auth()->user()->isAssistantOnly())
                                <div class="flex items-center gap-2">
                                    <input
                                        x-model.boolean="modalCondition.primarySource"
                                        @change="
                                            modalCondition.primarySource = false;
                                            modalCondition.asserterText = '';
                                        "
                                        id="otherSource"
                                        type="radio"
                                        value="false"
                                        name="primarySource"
                                        class="default-radio cursor-pointer text-blue-600 focus:ring-blue-500"
                                        :checked="modalCondition.primarySource === false"
                                    />
                                    <label
                                        for="otherSource"
                                        class="cursor-pointer text-sm font-medium text-gray-900 dark:text-gray-300"
                                    >
                                        {{ __('medical-events.other_source') }}
                                    </label>
                                </div>
                            @endunless
                        </div>

                        <div class="max-w-md flex-1">
                            <label
                                for="conditionAsserterText"
                                class="sr-only"
                            >{{ __('conditions.asserter_name') }}</label>
                            <input
                                type="text"
                                id="conditionAsserterText"
                                x-model="modalCondition.asserterText"
                                :disabled="modalCondition.primarySource === true"
                                class="w-full rounded-lg border border-gray-200 bg-gray-50 p-2 px-3 text-sm text-gray-900 transition-colors focus:border-blue-500 focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100 disabled:opacity-50 dark:border-gray-600 dark:bg-gray-700 dark:text-white dark:disabled:bg-gray-800"
                                placeholder="{{ __('conditions.asserter_name') }}"
                            />
                        </div>
                    </div>

                    <div x-show="modalCondition.primarySource === false" class="mt-6 space-y-6 transition-all">
                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <label
                                    for="conditionReportOrigin"
                                    class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                                >
                                    {{ __('medical-events.information_source') }}
                                </label>
                                <div class="relative">
                                    <select
                                        x-model="modalCondition.reportOriginCode"
                                        id="conditionReportOrigin"
                                        class="input-select w-full appearance-none bg-none"
                                        required
                                    >
                                        <option selected value="">{{ __('forms.select') }}</option>
                                        @foreach ($this->dictionaries['eHealth/report_origins'] as $key => $reportOrigin)
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
                            <label
                                for="doctorComment"
                                class="mb-3 block text-sm font-bold text-gray-900 dark:text-white"
                            >
                                {{ __('conditions.asserter_text') }}
                            </label>
                            <textarea
                                rows="4"
                                x-model="modalCondition.asserterText"
                                id="doctorComment"
                                name="doctorComment"
                                class="textarea w-full rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm transition-colors focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                placeholder="{{ __('forms.write_comment_here') }}"
                            ></textarea>
                        </div>
                    </div>
                </div>

                <div x-show="conditionMode === 'select'" x-cloak class="space-y-6">
                    <fieldset @disabled($isReadonly) @class(['pointer-events-none' => $isReadonly])>
                        <fieldset class="rounded-lg border border-gray-200 p-6 dark:border-gray-700">
                            <legend class="px-2 text-sm font-bold text-gray-900 dark:text-white">
                                Пошук раніше зареєстрованого стану
                            </legend>

                            <div class="mt-2 flex flex-col gap-4">
                                <div class="index-table-wrapper">
                                    <table class="index-table">
                                        <thead class="index-table-thead">
                                            <tr>
                                                <th scope="col" class="index-table-th">ДАТА</th>
                                                <th scope="col" class="index-table-th">КОД ТА НАЗВА</th>
                                                <th scope="col" class="index-table-th">ЕПІЗОД</th>
                                                <th scope="col" class="index-table-th text-center">ДІЯ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-if="registeredCondition">
                                                <tr class="index-table-tr">
                                                    <td
                                                        class="index-table-td-primary"
                                                        x-text="registeredCondition.onsetDate"
                                                    ></td>
                                                    <td
                                                        class="index-table-td"
                                                        x-text="registeredConditionLabel(registeredCondition)"
                                                    ></td>
                                                    <td
                                                        class="index-table-td"
                                                        x-text="registeredCondition.episodeName"
                                                    ></td>
                                                    <td class="index-table-td-actions">
                                                        <button
                                                            type="button"
                                                            @click="registeredCondition = null"
                                                            class="cursor-pointer text-gray-500 hover:text-red-600"
                                                        >
                                                            @icon('trash', 'w-5 h-5')
                                                        </button>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>

                                <div>
                                    <button
                                        @click.prevent="openConditionSearchDrawer = true"
                                        type="button"
                                        class="flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                    >
                                        <span>+ Знайти стан</span>
                                    </button>
                                </div>
                            </div>
                        </fieldset>

                        <div class="mt-6 grid grid-cols-1 gap-x-8 gap-y-6 md:grid-cols-2">
                            <div>
                                <label
                                    for="diagnoseCodeSelect"
                                    class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                                >
                                    {{ __('conditions.role') }}<span class="text-red-600"> *</span>
                                </label>
                                <div class="relative">
                                    <select
                                        x-model="modalDiagnosis.roleCode"
                                        id="diagnoseCodeSelect"
                                        class="input-select w-full appearance-none bg-none"
                                        type="text"
                                    >
                                        <option value="" selected>{{ __('forms.select') }}</option>
                                        @foreach ($this->dictionaries['eHealth/diagnosis_roles'] as $key => $diagnosisRole)
                                            <option value="{{ $key }}">{{ $diagnosisRole }}</option>
                                        @endforeach
                                    </select>
                                    @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 pointer-events-none')
                                </div>
                            </div>

                            <div>
                                <label
                                    for="rankSelect"
                                    class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                                >
                                    {{ __('conditions.rank') }}
                                </label>
                                <div class="relative">
                                    <select
                                        x-model.number="modalDiagnosis.rank"
                                        id="rankSelect"
                                        class="input-select w-full appearance-none bg-none"
                                        type="text"
                                    >
                                        <option selected>{{ __('forms.select') }}</option>
                                        @for ($i = 1; $i <= 10; $i++)
                                            <option value="{{ $i }}">{{ $i }}</option>
                                        @endfor
                                    </select>
                                    @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 dark:text-gray-500 pointer-events-none')
                                </div>
                            </div>
                        </div>
                    </fieldset>
                </div>

                <p class="mt-6 text-sm text-gray-400 dark:text-gray-500">{{ __('forms.form_required_note') }}</p>

                <div class="mt-8 flex items-center justify-start gap-4">
                    <button
                        type="button"
                        @click="
                            showPrimaryWarning = false;
                            showDuplicateCodeWarning = false;
                            showPrimaryChangeWarning = false;
                            openEvidenceDrawer = false;
                            openConditionDrawer = false;
                        "
                        class="button-minor cursor-pointer"
                    >
                        {{ __('forms.close') }}
                    </button>

                    @unless ($isReadonly)
                        <button
                            @click.prevent="saveCondition()"
                            class="cursor-pointer rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-blue-700 focus:ring-4 focus:ring-blue-100 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="conditionMode === 'select'
                                ? ! (registeredCondition && modalDiagnosis.roleCode)
                                : ! (
                                      modalCondition.clinicalStatus.trim() &&
                                      modalCondition.verificationStatus.trim() &&
                                      modalCondition.codeCode.trim() &&
                                      modalDiagnosis.roleCode &&
                                      modalCondition.onsetDate?.trim() &&
                                      modalCondition.onsetTime?.trim() &&
                                      modalCondition.assertedDate?.trim() &&
                                      modalCondition.assertedTime?.trim() &&
                                      conditionDatesAreValid()
                                  )"
                        >
                            <span x-text="saveConditionLabel()"></span>
                        </button>
                    @endunless
                </div>
                <div class="mt-2 text-left">
                    <template x-if="showPrimaryWarning">
                        <p class="text-error">{{ __('conditions.validation.single_primary_diagnosis') }}</p>
                    </template>
                    <template x-if="showPrimaryChangeWarning">
                        <p class="text-error">{!! __('conditions.new_primary_diagnose') !!}</p>
                    </template>
                    <template x-if="showDuplicateCodeWarning">
                        <p
                            class="text-error"
                            x-text="'{{ __('conditions.validation.diagnosis_code_in_several_roles') }}'.replace(':code', conditionToSave().codeCode)"
                        ></p>
                    </template>
                </div>
            </form>
        </x-dialog-drawer>
    </div>

    <div
        x-cloak
        x-show="showPrimaryChangeWarning"
        class="fixed inset-0 z-[60] overflow-y-auto px-4 py-6 sm:px-0"
        style="display: none"
    >
        <div class="fixed inset-0 flex items-center justify-center">
            <div
                x-show="showPrimaryChangeWarning"
                class="fixed inset-0 bg-gray-500/75 transition-opacity dark:bg-gray-900/80"
                x-on:click="showPrimaryChangeWarning = false"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
            ></div>

            <div
                x-show="showPrimaryChangeWarning"
                x-on:click.stop
                class="relative z-10 w-full transform overflow-auto rounded-lg bg-white shadow-xl transition-all sm:max-w-2xl dark:bg-gray-800"
                x-trap.inert.noscroll="showPrimaryChangeWarning"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            >
                <div class="px-8 py-8">
                    <p class="mb-6 text-lg font-bold text-gray-900 dark:text-gray-100">
                        {!! __('conditions.new_primary_diagnose') !!}
                    </p>

                    <div class="mb-2">
                        <label
                            for="modalEpisodeName"
                            class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400"
                        >
                            {{ __('encounters.episode_name') }}
                        </label>
                        <div class="form-group group">
                            <input type="text" id="modalEpisodeName" x-model="episodeName" class="input peer w-full" />
                        </div>
                    </div>
                </div>

                <div class="flex justify-start space-x-4 px-8 pb-8">
                    <button type="button" class="button-minor" @click="showPrimaryChangeWarning = false">
                        {{ __('forms.cancel') }}
                    </button>
                    <button type="button" class="button-primary" @click="saveCondition(true)">
                        {{ __('forms.save') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Condition Search Drawer --}}
    <x-dialog-drawer
        x-model="openConditionSearchDrawer"
        maxWidth="3/5"
        overlayWidth="100%"
        stopClickPropagation="true"
        wire:ignore
    >
        <x-slot name="title">{{ __('encounters.search_medical_records') }}</x-slot>

        <livewire:encounter.medical-record-search
            :patient-uuid="$patientUuid"
            selection-event="registered-condition-selected"
            is-added-check="isRegisteredConditionSelected"
            :episodes="$episodes"
            fixed-record-type="condition"
            :with-condition-filters="true"
            :key="'registered-condition-search'"
        />

        <div class="mt-6 flex justify-start space-x-2">
            <button type="button" @click="openConditionSearchDrawer = false" class="button-minor">
                {{ __('forms.cancel') }}
            </button>
        </div>
    </x-dialog-drawer>

    <x-dialog-drawer
        x-model="openEvidenceDrawer"
        maxWidth="3/5"
        overlayWidth="100%"
        zIndex="45"
        stopClickPropagation="true"
        wire:ignore
    >
        <x-slot name="title">{{ __('encounters.search_medical_records') }}</x-slot>

        <livewire:encounter.medical-record-search
            :patient-uuid="$patientUuid"
            selection-event="condition-evidence-selected"
            is-added-check="isEvidenceAdded"
            :episodes="$episodes"
            :key="'condition-evidence-search'"
        />

        <div class="mt-6 flex justify-between space-x-2">
            <button type="button" @click="openEvidenceDrawer = false" class="button-minor">
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
        constructor(obj = null, encounter = null) {
            const now = new Date();
            const dd = String(now.getDate()).padStart(2, '0');
            const mm = String(now.getMonth() + 1).padStart(2, '0');
            const formattedDate = `${dd}.${mm}.${now.getFullYear()}`;
            const formattedTime = now.toLocaleTimeString('uk-UA', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: false,
            });
            const defaultDate = encounter?.periodDate || formattedDate;
            const defaultTime = encounter?.periodStart || formattedTime;

            this.uuid = obj?.uuid || crypto.randomUUID();
            this.primarySource = true;
            this.codeSystem = '';
            this.codeCode = '';
            this.clinicalStatus = '';
            this.verificationStatus = '';
            this.onsetDate = defaultDate;
            this.onsetTime = defaultTime;
            this.assertedDate = defaultDate;
            this.assertedTime = defaultTime;
            this.severityCode = '';
            this.stageCode = '';
            this.asserterText = '';
            this.reportOriginCode = '';
            this.evidenceCodes = [];
            this.evidenceDetails = [];
            this.bodySites = [];

            if (obj) {
                Object.assign(this, JSON.parse(JSON.stringify(obj)));
            }

            // An empty body site row keeps the field in sight, the mapper drops rows without a code
            if (!this.bodySites?.length) {
                this.bodySites = [{ code: '' }];
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
