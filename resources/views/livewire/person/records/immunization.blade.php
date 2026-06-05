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
                <p>{{ __('patients.immunization_search') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group relative"
                    x-data="{
                        open: false,
                        search: '',
                        selected: $wire.entangle('filterVaccine'),

                        get options() {
                            return $wire.get('filterVaccineOptions') ?? [];
                        },

                        get filteredOptions() {
                            if (!this.search.trim()) {
                                return this.options;
                            }

                            const needle = this.search.toLowerCase();

                            return this.options.filter((option) => {
                                const label = (option.label ?? '').toLowerCase();
                                const value = (option.value ?? '').toLowerCase();
                                const description = (option.description ?? '').toLowerCase();

                                return label.includes(needle)
                                    || value.includes(needle)
                                    || description.includes(needle);
                            });
                        },

                        get selectedOption() {
                            return this.options.find((option) => option.value === this.selected);
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
                               name="filterVaccineSearch"
                               id="filterVaccineSearch"
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

                        <label for="filterVaccineSearch" class="label">
                            {{ __('patients.vaccine') }}
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
                                    {{ __('forms.nothing_found') }}
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
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

                <div class="relative"
                     x-data="{ openGroupActions: false }"
                     @click.outside="openGroupActions = false"
                >
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

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="immunization-search-filters" class="mb-8">
                <div class="form-row-3 mb-6">

                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="filterDateFrom"
                                   type="text"
                                   name="filterDateFrom"
                                   id="filterDateFrom"
                                   class="datepicker-input with-leading-icon input peer w-full"
                                   placeholder=" "
                                   autocomplete="off"
                            />

                            <label for="filterDateFrom" class="wrapped-label">
                                {{ __('patients.date_from') }}
                            </label>
                        </div>
                    </div>

                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="filterDateTo"
                                   type="text"
                                   name="filterDateTo"
                                   id="filterDateTo"
                                   class="datepicker-input with-leading-icon input peer w-full"
                                   placeholder=" "
                                   autocomplete="off"
                            />

                            <label for="filterDateTo" class="wrapped-label">
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
                                    const label = (option.label ?? '').toLowerCase();
                                    const value = (option.value ?? '').toLowerCase();
                                    const description = (option.description ?? '').toLowerCase();

                                    return label.includes(needle)
                                        || value.includes(needle)
                                        || description.includes(needle);
                                });
                            },

                            get selectedOption() {
                                return this.options.find((option) => option.value === this.selected);
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
                                {{ __('patients.episode_id') }}
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
                                    const label = (option.label ?? '').toLowerCase();
                                    const value = (option.value ?? '').toLowerCase();
                                    const description = (option.description ?? '').toLowerCase();

                                    return label.includes(needle)
                                        || value.includes(needle)
                                        || description.includes(needle);
                                });
                            },

                            get selectedOption() {
                                return this.options.find((option) => option.value === this.selected);
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
                                        {{ __('forms.nothing_found') }}
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @php
                $dictionaryDisplay = function (string $dictionaryName, $code, $fallback = null): string {
                    if (!$code) {
                        return $fallback ?: '—';
                    }

                    $value = data_get($this->dictionaries, $dictionaryName . '.' . $code);

                    if (is_array($value)) {
                        $value = data_get($value, 'name') ?: data_get($value, 'description');
                    }

                    return $value ?: ($fallback ?: $code);
                };

                $dictionaryCodeDisplay = function (string $dictionaryName, $code, $fallback = null) use ($dictionaryDisplay): string {
                    if (!$code) {
                        return $fallback ?: '—';
                    }

                    $value = $dictionaryDisplay($dictionaryName, $code, $fallback ?: $code);

                    return collect([$code, $value !== $code ? $value : null])
                        ->filter()
                        ->implode(' | ') ?: ($fallback ?: '—');
                };

                $codeableConceptDisplay = function ($concept, string $dictionaryName) use ($dictionaryDisplay): string {
                    $code = data_get($concept, 'coding.0.code');
                    $text = data_get($concept, 'text');

                    return $dictionaryDisplay($dictionaryName, $code, $text ?: $code);
                };

                $codeableConceptCodeDisplay = function ($concept, string $dictionaryName) use ($dictionaryCodeDisplay): string {
                    $code = data_get($concept, 'coding.0.code') ?: data_get($concept, 'code');
                    $text = data_get($concept, 'text');

                    return $dictionaryCodeDisplay($dictionaryName, $code, $text ?: $code);
                };
            @endphp

            <div class="space-y-4">
                @forelse($paginatedImmunizations as $immunization)
                    <div class="record-inner-card">
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <input type="checkbox" class="default-checkbox w-5 h-5">
                            </div>

                            <div class="record-inner-column !pl-4 flex-1">
                                <div class="record-inner-label">
                                    {{ __('patients.vaccine') }}
                                </div>

                                <div class="record-inner-value text-[17px] font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $codeableConceptCodeDisplay(data_get($immunization, 'vaccineCode'), 'eHealth/vaccine_codes') }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full md:w-[180px] shrink-0">
                                <div class="record-inner-label">
                                    {{ __('forms.status.label') }}
                                </div>

                                <div>
                                    <span class="badge-green">
                                        {{ $dictionaryDisplay('eHealth/immunization_statuses', data_get($immunization, 'status')) }}
                                    </span>
                                </div>
                            </div>

                            <div
                                class="record-inner-action-col border-l border-gray-200 dark:border-gray-700 w-16 flex items-center justify-center shrink-0 h-full relative">
                                <div x-data="{
                                    open: false,
                                    toggle() {
                                        if (this.open) {
                                            return this.close();
                                        }
                                        this.$refs.button.focus();
                                        this.open = true;
                                    },
                                    close(focusAfter) {
                                        if (!this.open) {
                                            return;
                                        }
                                        this.open = false;
                                        focusAfter && focusAfter.focus();
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

                        <div class="record-inner-body">
                            <div class="record-inner-grid-container">
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-3">
                                    <div class="space-y-2.5 min-w-0">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px]">
                                                {{ __('patients.dosage') }}
                                            </div>

                                            <div class="record-inner-value text-[14px] font-semibold">
                                                @php
                                                    $doseValue = data_get($immunization, 'doseQuantity.value');
                                                    $doseUnit = $dictionaryDisplay('eHealth/immunization_dosage_units', data_get($immunization, 'doseQuantity.code'), data_get($immunization, 'doseQuantity.unit'));
                                                @endphp
                                                {{ collect([$doseValue, $doseUnit !== '—' ? $doseUnit : null])->filter()->implode(' ') ?: '—' }}
                                            </div>
                                        </div>

                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px]">
                                                {{ __('patients.manufacturer_and_lot_number') }}
                                            </div>

                                            <div class="record-inner-value text-[14px] font-semibold break-words">
                                                @php
                                                    $manufacturer = data_get($immunization, 'manufacturer');
                                                    $lotNumber = data_get($immunization, 'lotNumber');
                                                @endphp
                                                {{ collect([$manufacturer, $lotNumber ? '(' . $lotNumber . ')' : null])->filter()->implode(' ') ?: '—' }}
                                            </div>
                                        </div>

                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px]">
                                                {{ __('patients.performer') }}
                                            </div>

                                            <div class="record-inner-value text-[14px] font-semibold break-words">
                                                {{ data_get($immunization, 'performer.displayValue', '—') }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="space-y-2.5 min-w-0">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px]">
                                                {{ __('patients.route') }}
                                            </div>

                                            <div class="record-inner-value text-[14px] font-semibold break-words">
                                                {{ $codeableConceptDisplay(data_get($immunization, 'route'), 'eHealth/vaccination_routes') }}
                                            </div>
                                        </div>

                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px]">
                                                {{ __('patients.body_part') }}
                                            </div>

                                            <div class="record-inner-value text-[14px] font-semibold break-words">
                                                {{ $codeableConceptDisplay(data_get($immunization, 'site'), 'eHealth/immunization_body_sites') }}
                                            </div>
                                        </div>

                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px]">
                                                {{ __('patients.date_time_entered') }}
                                            </div>

                                            <div class="record-inner-value text-[14px] font-semibold">
                                                {{ optional(\Carbon\Carbon::make(data_get($immunization, 'ehealthInsertedAt')))?->format('d.m.Y H:i') ?? '—' }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="space-y-2.5 min-w-0">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px]">
                                                {{ __('patients.reason') }}
                                            </div>

                                            <div class="record-inner-value text-[14px] font-semibold break-words">
                                                {{ $codeableConceptDisplay(data_get($immunization, 'explanation.reasons.0'), 'eHealth/reason_explanations') }}
                                            </div>
                                        </div>

                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px]">
                                                {{ __('patients.was_performed') }}
                                            </div>

                                            <div class="record-inner-value text-[14px] font-semibold">
                                                {{ data_get($immunization, 'notGiven') ? 'Ні' : 'Так' }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="space-y-2.5 min-w-0">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px]">
                                                {{ __('patients.reactions') }}
                                            </div>

                                            <div class="record-inner-value text-[14px]">
                                                {{ data_get($immunization, 'reactions.0.detail.displayValue', data_get($immunization, 'reactions.0.displayValue', '—')) }}
                                            </div>
                                        </div>

                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px]">
                                                {{ __('patients.date_time_performed') }}
                                            </div>

                                            <div class="record-inner-value text-[14px] font-semibold">
                                                {{ optional(\Carbon\Carbon::make(data_get($immunization, 'date')))->format('d.m.Y H:i') ?? '-' }}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700/50">
                                <div class="record-inner-label font-bold text-gray-900 dark:text-gray-100 mb-2">
                                    {{ __('patients.vaccination_protocol') }}:
                                </div>

                                @php($protocol = data_get($immunization, 'vaccinationProtocols.0', data_get($immunization, 'immunizationProtocols.0', [])))

                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-x-4 gap-y-3">
                                    <ul class="space-y-2.5">
                                        <li class="flex items-start gap-1.5 text-[13px] leading-tight">
                                            <span class="w-1 h-1 rounded-full bg-gray-400 mt-1.5 shrink-0"></span>
                                            <div class="min-w-0">
                                                <div class="text-gray-500 dark:text-gray-400 text-[10px] mb-0">
                                                    {{ __('patients.target_diseases') }}:
                                                </div>
                                                <div class="text-gray-800 dark:text-gray-200 font-semibold break-words">
                                                    {{ collect(data_get($protocol, 'targetDiseases', []))->map(fn($disease) => $codeableConceptDisplay($disease, 'eHealth/vaccination_target_diseases'))->filter(fn($value) => $value !== '—')->join(', ') ?: '—' }}
                                                </div>
                                            </div>
                                        </li>

                                        <li class="flex items-start gap-1.5 text-[13px] leading-tight">
                                            <span class="w-1 h-1 rounded-full bg-gray-400 mt-1.5 shrink-0"></span>
                                            <div class="min-w-0">
                                                <div class="text-gray-500 dark:text-gray-400 text-[10px] mb-0">
                                                    {{ __('patients.protocol_author') }}:
                                                </div>
                                                <div class="text-gray-800 dark:text-gray-200 font-semibold uppercase tracking-wide text-[11px] break-words">
                                                    {{ $codeableConceptDisplay(data_get($protocol, 'authority'), 'eHealth/vaccination_authorities') }}
                                                </div>
                                            </div>
                                        </li>
                                    </ul>

                                    <ul class="space-y-2.5">
                                        <li class="flex items-start gap-1.5 text-[13px] leading-tight">
                                            <span class="w-1 h-1 rounded-full bg-gray-400 mt-1.5 shrink-0"></span>
                                            <div class="min-w-0">
                                                <div class="text-gray-500 dark:text-gray-400 text-[10px] mb-0">
                                                    {{ __('patients.dose_sequence') }}:
                                                </div>
                                                <div class="text-gray-800 dark:text-gray-200 font-semibold">
                                                    {{ data_get($protocol, 'doseSequence', '—') }}
                                                </div>
                                            </div>
                                        </li>

                                        <li class="flex items-start gap-1.5 text-[13px] leading-tight">
                                            <span class="w-1 h-1 rounded-full bg-gray-400 mt-1.5 shrink-0"></span>
                                            <div class="min-w-0">
                                                <div class="text-gray-500 dark:text-gray-400 text-[10px] mb-0">
                                                    {{ __('patients.immunization_series') }}:
                                                </div>
                                                <div class="text-gray-800 dark:text-gray-200 font-semibold">
                                                    {{ data_get($protocol, 'series', '—') }}
                                                </div>
                                            </div>
                                        </li>
                                    </ul>

                                    <ul class="space-y-2.5">
                                        <li class="flex items-start gap-1.5 text-[13px] leading-tight">
                                            <span class="w-1 h-1 rounded-full bg-gray-400 mt-1.5 shrink-0"></span>
                                            <div class="min-w-0">
                                                <div class="text-gray-500 dark:text-gray-400 text-[10px] mb-0">
                                                    {{ __('patients.series_of_doses_by_protocol') }}:
                                                </div>
                                                <div class="text-gray-800 dark:text-gray-200 font-semibold">
                                                    {{ data_get($protocol, 'seriesDoses', '—') }}
                                                </div>
                                            </div>
                                        </li>

                                        <li class="flex items-start gap-1.5 text-[13px] leading-tight">
                                            <span class="w-1 h-1 rounded-full bg-gray-400 mt-1.5 shrink-0"></span>
                                            <div class="min-w-0">
                                                <div class="text-gray-500 dark:text-gray-400 text-[10px] mb-0">
                                                    {{ __('patients.protocol_description') }}:
                                                </div>
                                                <div class="text-gray-800 dark:text-gray-200 font-semibold break-words">
                                                    {{ data_get($protocol, 'description', '—') }}
                                                </div>
                                            </div>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                            <div class="record-inner-id-col">
                                <div class="min-w-0">
                                    <div class="record-inner-label">
                                        ID ЕСОЗ
                                    </div>

                                    <div class="record-inner-id-value">
                                        {{ data_get($immunization, 'uuid', '—') }}
                                    </div>
                                </div>

                                <div class="min-w-0">
                                    <div class="record-inner-label">
                                        {{ __('patients.medical_record_id') }}
                                    </div>

                                    <div class="record-inner-id-value">
                                        {{ data_get($immunization, 'context.identifier.value', '—') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div
                        class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-6 text-sm text-gray-500 dark:text-gray-400">
                        {{ __('patients.immunizations_not_found') }}
                    </div>
                @endforelse
            </div>
            <div class="mt-8">
                {{ $paginatedImmunizations->links() }}
            </div>
        </div>
    </div>

    <x-forms.loading/>
</x-layouts.patient>
