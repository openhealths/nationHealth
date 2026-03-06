<div>
    <x-header-navigation x-data="{ showFilter: false }" class="breadcrumb-form">
        <x-slot name="title">
            {{ __('dictionaries.medication_programs.title') }}
        </x-slot>

        <x-slot name="navigation">
            <div class="flex flex-col gap-4">
                <div class="flex items-center gap-1 font-semibold text-gray-900 dark:text-white">
                    @icon('search-outline', 'w-4.5 h-4.5')
                    <p>{{ __('dictionaries.search_title') }}</p>
                </div>

                <div class="form-row-3">
                    <div class="form-group group w-full">
                        <select
                            id="program"
                            name="program"
                            class="peer input-select"
                        >
                            <option value="" selected>{{ __('dictionaries.medication_programs.program_option_prescription_medication') }}</option>
                        </select>
                        <label for="program" class="label peer-focus:text-blue-600 peer-valid:text-blue-600">
                            {{ __('dictionaries.program_label') }}
                        </label>
                    </div>
                </div>
            </div>
        </x-slot>
    </x-header-navigation>

    <section class="shift-content pl-3.5 mt-6 max-w-[1280px]">
        <fieldset class="fieldset p-6 sm:p-8">
            <legend class="legend">
                {{ __('dictionaries.medication_programs.prescription_medication') }}
            </legend>

            <div class="space-y-2 text-gray-900 dark:text-gray-100">
                <p>{{ __('dictionaries.medication_programs.funding_source') }}</p>
                <p>{{ __('dictionaries.medication_programs.prescription_form_type') }}</p>
                <p>{{ __('dictionaries.medication_programs.treatment_plan_required') }}</p>
                <p>{{ __('dictionaries.medication_programs.allowed_user_types') }}</p>
                <p>{{ __('dictionaries.medication_programs.allowed_specialties') }}</p>
                <p>{{ __('dictionaries.medication_programs.same_inn_course') }}</p>
                <p>{{ __('dictionaries.medication_programs.max_course_duration') }}</p>
                <p>{{ __('dictionaries.medication_programs.no_declaration_required_patient') }}</p>
                <p>{{ __('dictionaries.medication_programs.no_declaration_required_facility') }}</p>
                <p>{{ __('dictionaries.medication_programs.partial_redemption') }}</p>
                <p>{{ __('dictionaries.medication_programs.patient_notifications_off') }}</p>
                <p>{{ __('dictionaries.medication_programs.allowed_patient_categories') }}</p>
            </div>
        </fieldset>

        <div class="mt-8 pl-3.5 pb-8 lg:pl-8 2xl:pl-5">
            {{--{{ $dictionary->links() }}--}}
        </div>
    </section>

    <x-forms.loading />
    <livewire:components.x-message :key="time()" />
</div>
