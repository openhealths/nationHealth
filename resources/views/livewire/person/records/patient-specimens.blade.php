@use(App\Enums\Specimen\Status as SpecimenStatus)
@use(App\Models\MedicalEvents\Sql\Specimen)

<x-layouts.patient
    :personId="$personId"
    :prepersonId="$prepersonId"
    :patientFullName="$patientFullName"
    activeTab="specimens"
>
    <x-slot name="headerActions">
        @can('create', Specimen::class)
            <a
                href="{{ $prepersonId ? route('prepersons.specimens.create', [legalEntity(), 'preperson' => $prepersonId]) : route('persons.specimens.create', [legalEntity(), 'person' => $personId]) }}"
                class="button-primary"
                wire:navigate
            >
                {{ __('specimens.new_specimen') }}
            </a>
        @endcan
        <button type="button" class="button-primary-outline px-5 py-2 text-sm whitespace-nowrap">
            {{ __('patients.data_access') }}
        </button>
        <button
            wire:click.prevent="sync"
            wire:loading.attr="disabled"
            wire:target="sync"
            type="button"
            class="button-sync flex items-center gap-2 px-5 py-2 text-sm whitespace-nowrap shadow-sm transition-colors"
        >
            @icon('refresh', 'w-4 h-4')
            {{ __('forms.synchronise_with_eHealth') }}
        </button>
    </x-slot>

    <div class="breadcrumb-form shift-content p-4">
        <div class="mt-6 w-full" x-data="{ showAdditionalParams: $wire.entangle('showAdditionalParams') }">
            <div class="mb-4 flex items-center gap-1 font-semibold text-gray-900 dark:text-gray-100">
                @icon('search-outline', 'w-4.5 h-4.5')
                <p>{{ __('specimens.search_specimens') }}</p>
            </div>

            <div class="form-row-3 mb-6">
                <div class="form-group group">
                    <select id="filterStatus" wire:model="filterStatus" class="input-select peer w-full">
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach (SpecimenStatus::cases() as $specimenStatus)
                            @continue($specimenStatus === SpecimenStatus::DRAFT)
                            <option value="{{ $specimenStatus->value }}">{{ $specimenStatus->label() }}</option>
                        @endforeach
                    </select>
                    <label class="label" for="filterStatus">{{ __('forms.status.label') }}</label>
                </div>
                <div class="form-group group">
                    <select id="filterType" wire:model="filterType" class="input-select peer w-full">
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach ($dictionaries['specimen_types'] as $code => $name)
                            <option value="{{ $code }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    <label class="label" for="filterType">{{ __('specimens.specimen_type') }}</label>
                </div>
                <div class="form-group group">
                    <div class="datepicker-wrapper">
                        <input
                            id="filterCollectedRange"
                            type="text"
                            class="daterangepicker-uk with-leading-icon input peer w-full"
                            placeholder=" "
                            autocomplete="off"
                            wire:model="filterCollectedRange"
                        />
                        <label class="wrapped-label" for="filterCollectedRange">
                            {{ __('specimens.collection_date_range') }}
                        </label>
                    </div>
                </div>
            </div>

            <div class="mb-9 flex flex-wrap justify-between gap-4">
                <div class="flex flex-wrap gap-2">
                    <button
                        wire:click.prevent="search"
                        type="button"
                        class="button-primary flex items-center gap-2 px-5 py-2.5 text-sm shadow-sm"
                    >
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('forms.search') }}</span>
                    </button>
                    <button
                        wire:click.prevent="resetFilters"
                        type="button"
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
                <div
                    class="relative"
                    x-data="{ openGroupActions: false }"
                    @keydown.escape.prevent.stop="openGroupActions = false"
                    @click.outside="openGroupActions = false"
                >
                    <button
                        type="button"
                        @click="openGroupActions = ! openGroupActions"
                        class="button-primary-outline px-5 py-2.5 text-sm"
                    >
                        {{ __('forms.group_actions') }}
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
                                class="dropdown-button flex! w-full items-center gap-2.5 px-4 py-2 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
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
                <div class="form-row-3 mb-6">
                    <x-forms.combobox
                        :options="$employees"
                        bind="filterRegisteredBy"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('specimens.employee_created_record')"
                    />
                    <div class="form-group group">
                        <input
                            id="filterContainerIdentifier"
                            type="text"
                            class="input peer"
                            wire:model="filterContainerIdentifier"
                            placeholder=" "
                            autocomplete="off"
                        />
                        <label class="label" for="filterContainerIdentifier">{{ __('specimens.container_id') }}</label>
                    </div>
                    <div class="form-group group">
                        <select
                            id="filterContainerType"
                            wire:model="filterContainerType"
                            class="input-select peer w-full"
                        >
                            <option value="" selected>{{ __('forms.select') }}</option>
                            @foreach ($dictionaries['specimen_container_types'] as $code => $name)
                                <option value="{{ $code }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        <label class="label" for="filterContainerType">{{ __('specimens.container_type') }}</label>
                    </div>
                </div>

                <div class="form-row-3 mb-6">
                    <x-forms.combobox
                        :options="$parentSpecimens"
                        bind="filterParent"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('specimens.parent_specimen')"
                    />
                    <x-forms.combobox
                        :options="$referrals"
                        bind="filterRequest"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('specimens.electronic_referral')"
                    />
                    <x-forms.combobox
                        :options="$encounters"
                        bind="filterEncounter"
                        bindValue="uuid"
                        bindParam="name"
                        :label="__('specimens.encounter')"
                    />
                </div>
            </div>

            <div class="mt-6 space-y-4">
                @forelse ($this->paginatedSpecimens as $specimen)
                    @php
                        $collectedDateTime = data_get($specimen, 'collection.collectedDateTime');
                        $collectedPeriodStart = data_get($specimen, 'collection.collectedPeriod.start');
                        $collectedPeriodEnd = data_get($specimen, 'collection.collectedPeriod.end');
                        $status = SpecimenStatus::from(data_get($specimen, 'status'));
                    @endphp
                    <div class="record-inner-card" wire:key="specimen-{{ data_get($specimen, 'uuid') }}">
                        <div class="record-inner-header">
                            <div class="record-inner-checkbox-col">
                                <label
                                    for="specimenRecord{{ $loop->index }}"
                                    class="sr-only"
                                >{{ __('forms.select') }}</label>
                                <input
                                    type="checkbox"
                                    id="specimenRecord{{ $loop->index }}"
                                    class="default-checkbox h-5 w-5"
                                />
                            </div>
                            <div class="record-inner-column flex-1">
                                <div class="record-inner-label">
                                    {{ __('specimens.specimen_number') }} {{ data_get($specimen, 'accessionIdentifier') ?? '-' }}
                                </div>
                                <div class="record-inner-value text-[17px] font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $this->dictionaryLabel($specimen, 'type') }}
                                </div>
                            </div>

                            <div class="record-inner-column-bordered w-full shrink-0 md:w-48">
                                <div class="record-inner-label">{{ __('forms.status.label') }}</div>
                                <div>
                                    <span class="{{ $status->color() }}">{{ $status->label() }}</span>
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
                                        @if (data_get($specimen, 'status') === SpecimenStatus::DRAFT->value)
                                            <a
                                                href="{{
                                                    $prepersonId
                                                    ? route('prepersons.specimens.edit', [legalEntity(), 'preperson' => $prepersonId, 'specimenId' => data_get($specimen, 'id')])
                                                    : route('persons.specimens.edit', [legalEntity(), 'person' => $personId, 'specimenId' => data_get($specimen, 'id')])
                                                }}"
                                                class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                            >
                                                @icon('edit', 'w-5 h-5 text-gray-500')
                                                {{ __('forms.edit') }}
                                            </a>
                                        @elseif (data_get($specimen, 'id'))
                                            <a
                                                href="{{
                                                    $prepersonId
                                                    ? route('prepersons.specimens.view', [legalEntity(), 'preperson' => $prepersonId, 'specimen' => data_get($specimen, 'id')])
                                                    : route('persons.specimens.view', [legalEntity(), 'person' => $personId, 'specimen' => data_get($specimen, 'id')])
                                                }}"
                                                class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                            >
                                                @icon('eye', 'w-5 h-5 text-gray-500')
                                                {{ __('forms.view_details') }}
                                            </a>
                                        @else
                                            {{-- Found through the eHealth search: the record is stored on the way to its page --}}
                                            <button
                                                type="button"
                                                wire:click="view('{{ data_get($specimen, 'uuid') }}')"
                                                class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                            >
                                                @icon('eye', 'w-5 h-5 text-gray-500')
                                                {{ __('forms.view_details') }}
                                            </button>
                                        @endif

                                        @if (data_get($specimen, 'context.identifier.value'))
                                            {{-- Recorded in an encounter package, so it is marked as entered in error through the package --}}
                                            @if (data_get($specimen, 'status') !== SpecimenStatus::ENTERED_IN_ERROR->value)
                                                <button
                                                    type="button"
                                                    wire:click="openRecordCancellation('specimens', '{{ data_get($specimen, 'uuid') }}')"
                                                    @click="close($refs.button)"
                                                    class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                >
                                                    @icon('alert-circle', 'w-5 h-5 text-gray-500')
                                                    {{ __('medical-events.mark_as_error') }}
                                                </button>
                                            @endif
                                        @else
                                            @can('cancel', [Specimen::class, $specimen])
                                                <button
                                                    type="button"
                                                    @click="
                                                        $dispatch('open-specimen-cancellation', { id: '{{ data_get($specimen, 'uuid') }}' });
                                                        close($refs.button);
                                                    "
                                                    class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                >
                                                    @icon('alert-circle', 'w-5 h-5 text-gray-500')
                                                    {{ __('medical-events.mark_as_error') }}
                                                </button>
                                            @endcan
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="record-inner-body">
                            <div class="record-inner-grid-container">
                                <div class="grid grid-cols-2 gap-x-4 gap-y-3 md:grid-cols-3">
                                    <div>
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('specimens.collection') }}
                                        </div>
                                        <div class="record-inner-value font-semibold">
                                            @if ($collectedDateTime)
                                                {{ $collectedDateTime }}
                                            @else
                                                {{ $collectedPeriodStart ?? '-' }}
                                                @if ($collectedPeriodEnd)
                                                    -<br

                                                    />{{ $collectedPeriodEnd }}
                                                @endif
                                            @endif
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('specimens.parent_specimen') }}
                                        </div>
                                        <div class="record-inner-value font-semibold">
                                            @forelse (data_get($specimen, 'parent', []) as $parent)
                                                <div>{{ data_get($parent, 'identifier.value') }}</div>
                                            @empty
                                                -
                                            @endforelse
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('specimens.containers_and_type') }}
                                        </div>
                                        <div class="record-inner-value font-semibold">
                                            @forelse (data_get($specimen, 'container', []) as $container)
                                                <div>
                                                    {{ data_get($container, 'identifier') }}, {{ $this->dictionaryLabel($container, 'type') }}
                                                </div>
                                            @empty
                                                -
                                            @endforelse
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('specimens.created_by') }}
                                        </div>
                                        <div class="record-inner-value font-semibold">
                                            {{ data_get($specimen, 'registeredBy.displayValue') ?? '-' }}
                                        </div>
                                    </div>
                                    <div>
                                        <div class="record-inner-label text-[10px] uppercase">
                                            {{ __('specimens.electronic_referral') }}
                                        </div>
                                        <div class="record-inner-value font-semibold">
                                            @forelse (data_get($specimen, 'request', []) as $request)
                                                <div>{{ data_get($request, 'identifier.value') }}</div>
                                            @empty
                                                -
                                            @endforelse
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="record-inner-id-col md:w-64!">
                                <div class="min-w-0">
                                    <div class="record-inner-label">{{ __('specimens.encounter') }}</div>
                                    <div class="record-inner-id-value font-semibold whitespace-nowrap">
                                        {{ data_get($specimen, 'context.identifier.value') ?? '-' }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <x-nothing-found :description="null" />
                @endforelse
            </div>

            <div class="mt-8">{{ $this->paginatedSpecimens->links() }}</div>
        </div>
    </div>

    <livewire:specimen.specimen-cancellation :patient-id="$uuid" />

    @include('livewire.encounter-package.encounter-cancellation')
</x-layouts.patient>
