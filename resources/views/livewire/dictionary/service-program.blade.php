<div x-data="{
        programs: @js($activePrograms),
        selectedProgramId: '',
        get selectedProgram() {
            return this.programs.find(program => program.id === this.selectedProgramId) || null;
        }
    }"
>
    <x-header-navigation x-data="{ showFilter: false }" class="breadcrumb-form">
        <x-slot name="title">
            {{ __('dictionaries.service_programs.title') }}
        </x-slot>

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
                </div>
            </div>
        </x-slot>
    </x-header-navigation>

    <section class="shift-content pl-3.5 mt-6 max-w-[1280px]">
        <template x-if="selectedProgram">
            <fieldset class="fieldset p-6 sm:p-8">
                <legend class="legend">
                    {{ __('dictionaries.service_programs.medical_guarantees') }}
                </legend>

                <div class="space-y-2 text-gray-900 dark:text-gray-100">
                    <p>{{ __('dictionaries.service_programs.care_plan_required') }}:
                        <span x-text="selectedProgram.medical_program_settings.care_plan_required ? '{{ __('forms.yes') }}' : '{{ __('forms.no') }}'"></span>
                    </p>
                </div>
            </fieldset>
        </template>
    </section>

    <x-forms.loading />
    <livewire:components.x-message :key="time()" />
</div>
