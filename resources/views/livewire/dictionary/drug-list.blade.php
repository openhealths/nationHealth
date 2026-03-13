<div>
    <x-header-navigation class="breadcrumb-form">
        <x-slot name="title">
            {{ __('dictionaries.drug_list.title') }}
        </x-slot>

        <x-slot name="navigation">
            <div class="flex flex-col gap-4" x-data="{ showFilter: false }">
                <div class="flex flex-col gap-4 max-w-sm">
                    <div class="form-group group">
                        <label for="programSelect" class="default-label mb-2">
                            {{ __('dictionaries.program_label') }}
                        </label>
                        <select wire:model="selectedProgram"
                                id="programSelect"
                                class="input-select @error('selectedProgram') input-error @enderror"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            @foreach($programs as $program)
                                <option value="{{ $program['id'] }}">{{ $program['name'] }}</option>
                            @endforeach
                        </select>

                        @error('selectedProgram')<p class="text-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="form-group group">
                        <label for="drugSearch" class="default-label mb-2">
                            {{ __('dictionaries.drug_list.search') }}
                        </label>

                        <div class="relative">
                            <div class="absolute inset-y-0 start-0 flex items-center ps-3 pointer-events-none">
                                @icon('search-outline', 'w-4 h-4 text-gray-500 dark:text-gray-400')
                            </div>
                            <input type="text"
                                   id="drugSearch"
                                   class="input w-full ps-9"
                                   placeholder="{{ __('dictionaries.drug_list.name') }}"
                                   wire:model="innmDosageName"
                                   autocomplete="off"
                            />
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="button"
                            wire:click="search"
                            class="button-primary flex items-center gap-2"
                    >
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('forms.search') }}</span>
                    </button>

                    <button type="button"
                            wire:click="resetFilters"
                            class="button-primary-outline-red"
                    >
                        {{ __('forms.reset_all_filters') }}
                    </button>

                    <button type="button"
                            class="button-minor flex items-center gap-2"
                            @click="showFilter = !showFilter"
                    >
                        @icon('adjustments', 'w-4 h-4')
                        <span>{{ __('forms.additional_search_parameters') }}</span>
                    </button>
                </div>

                {{-- Additional filters --}}
                <div x-cloak x-show="showFilter" x-transition class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group group">
                        <input wire:model="innmName"
                               type="text"
                               id="innmName"
                               class="input peer"
                               placeholder=" "
                               autocomplete="off"
                        />
                        <label for="innmName" class="label peer-focus:text-blue-600 peer-valid:text-blue-600">
                            {{ __('dictionaries.drug_list.inn_name') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="innmDosageForm"
                                id="innmDosageForm"
                                class="peer input-select w-full"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            @foreach($this->dictionaries['MEDICATION_FORM'] as $key => $value)
                                <option value="{{ $key }}">{{ $value }}</option>
                            @endforeach
                        </select>
                        <label for="innmDosageForm" class="label peer-focus:text-blue-600 peer-valid:text-blue-600">
                            {{ __('dictionaries.drug_list.dosage_form') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="medicationCodeAtc"
                                id="medicationCodeAtc"
                                class="peer input-select w-full"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            @foreach(config('ehealth.medications_atc_code') as $code)
                                <option value="{{ $code }}">{{ $code }}</option>
                            @endforeach
                        </select>
                        <label for="medicationCodeAtc" class="label peer-focus:text-blue-600 peer-valid:text-blue-600">
                            {{ __('dictionaries.drug_list.medication_code_atc') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="mrBlankType"
                                id="mrBlankType"
                                class="peer input-select w-full"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            @foreach($this->dictionaries['MR_BLANK_TYPES'] as $key => $value)
                                <option value="{{ $key }}">{{ $value }}</option>
                            @endforeach
                        </select>
                        <label for="mrBlankType"
                               class="label peer-focus:text-blue-600 peer-valid:text-blue-600"
                        >
                            {{ __('dictionaries.mr_blank_type') }}
                        </label>
                    </div>
                </div>
            </div>
        </x-slot>
    </x-header-navigation>

    @if($drugs->isNotEmpty())
        <section class="shift-content pl-3.5 mt-6 max-w-[1280px]">
            @foreach($drugs as $drug)
                <fieldset class="fieldset p-6 sm:p-8">
                    <legend class="legend">{{ mb_ucfirst($drug['name']) }}</legend>

                    <div class="space-y-2 text-gray-900 dark:text-gray-100">
                        <p class="font-semibold">{{ __('dictionaries.mr_blank_type') }}:
                            <span>{{ $drug['mr_blank_type'] }}</span></p>
                        <p>{{ __('dictionaries.drug_list.dosage_form_is_dosed') }}:
                            <span>{{ $drug['dosage_form_is_dosed'] ? __('forms.yes') : __('forms.no') }}</span>
                        </p>

                        <p class="font-semibold">{{ __('dictionaries.drug_list.ingredient.label') }}:</p>
                        @foreach($drug['ingredients'] as $ingredient)
                            <p class="font-semibold">{{ __('dictionaries.drug_list.ingredient.name') }}:
                                <span>{{ $ingredient['name'] }}</span>
                            </p>
                            <p class="font-semibold">{{ __('dictionaries.drug_list.ingredient.is_primary') }}:
                                <span>{{ $ingredient['is_primary'] ? __('forms.yes') : __('forms.no') }}</span>
                            </p>
                            <p class="font-semibold">{{ __('dictionaries.drug_list.ingredient.dosage.numerator_value') }}:
                                <span>{{ $ingredient['dosage']['numerator_value'] }}</span>
                            </p>
                            <p class="font-semibold">{{ __('dictionaries.drug_list.ingredient.dosage.numerator_unit') }}:
                                <span>{{ $this->getMedicationUnit($ingredient['dosage']['numerator_unit']) }}</span>
                            </p>
                            <p class="font-semibold">{{ __('dictionaries.drug_list.ingredient.dosage.denumerator_value') }}:
                                <span>{{ $ingredient['dosage']['denumerator_value'] }}</span>
                            </p>
                            <p class="font-semibold">{{ __('dictionaries.drug_list.ingredient.dosage.denumerator_unit') }}:
                                <span>{{ $this->getMedicationUnit($ingredient['dosage']['denumerator_unit']) }}</span>
                            </p>
                        @endforeach

                        @if($drug['daily_dosage'])
                            @php
                                $unit = $drug['ingredients'][0]['dosage']['denumerator_unit'] ?? null;
                                $unitLabel = $unit ? $this->getMedicationUnit($unit) : '';

                                $maxDailyDosage = collect($drug['packages'])
                                    ->flatMap(static fn(array $package) => $package['program_medications'])
                                    ->whereNotNull('max_daily_dosage')
                                    ->value('max_daily_dosage');
                            @endphp

                            <p class="font-semibold">{{ __('dictionaries.drug_list.daily_dosage') }}:
                                <span>{{ $drug['daily_dosage'] }} {{ $unitLabel }}</span>
                            </p>

                            @if($maxDailyDosage)
                                <p class="font-semibold">{{ __('dictionaries.drug_list.max_daily_dosage') }}:
                                    <span>{{ $maxDailyDosage }} {{ $unitLabel }}</span>
                                </p>
                            @endif
                        @endif

                        @if($drug['packages'])
                            <p class="font-semibold">{{ __('dictionaries.drug_list.package.label') }}:</p>
                            @foreach($drug['packages'] as $package)
                                <p class="font-semibold">{{ __('dictionaries.drug_list.package.container_quantity') }}:
                                    <span>{{ $package['container_dosage']['numerator_value'] }}</span>
                                </p>
                                <p>{{ __('dictionaries.drug_list.package.container_quantity_unit') }}:
                                    <span>{{ $this->getMedicationUnit($package['container_dosage']['numerator_unit']) }}</span>
                                </p>
                                <p>{{ __('dictionaries.drug_list.package.primary_packages_count') }}:
                                    <span>{{ $package['container_dosage']['denumerator_value'] }}</span>
                                </p>
                                <p>{{ __('dictionaries.drug_list.package.primary_package_unit') }}:
                                    <span>{{ $this->getMedicationUnit($package['container_dosage']['denumerator_unit']) }}</span>
                                </p>
                                <p>{{ __('dictionaries.drug_list.package.min_sale_quantity') }}:
                                    <span>{{ $package['package_min_qty'] }} {{ $this->getMedicationUnit($package['container_dosage']['numerator_unit']) }}</span>
                                </p>
                                <p>{{ __('dictionaries.drug_list.package.package_quantity') }}:
                                    <span>{{ $package['package_qty'] }} {{ $this->getMedicationUnit($package['container_dosage']['numerator_unit']) }}</span>
                                </p>
                                <p>{{ __('dictionaries.drug_list.package.max_request_quantity') }}:
                                    <span>{{ $package['max_request_dosage'] }} {{ $this->getMedicationUnit($package['container_dosage']['numerator_unit']) }}</span>
                                </p>
                            @endforeach
                        @endif
                    </div>
                </fieldset>
            @endforeach

            <div class="mt-8 pl-3.5 pb-8 lg:pl-8 2xl:pl-5">
                {{ $drugs->links() }}
            </div>
        </section>
    @endif

    <x-forms.loading />
    <livewire:components.x-message :key="time()" />
</div>
