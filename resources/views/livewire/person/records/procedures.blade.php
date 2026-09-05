@use(App\Enums\JobStatus)
@use(App\Enums\Person\ProcedureStatus)
@use(App\Models\MedicalEvents\Sql\Procedure)

<x-layouts.patient :personId="$personId" :prepersonId="$prepersonId" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', Procedure::class)
            <a
                href="{{
                    $prepersonId
                    ? route('prepersons.procedure.create', [legalEntity(), 'preperson' => $prepersonId])
                    : route('procedure.create', [legalEntity(), 'person' => $personId])
                }}"
                class="button-primary flex items-center gap-2 px-5 py-2 text-sm shadow-sm"
            >
                @icon('plus', 'w-4 h-4')
                {{ __('patients.starts_interacting') }}
            </a>
        @endcan

        <button type="button" class="button-primary-outline px-5 py-2 text-sm whitespace-nowrap">
            {{ __('patients.data_access') }}
        </button>

        @php
            $isSyncing = $syncStatus === JobStatus::PROCESSING->value;
            $isRetryable = $syncStatus === JobStatus::PAUSED->value || $syncStatus === JobStatus::FAILED->value;
        @endphp
        <button
            @if (!$isSyncing) wire:click="sync" @endif
            type="button"
            @if ($isSyncing) disabled @endif
            class="flex items-center gap-2 whitespace-nowrap px-5 py-2 text-sm shadow-sm transition-colors
                @if($isSyncing) button-sync-disabled cursor-not-allowed @else button-sync @endif"
        >
            @icon('refresh', 'w-4 h-4')
            <span>{{ $isRetryable ? __('forms.sync_retry') : __('forms.synchronise_with_eHealth') }}</span>
        </button>
    </x-slot>

    <div class="breadcrumb-form shift-content p-4">
        <div class="mt-6 w-full" x-data="{ showAdditionalParams: $wire.entangle('showAdditionalParams') }">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('procedures.search') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <select
                        wire:model.live="filterCategory"
                        id="filterCategory"
                        name="filterCategory"
                        class="input-select peer w-full"
                    >
                        <option value="">{{ __('forms.select') }} {{ mb_strtolower(__('forms.category')) }}</option>

                        @foreach ($this->dictionaries['eHealth/procedure_categories'] as $key => $category)
                            <option value="{{ $key }}">{{ $category }}</option>
                        @endforeach
                    </select>

                    <label for="filterCategory" class="label pointer-events-none"> {{ __('forms.category') }} </label>
                </div>

                <x-forms.combobox
                    wire:key="filter-code-{{ $filterCategory }}"
                    :options="$services"
                    bind="filterCode"
                    bindValue="id"
                    bindParam="name"
                    :label="__('forms.services')"
                />
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
                        <span>{{ __('forms.additional_search_parameters') }}</span>
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
                        class="absolute top-full right-0 z-10 mt-2 w-60 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-600 dark:bg-gray-700"
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

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="procedure-search-filters">
                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <select
                            wire:model="filterStatus"
                            name="filterStatus"
                            id="filterStatus"
                            class="input-select peer w-full"
                        >
                            <option value="">
                                {{ __('forms.select') }} {{ mb_strtolower(__('forms.status.label')) }}
                            </option>

                            @foreach (ProcedureStatus::cases() as $status)
                                <option value="{{ $status->value }}">{{ $status->label() }}</option>
                            @endforeach
                        </select>

                        <label for="filterStatus" class="label"> {{ __('forms.status.label') }} </label>
                    </div>

                    <x-forms.combobox
                        :options="$episodes"
                        bind="filterEpisodeId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('episodes.id')"
                    />

                    <x-forms.combobox
                        :options="$encounters"
                        bind="filterEncounterId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('patients.encounter_id')"
                    />
                </div>

                <div class="form-row-3 mb-6">
                    <x-forms.combobox
                        :options="$originEpisodes"
                        bind="filterOriginEpisodeId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('episodes.origin_id')"
                    />

                    <x-forms.combobox
                        :options="$basedOnRequests"
                        bind="filterBasedOn"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('patients.based_on')"
                    />

                    <x-forms.combobox
                        :options="$usedReferences"
                        bind="filterUsedReferenceId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('procedures.used_reference_id')"
                    />
                </div>

                <div class="form-row-3 mb-9">
                    {{-- TODO: список порожній, поки в проєкті немає обладнання (devices). --}}
                    <x-forms.combobox
                        :options="$devices"
                        bind="filterDeviceId"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('patients.device_id')"
                    />
                </div>
            </div>

            <div class="space-y-4">
                @forelse ($this->paginatedProcedures as $procedure)
                    <div class="record-inner-card">
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <input type="checkbox" class="default-checkbox h-5 w-5" />
                            </div>

                            <div class="record-inner-column flex-1">
                                <div class="record-inner-label">{{ __('medical-events.code_and_name') }}</div>
                                <div class="record-inner-value text-[16px]">
                                    {{
                                        data_get($procedure, 'code.identifier.value') && data_get($procedure, 'code.displayValue')
                                        ? data_get($procedure, 'code.identifier.value') . ' | ' . data_get($procedure, 'code.displayValue')
                                        : '-'
                                    }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full shrink-0 md:w-36">
                                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                                <div>
                                    @php
                                        $status = ProcedureStatus::from(data_get($procedure, 'status'));
                                    @endphp
                                    <span @class([$status->color()])> {{ $status->label() ?? '-' }} </span>
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
                                        class="record-inner-action-btn cursor-pointer"
                                    >
                                        @icon('edit-user-outline', 'w-5 h-5')
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
                                        <button
                                            type="button"
                                            @click="close($refs.button)"
                                            wire:click="openProcedureView('{{ data_get($procedure, 'uuid') }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="openProcedureView"
                                            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                        >
                                            @icon('eye', 'w-5 h-5 text-gray-500')
                                            {{ __('patients.view_details') }}
                                        </button>

                                        <button
                                            type="button"
                                            @click="close($refs.button)"
                                            wire:click="openProcedureCancellation('{{ data_get($procedure, 'uuid') }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="openProcedureCancellation"
                                            class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                        >
                                            @icon('alert-circle', 'w-5 h-5 text-gray-500')
                                            {{ __('procedures.status.entered_in_error') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-body">
                            <div class="record-inner-grid-container">
                                <div class="grid grid-cols-2 gap-x-4 gap-y-3 md:grid-cols-3">
                                    <div class="min-w-0 space-y-2.5">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('forms.category') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold wrap-break-word">
                                                @php
                                                    $categoryCode = data_get($procedure, 'category.coding.0.code')
                                                        ?? data_get($procedure, 'category.0.coding.0.code');
                                                @endphp

                                                {{
                                                    data_get(
                                                        $this->dictionaries,
                                                        'eHealth/procedure_categories.' . $categoryCode,
                                                        data_get($procedure, 'category.text', data_get($procedure, 'category.0.text', $categoryCode ?: '—'))
                                                    )
                                                }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('patients.referrals') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold wrap-break-word">
                                                {{ data_get($procedure, 'paperReferral.requisition', '—') }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="min-w-0 space-y-2.5">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('medical-events.performer') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold break-words uppercase">
                                                {{ data_get($procedure, 'performer.displayValue', '-') }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('patients.notes') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold wrap-break-word">
                                                {{ data_get($procedure, 'note') ?? '-' }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="min-w-0 space-y-2.5">
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('patients.created') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold wrap-break-word">
                                                @php
                                                    $createdAt = data_get($procedure, 'ehealthInsertedAt') ?: data_get($procedure, 'createdAt');
                                                @endphp

                                                {{ $createdAt ? optional(\Carbon\Carbon::make($createdAt))->format('d.m.Y H:i') : '-' }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="record-inner-label text-[10px] uppercase">
                                                {{ __('patients.doctor') }}
                                            </div>
                                            <div class="record-inner-value text-[14px] font-semibold break-words">
                                                {{ data_get($procedure, 'recordedBy.displayValue') ?? '-' }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="record-inner-id-col">
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">
                                        {{ __('patients.ehealth_id') }}
                                    </div>
                                    <div class="record-inner-id-value">{{ data_get($procedure, 'uuid') ?? '-' }}</div>
                                </div>
                                <div class="min-w-0">
                                    <div class="record-inner-label text-[10px] uppercase">
                                        {{ __('patients.medical_record_id') }}
                                    </div>
                                    <div class="record-inner-id-value">
                                        {{ data_get($procedure, 'encounter.identifier.value') ?? '-' }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <x-nothing-found :description="null" />
                @endforelse
            </div>
            <div class="mt-8">{{ $this->paginatedProcedures->links() }}</div>
        </div>
    </div>
    @include('livewire.procedure.procedure-cancellation')
    <x-forms.loading />
</x-layouts.patient>
