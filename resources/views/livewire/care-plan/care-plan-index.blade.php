@use('App\Livewire\CarePlan\CarePlanIndex')

<section class="section-form">
    <x-header-navigation class="items-start" x-data="{ showFilter: false }">
        <x-slot name="title">
            {{ __('care-plan.care_plans') }}
        </x-slot>

        <div class="mt-3 ml-0 flex flex-col sm:flex-row sm:flex-wrap gap-2 self-start">
            <a href="{{ route('care-plans.create', legalEntity()) }}" class="button-primary">
                + {{ __('care-plan.new_care_plan') }}
            </a>

            <button wire:click.prevent="sync"
                    type="button"
                    class="button-sync flex items-center gap-2 whitespace-nowrap px-5 py-2 text-sm shadow-sm"
                    wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="sync">
                    @icon('refresh', 'w-4 h-4')
                </span>
                <span wire:loading wire:target="sync" class="animate-spin">
                    @icon('refresh', 'w-4 h-4')
                </span>
                <span>{{ __('patients.sync_ehealth_data') }}</span>
            </button>
        </div>
    </x-header-navigation>

    <div class="form shift-content">
        {{-- Search and Filters Section --}}
        <div class="w-full mb-6" x-data="{ showAdditionalParams: $wire.entangle('showAdditionalParams') }">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('care-plan.search_care_plan') }}</p>
            </div>

            <div class="form-row-4 mb-6">
                <div class="form-group group">
                    <div class="relative">
                        <input wire:model="searchRequisition"
                               wire:keydown.enter="search"
                               type="text"
                               name="searchRequisition"
                               id="searchRequisition"
                               class="input peer w-full"
                               placeholder=" "
                               autocomplete="off"
                        />
                        <label for="searchRequisition" class="label">
                            {{ __('care-plan.medical_number') }}
                        </label>
                        <button type="button" wire:click="$set('searchRequisition', '')"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                x-show="$wire.searchRequisition">
                            @icon('close', 'w-4 h-4')
                        </button>
                    </div>
                </div>

                <div class="form-group group">
                    <select wire:model="filterStatus"
                            name="filterStatus"
                            id="filterStatus"
                            class="input-select peer w-full"
                    >
                        <option value="">{{ __('forms.select') }}</option>
                        <option value="draft">{{ __('care-plan.status.draft') }}</option>
                        <option value="active">{{ __('care-plan.status.active') }}</option>
                        <option value="completed">{{ __('care-plan.status.completed') }}</option>
                        <option value="revoked">{{ __('care-plan.status.revoked') }}</option>
                    </select>
                    <label for="filterStatus" class="label">
                        {{ __('forms.status.label') }}
                    </label>
                </div>
            </div>

            <div class="mb-9 flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="search"
                            class="flex items-center gap-2 button-primary px-5 py-2.5 text-sm shadow-sm"
                    >
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('forms.search') }}</span>
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
                        <span>{{ __('forms.additional_search_parameters') }}</span>
                    </button>
                </div>
            </div>

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="care-plans-search-filters">
                <div class="form-row-4 mb-6">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input wire:model="filterStartDateRange"
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
                            <input wire:model="filterEndDateRange"
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

                <div class="form-row-4 mb-6">
                    <div class="form-group group">
                        <div class="relative">
                            <input wire:model="filterIsPartOf"
                                   wire:keydown.enter="search"
                                   type="text"
                                   name="filterIsPartOf"
                                   id="filterIsPartOf"
                                   class="input peer w-full"
                                   placeholder=" "
                                   autocomplete="off"
                            />
                            <label for="filterIsPartOf" class="label">
                                {{ __('care-plan.is_part_of_care_plan') }}
                            </label>
                            <button type="button" wire:click="$set('filterIsPartOf', '')"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                    x-show="$wire.filterIsPartOf">
                                @icon('close', 'w-4 h-4')
                            </button>
                        </div>
                    </div>

                    <div class="form-group group">
                        <div class="relative">
                            <input wire:model="filterIncludes"
                                   wire:keydown.enter="search"
                                   type="text"
                                   name="filterIncludes"
                                   id="filterIncludes"
                                   class="input peer w-full"
                                   placeholder=" "
                                   autocomplete="off"
                            />
                            <label for="filterIncludes" class="label">
                                {{ __('care-plan.includes_care_plan') }}
                            </label>
                            <button type="button" wire:click="$set('filterIncludes', '')"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                    x-show="$wire.filterIncludes">
                                @icon('close', 'w-4 h-4')
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            @forelse($carePlans as $plan)
                @php
                    /** @var \App\Models\CarePlan $plan */
                    $status = strtolower($plan->status ?? '');

                    $statusClass = 'badge-dark';
                    if (in_array($status, ['active', 'new', 'completed'])) {
                        $statusClass = 'badge-green';
                    } elseif (in_array($status, ['draft', 'revoked'])) {
                        $statusClass = 'badge-red';
                    }

                    $created = $plan->created_at?->format(config('app.date_format', 'd.m.Y')) ?? '-';
                    $start = $plan->period_start?->format(config('app.date_format', 'd.m.Y')) ?? '-';
                    $end = $plan->period_end?->format(config('app.date_format', 'd.m.Y')) ?? '-';

                    $medRecordNo = $plan->encounterIdentifier?->value ?? $plan->encounter?->uuid ?? $plan->requisition ?? $plan->encounter_id ?? '-';
                @endphp
                <div class="record-inner-card" wire:key="care-plan-{{ $plan->id }}">
                    <div class="record-inner-header">
                        <div class="record-inner-column flex-1">
                            <div class="record-inner-label">{{ __('forms.name') }}</div>
                            <div class="record-inner-value text-[17px] font-semibold text-gray-900 dark:text-gray-100">
                                {{ $plan->title }}
                            </div>
                            <div class="text-xs text-gray-500 mt-1.5 dark:text-gray-400">
                                {{ __('care-plan.patient') }}: {{ $plan->person?->last_name }} {{ $plan->person?->first_name }}
                            </div>
                        </div>

                        <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
                            <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                            <div>
                                <span class="{{ $statusClass }}">
                                    {{ $plan->status_display }}
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
                                    @if(isset($plan->id))
                                        @if($plan->status === 'draft')
                                            <a href="{{ route('care-plans.edit', [legalEntity(), $plan->id]) }}" class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                                                @icon('edit', 'w-5 h-5 text-gray-500')
                                                {{ __('forms.edit') }}
                                            </a>
                                        @endif

                                        <a href="{{ route('care-plans.show', [legalEntity(), $plan->id]) }}" class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                                            @icon('eye', 'w-5 h-5 text-gray-500')
                                            {{ __('patients.view_details') }}
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="record-inner-body">
                        <div class="record-inner-grid-container">
                            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-x-4 gap-y-3">
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">{{ __('patients.created') }}</div>
                                    <div class="record-inner-value text-[14px] font-semibold break-words">
                                        {{ $created }}
                                    </div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">{{ __('forms.start') }}</div>
                                    <div class="record-inner-value text-[14px] font-semibold break-words">
                                        {{ $start }}
                                    </div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">{{ __('forms.end') }}</div>
                                    <div class="record-inner-value text-[14px] font-semibold break-words">
                                        {{ $end }}
                                    </div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">{{ __('care-plan.doctor') }}</div>
                                    <div class="record-inner-value text-[14px] font-semibold break-words uppercase">
                                        {{ $plan->author_name }}
                                    </div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">{{ __('care-plan.care_provision_conditions_label') }}</div>
                                    <div class="record-inner-value text-[14px] font-semibold break-words">
                                        {{ $plan->care_provision_conditions ?? '-' }}
                                    </div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">{{ __('care-plan.medical_number') }}</div>
                                    <div class="record-inner-value text-[14px] font-semibold break-words">
                                        {{ $medRecordNo }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-id-col">
                            <div class="min-w-0">
                                <div class="record-inner-label text-[10px] uppercase">{{ __('patients.ehealth_id') }}</div>
                                <div class="record-inner-id-value">
                                    {{ $plan->uuid ?? '-' }}
                                </div>
                            </div>
                            <div class="min-w-0">
                                <div class="record-inner-label text-[10px] uppercase">{{ __('care-plan.episode_id') }}</div>
                                <div class="record-inner-id-value">
                                    {{ $plan->episode_id ?? '-' }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <x-nothing-found />
            @endforelse
        </div>
    </div>

    <x-forms.loading/>
</section>
