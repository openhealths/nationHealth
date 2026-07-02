@use('App\Models\MedicalEvents\Sql\Encounter')
@use('App\Enums\Person\EncounterStatus')
@use('App\Enums\JobStatus')

<x-layouts.patient :personId="$personId" :prepersonId="$prepersonId" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', Encounter::class)
            <a href="{{ $prepersonId
                ? route('prepersons.encounter.create', [legalEntity(), 'preperson' => $prepersonId])
                : route('encounter.create', [legalEntity(), 'person' => $personId]) }}"
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

        @php
            $isSyncing = $syncStatus === JobStatus::PROCESSING->value;
            $isRetryable = $syncStatus === JobStatus::PAUSED->value || $syncStatus === JobStatus::FAILED->value;
        @endphp
        <button @if(!$isSyncing) wire:click="sync" @endif
        type="button"
                @if($isSyncing) disabled @endif
                class="flex items-center gap-2 whitespace-nowrap px-5 py-2 text-sm shadow-sm transition-colors
                @if($isSyncing) button-sync-disabled cursor-not-allowed @else button-sync @endif"
        >
            @icon('refresh', 'w-4 h-4')
            <span>{{ $isRetryable ? __('forms.sync_retry') : __('forms.synchronise_with_eHealth') }}</span>
        </button>
    </x-slot>

    <div class="breadcrumb-form p-4 shift-content">
        <div class="w-full mt-6" x-data="{ showAdditionalParams: $wire.entangle('showAdditionalParams') }">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('patients.encounter_search') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <div class="datepicker-wrapper">
                        <input wire:model="filterStartDateRange"
                               type="text"
                               name="filterStartDateRange"
                               id="filterStartDateRange"
                               class="daterangepicker-uk with-leading-icon input peer w-full"
                               placeholder=" "
                               autocomplete="off"
                        />
                        <label for="filterStartDateRange" class="wrapped-label">
                            Дата початку від - до
                        </label>
                    </div>
                </div>

                <div class="form-group group">
                    <div class="datepicker-wrapper">
                        <input wire:model="filterEndDateRange"
                               type="text"
                               name="filterEndDateRange"
                               id="filterEndDateRange"
                               class="daterangepicker-uk with-leading-icon input peer w-full"
                               placeholder=" "
                               autocomplete="off"
                        />
                        <label for="filterEndDateRange" class="wrapped-label">
                            Дата завершення від - до
                        </label>
                    </div>
                </div>

                <x-forms.combobox :options="$episodes"
                                  bind="filterEpisodeId"
                                  bindValue="uuid"
                                  bindParam="name"
                                  :label="__('patients.episodes')"
                />
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

            <div x-show="showAdditionalParams" x-transition x-cloak>
                <div class="form-row-3 mb-9">
                    <x-forms.combobox :options="$incomingReferrals"
                                      bind="filterIncomingReferralId"
                                      bindValue="uuid"
                                      bindParam="displayValue"
                                      :label="__('patients.referrals')"
                    />

                    <x-forms.combobox :options="$originEpisodes"
                                      bind="filterOriginEpisodeId"
                                      bindValue="uuid"
                                      bindParam="displayValue"
                                      :label="__('patients.origin_episode')"
                    />
                </div>
            </div>

            <div class="space-y-4">
                @forelse($this->paginatedEncounters->items() as $encounter)
                    <div class="record-inner-card" wire:key="encounter-{{ data_get($encounter, 'uuid') }}">
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <input type="checkbox" class="default-checkbox w-5 h-5">
                            </div>

                            <div class="record-inner-column flex-1">
                                <div class="record-inner-label">{{ __('forms.date') }}</div>
                                <div class="record-inner-value text-[16px]">
                                    {{ data_get($encounter, 'period.start') }}
                                    - {{ data_get($encounter, 'period.end') }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
                                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                                <div>
                                    @php($status = EncounterStatus::from(data_get($encounter, 'status')))
                                    <span @class([$status->color()])>
                                        {{ $status->label() }}
                                    </span>
                                </div>
                            </div>

                            <div class="record-inner-action-col">
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
                                        @if(!empty(data_get($encounter, 'id')))
                                            <a href="{{ $prepersonId
                                                ? route('prepersons.encounter.edit', [legalEntity(), 'preperson' => $prepersonId, 'encounterId' => data_get($encounter, 'id')])
                                                : route('encounter.edit', [legalEntity(), 'person' => $personId, 'encounterId' => data_get($encounter, 'id')]) }}"
                                               wire:navigate
                                               class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                                            >
                                                @icon('eye', 'w-5 h-5 text-gray-500')
                                                {{ __('patients.view_details') }}
                                            </a>
                                        @endif

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
                                <div class="grid grid-cols-2 xl:grid-cols-4 gap-4">
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('patients.class') }}</div>
                                        <div class="record-inner-value text-[14px]">
                                            {{ $this->dictionaryLabel($encounter, 'class') }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('forms.type') }}</div>
                                        <div class="record-inner-value text-[14px]">
                                            {{ $this->dictionaryLabel($encounter, 'type') }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('patients.doctor_speciality') }}</div>
                                        <div class="record-inner-value text-[14px]">
                                            {{ data_get($encounter, 'performer.displayValue', '-') }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('patients.referrals') }}</div>
                                        <div class="record-inner-value text-[14px]">
                                            {{ data_get($encounter, 'paperReferral.requisition', '-') }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="record-inner-id-col">
                                <div class="min-w-0">
                                    <div class="record-inner-label">
                                        {{ __('patients.filter_code') }}
                                    </div>
                                    <div class="record-inner-id-value">
                                        {{ data_get($encounter, 'uuid', '-') }}
                                    </div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label">
                                        ID {{ __('care-plan.episode') }}
                                    </div>
                                    <div class="record-inner-id-value">
                                        {{ data_get($encounter, 'episode.identifier.value', '-') }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <x-nothing-found :description="null" />
                @endforelse

                <div class="mt-6">
                    {{ $this->paginatedEncounters->links() }}
                </div>
            </div>
        </div>
    </div>

    <x-forms.loading/>
</x-layouts.patient>
