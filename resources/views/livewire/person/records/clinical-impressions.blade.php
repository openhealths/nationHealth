@use('App\Enums\Person\ClinicalImpressionStatus')

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
                <p>{{ __('patients.clinical_impression_search') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group relative"
                     x-data="{
                        open: false,
                        selected: $wire.entangle('filterCode'),

                        get options() {
                            return $wire.get('filterCodeOptions') ?? [];
                        },

                        get selectedOption() {
                            return this.options.find((option) => String(option.value) === String(this.selected));
                        },

                        get selectedLabel() {
                            return this.selectedOption ? this.selectedOption.label : '';
                        },

                        selectOption(option) {
                            this.selected = option.value;
                            this.open = false;
                        },

                        clearOption() {
                            this.selected = '';
                            this.open = false;
                        }
                    }"
                     @click.outside="open = false"
                >
                    <div class="relative">
                        <input type="text"
                               name="filterCodeSearch"
                               id="filterCodeSearch"
                               class="input peer w-full pr-10 cursor-pointer"
                               placeholder=" "
                               autocomplete="off"
                               readonly
                               :value="selectedLabel"
                               @focus="open = true"
                               @click="open = true"
                        />

                        <label for="filterCodeSearch" class="label">
                            {{ __('patients.code_and_name') }}
                        </label>

                        <button type="button"
                                x-show="selected"
                                x-cloak
                                @click.stop="clearOption()"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                        >
                            @icon('close', 'w-4 h-4')
                        </button>

                        <div x-show="open"
                             x-transition
                             x-cloak
                             class="absolute left-0 right-0 top-full mt-1 z-50 max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800"
                        >
                            <template x-if="options.length > 0">
                                <div>
                                    <template x-for="option in options" :key="option.value">
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

                            <template x-if="options.length === 0">
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

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="clinical-impressions-search-filters">
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

                            getOptionValue(option) {
                                return option.value ?? option.uuid ?? option.id ?? '';
                            },

                            getOptionLabel(option) {
                                return option.name
                                    ?? option.title
                                    ?? option.label
                                    ?? option.value
                                    ?? option.uuid
                                    ?? '';
                            },

                            getOptionDescription(option) {
                                const value = this.getOptionValue(option);

                                return option.description ?? option.uuid ?? option.id ?? value;
                            },

                            get filteredOptions() {
                                if (!this.search.trim()) {
                                    return this.options.slice(0, 10);
                                }

                                const needle = this.search.toLowerCase();

                                return this.options.filter((option) => {
                                    const label = String(this.getOptionLabel(option) ?? '').toLowerCase();
                                    const value = String(this.getOptionValue(option) ?? '').toLowerCase();
                                    const description = String(this.getOptionDescription(option) ?? '').toLowerCase();

                                    return label.includes(needle)
                                        || value.includes(needle)
                                        || description.includes(needle);
                                })
                                .slice(0, 10);
                            },

                            get selectedOption() {
                                return this.options.find((option) => String(this.getOptionValue(option)) === String(this.selected));
                            },

                            selectOption(option) {
                                this.selected = this.getOptionValue(option);
                                this.search = this.getOptionLabel(option);
                                this.open = false;
                            },

                            clearOption() {
                                this.selected = '';
                                this.search = '';
                                this.open = false;
                            },

                            init() {
                                this.search = this.selectedOption ? this.getOptionLabel(this.selectedOption) : '';

                                this.$watch('selected', () => {
                                    this.search = this.selectedOption ? this.getOptionLabel(this.selectedOption) : '';
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
                                        <template x-for="option in filteredOptions" :key="getOptionValue(option)">
                                            <button type="button"
                                                    class="w-full px-3 py-2 text-left text-sm hover:bg-gray-100 dark:hover:bg-gray-700"
                                                    @click="selectOption(option)"
                                            >
                                                <div class="font-medium text-gray-900 dark:text-gray-100"
                                                     x-text="getOptionLabel(option) || 'Без назви'"
                                                ></div>

                                                <div class="text-xs text-gray-500 break-all"
                                                     x-text="getOptionDescription(option)"
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

                    <div class="form-group group relative max-w-[220px] w-full"
                         x-data="{
                            open: false,
                            selected: $wire.entangle('filterStatus'),
                            options: @js(collect(ClinicalImpressionStatus::options())->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()),

                            get selectedOption() {
                                return this.options.find((option) => String(option.value) === String(this.selected));
                            },

                            get selectedLabel() {
                                return this.selectedOption ? this.selectedOption.label : '';
                            },

                            selectOption(option) {
                                this.selected = option.value;
                                this.open = false;
                            },

                            clearOption() {
                                this.selected = '';
                                this.open = false;
                            }
                        }"
                         @click.outside="open = false"
                    >
                        <div class="relative">
                            <input type="text"
                                   name="filterStatusSearch"
                                   id="filterStatusSearch"
                                   class="input peer w-full pr-10 cursor-pointer"
                                   placeholder=" "
                                   autocomplete="off"
                                   readonly
                                   :value="selectedLabel"
                                   @focus="open = true"
                                   @click="open = true"
                            />

                            <label for="filterStatusSearch" class="label">
                                {{ __('forms.status.label') }}
                            </label>

                            <button type="button"
                                    x-show="selected"
                                    x-cloak
                                    @click.stop="clearOption()"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                            >
                                @icon('close', 'w-4 h-4')
                            </button>

                            <div x-show="open"
                                 x-transition
                                 x-cloak
                                 class="absolute left-0 right-0 top-full mt-1 z-50 max-h-64 overflow-y-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800"
                            >
                                <template x-if="options.length > 0">
                                    <div>
                                        <template x-for="option in options" :key="option.value">
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

                                <template x-if="options.length === 0">
                                    <div class="px-3 py-2 text-sm text-gray-500">
                                        {{ __('patients.nothing_found') }}
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-row-3 mb-9">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="filterEffectiveDateFrom"
                                   type="text"
                                   name="filterEffectiveDateFrom"
                                   id="filterEffectiveDateFrom"
                                   class="datepicker-input with-leading-icon input peer w-full"
                                   placeholder=" "
                                   autocomplete="off"
                            />

                            <label for="filterEffectiveDateFrom" class="wrapped-label">
                                {{ __('patients.start_date') }}
                            </label>
                        </div>
                    </div>

                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="filterEffectiveDateTo"
                                   type="text"
                                   name="filterEffectiveDateTo"
                                   id="filterEffectiveDateTo"
                                   class="datepicker-input with-leading-icon input peer w-full"
                                   placeholder=" "
                                   autocomplete="off"
                            />

                            <label for="filterEffectiveDateTo" class="wrapped-label">
                                {{ __('patients.end_date') }}
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @if(count($this->clinicalImpressions) > 0)
                    @include('livewire.person.records.parts.clinical-impressions')
                @else
                    <x-nothing-found :description="null" />
                @endif
            </div>

            <div class="mt-8">
                {{ $paginatedClinicalImpressions->links() }}
            </div>
        </div>
    </div>

    <x-forms.loading />
</x-layouts.patient>
