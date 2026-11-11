@php
    use App\Enums\Episode\Status;
    use App\Models\MedicalEvents\Sql\Encounter;
    use App\Models\MedicalEvents\Sql\Episode;
@endphp

<x-layouts.patient :personId="$personId" :prepersonId="$prepersonId" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', Encounter::class)
            <a
                href="{{
                    $prepersonId
                    ? route('prepersons.encounters.create', [legalEntity(), 'preperson' => $prepersonId])
                    : route('persons.encounters.create', [legalEntity(), 'person' => $personId])
                }}"
                class="button-primary flex items-center gap-2 px-5 py-2 text-sm shadow-sm"
            >
                @icon('plus', 'w-4 h-4')
                {{ __('encounters.start') }}
            </a>
        @endcan

        <button type="button" class="button-primary-outline px-5 py-2 text-sm whitespace-nowrap">
            {{ __('patients.data_access') }}
        </button>

        @can('viewAny', Episode::class)
            <button
                wire:click.prevent="sync"
                type="button"
                class="button-sync flex items-center gap-2 px-5 py-2 text-sm whitespace-nowrap shadow-sm"
            >
                @icon('refresh', 'w-4 h-4')
                {{ __('forms.synchronise_with_eHealth') }}
            </button>
        @endcan
    </x-slot>

    <div class="breadcrumb-form shift-content p-4">
        <div
            class="mt-6 w-full"
            x-data="{ showAdditionalParams: $wire.entangle('showAdditionalParams'), deletingUuid: '' }"
        >
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('episodes.search') }}</p>
            </div>

            <div
                class="form-row-3 mb-6"
                x-data="{ dictionary: '', filterCode: $wire.entangle('filterCode'), selectedIcd10: null }"
            >
                <div class="form-group group">
                    <select
                        x-model="dictionary"
                        @change="filterCode = ''"
                        name="codeSystem"
                        id="codeSystem"
                        class="input-select peer mb-1 w-full text-sm"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        <option value="icd10">{{ __('medical-events.icd_10') }}</option>
                        <option value="icpc2">{{ __('medical-events.icpc_2') }}</option>
                    </select>
                    <label for="codeSystem" class="label">{{ __('episodes.code_system') }}</label>
                </div>

                <div class="form-group group" x-show="dictionary">
                    <div
                        x-show="dictionary === 'icd10'"
                        x-data="icd10Search"
                        data-icd10-search-url="{{ route('icd10.lookup', [legalEntity()]) }}"
                        class="relative"
                    >
                        <input
                            type="text"
                            :value="selectedIcd10?.code === filterCode
                                ? selectedIcd10.code + ' - ' + selectedIcd10.description
                                : filterCode"
                            @input.debounce.600ms="
                                filterCode = $event.target.value;
                                search($event.target.value);
                            "
                            @click.away="showResults = false"
                            id="filterCodeIcd10"
                            class="input-select peer w-full"
                            placeholder="{{ __('forms.type_to_search') }}"
                            autocomplete="off"
                        />
                        <div
                            x-show="showResults && results.length > 0"
                            class="absolute top-full left-0 z-10 max-h-60 w-full overflow-auto rounded-lg border bg-white p-1.5 shadow-lg dark:bg-gray-800"
                        >
                            <template x-for="result in results" :key="result.code">
                                <div
                                    @click="
                                        selectedIcd10 = result;
                                        filterCode = result.code;
                                        showResults = false;
                                    "
                                    class="cursor-pointer rounded-md px-2 py-1.5 text-sm hover:bg-gray-100 dark:text-white dark:hover:bg-gray-700"
                                    x-text="result.code + ' - ' + result.description"
                                ></div>
                            </template>
                        </div>
                    </div>
                    <div x-show="dictionary === 'icpc2'">
                        <x-select2
                            modelPath="filterCode"
                            dictionaryName="eHealth/ICPC2/condition_codes"
                            id="filterCodeIcpc2"
                            class="input-select peer w-full"
                        />
                    </div>
                    <label
                        :for="dictionary === 'icd10' ? 'filterCodeIcd10' : 'filterCodeIcpc2'"
                        class="label"
                    >{{ __('episodes.code') }}</label>
                </div>

                <div class="form-group group">
                    <select
                        wire:model="filterStatus"
                        name="filterStatus"
                        id="filterStatus"
                        class="input-select peer w-full"
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach (Status::searchableOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <label for="filterStatus" class="label"> {{ __('forms.status.label') }} </label>
                </div>
            </div>

            <div class="mb-9 flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        wire:click="search"
                        class="button-primary flex items-center gap-2 px-5 py-2.5 text-sm shadow-sm"
                    >
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('forms.search') }}</span>
                    </button>
                    <button
                        type="button"
                        wire:click="resetFilters"
                        class="button-primary-outline-red px-5 py-2.5 text-sm"
                    >
                        {{ __('forms.reset_all_filters') }}
                    </button>
                    <button
                        type="button"
                        class="button-minor flex items-center gap-2 px-5 py-2.5 text-sm whitespace-nowrap"
                        @click.prevent="showAdditionalParams = ! showAdditionalParams"
                    >
                        @icon('adjustments', 'w-4 h-4 text-gray-500')
                        <span>{{ __('forms.additional_search_parameters') }}</span>
                    </button>
                </div>

                <div class="flex items-center gap-3">
                    @can('create', Episode::class)
                        <a
                            href="{{
                                $prepersonId
                                ? route('prepersons.episodes.create', [legalEntity(), 'preperson' => $prepersonId])
                                : route('persons.episodes.create', [legalEntity(), 'person' => $personId])
                            }}"
                            wire:navigate
                            class="flex cursor-pointer items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-800"
                        >
                            @icon('plus', 'w-4 h-4')
                            <span>{{ __('episodes.create') ?? 'Створити епізод' }}</span>
                        </a>
                    @endcan

                    <div
                        class="relative"
                        x-data="{ openGroupActions: false }"
                        @click.outside="openGroupActions = false"
                    >
                        <button
                            type="button"
                            @click="openGroupActions = ! openGroupActions"
                            class="button-primary-outline px-5 py-2.5 text-sm"
                        >
                            {{ __('forms.group_actions') }}
                        </button>

                        <div
                            x-show="openGroupActions"
                            x-transition
                            x-cloak
                            class="absolute top-full right-0 z-10 mt-2 w-60 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-600 dark:bg-gray-700"
                        >
                            <div class="py-1">
                                <button
                                    type="button"
                                    @click="openGroupActions = false"
                                    class="dropdown-button flex! w-full items-center gap-2.5 px-4 py-2 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
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
            </div>

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="episodes-search-filters">
                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input
                                wire:model="filterPeriodDateRange"
                                type="text"
                                name="filterPeriodDateRange"
                                id="filterPeriodDateRange"
                                class="daterangepicker-uk with-leading-icon input peer w-full @error('filterPeriodDateRange') input-error @enderror"
                                placeholder=" "
                                autocomplete="off"
                            />
                            <label for="filterPeriodDateRange" class="wrapped-label">
                                {{ __('forms.filter_created_at_range') }}
                            </label>
                        </div>

                        @error('filterPeriodDateRange')
                            <p class="text-error mt-1 text-xs">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @if (count($this->paginatedEpisodes->items()) > 0)
                    @include('livewire.person.records.parts.episodes', [
                        'episodes' => $this->paginatedEpisodes->items(),
                        'showStatusActions' => true
                    ])
                @else
                    <x-nothing-found :description="null" />
                @endif
            </div>

            <div class="mt-6">{{ $this->paginatedEpisodes->links() }}</div>

            <x-modal id="episode-delete-draft-modal" alpine-model="deletingUuid" maxWidth="xl">
                <div
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="episode-delete-draft-title"
                    class="bg-white p-6 text-left md:p-8 dark:bg-gray-800"
                >
                    <h3 id="episode-delete-draft-title" class="mb-4 text-xl font-bold text-gray-900 dark:text-white">
                        {{ __('episodes.delete_draft_title') }}
                    </h3>

                    <p class="mb-6 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                        {{ __('episodes.delete_draft_description') }}
                    </p>

                    <div class="flex items-center justify-start gap-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <button type="button" @click="deletingUuid = ''" class="button-minor">
                            {{ __('forms.cancel') }}
                        </button>

                        <button
                            type="button"
                            @click="
                                $wire.deleteDraft(deletingUuid);
                                deletingUuid = '';
                            "
                            class="button-danger"
                        >
                            {{ __('episodes.delete_draft') }}
                        </button>
                    </div>
                </div>
            </x-modal>
        </div>
    </div>

    @include('livewire.person.records.parts.episode-cancellation-modal')
    @include('livewire.person.records.parts.episode-closing-modal')

    <x-forms.loading />
</x-layouts.patient>
