@use(App\Enums\Person\ObservationStatus)
<x-layouts.patient :personId="$personId" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', \App\Models\MedicalEvents\Sql\Encounter::class)
            <a href="{{ route('encounter.create', [legalEntity(), 'personId' => $personId]) }}"
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

        <button wire:click.prevent="sync"
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
                <p>{{ __('patients.observations') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group relative"
                    x-data="{
                        open: false,
                        search: '',
                        selected: $wire.entangle('filterCode'),

                        get options() {
                            return $wire.get('filterCodeOptions') ?? [];
                        },

                        get filteredOptions() {
                            if (!this.search.trim()) {
                                return this.options;
                            }

                            const needle = this.search.toLowerCase();

                            return this.options.filter((option) => {
                                const label = String(option.label ?? '').toLowerCase();
                                const value = String(option.value ?? '').toLowerCase();
                                const description = String(option.description ?? '').toLowerCase();

                                return label.includes(needle)
                                    || value.includes(needle)
                                    || description.includes(needle);
                            });
                        },

                        get selectedOption() {
                            return this.options.find((option) => String(option.value) === String(this.selected));
                        },

                        selectOption(option) {
                            this.selected = option.value;
                            this.search = option.label;
                            this.open = false;
                        },

                        clearOption() {
                            this.selected = '';
                            this.search = '';
                            this.open = false;
                        },

                        init() {
                            this.search = this.selectedOption ? this.selectedOption.label : '';

                            this.$watch('selected', () => {
                                this.search = this.selectedOption ? this.selectedOption.label : '';
                            });
                        }
                    }"
                    @click.outside="open = false"
                >
                    <div class="relative">
                        <input type="text"
                            name="filterCodeSearch"
                            id="filterCodeSearch"
                            class="input peer w-full pr-10"
                            placeholder=" "
                            autocomplete="off"
                            x-model="search"
                            @focus="open = true"
                            @input="
                                    open = true;

                                    if (selected) {
                                        selected = '';
                                    }
                            "
                        />

                        <label for="filterCodeSearch" class="label">
                            {{ __('forms.code') }}
                        </label>

                        <button type="button"
                                x-show="selected || search"
                                x-cloak
                                @click="clearOption()"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                        >
                            @icon('close', 'w-4 h-4')
                        </button>

                        <div x-show="open"
                            x-transition
                            x-cloak
                            class="absolute left-0 right-0 top-full mt-1 z-50 max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800"
                        >
                            <template x-if="filteredOptions.length > 0">
                                <div>
                                    <template x-for="option in filteredOptions" :key="option.value">
                                        <button type="button"
                                                class="w-full px-3 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700"
                                                @click="selectOption(option)"
                                        >
                                            <div class="font-medium text-gray-900 dark:text-gray-100"
                                                x-text="option.label || 'Без назви'"
                                            ></div>

                                            <div class="text-xs text-gray-500 break-all"
                                                x-text="option.description || option.value"
                                            ></div>
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <template x-if="filteredOptions.length === 0">
                                <div class="px-3 py-2 text-sm text-gray-500">
                                    {{ __('patients.nothing_found') }}
                                </div>
                            </template>
                        </div>
                    </div>
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

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="observations-search-filters">
                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="filterIssuedFrom"
                                type="text"
                                name="filterIssuedFrom"
                                id="filterIssuedFrom"
                                datepicker-format="dd.mm.yyyy"
                                class="datepicker-input with-leading-icon input peer w-full"
                                placeholder=" "
                                autocomplete="off"
                            />
                    
                            <label for="filterIssuedFrom" class="wrapped-label">
                                {{ __('patients.date_from') }}
                            </label>
                        </div>
                    </div>

                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="filterIssuedTo"
                                type="text"
                                name="filterIssuedTo"
                                id="filterIssuedTo"
                                datepicker-format="dd.mm.yyyy"
                                class="datepicker-input with-leading-icon input peer w-full"
                                placeholder=" "
                                autocomplete="off"
                            />
                    
                            <label for="filterIssuedTo" class="wrapped-label">
                                {{ __('patients.date_to') }}
                            </label>
                        </div>
                    </div>

                    <div class="form-group group relative"
                        x-data="{
                            open: false,
                            search: '',
                            selected: $wire.entangle('filterEpisodeId'),
                    
                            get options() {
                                return $wire.get('filterEpisodeOptions') ?? [];
                            },
                    
                            get filteredOptions() {
                                if (!this.search.trim()) {
                                    return this.options;
                                }
                    
                                const needle = this.search.toLowerCase();
                    
                                return this.options.filter((option) => {
                                    const label = String(option.label ?? '').toLowerCase();
                                    const value = String(option.value ?? '').toLowerCase();
                                    const description = String(option.description ?? '').toLowerCase();
                    
                                    return label.includes(needle)
                                        || value.includes(needle)
                                        || description.includes(needle);
                                });
                            },
                    
                            get selectedOption() {
                                return this.options.find((option) => String(option.value) === String(this.selected));
                            },
                    
                            selectOption(option) {
                                this.selected = option.value;
                                this.search = option.label;
                                this.open = false;
                            },
                    
                            clearOption() {
                                this.selected = '';
                                this.search = '';
                                this.open = false;
                            },
                    
                            init() {
                                this.search = this.selectedOption ? this.selectedOption.label : '';
                    
                                this.$watch('selected', () => {
                                    this.search = this.selectedOption ? this.selectedOption.label : '';
                                });
                            }
                        }"
                        @click.outside="open = false"
                    >
                        <div class="relative">
                            <input type="text"
                                name="filterEpisodeIdSearch"
                                id="filterEpisodeIdSearch"
                                class="input peer w-full pr-10"
                                placeholder=" "
                                autocomplete="off"
                                x-model="search"
                                @focus="open = true"
                                @input="
                                        open = true;
                    
                                        if (selected) {
                                            selected = '';
                                        }
                                "
                            />
                    
                            <label for="filterEpisodeIdSearch" class="label">
                                {{ __('patients.episode') }}
                            </label>
                    
                            <button type="button"
                                    x-show="selected || search"
                                    x-cloak
                                    @click="clearOption()"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                            >
                                @icon('close', 'w-4 h-4')
                            </button>
                    
                            <div x-show="open"
                                x-transition
                                x-cloak
                                class="absolute left-0 right-0 top-full mt-1 z-50 max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800"
                            >
                                <template x-if="filteredOptions.length > 0">
                                    <div>
                                        <template x-for="option in filteredOptions" :key="option.value">
                                            <button type="button"
                                                    class="w-full px-3 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700"
                                                    @click="selectOption(option)"
                                            >
                                                <div class="font-medium text-gray-900 dark:text-gray-100"
                                                    x-text="option.label || 'Без назви'"
                                                ></div>
                    
                                                <div class="text-xs text-gray-500 break-all"
                                                    x-text="option.description || option.value"
                                                ></div>
                                            </button>
                                        </template>
                                    </div>
                                </template>
                    
                                <template x-if="filteredOptions.length === 0">
                                    <div class="px-3 py-2 text-sm text-gray-500">
                                        {{ __('patients.episodes_not_found') }}
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-row-3 mb-6">
                    <div class="form-group group relative"
                        x-data="{
                            open: false,
                            search: '',
                            selected: $wire.entangle('filterEncounterId'),
                    
                            get options() {
                                return $wire.get('filterEncounterOptions') ?? [];
                            },
                    
                            get filteredOptions() {
                                if (!this.search.trim()) {
                                    return this.options;
                                }
                    
                                const needle = this.search.toLowerCase();
                    
                                return this.options.filter((option) => {
                                    const label = String(option.label ?? '').toLowerCase();
                                    const value = String(option.value ?? '').toLowerCase();
                                    const description = String(option.description ?? '').toLowerCase();
                    
                                    return label.includes(needle)
                                        || value.includes(needle)
                                        || description.includes(needle);
                                });
                            },
                    
                            get selectedOption() {
                                return this.options.find((option) => String(option.value) === String(this.selected));
                            },
                    
                            selectOption(option) {
                                this.selected = option.value;
                                this.search = option.label;
                                this.open = false;
                            },
                    
                            clearOption() {
                                this.selected = '';
                                this.search = '';
                                this.open = false;
                            },
                    
                            init() {
                                this.search = this.selectedOption ? this.selectedOption.label : '';
                    
                                this.$watch('selected', () => {
                                    this.search = this.selectedOption ? this.selectedOption.label : '';
                                });
                            }
                        }"
                        @click.outside="open = false"
                    >
                        <div class="relative">
                            <input type="text"
                                name="filterEncounterIdSearch"
                                id="filterEncounterIdSearch"
                                class="input peer w-full pr-10"
                                placeholder=" "
                                autocomplete="off"
                                x-model="search"
                                @focus="open = true"
                                @input="
                                        open = true;
                    
                                        if (selected) {
                                            selected = '';
                                        }
                                "
                            />
                    
                            <label for="filterEncounterIdSearch" class="label">
                                {{ __('patients.encounter_id') }}
                            </label>
                    
                            <button type="button"
                                    x-show="selected || search"
                                    x-cloak
                                    @click="clearOption()"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                            >
                                @icon('close', 'w-4 h-4')
                            </button>
                    
                            <div x-show="open"
                                x-transition
                                x-cloak
                                class="absolute left-0 right-0 top-full mt-1 z-50 max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800"
                            >
                                <template x-if="filteredOptions.length > 0">
                                    <div>
                                        <template x-for="option in filteredOptions" :key="option.value">
                                            <button type="button"
                                                    class="w-full px-3 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700"
                                                    @click="selectOption(option)"
                                            >
                                                <div class="font-medium text-gray-900 dark:text-gray-100"
                                                    x-text="option.label || 'Без назви'"
                                                ></div>
                    
                                                <div class="text-xs text-gray-500 break-all"
                                                    x-text="option.description || option.value"
                                                ></div>
                                            </button>
                                        </template>
                                    </div>
                                </template>
                    
                                <template x-if="filteredOptions.length === 0">
                                    <div class="px-3 py-2 text-sm text-gray-500">
                                        {{ __('patients.episodes_not_found') }}
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="form-group group relative"
                        x-data="{
                            open: false,
                            search: '',
                            selected: $wire.entangle('filterDiagnosticReportId'),
                    
                            get options() {
                                return $wire.get('filterDiagnosticReportOptions') ?? [];
                            },
                    
                            get filteredOptions() {
                                if (!this.search.trim()) {
                                    return this.options;
                                }
                    
                                const needle = this.search.toLowerCase();
                    
                                return this.options.filter((option) => {
                                    const label = String(option.label ?? '').toLowerCase();
                                    const value = String(option.value ?? '').toLowerCase();
                                    const description = String(option.description ?? '').toLowerCase();
                    
                                    return label.includes(needle)
                                        || value.includes(needle)
                                        || description.includes(needle);
                                });
                            },
                    
                            get selectedOption() {
                                return this.options.find((option) => String(option.value) === String(this.selected));
                            },
                    
                            selectOption(option) {
                                this.selected = option.value;
                                this.search = option.label;
                                this.open = false;
                            },
                    
                            clearOption() {
                                this.selected = '';
                                this.search = '';
                                this.open = false;
                            },
                    
                            init() {
                                this.search = this.selectedOption ? this.selectedOption.label : '';
                    
                                this.$watch('selected', () => {
                                    this.search = this.selectedOption ? this.selectedOption.label : '';
                                });
                            }
                        }"
                        @click.outside="open = false"
                    >
                        <div class="relative">
                            <input type="text"
                                name="filterDiagnosticReportIdSearch"
                                id="filterDiagnosticReportIdSearch"
                                class="input peer w-full pr-10"
                                placeholder=" "
                                autocomplete="off"
                                x-model="search"
                                @focus="open = true"
                                @input="
                                        open = true;
                    
                                        if (selected) {
                                            selected = '';
                                        }
                                "
                            />
                    
                            <label for="filterDiagnosticReportIdSearch" class="label">
                                {{ __('patients.diagnostic_report_id') }}
                            </label>
                    
                            <button type="button"
                                    x-show="selected || search"
                                    x-cloak
                                    @click="clearOption()"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                            >
                                @icon('close', 'w-4 h-4')
                            </button>
                    
                            <div x-show="open"
                                x-transition
                                x-cloak
                                class="absolute left-0 right-0 top-full mt-1 z-50 max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800"
                            >
                                <template x-if="filteredOptions.length > 0">
                                    <div>
                                        <template x-for="option in filteredOptions" :key="option.value">
                                            <button type="button"
                                                    class="w-full px-3 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700"
                                                    @click="selectOption(option)"
                                            >
                                                <div class="font-medium text-gray-900 dark:text-gray-100"
                                                    x-text="option.label || 'Без назви'"
                                                ></div>
                    
                                                <div class="text-xs text-gray-500 break-all"
                                                    x-text="option.description || option.value"
                                                ></div>
                                            </button>
                                        </template>
                                    </div>
                                </template>
                    
                                <template x-if="filteredOptions.length === 0">
                                    <div class="px-3 py-2 text-sm text-gray-500">
                                        {{ __('patients.nothing_found') }}
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="form-group group relative"
                        x-data="{
                            open: false,
                            search: '',
                            selected: $wire.entangle('filterDeviceId'),
                    
                            get options() {
                                return $wire.get('filterDeviceOptions') ?? [];
                            },
                    
                            get filteredOptions() {
                                if (!this.search.trim()) {
                                    return this.options;
                                }
                    
                                const needle = this.search.toLowerCase();
                    
                                return this.options.filter((option) => {
                                    const label = String(option.label ?? '').toLowerCase();
                                    const value = String(option.value ?? '').toLowerCase();
                                    const description = String(option.description ?? '').toLowerCase();
                    
                                    return label.includes(needle)
                                        || value.includes(needle)
                                        || description.includes(needle);
                                });
                            },
                    
                            get selectedOption() {
                                return this.options.find((option) => String(option.value) === String(this.selected));
                            },
                    
                            selectOption(option) {
                                this.selected = option.value;
                                this.search = option.label;
                                this.open = false;
                            },
                    
                            clearOption() {
                                this.selected = '';
                                this.search = '';
                                this.open = false;
                            },
                    
                            init() {
                                this.search = this.selectedOption ? this.selectedOption.label : '';
                    
                                this.$watch('selected', () => {
                                    this.search = this.selectedOption ? this.selectedOption.label : '';
                                });
                            }
                        }"
                        @click.outside="open = false"
                    >
                        <div class="relative">
                            <input type="text"
                                name="filterDeviceIdSearch"
                                id="filterDeviceIdSearch"
                                class="input peer w-full pr-10"
                                placeholder=" "
                                autocomplete="off"
                                x-model="search"
                                @focus="open = true"
                                @input="
                                        open = true;
                    
                                        if (selected) {
                                            selected = '';
                                        }
                                "
                            />
                    
                            <label for="filterDeviceIdSearch" class="label">
                                {{ __('patients.device_id') }}
                            </label>
                    
                            <button type="button"
                                    x-show="selected || search"
                                    x-cloak
                                    @click="clearOption()"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                            >
                                @icon('close', 'w-4 h-4')
                            </button>
                    
                            <div x-show="open"
                                x-transition
                                x-cloak
                                class="absolute left-0 right-0 top-full mt-1 z-50 max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800"
                            >
                                <template x-if="filteredOptions.length > 0">
                                    <div>
                                        <template x-for="option in filteredOptions" :key="option.value">
                                            <button type="button"
                                                    class="w-full px-3 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700"
                                                    @click="selectOption(option)"
                                            >
                                                <div class="font-medium text-gray-900 dark:text-gray-100"
                                                    x-text="option.label || 'Без назви'"
                                                ></div>
                    
                                                <div class="text-xs text-gray-500 break-all"
                                                    x-text="option.description || option.value"
                                                ></div>
                                            </button>
                                        </template>
                                    </div>
                                </template>
                    
                                <template x-if="filteredOptions.length === 0">
                                    <div class="px-3 py-2 text-sm text-gray-500">
                                        {{ __('patients.nothing_found') }}
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-row-3 mb-9">
                    <div class="form-group group relative"
                        x-data="{
                            open: false,
                            search: '',
                            selected: $wire.entangle('filterSpecimenId'),
                    
                            get options() {
                                return $wire.get('filterSpecimenOptions') ?? [];
                            },
                    
                            get filteredOptions() {
                                if (!this.search.trim()) {
                                    return this.options;
                                }
                    
                                const needle = this.search.toLowerCase();
                    
                                return this.options.filter((option) => {
                                    const label = String(option.label ?? '').toLowerCase();
                                    const value = String(option.value ?? '').toLowerCase();
                                    const description = String(option.description ?? '').toLowerCase();
                    
                                    return label.includes(needle)
                                        || value.includes(needle)
                                        || description.includes(needle);
                                });
                            },
                    
                            get selectedOption() {
                                return this.options.find((option) => String(option.value) === String(this.selected));
                            },
                    
                            selectOption(option) {
                                this.selected = option.value;
                                this.search = option.label;
                                this.open = false;
                            },
                    
                            clearOption() {
                                this.selected = '';
                                this.search = '';
                                this.open = false;
                            },
                    
                            init() {
                                this.search = this.selectedOption ? this.selectedOption.label : '';
                    
                                this.$watch('selected', () => {
                                    this.search = this.selectedOption ? this.selectedOption.label : '';
                                });
                            }
                        }"
                        @click.outside="open = false"
                    >
                        <div class="relative">
                            <input type="text"
                                name="filterSpecimenIdSearch"
                                id="filterSpecimenIdSearch"
                                class="input peer w-full pr-10"
                                placeholder=" "
                                autocomplete="off"
                                x-model="search"
                                @focus="open = true"
                                @input="
                                        open = true;
                    
                                        if (selected) {
                                            selected = '';
                                        }
                                "
                            />
                    
                            <label for="filterSpecimenIdSearch" class="label">
                                {{ __('patients.specimen_id') }}
                            </label>
                    
                            <button type="button"
                                    x-show="selected || search"
                                    x-cloak
                                    @click="clearOption()"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                            >
                                @icon('close', 'w-4 h-4')
                            </button>
                    
                            <div x-show="open"
                                x-transition
                                x-cloak
                                class="absolute left-0 right-0 top-full mt-1 z-50 max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800"
                            >
                                <template x-if="filteredOptions.length > 0">
                                    <div>
                                        <template x-for="option in filteredOptions" :key="option.value">
                                            <button type="button"
                                                    class="w-full px-3 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700"
                                                    @click="selectOption(option)"
                                            >
                                                <div class="font-medium text-gray-900 dark:text-gray-100"
                                                    x-text="option.label || 'Без назви'"
                                                ></div>
                    
                                                <div class="text-xs text-gray-500 break-all"
                                                    x-text="option.description || option.value"
                                                ></div>
                                            </button>
                                        </template>
                                    </div>
                                </template>
                    
                                <template x-if="filteredOptions.length === 0">
                                    <div class="px-3 py-2 text-sm text-gray-500">
                                        {{ __('patients.nothing_found') }}
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @forelse($paginatedObservations as $observation)
                    <div class="record-inner-card">
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <input type="checkbox" class="default-checkbox w-5 h-5">
                            </div>

                            <div class="record-inner-column !pl-4 flex-1">
                                <div class="record-inner-label">{{ __('patients.category_and_code') }}</div>
                                <div class="record-inner-value text-[17px] font-semibold text-gray-900 dark:text-gray-100">
                                    {{ data_get(
                                        $this->dictionaries,
                                        data_get($observation, 'categories.0.coding.0.system', 'eHealth/observation_categories') . '.' . data_get($observation, 'categories.0.coding.0.code'),
                                        data_get($observation, 'categories.0.text', data_get($observation, 'categories.0.coding.0.code', '—'))
                                    ) }} |  {{ data_get(
                                        $this->dictionaries,
                                        data_get($observation, 'code.coding.0.system', 'eHealth/LOINC/observation_codes') . '.' . data_get($observation, 'code.coding.0.code'),
                                        data_get($observation, 'code.text', data_get($observation, 'code.coding.0.code', '—'))
                                    ) }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
                                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                                <div>
                                    <span class="badge-green">
                                        {{ ObservationStatus::tryFrom(data_get($observation, 'status'))?->label() ?? data_get($observation, 'status', '-') }}
                                    </span>
                                </div>
                            </div>

                            <div class="record-inner-action-col">
                                <div class="flex justify-center relative">
                                    <div x-data="{
                                        open: false,
                                        toggle() {
                                            if (this.open) { return this.close(); }
                                            this.$refs.button.focus();
                                            this.open = true;
                                        },
                                        close(focusAfter) {
                                            if (!this.open) return;
                                            this.open = false;
                                            focusAfter && focusAfter.focus()
                                        }
                                    }"
                                        @keydown.escape.prevent.stop="close($refs.button)"
                                        @focusin.window="!$refs.panel.contains($event.target) && close()"
                                        x-id="['dropdown-button']"
                                        class="relative"
                                    >
                                        <button @click="toggle()"
                                                x-ref="button"
                                                :aria-expanded="open"
                                                :aria-controls="$id('dropdown-button')"
                                                type="button"
                                                class="record-inner-action-btn"
                                        >
                                            @icon('edit-user-outline', 'w-5 h-5')
                                        </button>

                                        <div x-show="open"
                                            x-cloak
                                            x-ref="panel"
                                            x-transition.origin.top.right
                                            @click.outside="close($refs.button)"
                                            :id="$id('dropdown-button')"
                                            class="absolute right-0 mt-2 w-56 rounded-md bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 shadow-md z-50 py-1"
                                        >
                                            <button @click="close($refs.button)"
                                                    class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                                            >
                                                @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                {{ __('patients.view_details') }}
                                            </button>

                                            <button @click="close($refs.button)"
                                                    class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                                            >
                                                @icon('alert-circle', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                {{ __('patients.status.entered_in_error') }}
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-body">
                            <div class="record-inner-grid-container">
                                <div class="flex flex-col gap-4">
                                    <div class="grid grid-cols-2 lg:grid-cols-5 gap-2 xl:gap-4 overflow-hidden">
                                        <div>
                                            <div class="record-inner-label">{{ __('patients.source_label') }}</div>
                                            <div class="record-inner-value">
                                                {{ data_get($observation, 'primarySource') ? 'Пацієнт' : '-' }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="record-inner-label">{{ __('patients.method') }}</div>
                                            <div class="record-inner-value">
                                                {{ data_get(
                                                    $this->dictionaries,
                                                    data_get($observation, 'method.coding.0.system', 'eHealth/observation_methods') . '.' . data_get($observation, 'method.coding.0.code'),
                                                    data_get($observation, 'method.text', data_get($observation, 'method.coding.0.code', '-'))
                                                ) }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="record-inner-label">{{ __('patients.value') }}</div>
                                            <div class="record-inner-value">
                                                {{ data_get($observation, 'components.0.') ?? '-' }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="record-inner-label">{{ __('patients.getting_indicators') }}</div>
                                            <div class="record-inner-value">
                                                {{ data_get($observation, 'effectiveDate')
                                                    ? substr(data_get($observation, 'effectiveDate'), 8, 2) . '.' . substr(data_get($observation, 'effectiveDate'), 5, 2) . '.' . substr(data_get($observation, 'effectiveDate'), 0, 4) . (data_get($observation, 'effectiveTime') ? ' ' . data_get($observation, 'effectiveTime') : '')
                                                    : (data_get($observation, 'effectiveDateTime')
                                                        ? substr(data_get($observation, 'effectiveDateTime'), 8, 2) . '.' . substr(data_get($observation, 'effectiveDateTime'), 5, 2) . '.' . substr(data_get($observation, 'effectiveDateTime'), 0, 4) . ' ' . substr(data_get($observation, 'effectiveDateTime'), 11, 5)
                                                        : '-')
                                                }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="record-inner-label">{{ __('forms.update') }}</div>
                                            <div class="record-inner-value">
                                                {{ data_get($observation, 'effectiveDate')
                                                    ? substr(data_get($observation, 'effectiveDate'), 8, 2) . '.' . substr(data_get($observation, 'effectiveDate'), 5, 2) . '.' . substr(data_get($observation, 'effectiveDate'), 0, 4) . (data_get($observation, 'effectiveTime') ? ' ' . data_get($observation, 'effectiveTime') : '')
                                                    : (data_get($observation, 'effectiveDateTime')
                                                        ? substr(data_get($observation, 'effectiveDateTime'), 8, 2) . '.' . substr(data_get($observation, 'effectiveDateTime'), 5, 2) . '.' . substr(data_get($observation, 'effectiveDateTime'), 0, 4) . ' ' . substr(data_get($observation, 'effectiveDateTime'), 11, 5)
                                                        : '-')
                                                }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 lg:grid-cols-5 gap-2 xl:gap-4 overflow-hidden">
                                        <div>
                                            <div class="record-inner-label">{{ __('patients.interpretation') }}</div>
                                            <div class="record-inner-value">
                                                {{ data_get(
                                                    $this->dictionaries,
                                                    data_get($observation, 'interpretation.coding.0.system', 'eHealth/observation_interpretations') . '.' . data_get($observation, 'interpretation.coding.0.code'),
                                                    data_get($observation, 'interpretation.text', data_get($observation, 'interpretation.coding.0.code', '-'))
                                                ) }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="record-inner-label">{{ __('patients.body_part') }}</div>
                                            <div class="record-inner-value">
                                               {{ data_get(
                                                    $this->dictionaries,
                                                    data_get($observation, 'bodySite.coding.0.system', 'eHealth/body_sites') . '.' . data_get($observation, 'bodySite.coding.0.code'),
                                                    data_get($observation, 'bodySite.text', data_get($observation, 'bodySite.coding.0.code', '-'))
                                                ) }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="record-inner-label">{{ __('patients.doctor') }}</div>
                                            <div class="record-inner-value">
                                                {{ data_get($observation, 'performer.displayValue') ?? '-' }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="record-inner-label">{{ __('patients.created') }}</div>
                                            <div class="record-inner-value">
                                                {{ data_get($observation, 'ehealthInsertedAt')
                                                    ? substr(data_get($observation, 'ehealthInsertedAt'), 8, 2) . '.' . substr(data_get($observation, 'ehealthInsertedAt'), 5, 2) . '.' . substr(data_get($observation, 'ehealthInsertedAt'), 0, 4) . ' ' . substr(data_get($observation, 'ehealthInsertedAt'), 11, 5)
                                                    : (data_get($observation, 'insertedAt')
                                                        ? substr(data_get($observation, 'insertedAt'), 8, 2) . '.' . substr(data_get($observation, 'insertedAt'), 5, 2) . '.' . substr(data_get($observation, 'insertedAt'), 0, 4) . ' ' . substr(data_get($observation, 'insertedAt'), 11, 5)
                                                        : '-')
                                                }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="record-inner-id-col">
                                <div class="min-w-0 mb-3">
                                    <div class="record-inner-label">ID ECO3</div>
                                    <div class="record-inner-id-value">
                                        {{ data_get($observation, 'uuid') ?? '-' }}
                                    </div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('patients.medical_record_id') }}</div>
                                    <div class="record-inner-id-value">
                                        {{ data_get($observation, 'context.identifier.value') ?? '-' }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-6 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('patients.observations_not_found') }}
                    </div>
                @endforelse
            </div>
            
            <div class="mt-8">
                {{ $paginatedObservations->links() }}
            </div>
        </div>
    </div>

    <x-forms.loading />
</x-layouts.patient>
