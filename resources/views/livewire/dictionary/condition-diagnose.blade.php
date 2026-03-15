<div>
    <x-header-navigation x-data="{ showFilter: false }" class="breadcrumb-form">
        <x-slot name="title">
            {{ __('dictionaries.condition_diagnose.title') }}
        </x-slot>

        <x-slot name="navigation">
            <div class="flex flex-col gap-4 max-w-sm">
                <div class="flex items-center gap-1 font-semibold text-gray-900 dark:text-white">
                    @icon('search-outline', 'w-4.5 h-4.5')
                    <p>{{ __('dictionaries.condition_diagnose.search_title') }}</p>
                </div>

                <div class="form-group group w-full">
                    <label for="conditionDiagnoseGroup" class="default-label mb-2">
                        {{ __('dictionaries.condition_diagnose.group_label') }}
                    </label>

                    <select id="conditionDiagnoseGroup"
                            name="conditionDiagnoseGroup"
                            class="peer input-select w-full"
                            wire:model="selectedDiagnoseGroup"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach($diagnoseGroups as $diagnoseGroup)
                            <option value="{{ $diagnoseGroup['id'] }}">
                                {{ $diagnoseGroup['code'] }} - {{ $diagnoseGroup['name'] }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="search" class="button-primary flex items-center gap-2">
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('forms.search') }}</span>
                    </button>
                    <button type="button" wire:click="resetFilters" class="button-primary-outline-red">
                        {{ __('forms.reset_all_filters') }}
                    </button>
                </div>
            </div>
        </x-slot>
    </x-header-navigation>

    @nonempty($diagnoseDetails)
    <section class="shift-content pl-3.5 mt-6 max-w-[1280px]">
        <fieldset class="fieldset p-6 sm:p-8">
            <legend class="legend">
                {{ $diagnoseDetails['code'] }} - {{ $diagnoseDetails['name'] }}
            </legend>

            <div class="space-y-2 text-gray-900 dark:text-gray-100">
                @foreach($diagnoseDetails['diagnoses_group_codes'] as $code)
                    <p class="text-base">
                        <span class="font-semibold">{{ $code['code'] }}</span>
                        @if(!empty($code['description']))
                            <span> - {{ $code['description'] }}</span>
                        @endif
                    </p>
                @endforeach
            </div>
        </fieldset>
    </section>
    @endnonempty

    <x-forms.loading />
    <livewire:components.x-message :key="time()" />
</div>
