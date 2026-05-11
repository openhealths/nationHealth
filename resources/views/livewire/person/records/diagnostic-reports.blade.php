@use(App\Enums\Person\DiagnosticReportStatus)
<x-layouts.patient :personId="$personId" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', \App\Models\MedicalEvents\Sql\DiagnosticReport::class)
            <a href="{{ route('diagnostic-report.create', [legalEntity(), 'personId' => $personId]) }}"
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

        <button wire:click.prevent=""
                type="button"
                class="button-sync flex items-center gap-2 whitespace-nowrap px-5 py-2 text-sm shadow-sm"
        >
            @icon('refresh', 'w-4 h-4')
            {{ __('patients.sync_ehealth_data') }}
        </button>
    </x-slot>

    <div class="breadcrumb-form p-4 shift-content">
        <div class="w-full mt-6" 
            x-data="{ 
                showAdditionalParams: $wire.entangle('showAdditionalParams'),
                modalDiagnosticReport: {
                    categoryCode: $wire.entangle('filterCategory'),
                },
            }"
        >
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('patients.diagnostic_reports_search') }}</p>
            </div>
            
            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <select x-model="modalDiagnosticReport.categoryCode"
                            id="filterCategory"
                            name="filterCategory"
                            class="input-select peer w-full"
                    >
                        <option value="">
                            {{ __('forms.select') }} {{ mb_strtolower(__('forms.category')) }}
                        </option>

                        @foreach($this->dictionaries['eHealth/diagnostic_report_categories'] as $key => $category)
                            <option value="{{ $key }}">{{ $category }}</option>
                        @endforeach
                    </select>

                    <label for="filterCategory" class="label pointer-events-none">
                        {{ __('forms.category') }}
                    </label>
                </div>

               @php
                    $servicesForSearch = collect($this->dictionaries['custom/services'] ?? [])
                        ->map(fn ($service) => [
                            'id' => data_get($service, 'id'),
                            'code' => data_get($service, 'code'),
                            'name' => data_get($service, 'name'),
                            'category' => data_get($service, 'category'),
                            'categoryCode' => data_get($service, 'categoryCode'),
                            'category_code' => data_get($service, 'category_code'),
                        ])
                        ->values();
                @endphp

                <div class="form-group group relative"
                    x-data="{
                        open: false,
                        search: '',
                        selected: $wire.entangle('filterCode'),
                        services: @js($servicesForSearch),

                        get filteredServices() {
                            const selectedCategory = String(modalDiagnosticReport.categoryCode ?? '');
                            const needle = this.search.trim().toLowerCase();

                            return this.services
                                .filter((service) => {
                                    const serviceCategory = String(service.category ?? '');
                                    const serviceCategoryCode = String(service.categoryCode ?? '');
                                    const serviceCategorySnake = String(service.category_code ?? '');

                                    const matchesCategory = !selectedCategory
                                        || serviceCategory === selectedCategory
                                        || serviceCategoryCode === selectedCategory
                                        || serviceCategorySnake === selectedCategory;

                                    const name = String(service.name ?? '').toLowerCase();
                                    const code = String(service.code ?? '').toLowerCase();
                                    const id = String(service.id ?? '').toLowerCase();

                                    const matchesSearch = !needle
                                        || name.includes(needle)
                                        || code.includes(needle)
                                        || id.includes(needle);

                                    return matchesCategory && matchesSearch;
                                })
                                .slice(0, 100);
                        },

                        get selectedService() {
                            return this.services.find((service) => String(service.id) === String(this.selected));
                        },

                        makeServiceLabel(service) {
                            return [
                                service.code,
                                service.name,
                            ].filter(Boolean).join(' — ') || service.id;
                        },

                        selectService(service) {
                            this.selected = service.id;
                            this.search = this.makeServiceLabel(service);
                            this.open = false;
                        },

                        clearService() {
                            this.selected = '';
                            this.search = '';
                            this.open = false;
                        },

                        init() {
                            this.search = this.selectedService ? this.makeServiceLabel(this.selectedService) : '';

                            this.$watch('selected', () => {
                                this.search = this.selectedService ? this.makeServiceLabel(this.selectedService) : '';
                            });

                            this.$watch('modalDiagnosticReport.categoryCode', () => {
                                this.clearService();
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
                            {{ __('forms.select') }} {{ mb_strtolower(__('forms.services')) }}
                        </label>

                        <button type="button"
                                x-show="selected || search"
                                x-cloak
                                @click="clearService()"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                        >
                            @icon('close', 'w-4 h-4')
                        </button>

                        <div x-show="open"
                            x-transition
                            x-cloak
                            class="absolute left-0 right-0 top-full mt-1 z-50 max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800"
                        >
                            <template x-if="filteredServices.length > 0">
                                <div>
                                    <template x-for="service in filteredServices" :key="service.id">
                                        <button type="button"
                                                class="w-full px-3 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700"
                                                @click="selectService(service)"
                                        >
                                            <div class="font-medium text-gray-900 dark:text-gray-100"
                                                x-text="makeServiceLabel(service)"
                                            ></div>

                                            <div class="text-xs text-gray-500 break-all"
                                                x-text="service.id"
                                            ></div>
                                        </button>
                                    </template>
                                </div>
                            </template>

                            <template x-if="filteredServices.length === 0">
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

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="diagnostic-reports-search-filters">
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
                                name="filterSpecimenId"
                                id="filterSpecimenId"
                                class="input peer w-full"
                                placeholder=" "
                                autocomplete="off"
                                x-model="search"
                                @focus="open = true"
                                @input="open = true"
                            />

                            <label for="filterSpecimenId" class="label">
                                ID зразка
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

                {{-- TODO: Фільтр по context_episode_id та origin_episode_id реалізований, але наразі у Diagnostic Report ці поля приходить null.
                    Коли в ЕСОЗ/тестових даних з’являться записи з contextEpisode або origin_episode_id, потрібно перевірити,
                    чи коректно API фільтрує Diagnostic Reports за цим параметром. 
                --}}
                <div class="form-row-3 mb-9">
                    <div class="form-group group relative"
                        x-data="{
                            open: false,
                            search: '',
                            selected: $wire.entangle('filterContextEpisodeId'),

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
                                name="episodesId"
                                id="episodesId"
                                class="input peer w-full"
                                placeholder=" "
                                autocomplete="off"
                                x-model="search"
                                @focus="open = true"
                                @input="open = true"
                            />

                            <label for="episodesId" class="label">
                                ID контекстного епізоду
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
                            selected: $wire.entangle('filterOriginEpisodeId'),

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
                                name="originEpisodesId"
                                id="originEpisodesId"
                                class="input peer w-full"
                                placeholder=" "
                                autocomplete="off"
                                x-model="search"
                                @focus="open = true"
                                @input="open = true"
                            />

                            <label for="originEpisodesId" class="label">
                                ID первинного епізоду
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
                        <select wire:model.defer="filterEncounterId"
                                name="filterEncounterId"
                                id="filterEncounterId"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>

                            @foreach($filterEncounterOptions as $encounter)
                                @php
                                    $encounterId = data_get($encounter, 'uuid');

                                    $typeCode = data_get($encounter, 'actions.0.coding.0.code');

                                    $classCode = data_get($encounter, 'class.code');

                                    $encounterLabel = collect([
                                        $typeCode,
                                        $classCode,
                                    ])->filter()->implode(' | ');
                                @endphp

                                @if($encounterId)
                                    <option value="{{ $encounterId }}">
                                        {{ $encounterLabel ?: $encounterId }}
                                    </option>
                                @endif
                            @endforeach
                        </select>

                        <label for="filterEncounterId" class="label">
                            ID взаємодії
                        </label>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @forelse($diagnosticReports as $diagnosticReport)
                    <div class="record-inner-card">
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <input type="checkbox" class="default-checkbox w-5 h-5">
                            </div>

                            <div class="record-inner-column !pl-4 flex-1">
                                <div class="record-inner-label">{{ __('patients.code_and_name') }}</div>
                                <div class="record-inner-value text-[17px] font-semibold text-gray-900 dark:text-gray-100">
                                    {{ data_get($diagnosticReport, 'code.identifier.value') && data_get($diagnosticReport, 'code.displayValue')
                                    ? data_get($diagnosticReport, 'code.identifier.value') . ' | ' . data_get($diagnosticReport, 'code.displayValue')
                                    : '-' }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full md:w-[180px] shrink-0">
                                <div class="record-inner-label">{{ __('patients.status_label') }}</div>
                                <div>
                                    <span class="badge-green">
                                        {{ DiagnosticReportStatus::tryFrom(data_get($diagnosticReport, 'status'))?->label() ?? '-' }}
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

                        <div class="record-inner-body grid grid-cols-1 xl:grid-cols-[2.2fr_1.5fr] divide-y xl:divide-y-0 xl:divide-x divide-gray-200 dark:divide-gray-700 !p-0">
                            <div class="p-3.5 pl-4 overflow-hidden">
                                <div class="grid grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-3">
                                    <div class="space-y-2.5 min-w-0">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">{{ __('patients.category') }}</div>
                                            <div class="record-inner-value text-[14px] font-semibold break-words">
                                                {{ data_get(
                                                    $this->dictionaries,
                                                    'eHealth/diagnostic_report_categories.' . data_get($diagnosticReport, 'category.0.coding.0.code'),
                                                    data_get($diagnosticReport, 'category.0.text', data_get($diagnosticReport, 'category.0.coding.0.code', '—'))
                                                ) }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">{{ __('patients.referrals') }}</div>
                                            <div class="record-inner-value text-[14px] font-semibold break-words">
                                                {{ data_get($diagnosticReport, 'paperReferral.requisition', '—') }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="space-y-2.5 min-w-0">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">{{ __('patients.performer') }}</div>
                                            <div class="record-inner-value text-[14px] font-semibold break-words uppercase">
                                                {{ data_get($diagnosticReport, 'performer.reference.displayValue' ,'-') }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">{{ __('patients.conclusion') }}</div>
                                            <div class="record-inner-value text-[14px] font-semibold break-words">
                                                {{ data_get($diagnosticReport, 'conclusion') ?? '-' }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="space-y-2.5 min-w-0">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">{{ __('patients.created') }}</div>
                                            <div class="record-inner-value text-[14px] font-semibold break-words">
                                                {{ optional(\Carbon\Carbon::make(data_get($diagnosticReport, 'ehealthInsertedAt')))->format('d.m.Y H:i') ?? '-' }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">{{ __('patients.doctor') }}</div>
                                            <div class="record-inner-value text-[14px] font-semibold break-words">
                                                {{ data_get($diagnosticReport, 'recordedBy.displayValue') ?? '-' }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="p-3.5 px-4 overflow-hidden flex flex-col justify-center">
                                <div class="space-y-4">
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            ID ECO3
                                        </div>
                                        <div class="record-inner-id-value text-[13px] break-all whitespace-normal leading-normal">
                                            {{ data_get($diagnosticReport, 'uuid') ?? '-'}}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('patients.medical_record_id') }}
                                        </div>
                                        <div class="record-inner-id-value text-[13px] break-all whitespace-normal leading-normal">
                                            {{ data_get($diagnosticReport, 'encounter.identifier.value') ?? '-' }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    @empty
                    <div class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-6 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('diagnosticReport.diagnostic_report_not_found') }}
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    <x-forms.loading />
</x-layouts.patient>
