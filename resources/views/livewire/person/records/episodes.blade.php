@php
    use App\Models\MedicalEvents\Sql\Encounter;
    use App\Enums\Person\EpisodeStatus;
    use App\Models\MedicalEvents\Sql\Episode;
@endphp

<x-layouts.patient :personId="$personId" :prepersonId="$prepersonId" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', Encounter::class)
            <a href="{{ $prepersonId
                ? route('prepersons.encounter.create', [legalEntity(), 'preperson' => $prepersonId])
                : route('encounter.create', [legalEntity(), 'person' => $personId]) }}"
               class="flex items-center gap-2 button-primary px-5 py-2 text-sm shadow-sm"
            >
                @icon('plus', 'w-4 h-4')
                {{ __('patients.start_interacting') }}
            </a>
        @endcan

        <button type="button"
                class="button-primary-outline whitespace-nowrap px-5 py-2 text-sm"
        >
            {{ __('patients.data_access') }}
        </button>

        @can('view', Episode::class)
            <button wire:click.prevent="sync"
                    type="button"
                    class="button-sync flex items-center gap-2 whitespace-nowrap px-5 py-2 text-sm shadow-sm"
            >
                @icon('refresh', 'w-4 h-4')
                {{ __('patients.sync_ehealth_data') }}
            </button>
        @endcan
    </x-slot>

    <div class="breadcrumb-form p-4 shift-content">
        <div class="w-full mt-6" x-data="{ showAdditionalParams: $wire.entangle('showAdditionalParams') }">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('patients.search_episode') }}</p>
            </div>

            <div class="form-row-3 mb-6"
                 x-data="{
                     dictionary: '',
                     filterCode: $wire.entangle('filterCode'),
                     icd10Results: $wire.entangle('icd10Results'),
                     showIcd10Results: false
                 }"
            >
                <div class="form-group group">
                    <select x-model="dictionary"
                            @change="filterCode = ''"
                            class="input-select peer w-full mb-1 text-sm"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        <option value="icd10">ICD-10-AM</option>
                        <option value="icpc2">ICPC-2</option>
                    </select>
                    <label class="label">{{ __('forms.type') }}</label>
                </div>

                <div class="form-group group" x-show="dictionary">
                    <div x-show="dictionary === 'icd10'" class="relative">
                        <input type="text"
                               :value="filterCode"
                               @input.debounce.300ms="
                                   filterCode = $event.target.value;
                                   let value = $event.target.value;
                                   let isEnglish = /^[a-zA-Z0-9.]+$/.test(value);
                                   if ((isEnglish && value.length >= 1) || (!isEnglish && value.length >= 3)) {
                                       $wire.searchICD10(value);
                                       showIcd10Results = true;
                                   }
                               "
                               @click.away="showIcd10Results = false"
                               id="filterCodeIcd10"
                               class="input-select peer w-full"
                               placeholder="{{ __('forms.type_to_search') }}"
                               autocomplete="off"
                        />
                        <div x-show="showIcd10Results && icd10Results.length > 0"
                             class="absolute left-0 top-full z-10 max-h-60 w-full overflow-auto rounded-lg border bg-white dark:bg-gray-800 p-1.5 shadow-lg"
                        >
                            <template x-for="result in icd10Results" :key="result.code">
                                <div @click="filterCode = result.code; showIcd10Results = false"
                                     class="cursor-pointer rounded-md px-2 py-1.5 text-sm dark:text-white hover:bg-gray-100 dark:hover:bg-gray-700"
                                     x-text="result.code + ' - ' + result.description"
                                ></div>
                            </template>
                        </div>
                    </div>
                    <div x-show="dictionary === 'icpc2'">
                        <x-select2 modelPath="filterCode"
                                   dictionaryName="eHealth/ICPC2/condition_codes"
                                   id="filterCodeIcpc2"
                                   class="input-select peer w-full"
                        />
                    </div>
                    <label class="label">{{ __('forms.code') }}</label>
                </div>

                <div class="form-group group">
                    <select wire:model="filterStatus"
                            name="filterStatus"
                            id="filterStatus"
                            class="input-select peer w-full"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach(EpisodeStatus::searchableOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <label for="filterStatus" class="label">
                        {{ __('forms.status.label') }}
                    </label>
                </div>
            </div>

            <div class="mb-9 flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap gap-2">
                    <button type="button"
                            wire:click="search"
                            class="flex items-center gap-2 button-primary px-5 py-2.5 text-sm shadow-sm"
                    >
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('patients.search') }}</span>
                    </button>
                    <button type="button"
                            wire:click="resetFilters"
                            class="button-primary-outline-red px-5 py-2.5 text-sm"
                    >
                        {{ __('patients.reset_filters') }}
                    </button>
                    <button type="button"
                            class="flex items-center gap-2 button-minor px-5 py-2.5 text-sm whitespace-nowrap"
                            @click.prevent="showAdditionalParams = !showAdditionalParams"
                    >
                        @icon('adjustments', 'w-4 h-4 text-gray-500')
                        <span>{{ __('patients.additional_params') }}</span>
                    </button>
                </div>

                <div class="relative" x-data="{ openGroupActions: false }" @click.outside="openGroupActions = false">
                    <button type="button"
                            @click="openGroupActions = !openGroupActions"
                            class="button-primary-outline px-5 py-2.5 text-sm"
                    >
                        {{ __('patients.group_actions') }}
                    </button>

                    <div x-show="openGroupActions"
                         x-transition
                         x-cloak
                         class="absolute right-0 top-full mt-2 z-10 w-60 bg-white rounded-lg shadow-lg border border-gray-200 dark:bg-gray-700 dark:border-gray-600 overflow-hidden"
                    >
                        <div class="py-1">
                            <button type="button"
                                    @click="openGroupActions = false"
                                    class="dropdown-button !flex items-center gap-2.5 w-full px-4 py-2 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors text-left"
                            >
                                <span class="text-gray-500">
                                    @icon('close', 'w-4 h-4')
                                </span>
                                {{ __('patients.revoke_access') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="episodes-search-filters">
                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="filterPeriodDateRange"
                                   type="text"
                                   name="filterPeriodDateRange"
                                   id="filterPeriodDateRange"
                                   class="daterangepicker-uk with-leading-icon input peer w-full"
                                   placeholder=" "
                                   autocomplete="off"
                            />
                            <label for="filterPeriodDateRange" class="wrapped-label">
                                {{ __('patients.filter_created_at_range') }}
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @if(count($this->paginatedEpisodes->items()) > 0)
                    @include('livewire.person.records.parts.episodes', ['episodes' => $this->paginatedEpisodes->items()])
                @else
                    <x-nothing-found :description="null" />
                @endif
            </div>

            <div class="mt-6">
                {{ $this->paginatedEpisodes->links() }}
            </div>
        </div>
    </div>

    <x-forms.loading />
</x-layouts.patient>
