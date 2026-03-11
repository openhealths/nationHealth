<div x-data="{
        programs: @js($activePrograms),
        roleLabels: @js(__('users.role')),
        dictionaries: @js($dictionaries),
        selectedProgramId: '',
        get selectedProgram() {
            return this.programs.find(program => program.id === this.selectedProgramId) || null;
        },
        translateRoles(roles) {
            return roles?.map(role => this.roleLabels[role] || role).join(', ') || '-';
        },
        translateSpecialities(specialities) {
            return specialities?.map(speciality => this.dictionaries.SPECIALITY_TYPE[speciality] || speciality).join(', ') || '-';
        },
        translatePatientCategories(categories) {
            return categories?.map(category => this.dictionaries['eHealth/clinical_impression_patient_categories'][category] || category).join(', ') || '-';
        }
    }"
>
    <x-header-navigation x-data="{ showFilter: false }" class="breadcrumb-form">
        <x-slot name="title">{{ __('dictionaries.medication_programs.title') }}</x-slot>

        <x-slot name="navigation">
            <div class="flex flex-col gap-4">
                <div class="flex items-center gap-1 font-semibold text-gray-900 dark:text-white">
                    @icon('search-outline', 'w-4.5 h-4.5')
                    <p>{{ __('dictionaries.search_title') }}</p>
                </div>

                <div class="form-row-3">
                    <div class="form-group group w-full">
                        <select id="program"
                                name="program"
                                class="peer input-select"
                                x-model="selectedProgramId"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            <template x-for="program in programs" :key="program.id">
                                <option :value="program.id" x-text="program.name"></option>
                            </template>
                        </select>

                        <label for="program" class="label peer-focus:text-blue-600 peer-valid:text-blue-600">
                            {{ __('dictionaries.program_label') }}
                        </label>
                    </div>

                    <div class="form-group group w-full">
                        <select id="prescriptionWithoutDeclaration"
                                name="prescriptionWithoutDeclaration"
                                class="peer input-select w-full"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            <option value="1">{{ __('forms.yes') }}</option>
                            <option value="0">{{ __('forms.no') }}</option>
                        </select>

                        <label for="prescriptionWithoutDeclaration" class="label peer-focus:text-blue-600 peer-valid:text-blue-600">
                            {{ __('dictionaries.medication_programs.prescription_without_declaration') }}
                        </label>
                    </div>
                </div>
            </div>
        </x-slot>
    </x-header-navigation>

    <section class="shift-content pl-3.5 mt-6 max-w-[1280px]">
        <template x-if="selectedProgram">
            <fieldset class="fieldset p-6 sm:p-8">
                <legend class="legend">
                    {{ __('dictionaries.medication_programs.prescription_medication') }}
                </legend>

                <div class="space-y-2 text-gray-900 dark:text-gray-100">
                    <p>
                        <span class="font-semibold">{{ __('dictionaries.medication_programs.funding_source') }}:</span>
                        <span x-text="dictionaries.FUNDING_SOURCE[selectedProgram.funding_source]"></span>
                    </p>
                    <p>{{ __('dictionaries.mr_blank_type') }}:
                    <p>
                        <span class="font-semibold">{{ __('dictionaries.medication_programs.mr_blank_type') }}:</span>
                    <p>{{ __('dictionaries.mr_blank_type') }}:
                        <span x-text="selectedProgram.mr_blank_type"></span>
                    </p>
                    <p>
                        <span class="font-semibold">{{ __('dictionaries.medication_programs.care_plan_required') }}:</span>
                        <span x-text="selectedProgram.medical_program_settings.care_plan_required ? '{{ __('forms.yes') }}' : '{{ __('forms.no') }}'"></span>
                    </p>
                    <p>
                        <span class="font-semibold">{{ __('dictionaries.medication_programs.employee_types_to_create_request') }}:</span>
                        <span x-text="translateRoles(selectedProgram.medical_program_settings.employee_types_to_create_request)"></span>
                    </p>
                    <p>
                        <span class="font-semibold">{{ __('dictionaries.medication_programs.speciality_types_allowed') }}:</span>
                        <span x-text="translateSpecialities(selectedProgram.medical_program_settings.speciality_types_allowed)"></span>
                    </p>
                    <p>
                        <span class="font-semibold">{{ __('dictionaries.medication_programs.skip_treatment_period') }}:</span>
                        <span x-text="selectedProgram.medical_program_settings.skip_treatment_period ? '{{ __('forms.yes') }}' : '{{ __('forms.no') }}'"></span>
                    </p>
                    <p>
                        <span class="font-semibold">{{ __('dictionaries.medication_programs.request_max_period_day') }}:</span>
                        <span x-text="selectedProgram.medical_program_settings.request_max_period_day"></span>
                    </p>
                    <p>
                        <span class="font-semibold">{{ __('dictionaries.medication_programs.skip_request_employee_declaration_verify') }}:</span>
                        <span x-text="selectedProgram.medical_program_settings.skip_request_employee_declaration_verify ? '{{ __('forms.yes') }}' : '{{ __('forms.no') }}'"></span>
                    </p>
                    <p>
                        <span class="font-semibold">{{ __('dictionaries.medication_programs.skip_request_legal_entity_declaration_verify') }}:</span>
                        <span x-text="selectedProgram.medical_program_settings.skip_request_legal_entity_declaration_verify ? '{{ __('forms.yes') }}' : '{{ __('forms.no') }}'"></span>
                    </p>
                    <p>
                        <span class="font-semibold">{{ __('dictionaries.medication_programs.multi_medication_dispense_allowed') }}:</span>
                        <span x-text="selectedProgram.medical_program_settings.multi_medication_dispense_allowed ? '{{ __('forms.yes') }}' : '{{ __('forms.no') }}'"></span>
                    </p>
                    <p>
                        <span class="font-semibold">{{ __('dictionaries.medication_programs.request_notification_disabled') }}:</span>
                        <span x-text="selectedProgram.medical_program_settings.request_notification_disabled ? '{{ __('forms.yes') }}' : '{{ __('forms.no') }}'"></span>
                    </p>
                    <p>
                        <span class="font-semibold">{{ __('dictionaries.medication_programs.patient_categories_allowed') }}:</span>
                        <span x-text="translatePatientCategories(selectedProgram.medical_program_settings.patient_categories_allowed)"></span>
                    </p>
                </div>
            </fieldset>
        </template>
    </section>

    <x-forms.loading />
    <livewire:components.x-message :key="time()" />
</div>
