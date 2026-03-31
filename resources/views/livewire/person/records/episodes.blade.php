<x-layouts.patient :id="$id" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', \App\Models\MedicalEvents\Sql\Encounter::class)
            <a href="{{ route('encounter.create', [legalEntity(), 'patientId' => $id]) }}"
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

        <button wire:click.prevent="syncEpisodes"
                type="button"
                class="button-sync flex items-center gap-2 whitespace-nowrap px-5 py-2 text-sm shadow-sm"
        >
            @icon('refresh', 'w-4 h-4')
            {{ __('patients.sync_ehealth_data') }}
        </button>
    </x-slot>

    <div class="breadcrumb-form p-4 shift-content">



        <div class="w-full mt-6" x-data="{ showAdditionalParams: @entangle('showAdditionalParams') }">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('patients.search_episode') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <input wire:model="filterName"
                           type="text"
                           name="filterName"
                           id="filterName"
                           class="input peer"
                           placeholder=" "
                           autocomplete="off"
                    />
                    <label for="filterName" class="label">
                        {{ __('patients.filter_name') }}
                    </label>
                </div>

                <div class="form-group group">
                    <input wire:model="filterCode"
                           type="text"
                           name="filterCode"
                           id="filterCode"
                           class="input peer"
                           placeholder=" "
                           autocomplete="off"
                    />
                    <label for="filterCode" class="label">
                        {{ __('patients.filter_code') }}
                    </label>
                </div>

                <div class="form-group group">
                    <select wire:model="filterDoctor"
                            name="filterDoctor"
                            id="filterDoctor"
                            class="input-select peer w-full"
                    >
                        <option value="">{{ __('forms.select') }} ...</option>
                        <option value="1">Шевченко Т.Г.</option>
                    </select>
                    <label for="filterDoctor" class="label">
                        {{ __('patients.filter_doctor') }}
                    </label>
                </div>
            </div>

            <div class="mb-9 flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="searchEpisodes"
                            class="flex items-center gap-2 button-primary px-5 py-2.5 text-sm shadow-sm"
                    >
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('patients.search_button') }}</span>
                    </button>
                    <button type="button" wire:click="resetFilters"
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
                         class="absolute right-0 top-full mt-2 z-10 w-[240px] bg-white rounded-lg shadow-lg border border-gray-200 dark:bg-gray-700 dark:border-gray-600 overflow-hidden"
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
                            <input wire:model="filterCreatedAtRange"
                                   type="text"
                                   name="filterCreatedAtRange"
                                   id="filterCreatedAtRange"
                                   class="datepicker-input with-leading-icon input peer"
                                   placeholder=" "
                                   autocomplete="off"
                            />
                            <label for="filterCreatedAtRange" class="wrapped-label">
                                {{ __('patients.filter_created_at_range') }}
                            </label>
                        </div>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterStatus"
                                name="filterStatus"
                                id="filterStatus"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="active">Діючий</option>
                            <option value="cancelled">Скасований</option>
                        </select>
                        <label for="filterStatus" class="label">
                            {{ __('patients.filter_status') }}
                        </label>
                    </div>
                </div>

                <div class="form-row-3 mb-9">
                    <div class="form-group group">
                        <select wire:model="filterIcdDiagnosis"
                                name="filterIcdDiagnosis"
                                id="filterIcdDiagnosis"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                        </select>
                        <label for="filterIcdDiagnosis" class="label">
                            {{ __('patients.filter_icd_diagnosis') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterIcpcDiagnosis"
                                name="filterIcpcDiagnosis"
                                id="filterIcpcDiagnosis"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                        </select>
                        <label for="filterIcpcDiagnosis" class="label">
                            {{ __('patients.filter_icpc_diagnosis') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterType"
                                name="filterType"
                                id="filterType"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>
                            <option value="treatment">Лікування</option>
                        </select>
                        <label for="filterType" class="label">
                            {{ __('patients.filter_type') }}
                        </label>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @include('livewire.person.records.parts.episodes')
            </div>
        </div>

    </div>

    <x-forms.loading />
</x-layouts.patient>
