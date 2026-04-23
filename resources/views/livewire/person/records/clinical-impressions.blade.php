<x-layouts.patient :personId="$personId" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', \App\Models\MedicalEvents\Sql\Encounter::class)
            <a href="{{ route('encounter.create', [legalEntity(), 'personId' => $personId]) }}"
               class="flex items-center gap-2 button-primary px-5 py-2 text-sm shadow-sm"
            >
                @icon('plus', 'w-4 h-4')
                {{ __('patients.starts_interacting') }}
            </a>
        @endcan

        <button type="button"
                class="button-primary-outline whitespace-nowrap px-5 py-2 text-sm"
        >
            {{ __('patients.data_access') }}
        </button>

        <button wire:click.prevent="syncClinicalImpressions"
                type="button"
                class="button-sync flex items-center gap-2 whitespace-nowrap px-5 py-2 text-sm shadow-sm"
        >
            @icon('refresh', 'w-4 h-4')
            {{ __('patients.sync_ehealth_data') }}
        </button>
    </x-slot>

    <div class="breadcrumb-form p-4 shift-content">
        <div class="w-full mt-6" x-data="{ showAdditionalParams: $wire.entangle('showAdditionalParams') }">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>Пошук клінічних оцінок</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <select wire:model="filterCode"
                            name="filterCode"
                            id="filterCode"
                            class="input-select peer w-full"
                    >
                        <option value="">{{ __('forms.select') }} ...</option>
                        <option value="1">ЦД. Категорія 3 (студенти)</option>
                    </select>
                    <label for="filterCode" class="label">
                        Код
                    </label>
                </div>

                <div class="form-group group">
                    <input wire:model="filterEcozId"
                           type="text"
                           name="filterEcozId"
                           id="filterEcozId"
                           class="input peer"
                           placeholder=" "
                           autocomplete="off"
                    />
                    <label for="filterEcozId" class="label">
                        ID ECO3
                    </label>
                    <button type="button" @click="$wire.set('filterEcozId', '')" class="absolute right-0 top-3 text-gray-400 hover:text-gray-600">
                         @icon('close', 'w-4 h-4')
                    </button>
                </div>

                <div class="form-group group">
                    <input wire:model="filterMedicalRecordId"
                           type="text"
                           name="filterMedicalRecordId"
                           id="filterMedicalRecordId"
                           class="input peer"
                           placeholder=" "
                           autocomplete="off"
                    />
                    <label for="filterMedicalRecordId" class="label">
                        ID Мед. запису
                    </label>
                    <button type="button" @click="$wire.set('filterMedicalRecordId', '')" class="absolute right-0 top-3 text-gray-400 hover:text-gray-600">
                         @icon('close', 'w-4 h-4')
                    </button>
                </div>
            </div>

            <div class="mb-9 flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="search"
                            class="flex items-center gap-2 button-primary px-5 py-2.5 text-sm shadow-sm"
                    >
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('patients.search') }}</span>
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

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="clinical-impressions-search-filters">
                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="filterStartRange"
                                   type="text"
                                   id="filterStartRange"
                                   class="datepicker-input with-leading-icon input peer"
                                   placeholder=" "
                                   autocomplete="off"
                            />
                            <label for="filterStartRange" class="wrapped-label">
                                Дата початку від - до
                            </label>
                        </div>
                    </div>

                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="filterEndRange"
                                   type="text"
                                   id="filterEndRange"
                                   class="datepicker-input with-leading-icon input peer"
                                   placeholder=" "
                                   autocomplete="off"
                            />
                            <label for="filterEndRange" class="wrapped-label">
                                Дата завершення від - до
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
                            <option value="completed">Завершена</option>
                            <option value="entered_in_error">Внесено помилково</option>
                        </select>
                        <label for="filterStatus" class="label">
                            {{ __('forms.status.label') }}
                        </label>
                    </div>
                </div>

                <div class="form-row-3 mb-9">
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
                            {{ __('patients.doctor') }}
                        </label>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @include('livewire.person.records.parts.clinical-impressions')
            </div>
        </div>
    </div>

    <x-forms.loading />
</x-layouts.patient>
