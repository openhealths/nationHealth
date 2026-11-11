@use('App\Models\CarePlan')
@use('App\Enums\CarePlanStatusFilter')
@use('App\Enums\CarePlanCompletenessFilter')
@use('App\Enums\CarePlanStatus')
<x-layouts.patient :showLegacyMessages="false" :personId="$personId" :patientFullName="$patientFullName">
    <livewire:components.x-message :consume-messages="true" :key="(string) str()->uuid()" />
    @if ($syncBatchId !== null)
        <div wire:poll.3s="checkSyncStatus" data-livewire-background-actions="checkSyncStatus" class="mb-4 text-sm text-gray-600 dark:text-gray-300">
            {{ __('care-plan.sync_running') }}
        </div>
    @endif
    <x-slot name="headerActions">
        @can('create', CarePlan::class)
            <a
                href="{{ route('care-plans.create', [legalEntity(), $personId]) }}"
                class="button-primary flex items-center gap-2 px-5 py-2 text-sm shadow-sm"
            >
                @icon('plus', 'w-4 h-4')
                {{ __('encounters.new') }}
            </a>
        @endcan

        <livewire:person.records.patient-approval
            :person-id="$personId"
            :care-plans-only="true"
            :key="'care-plan-read-approval-'.$personId"
        />

        <button
            wire:click.prevent="sync"
            wire:loading.attr="disabled"
            wire:target="sync"
            @disabled($syncBatchId !== null)
            type="button"
            class="button-sync flex items-center gap-2 px-5 py-2 text-sm whitespace-nowrap shadow-sm"
        >
            @icon('refresh', 'w-4 h-4')
            {{ __('forms.synchronise_with_eHealth') }}
        </button>
    </x-slot>

    <div class="breadcrumb-form shift-content p-4">
        <div class="mt-6 w-full" x-data="{ showAdditionalParams: $wire.entangle('showAdditionalParams') }">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>Пошук плану лікування</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <div class="relative">
                        <input
                            wire:model="filterName"
                            type="text"
                            name="filterName"
                            id="filterName"
                            class="input peer w-full"
                            placeholder=" "
                            autocomplete="off"
                        />
                        <label for="filterName" class="label"> Назва </label>
                        <button
                            type="button"
                            wire:click="$set('filterName', '')"
                            class="absolute top-1/2 right-3 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                            x-show="$wire.filterName"
                        >
                            @icon('close', 'w-4 h-4')
                        </button>
                    </div>
                </div>

                <div class="form-group group">
                    <div class="relative">
                        <select wire:model="filterEncounterId" id="filterEncounterId" class="input-select peer w-full">
                            <option value="">{{ __('care-plan.select_encounter') }}</option>
                            @foreach ($this->encounterChoices as $choice)
                                <option value="{{ $choice['uuid'] }}">{{ $choice['label'] }}</option>
                            @endforeach
                        </select>
                        <label for="filterEncounterId" class="label">{{ __('care-plan.encounter') }}</label>
                        @error('filterEncounterId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
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
                    <label for="filterStatus" class="label"> Статус </label>
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
                        <span>Додаткові параметри пошуку</span>
                    </button>
                </div>
            </div>

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="care-plans-search-filters">
                <div class="form-row-3 mb-6">
                    @foreach (['filterBasedOn' => 'based_on', 'filterPartOf' => 'part_of'] as $field => $label)
                        <div class="form-group group">
                            <select wire:model="{{ $field }}" id="{{ $field }}" class="input-select peer w-full">
                                <option value="">{{ __('forms.select') }}</option>
                                @foreach ($this->relatedCarePlanChoices as $choice)
                                    <option value="{{ $choice['uuid'] }}">{{ $choice['label'] }}</option>
                                @endforeach
                            </select>
                            <label for="{{ $field }}" class="label">{{ __('care-plan.'.$label) }}</label>
                            @error($field) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                    <div class="form-group group">
                        <input wire:model="filterPeriodDate" id="filterPeriodDate" type="date" class="input peer w-full" />
                        <label for="filterPeriodDate" class="label">{{ __('care-plan.period_date') }}</label>
                        @error('filterPeriodDate') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="form-group group">
                        <input wire:model="filterManagingOrganization" id="filterManagingOrganization" type="text" class="input peer w-full" placeholder=" " />
                        <label for="filterManagingOrganization" class="label">{{ __('care-plan.managing_organization_uuid') }}</label>
                        @error('filterManagingOrganization') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input
                                wire:model="filterStartDateRange"
                                type="text"
                                name="filterStartDateRange"
                                id="filterStartDateRange"
                                class="daterangepicker-uk with-leading-icon input peer w-full"
                                placeholder=" "
                                autocomplete="off"
                            />
                            <label for="filterStartDateRange" class="wrapped-label"> Дата початку від - до </label>
                        </div>
                    </div>

                    <div class="form-group group">
                        <div class="datepicker-wrapper">
                            <input
                                wire:model="filterEndDateRange"
                                type="text"
                                name="filterEndDateRange"
                                id="filterEndDateRange"
                                class="daterangepicker-uk with-leading-icon input peer w-full"
                                placeholder=" "
                                autocomplete="off"
                            />
                            <label for="filterEndDateRange" class="wrapped-label"> Дата завершення від - до </label>
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

            <div class="space-y-4">
                @forelse ($carePlans as $plan)
                    <div class="record-inner-card" wire:key="care-plan-{{ $plan['id'] }}">
                        <div class="record-inner-header">
                            <div class="record-inner-column flex-1">
                                <div class="record-inner-label">{{ __('care-plan.name') }}</div>
                                <div class="record-inner-value text-[17px] font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $plan['title'] }}
                                    @if ($plan['requiresFhirImport'])
                                        <p class="mt-2 text-sm font-normal text-gray-600 dark:text-gray-300">
                                            {{ $plan['status'] === 'draft' ? __('care-plan.draft_backfill_required') : __('care-plan.registry_incomplete') }}
                                        </p>
                                    @endif
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                                <div class="record-inner-label">{{ __('forms.status.label') }}:</div>
                                <div>
                                    @php
                                        $rawStatus = is_array($plan['status']) ? ($plan['status']['coding'][0]['code'] ?? ($plan['status']['text'] ?? '')) : $plan['status'];
                                        $statusEnum = CarePlanStatus::fromStored($rawStatus);
                                    @endphp
                                    <span class="{{ $statusEnum === CarePlanStatus::DRAFT ? 'badge-red' : $statusEnum->color() }}">
                                        {{ CarePlanStatus::labelFor($rawStatus) }}
                                    </span>
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
                                            @if (!$plan['requiresFhirImport'] && $plan['status'] === CarePlanStatus::DRAFT->value)
                                                <a
                                                    href="{{ route('care-plans.edit', [legalEntity(), $plan['id']]) }}"
                                                    class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                >
                                                    @icon('edit', 'w-5 h-5 text-gray-500')
                                                    {{ __('forms.edit') ?? 'Редагувати' }}
                                                </a>
                                            @endif

                                            <a
                                                href="{{ route('care-plans.show', [legalEntity(), $plan['id']]) }}"
                                                class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                            >
                                                @icon('eye', 'w-5 h-5 text-gray-500')
                                                {{ __('forms.view_details') ?? 'Переглянути' }}
                                            </a>
                                        </div>
                                    </div>
                                </div>
                        </div>

                        <div class="record-inner-body">
                            <div class="record-inner-grid-container">
                                <!-- First Row of Details -->
                                <div class="mb-4 grid grid-cols-2 gap-x-4 gap-y-3 md:grid-cols-3 lg:grid-cols-5">
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('forms.created') ?? 'Створено' }}
                                        </div>
                                        <div class="record-inner-value font-semibold break-words">
                                            {{ $plan['createdAt'] }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('forms.start_date') ?? 'Початок' }}
                                        </div>
                                        <div class="record-inner-value font-semibold break-words">
                                            {{ $plan['periodStart'] }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('forms.end_date') ?? 'Кінець' }}
                                        </div>
                                        <div class="record-inner-value font-semibold break-words">
                                            {{ $plan['periodEnd'] }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('care-plan.doctor') ?? 'Лікар' }}
                                        </div>
                                        <div class="record-inner-value font-semibold break-words uppercase">
                                            {{ $plan['authorName'] }}
                                        </div>
                                    </div>
                                    <div class="col-span-1 min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('care-plan.terms_of_service') }}
                                        </div>
                                        <div class="record-inner-value font-semibold break-words">
                                            {{ $plan['careProvisionConditions'] }}
                                        </div>
                                    </div>
                                </div>

                                <!-- Second Row of Details -->
                                <div class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-2 lg:grid-cols-4">
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('care-plan.condition_diagnosis') }}
                                        </div>
                                        <div class="record-inner-value font-semibold break-words">
                                            {{ $plan['medicalCondition'] }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('care-plan.extended_description') }}
                                        </div>
                                        <div class="record-inner-value font-semibold break-words">
                                            {{ $plan['extendedDescription'] }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('care-plan.supporting_information') }}
                                        </div>
                                        <div class="record-inner-value font-semibold break-words">
                                            {{ $plan['additionalInfo'] }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('care-plan.notes') }}
                                        </div>
                                        <div class="record-inner-value font-semibold break-words">
                                            {{ $plan['notes'] }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="record-inner-id-col">
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">
                                        {{ __('care-plan.ehealth_id') }}
                                    </div>
                                    <div class="record-inner-id-value">{{ $plan['ehealthId'] }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">
                                        {{ __('care-plan.episode_id') ?? 'ID Епізоду' }}
                                    </div>
                                    <div class="record-inner-id-value">{{ $plan['episodeUuid'] }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <x-nothing-found :description="null" />
                @endforelse
            </div>
        </div>
    </div>

    <x-forms.loading />
</x-layouts.patient>
