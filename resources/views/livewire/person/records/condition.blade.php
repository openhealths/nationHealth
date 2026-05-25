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
                <p>{{ __('patients.condition_search') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group relative"
                     x-data="{
                        open: false,
                        search: '',
                        selected: $wire.entangle('filterCode'),
                        searchTimeout: null,
                        isLoading: false,

                        get options() {
                            return $wire.get('filterCodeOptions') ?? [];
                        },

                        get filteredOptions() {
                            if (!this.search.trim()) {
                                return [];
                            }

                            const needle = this.search.toLowerCase();

                            return this.options.filter((option) => {
                                const label = String(option.label ?? '').toLowerCase();
                                const value = String(option.value ?? '').toLowerCase();
                                const description = String(option.description ?? '').toLowerCase();

                                return label.includes(needle)
                                    || value.includes(needle)
                                    || description.includes(needle);
                            })
                            .slice(0, 10);;
                        },

                        get selectedOption() {
                            return this.options.find((option) => String(option.value) === String(this.selected));
                        },

                        searchCodes() {
                            clearTimeout(this.searchTimeout);                            

                            if (!this.search.trim()) {
                                this.isLoading = false;
                                return;
                            }

                            this.isLoading = true;

                            this.searchTimeout = setTimeout(() => {
                                $wire.searchCodes(this.search).then(() => {
                                    this.isLoading = false;
                                    this.open = true;
                                });
                            }, 1000);
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

                            clearTimeout(this.searchTimeout);
                            $wire.searchCodes('');
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
                               placeholder=""
                               autocomplete="off"
                               x-model="search"
                               @focus="open = true"
                               @input="
                                    open = true;

                                    if (selected) {
                                        selected = '';
                                    }

                                    searchCodes();
                               "
                        />

                        <label for="filterCodeSearch" class="label">
                            {{ __('patients.code_and_name') }}
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
                                    <template x-for="option in filteredOptions" :key="option.description + ':' + option.value">
                                        <button type="button"
                                                class="w-full px-3 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700"
                                                @click="selectOption(option)"
                                        >
                                            <div class="font-medium text-gray-900 dark:text-gray-100"
                                                 x-text="option.label || 'Без назви'"
                                            ></div>
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <template x-if="isLoading">
                                <div class="px-3 py-2 text-sm text-gray-500">
                                    {{ __('patients.loading') }}
                                </div>
                            </template>

                            <template x-if="!isLoading && !search.trim()">
                                <div class="px-3 py-2 text-sm text-gray-500">
                                    {{ __('patients.input_code_or_name') }}
                                </div>
                            </template>

                            <template x-if="!isLoading && search.trim() && filteredOptions.length === 0">
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

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="condition-search-filters">
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
                                    return this.options.slice(0, 10);
                                }

                                const needle = this.search.toLowerCase();

                                return this.options.filter((option) => {
                                    const label = String(option.label ?? '').toLowerCase();
                                    const value = String(option.value ?? '').toLowerCase();
                                    const description = String(option.description ?? '').toLowerCase();

                                    return label.includes(needle)
                                        || value.includes(needle)
                                        || description.includes(needle);
                                })
                                .slice(0, 10);
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
                            selected: $wire.entangle('filterEpisodeId'),

                            get options() {
                                return $wire.get('filterEpisodeOptions') ?? [];
                            },

                            get filteredOptions() {
                                if (!this.search.trim()) {
                                    return this.options.slice(0, 10);
                                }

                                const needle = this.search.toLowerCase();

                                return this.options.filter((option) => {
                                    const label = String(option.label ?? '').toLowerCase();
                                    const value = String(option.value ?? '').toLowerCase();
                                    const description = String(option.description ?? '').toLowerCase();

                                    return label.includes(needle)
                                        || value.includes(needle)
                                        || description.includes(needle);
                                })
                                .slice(0, 10);
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

                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="filterOnsetDateFrom"
                                   type="text"
                                   name="filterOnsetDateFrom"
                                   id="filterOnsetDateFrom"
                                   datepicker-format="dd.mm.yyyy"
                                   class="datepicker-input with-leading-icon input peer w-full"
                                   placeholder=" "
                                   autocomplete="off"
                            />

                            <label for="filterOnsetDateFrom" class="wrapped-label">
                                {{ __('patients.start_date') }}
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-row-3 mb-9">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="filterOnsetDateTo"
                                   type="text"
                                   name="filterOnsetDateTo"
                                   id="filterOnsetDateTo"
                                   datepicker-format="dd.mm.yyyy"
                                   class="datepicker-input with-leading-icon input peer w-full"
                                   placeholder=" "
                                   autocomplete="off"
                            />

                            <label for="filterOnsetDateTo" class="wrapped-label">
                                {{ __('patients.end_date') }}
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @forelse($paginatedConditions as $condition)
                    <div class="record-inner-card">
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <input type="checkbox" class="default-checkbox w-5 h-5">
                            </div>

                            <div class="record-inner-column !pl-4 flex-1">
                                <div class="record-inner-label">{{ __('patients.code_and_name') }}</div>
                                <div class="record-inner-value text-[17px] font-semibold text-gray-900 dark:text-gray-100">
                                    {{ 
                                        data_get($condition, 'code.coding.0.code') ?? '-' 
                                    }} - {{ 
                                        data_get( $this->dictionaries, 'eHealth/ICPC2/condition_codes.' . data_get($condition, 'code.coding.0.code') ?? '-')
                                    }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full md:w-[180px] shrink-0">
                                <div class="record-inner-label">{{ __('patients.status_clinical') }}</div>
                                <div>
                                    <span class="badge-green">
                                        {{ data_get(
                                            $this->dictionaries,
                                            'eHealth/condition_clinical_statuses.' . data_get($condition, 'clinicalStatus'),
                                            data_get($condition, 'clinicalStatus', '-')
                                        )}}
                                    </span>
                                </div>
                            </div>

                            <div class="record-inner-action-col border-l border-gray-200 dark:border-gray-700 w-16 flex items-center justify-center shrink-0 h-full relative">
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
                                            class="record-inner-action-btn transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/50 p-2 rounded-lg"
                                    >
                                        @icon('edit-user-outline', 'w-6 h-6 text-gray-700 dark:text-gray-300')
                                    </button>

                                    <div x-show="open"
                                        x-cloak
                                        x-ref="panel"
                                        x-transition.origin.top.right
                                        @click.outside="close($refs.button)"
                                        :id="$id('dropdown-button')"
                                        class="absolute right-0 mt-2 w-56 rounded-md bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 shadow-lg z-50 py-1"
                                    >
                                        <button @click="close($refs.button)"
                                                class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                                        >
                                            @icon('eye', 'w-5 h-5 text-gray-500')
                                            {{ __('patients.view_details') }}
                                        </button>

                                        <button @click="close($refs.button)"
                                                class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                                        >
                                            @icon('alert-circle', 'w-5 h-5 text-gray-500')
                                            {{ __('patients.status.entered_in_error') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-body grid grid-cols-1 xl:grid-cols-[2.2fr_1.5fr_minmax(280px,1fr)] divide-y xl:divide-y-0 xl:divide-x divide-gray-200 dark:divide-gray-700 !p-0">
                            <div class="p-3.5 pl-4 overflow-hidden">
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3">
                                    <div class="space-y-2.5 min-w-0">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">{{ __('forms.type') }}</div>
                                            <div class="record-inner-value text-[14px] font-semibold">
                                                {{ data_get($this->dictionaries, 'eHealth/report_origins.' . data_get($condition, 'reportOrigin.coding.0.code')) ?? '-' }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">{{ __('patients.doctor') }}</div>
                                            <div class="record-inner-value text-[14px] font-semibold break-words">
                                                {{ data_get($condition, 'asserter.displayValue') ?? '-'}}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="space-y-2.5 min-w-0">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">{{ __('patients.verification_status') }}</div>
                                            <div class="record-inner-value text-[14px] font-semibold uppercase">
                                                {{ data_get(
                                                    $this->dictionaries,
                                                    'eHealth/condition_verification_statuses.' . data_get($condition, 'verificationStatus'),
                                                    data_get($condition, 'verificationStatus', '-')
                                                ) }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">{{ __('patients.condition') }}</div>
                                            <div class="record-inner-value text-[14px] font-semibold break-words">
                                                {{ data_get(
                                                    $this->dictionaries,
                                                    data_get($condition, 'severity.coding.0.system') . '.' . data_get($condition, 'severity.coding.0.code'),
                                                    data_get($condition, 'severity.coding.0.code', '-')
                                                ) }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="space-y-2.5 min-w-0">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">{{ __('patients.body_part') }}</div>
                                            <div class="record-inner-value text-[14px] font-semibold break-words">
                                            @forelse(data_get($condition, 'bodySites', []) as $bodySite)
                                                <div>
                                                    {{ data_get(
                                                        $this->dictionaries,
                                                        data_get($bodySite, 'coding.0.system') . '.' . data_get($bodySite, 'coding.0.code'),
                                                        data_get($bodySite, 'coding.0.code', '-')
                                                    ) }}
                                                </div>
                                            @empty
                                                -
                                            @endforelse
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">{{ __('patients.start_date') }}</div>
                                            <div class="record-inner-value text-[14px] font-semibold">
                                                {{ optional(\Carbon\Carbon::make(data_get($condition, 'onsetDate')))->format(config('app.date_format')) ?? '-' }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="space-y-2.5 min-w-0">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">{{ __('patients.created') }}</div>
                                            <div class="record-inner-value text-[14px] font-semibold">
                                                {{ optional(\Carbon\Carbon::make(data_get($condition, 'assertedDate')))->format(config('app.date_format')) ?? '-' }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="p-4 bg-gray-50/5 dark:bg-gray-800/20">
                                <div class="record-inner-label font-bold text-gray-900 dark:text-gray-100 mb-2.5 text-[12px]">{{ __('patients.evidence') }}:</div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                                    <ul class="space-y-2">
                                        <li class="flex items-start gap-1.5 text-[13px] leading-tight">
                                            <span class="w-1 h-1 rounded-full bg-gray-400 mt-1.5 shrink-0"></span>
                                            <div class="min-w-0">
                                                <div class="text-gray-500 dark:text-gray-400 text-[10px] mb-0.5 uppercase">{{ __('patients.conditions') }}:</div>
                                                <div class="text-gray-800 dark:text-gray-200 font-semibold break-words whitespace-normal">
                                                    @forelse(data_get($condition, 'evidences', []) as $evidence)
                                                        @forelse(data_get($evidence, 'codes', []) as $code)
                                                            <p>
                                                                {{ data_get($code, 'coding.0.code', '—') }}
                                                                -
                                                                {{ data_get(
                                                                    $this->dictionaries,
                                                                    data_get($code, 'coding.0.system') . '.' . data_get($code, 'coding.0.code')
                                                                ) ?? '-' }}
                                                            </p>
                                                        @empty
                                                            <p>—</p>
                                                        @endforelse
                                                    @empty
                                                        <p>—</p>
                                                    @endforelse
                                                </div>
                                            </div>
                                        </li>
                                    </ul>
                                    <ul class="space-y-2">
                                        <li class="flex items-start gap-1.5 text-[13px] leading-tight">
                                            <span class="w-1 h-1 rounded-full bg-gray-400 mt-1.5 shrink-0"></span>
                                            <div class="min-w-0">
                                                <div class="text-gray-500 dark:text-gray-400 text-[10px] mb-0.5 uppercase">{{ __('patients.evidence_observations') }}:</div>
                                                <div class="text-gray-800 dark:text-gray-200 font-semibold break-all leading-relaxed">
                                                    @forelse(data_get($condition, 'evidences', []) as $evidence)
                                                        @forelse(data_get($evidence, 'details', []) as $detail)
                                                            <p>
                                                                {{ data_get($detail, 'displayValue')
                                                                    ?: data_get($detail, 'identifier.value', '-') }}
                                                            </p>
                                                        @empty
                                                            <p>—</p>
                                                        @endforelse
                                                    @empty
                                                        <p>—</p>
                                                    @endforelse
                                                </div>
                                            </div>
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <div class="p-3.5 px-4 overflow-hidden flex flex-col justify-center">
                                <div class="space-y-4">
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">ID ECO3</div>
                                        <div class="record-inner-id-value text-[13px] break-all whitespace-normal leading-normal">
                                            {{ data_get($condition, 'uuid') ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">{{ __('patients.medical_record_id') }}</div>
                                        <div class="record-inner-id-value text-[13px] break-all whitespace-normal leading-normal">
                                            {{ data_get($condition, 'context.identifier.value') ?? '-' }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                     @empty
                    <div class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-6 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('diagnosticReport.condotions_not_found') }}
                    </div>
                @endforelse
            </div>
            <div class="mt-8">
                {{ $paginatedConditions->links() }}
            </div>
        </div>
    </div>

    <x-forms.loading />
</x-layouts.patient>
