@use('App\Enums\CarePlanStatus')
<x-layouts.patient :personId="$personId" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', \App\Models\CarePlan::class)
            <a
                href="{{ route('care-plans.create', [legalEntity(), $personId]) }}"
                class="button-primary flex items-center gap-2 px-5 py-2 text-sm shadow-sm"
            >
                @icon('plus', 'w-4 h-4')
                {{ __('patients.starts_interacting') }}
            </a>
        @endcan

        <button type="button" class="button-primary-outline px-5 py-2 text-sm whitespace-nowrap">
            {{ __('patients.data_access') }}
        </button>

        <button
            wire:click.prevent="sync"
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
                        <input
                            wire:model="filterEncounterId"
                            type="text"
                            name="filterEncounterId"
                            id="filterEncounterId"
                            class="input peer w-full"
                            placeholder=" "
                            autocomplete="off"
                        />
                        <label for="filterEncounterId" class="label"> ID взаємодії </label>
                        <button
                            type="button"
                            wire:click="$set('filterEncounterId', '')"
                            class="absolute top-1/2 right-3 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                            x-show="$wire.filterEncounterId"
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
                        <option value="">{{ __('forms.select') }} ...</option>
                        @foreach (\App\Enums\CarePlanStatus::cases() as $status)
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
                        {{ __('patients.reset_filters') }}
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

                <div class="relative" x-data="{ openGroupActions: false }" @click.outside="openGroupActions = false">
                    <button
                        type="button"
                        @click="openGroupActions = ! openGroupActions"
                        class="button-primary-outline px-5 py-2.5 text-sm"
                    >
                        {{ __('patients.group_actions') }}
                    </button>

                    <div
                        x-show="openGroupActions"
                        x-transition
                        x-cloak
                        class="absolute top-full right-0 z-10 mt-2 w-[240px] overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-600 dark:bg-gray-700"
                    >
                        <div class="py-1">
                            <button
                                type="button"
                                @click="openGroupActions = false"
                                class="dropdown-button !flex w-full items-center gap-2.5 px-4 py-2 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
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

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="care-plans-search-filters">
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

                <div class="form-row-3 mb-9">
                    <div class="form-group group">
                        <div class="relative">
                            <input
                                wire:model="filterIsPartOf"
                                type="text"
                                name="filterIsPartOf"
                                id="filterIsPartOf"
                                class="input peer w-full"
                                placeholder=" "
                                autocomplete="off"
                            />
                            <label for="filterIsPartOf" class="label"> Є частиною плана лікування </label>
                            <button
                                type="button"
                                wire:click="$set('filterIsPartOf', '')"
                                class="absolute top-1/2 right-3 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                x-show="$wire.filterIsPartOf"
                            >
                                @icon('close', 'w-4 h-4')
                            </button>
                        </div>
                    </div>

                    <div class="form-group group">
                        <div class="relative">
                            <input
                                wire:model="filterIncludes"
                                type="text"
                                name="filterIncludes"
                                id="filterIncludes"
                                class="input peer w-full"
                                placeholder=" "
                                autocomplete="off"
                            />
                            <label for="filterIncludes" class="label"> Включає в себе план лікування </label>
                            <button
                                type="button"
                                wire:click="$set('filterIncludes', '')"
                                class="absolute top-1/2 right-3 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                x-show="$wire.filterIncludes"
                            >
                                @icon('close', 'w-4 h-4')
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @forelse ($carePlans as $plan)
                    <div class="record-inner-card" wire:key="care-plan-{{ $plan->id }}">
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <input type="checkbox" class="default-checkbox h-5 w-5" />
                            </div>

                            <div class="record-inner-column flex-1">
                                <div class="record-inner-label">{{ __('care-plan.name') }}</div>
                                <div class="record-inner-value text-[17px] font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $plan->title }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                                <div class="record-inner-label">{{ __('forms.status.label') }}:</div>
                                <div>
                                    @php
                                        $rawStatus = is_array($plan->status) ? ($plan->status['coding'][0]['code'] ?? ($plan->status['text'] ?? '')) : $plan->status;
                                        $statusEnum = CarePlanStatus::fromStored($rawStatus);
                                    @endphp
                                    <span class="{{ $statusEnum->color() }}">
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
                                        @if ($plan->status === CarePlanStatus::DRAFT->value)
                                            <a
                                                href="{{ route('care-plans.edit', [legalEntity(), $plan->id]) }}"
                                                class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                            >
                                                @icon('edit', 'w-5 h-5 text-gray-500')
                                                {{ __('forms.edit') ?? 'Редагувати' }}
                                            </a>
                                        @endif

                                        <a
                                            href="{{ route('care-plans.show', [legalEntity(), $plan->id]) }}"
                                            class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                        >
                                            @icon('eye', 'w-5 h-5 text-gray-500')
                                            {{ __('patients.view_details') ?? 'Переглянути' }}
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
                                        <div class="record-inner-value text-[14px] font-semibold break-words">
                                            {{ $plan->created_at?->format(config('app.date_format')) ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('forms.start_date') ?? 'Початок' }}
                                        </div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">
                                            {{ $plan->period_start?->format(config('app.date_format')) ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('forms.end_date') ?? 'Кінець' }}
                                        </div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">
                                            {{ $plan->period_end?->format(config('app.date_format')) ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('care-plan.doctor') ?? 'Лікар' }}
                                        </div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words uppercase">
                                            {{ $plan->author_name }}
                                        </div>
                                    </div>
                                    <div class="col-span-1 min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('care-plan.terms_of_service') }}
                                        </div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">
                                            {{ $plan->care_provision_conditions }}
                                        </div>
                                    </div>
                                </div>

                                <!-- Second Row of Details -->
                                <div class="grid grid-cols-1 gap-x-4 gap-y-3 md:grid-cols-2 lg:grid-cols-4">
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('care-plan.condition_diagnosis') }}
                                        </div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">
                                            {{ $plan->medical_condition }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('care-plan.extended_description') }}
                                        </div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">
                                            {{ $plan->extended_description }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('care-plan.supporting_information') }}
                                        </div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">
                                            {{ $plan->additional_info }}
                                        </div>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('care-plan.notes') }}
                                        </div>
                                        <div class="record-inner-value text-[14px] font-semibold break-words">
                                            {{ $plan->notes }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="record-inner-id-col">
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">
                                        {{ __('care-plan.ehealth_id') }}
                                    </div>
                                    <div class="record-inner-id-value">{{ $plan->ehealth_id }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">
                                        {{ __('care-plan.episode_id') ?? 'ID Епізоду' }}
                                    </div>
                                    <div class="record-inner-id-value">{{ $plan->episodeUuid() ?? '-' }}</div>
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
