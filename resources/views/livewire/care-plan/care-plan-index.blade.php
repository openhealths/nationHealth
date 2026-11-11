@use('App\Enums\CarePlanStatusFilter')
@use('App\Enums\CarePlanCompletenessFilter')
@use('App\Livewire\CarePlan\CarePlanIndex')
@use('App\Enums\CarePlanStatus')
@use('App\Models\CarePlan')

<div data-livewire-background-actions="checkSyncStatus">
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    @if ($syncBatchIds !== [])
        <div wire:poll.3s="checkSyncStatus" class="mb-4 text-sm text-gray-600 dark:text-gray-300">
            {{ __('care-plan.sync_running') }}
        </div>
    @endif
    <x-header-navigation class="items-start" x-data="{ showFilter: false }">
        <x-slot name="title">{{ __('care-plan.care_plans') }}</x-slot>

        <div class="mt-3 ml-0 flex flex-col gap-2 self-start sm:flex-row sm:flex-wrap">
            <a href="{{ route('care-plans.create', legalEntity()) }}" class="button-primary">
                + {{ __('care-plan.new_care_plan') }}
            </a>

            <button
                wire:click.prevent="sync"
                type="button"
                class="button-sync flex items-center gap-2 px-5 py-2 text-sm whitespace-nowrap shadow-sm"
                wire:loading.attr="disabled"
                wire:target="sync"
                @disabled($syncBatchIds !== [])
            >
                <span wire:loading.remove wire:target="sync">
                    @icon('refresh', 'w-4 h-4')
                </span>
                <span wire:loading wire:target="sync" class="animate-spin">
                    @icon('refresh', 'w-4 h-4')
                </span>
                <span>{{ __('forms.synchronise_with_eHealth') }}</span>
            </button>
        </div>
    </x-header-navigation>

    <section class="section-form mt-4">
        <div class="shift-content w-full max-w-7xl px-4 py-6 lg:py-10">
            {{-- Search and Filters Section --}}
            <div class="mb-6 w-full" x-data="{ showAdditionalParams: $wire.entangle('showAdditionalParams') }">
                <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                    @icon('search-outline', 'w-4.5 h-4.5')
                    <p>{{ __('care-plan.search_care_plan') }}</p>
                </div>

                <div class="form-row-4 mb-6">
                    <div class="form-group group">
                        <div class="relative">
                            <input
                                wire:model="searchRequisition"
                                wire:keydown.enter="search"
                                type="text"
                                name="searchRequisition"
                                id="searchRequisition"
                                class="input peer w-full"
                                placeholder=" "
                                autocomplete="off"
                            />
                            <label for="searchRequisition" class="label"> {{ __('care-plan.medical_number') }} </label>
                            <button
                                type="button"
                                wire:click="$set('searchRequisition', '')"
                                class="absolute top-1/2 right-3 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                x-show="$wire.searchRequisition"
                            >
                                @icon('close', 'w-4 h-4')
                            </button>
                        </div>
                    </div>

                    <div class="form-group group">
                        <select
                            wire:model="filterStatus"
                            name="filterStatus"
                            id="filterStatus"
                            class="input-select peer w-full"
                        >
                            <option value="{{ CarePlanStatusFilter::CURRENT->value }}">{{ __('care-plan.current_and_drafts') }}</option>
                            <option value="{{ CarePlanStatusFilter::ALL->value }}">{{ __('care-plan.all_statuses') }}</option>
                            @foreach (CarePlanStatus::filterOptions() as $status)
                                <option value="{{ $status->value }}">{{ $status->label() }}</option>
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
                </div>

                <div x-show="showAdditionalParams" x-transition x-cloak wire:key="care-plans-search-filters">
                    <div class="form-row-4 mb-6">
                        <div class="form-group group">
                            <div class="datepicker-wrapper">
                                <input
                                    wire:model="filterStartDateRange"
                                    wire:keydown.enter="search"
                                    type="text"
                                    name="filterStartDateRange"
                                    id="filterStartDateRange"
                                    class="datepicker-input with-leading-icon input peer w-full"
                                    placeholder=" "
                                    autocomplete="off"
                                />
                                <label for="filterStartDateRange" class="wrapped-label">
                                    {{ __('care-plan.filter_start_date_range') }}
                                </label>
                            </div>
                        </div>

                        <div class="form-group group">
                            <div class="datepicker-wrapper">
                                <input
                                    wire:model="filterEndDateRange"
                                    wire:keydown.enter="search"
                                    type="text"
                                    name="filterEndDateRange"
                                    id="filterEndDateRange"
                                    class="datepicker-input with-leading-icon input peer w-full"
                                    placeholder=" "
                                    autocomplete="off"
                                />
                                <label for="filterEndDateRange" class="wrapped-label">
                                    {{ __('care-plan.filter_end_date_range') }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group group mb-6">
                <select
                    wire:model.live="filterCompleteness"
                    id="filterCompleteness"
                    class="input-select peer w-full"
                >
                    <option value="{{ CarePlanCompletenessFilter::COMPLETE->value }}">{{ __('care-plan.list_complete') }}</option>
                    <option value="{{ CarePlanCompletenessFilter::ALL->value }}">{{ __('care-plan.list_all') }}</option>
                    <option value="{{ CarePlanCompletenessFilter::INCOMPLETE->value }}">{{ __('care-plan.list_incomplete') }}</option>
                </select>
                <label for="filterCompleteness" class="label">
                    {{ __('care-plan.list_completeness') }}
                </label>
            </div>

            @if ($requisitionResults !== [])
                <section class="mb-6 space-y-4" aria-label="{{ __('care-plan.requisition_results') }}">
                    <h2 class="font-semibold">{{ __('care-plan.requisition_results') }}</h2>
                    @foreach ($requisitionResults as $result)
                        <div class="record-inner-card" wire:key="requisition-{{ $result['id'] }}">
                            <p class="font-semibold">{{ $result['requisition'] }}</p>
                            <p>{{ trim(implode(' ', [$result['identity']['lastName'], $result['identity']['firstName'], $result['identity']['secondName']])) }} · {{ __('care-plan.patient_age', ['age' => $result['identity']['age']]) }}</p>
                            <p>{{ CarePlanStatus::labelFor($result['status']) }}</p>
                            <p>{{ $result['period']['start'] }} — {{ $result['period']['end'] ?? __('care-plan.no_end_date') }}</p>
                            <p>{{ $result['managingOrganization']['displayValue'] }}</p>
                        </div>
                    @endforeach
                </section>
            @endif

            <div class="space-y-4">
                @forelse ($this->carePlans as $plan)
                    @php
                        /** @var CarePlan $plan */
                        $statusEnum = CarePlanStatus::fromStored($plan->status);
                        $displayTitle = $plan->displayTitle();

                        $created = $plan->created_at?->format(config('app.date_format', 'd.m.Y')) ?? '-';
                        $start = $plan->period_start?->format(config('app.date_format', 'd.m.Y')) ?? '-';
                        $end = $plan->period_end?->format(config('app.date_format', 'd.m.Y')) ?? '-';

                        $medRecordNo = $plan->encounterIdentifier?->value ?? $plan->encounter?->uuid ?? $plan->requisition ?? $plan->encounter_id ?? '-';
                    @endphp
                    <div class="record-inner-card" wire:key="care-plan-{{ $plan->id }}">
                        <div class="record-inner-header md:min-h-28">
                            <div class="record-inner-checkbox-col">
                                <input
                                    type="checkbox"
                                    class="default-checkbox h-5 w-5"
                                    aria-label="{{ __('forms.select') }}: {{ $displayTitle }}"
                                />
                            </div>

                            <div class="record-inner-column min-w-0 flex-1">
                                <div class="record-inner-label">{{ __('care-plan.name') }}</div>
                                <div class="record-inner-value text-[20px] font-semibold break-words">
                                    {{ $displayTitle }}
                                    @if ($plan->requiresFhirImport())
                                        <p class="mt-2 text-sm font-normal text-gray-600 dark:text-gray-300">
                                            {{ $plan->status === 'draft' ? __('care-plan.draft_backfill_required') : __('care-plan.registry_incomplete') }}
                                        </p>
                                    @endif
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                                <div>
                                    <span class="{{ $statusEnum === CarePlanStatus::DRAFT ? 'badge-red' : $statusEnum->color() }}">{{ CarePlanStatus::labelFor($plan->status) }}</span>
                                </div>
                            </div>

                            <div class="record-inner-action-col">
                                <div
                                    x-data="{
                                        open: false,
                                        toggle() {
                                            if (this.open) {
                                                return this.close();
                                            }
                                            this.$refs.button.focus();
                                            this.open = true;
                                        },
                                        close(focusAfter) {
                                            if (! this.open) return;
                                            this.open = false;
                                            focusAfter && focusAfter.focus();
                                        },
                                    }"
                                    @keydown.escape.prevent.stop="close($refs.button)"
                                    @focusin.window="! $refs.panel.contains($event.target) && close()"
                                    x-id="['dropdown-button']"
                                    class="relative"
                                >
                                    <button
                                        @click="toggle()"
                                        x-ref="button"
                                        :aria-expanded="open"
                                        :aria-controls="$id('dropdown-button')"
                                        type="button"
                                        aria-label="{{ __('forms.actions') }}"
                                        class="record-inner-action-btn cursor-pointer rounded-lg p-2 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/50"
                                    >
                                        @icon('edit-user-outline', 'w-6 h-6 text-gray-700 dark:text-gray-300')
                                    </button>

                                    <div
                                        x-show="open"
                                        x-cloak
                                        x-ref="panel"
                                        x-transition.origin.top.right
                                        @click.outside="close($refs.button)"
                                        :id="$id('dropdown-button')"
                                        class="absolute right-0 z-50 mt-2 w-56 rounded-md border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-600 dark:bg-gray-700"
                                    >
                                        @if (isset($plan->id))
                                            @if (!$plan->requiresFhirImport() && $plan->status === 'draft')
                                                <a
                                                    href="{{ route('care-plans.edit', [legalEntity(), $plan->id]) }}"
                                                    class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                >
                                                    @icon('edit', 'w-5 h-5 text-gray-500')
                                                    {{ __('forms.edit') }}
                                                </a>
                                            @endif

                                            <a
                                                href="{{ route('care-plans.show', [legalEntity(), $plan->id]) }}"
                                                class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                            >
                                                @icon('eye', 'w-5 h-5 text-gray-500')
                                                {{ __('forms.view_details') }}
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-body md:min-h-44">
                            <div class="record-inner-grid-container min-w-0 md:!pl-4">
                                <div class="grid grid-cols-2 gap-x-4 gap-y-3 md:grid-cols-3 lg:grid-cols-6">
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('forms.created') }}</div>
                                        <div class="record-inner-value break-words">{{ $created }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('forms.start') }}</div>
                                        <div class="record-inner-value break-words">{{ $start }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('forms.end') }}</div>
                                        <div class="record-inner-value break-words">{{ $end }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('care-plan.doctor') }}</div>
                                        <div class="record-inner-value break-words">{{ $plan->authorName }}</div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">
                                            {{ __('care-plan.care_provision_conditions_label') }}
                                        </div>
                                        <div class="record-inner-value break-words">
                                            {{ $plan->careProvisionConditions ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label">{{ __('care-plan.medical_number') }}</div>
                                        <div class="record-inner-value break-words">{{ $medRecordNo }}</div>
                                    </div>
                                </div>
                            </div>

                            <div class="record-inner-id-col">
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('forms.ehealth_id') }}</div>
                                    <div class="record-inner-id-value">{{ $plan->uuid ?? '-' }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('care-plan.episode_id') }}</div>
                                    <div class="record-inner-id-value">{{ $plan->episodeUuid() ?? '-' }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <x-nothing-found />
                @endforelse
            </div>
        </div>

        <x-forms.loading />
    </section>
</div>
