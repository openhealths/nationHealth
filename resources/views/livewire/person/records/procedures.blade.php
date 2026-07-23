@use(App\Enums\Person\ProcedureStatus)
<x-layouts.patient :personId="$personId" :prepersonId="$prepersonId" :patientFullName="$patientFullName">
    <x-slot name="headerActions">
        @can('create', \App\Models\MedicalEvents\Sql\Procedure::class)
            <a href="{{ $prepersonId
                ? route('prepersons.procedure.create', [legalEntity(), 'preperson' => $prepersonId])
                : route('procedure.create', [legalEntity(), 'person' => $personId]) }}"
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
            {{ __('forms.synchronise_with_eHealth') }}
        </button>
    </x-slot>

    <div class="breadcrumb-form p-4 shift-content">
        <div class="w-full mt-6"
             x-data="{
                showAdditionalParams: $wire.entangle('showAdditionalParams'),
                modalProcedure: {
                    categoryCode: $wire.entangle('filterCategory'),
                },
            }"
        >
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('patients.procedure_search') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <select x-model="modalProcedure.categoryCode"
                            id="filterCategory"
                            name="filterCategory"
                            class="input-select peer w-full"
                    >
                        <option value="">
                            {{ __('forms.select') }} {{ mb_strtolower(__('forms.category')) }}
                        </option>

                        @foreach($this->dictionaries['eHealth/procedure_categories'] as $key => $category)
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
                            const selectedCategory = String(modalProcedure.categoryCode ?? '');
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

                            this.$watch('modalProcedure.categoryCode', () => {
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
                                    {{ __('forms.nothing_found') }}
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

            <div x-show="showAdditionalParams" x-transition x-cloak wire:key="procedure-search-filters">
                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <select wire:model="filterStatus"
                                name="filterStatus"
                                id="filterStatus"
                                class="input-select peer w-full"
                        >
                            <option value="">
                                {{ __('forms.select') }} {{ mb_strtolower(__('forms.status.label')) }}
                            </option>

                            @foreach(ProcedureStatus::cases() as $status)
                                <option value="{{ $status->value }}">
                                    {{ $status->label() }}
                                </option>
                            @endforeach
                        </select>

                        <label for="filterStatus" class="label">
                            {{ __('forms.status.label') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterEpisodeId"
                                name="filterEpisodeId"
                                id="filterEpisodeId"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>

                            @foreach($filterEpisodeOptions as $episode)
                                <option value="{{ data_get($episode, 'value') }}">
                                    {{ data_get($episode, 'label') }}
                                </option>
                            @endforeach
                        </select>

                        <label for="filterEpisodeId" class="label">
                            {{ __('episodes.id') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterEncounterId"
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
                                    $encounterLabel = collect([$typeCode, $classCode])->filter()->implode(' | ');
                                @endphp

                                @if($encounterId)
                                    <option value="{{ $encounterId }}">
                                        {{ $encounterLabel ?: $encounterId }}
                                    </option>
                                @endif
                            @endforeach
                        </select>

                        <label for="filterEncounterId" class="label">
                            {{ __('patients.encounter_id') }}
                        </label>
                    </div>
                </div>

                <div class="form-row-3 mb-6">
                    <div class="form-group group">
                        <select wire:model="filterOriginEpisodeId"
                                name="filterOriginEpisodeId"
                                id="filterOriginEpisodeId"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>

                            @foreach($filterOriginEpisodeOptions as $episode)
                                <option value="{{ data_get($episode, 'value') }}">
                                    {{ data_get($episode, 'label') }}
                                </option>
                            @endforeach
                        </select>

                        <label for="filterOriginEpisodeId" class="label">
                            {{ __('episodes.origin_id') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterBasedOn"
                                name="filterBasedOn"
                                id="filterBasedOn"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>

                            @foreach($filterBasedOnOptions as $basedOn)
                                <option value="{{ data_get($basedOn, 'value') }}">
                                    {{ data_get($basedOn, 'label') }}
                                </option>
                            @endforeach
                        </select>

                        <label for="filterBasedOn" class="label">
                            {{ __('patients.based_on') }}
                        </label>
                    </div>

                    <div class="form-group group">
                        <select wire:model="filterUsedReferenceId"
                                name="filterUsedReferenceId"
                                id="filterUsedReferenceId"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>

                            @foreach($filterUsedReferenceOptions as $usedReference)
                                <option value="{{ data_get($usedReference, 'value') }}">
                                    {{ data_get($usedReference, 'label') }}
                                </option>
                            @endforeach
                        </select>

                        <label for="filterUsedReferenceId" class="label">
                            {{ __('patients.used_reference_id') }}
                        </label>
                    </div>
                </div>

                <div class="form-row-3 mb-9">
                    <div class="form-group group">
                        <select wire:model="filterDeviceId"
                                name="filterDeviceId"
                                id="filterDeviceId"
                                class="input-select peer w-full"
                        >
                            <option value="">{{ __('forms.select') }} ...</option>

                            @foreach($filterDeviceOptions as $device)
                                <option value="{{ data_get($device, 'value') }}">
                                    {{ data_get($device, 'label') }}
                                </option>
                            @endforeach
                        </select>

                        <label for="filterDeviceId" class="label">
                            {{ __('patients.device_id') }}
                        </label>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                @forelse($paginatedProcedures as $procedure)
                    <div class="record-inner-card">
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <input type="checkbox" class="default-checkbox w-5 h-5">
                            </div>

                            <div class="record-inner-column flex-1">
                                <div class="record-inner-label">{{ __('patients.code_and_name') }}</div>
                                <div
                                    class="record-inner-value text-[16px]">
                                    {{ data_get($procedure, 'code.identifier.value') && data_get($procedure, 'code.displayValue')
                                    ? data_get($procedure, 'code.identifier.value') . ' | ' . data_get($procedure, 'code.displayValue')
                                    : '-' }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full md:w-36 shrink-0">
                                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                                <div>
                                    @php
                                        $status = ProcedureStatus::from(data_get($procedure, 'status'));
                                    @endphp
                                    <span @class([$status->color()])>
                                        {{ $status->label() ?? '-' }}
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
                                            class="record-inner-action-btn cursor-pointer"
                                    >
                                        @icon('edit-user-outline', 'w-5 h-5')
                                    </button>

                                    <div x-show="open"
                                         x-cloak
                                         x-ref="panel"
                                         x-transition.origin.top.right
                                         @click.outside="close($refs.button)"
                                         :id="$id('dropdown-button')"
                                         class="absolute right-0 mt-2 w-56 rounded-md bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 shadow-lg z-50 py-1"
                                    >
                                        <button type="button"
                                                @click="close($refs.button)"
                                                wire:click="openProcedureView('{{ data_get($procedure, 'uuid') }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="openProcedureView"
                                                class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors"
                                        >
                                            @icon('eye', 'w-5 h-5 text-gray-500')
                                            {{ __('patients.view_details') }}
                                        </button>
                                        
                                        <button type="button"
                                                @click="close($refs.button)"
                                                wire:click="openProcedureCancellation('{{ data_get($procedure, 'uuid') }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="openProcedureCancellation"
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
                                <div class="grid grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-3">
                                    <div class="space-y-2.5 min-w-0">
                                        <div class="min-w-0">
                                            <div
                                                class="record-inner-label text-[10px] uppercase">{{ __('forms.category') }}</div>
                                            <div class="record-inner-value text-[14px] font-semibold break-words">
                                                @php
                                                    $categoryCode = data_get($procedure, 'category.coding.0.code')
                                                        ?? data_get($procedure, 'category.0.coding.0.code');
                                                @endphp

                                                {{ data_get(
                                                    $this->dictionaries,
                                                    'eHealth/procedure_categories.' . $categoryCode,
                                                    data_get($procedure, 'category.text', data_get($procedure, 'category.0.text', $categoryCode ?: '—'))
                                                ) }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div
                                                class="record-inner-label text-[10px] uppercase">{{ __('patients.referrals') }}</div>
                                            <div class="record-inner-value text-[14px] font-semibold break-words">
                                                {{ data_get($procedure, 'paperReferral.requisition', '—') }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="space-y-2.5 min-w-0">
                                        <div class="min-w-0">
                                            <div
                                                class="record-inner-label text-[10px] uppercase">{{ __('patients.performer') }}</div>
                                            <div
                                                class="record-inner-value text-[14px] font-semibold break-words uppercase">
                                                {{ data_get($procedure, 'performer.displayValue', '-') }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div
                                                class="record-inner-label text-[10px] uppercase">{{ __('patients.notes') }}</div>
                                            <div class="record-inner-value text-[14px] font-semibold break-words">
                                                {{ data_get($procedure, 'note') ?? '-' }}
                                            </div>
                                        </div>
                                    </div>

                                    <div class="space-y-2.5 min-w-0">
                                        <div class="min-w-0">
                                            <div
                                                class="record-inner-label text-[10px] uppercase">{{ __('patients.created') }}</div>
                                            <div class="record-inner-value text-[14px] font-semibold break-words">
                                                @php
                                                    $createdAt = data_get($procedure, 'ehealthInsertedAt') ?: data_get($procedure, 'createdAt');
                                                @endphp

                                                {{ $createdAt ? optional(\Carbon\Carbon::make($createdAt))->format('d.m.Y H:i') : '-' }}
                                            </div>
                                        </div>
                                        <div class="min-w-0">
                                            <div
                                                class="record-inner-label text-[10px] uppercase">{{ __('patients.doctor') }}</div>
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
                                    <div class="record-inner-id-value">
                                        {{ data_get($procedure, 'uuid') ?? '-'}}
                                    </div>
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
            <div class="mt-8">
                {{ $paginatedProcedures->links() }}
            </div>
        </div>
    </div>
    @include('livewire.procedure.procedure-cancellation')
    <x-forms.loading/>
</x-layouts.patient>
