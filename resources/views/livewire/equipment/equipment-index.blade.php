@php
    use App\Enums\Equipment\{Status, AvailabilityStatus};
    use App\Models\Equipment;
    use App\Enums\JobStatus;
@endphp

<div>
    <livewire:components.x-message :key="time()" />
    <x-forms.loading />

    <x-header-navigation class="items-start">
        <x-slot name="title">
            {{ __('equipments.label') }}
        </x-slot>

        <div class="mt-3 ml-0 flex flex-col sm:flex-row sm:flex-wrap gap-2 self-start">
            <a href="{{ route('equipment.create', [legalEntity()]) }}" class="button-primary flex items-center gap-2">
                @icon('plus', 'w-4 h-4')
                {{ __('equipments.new') }}
            </a>

            @can('sync', Equipment::class)
                <button
                    type="button"
                    wire:click="{{ !$this->isSync ? 'sync' : '' }}"
                    class="{{ $this->isSync ? 'button-sync-disabled' : 'button-sync' }} flex items-center gap-2 whitespace-nowrap"
                    {{ $this->isSync ? 'disabled' : '' }}
                >
                    @icon('refresh', 'w-4 h-4')
                    <span>{{ ($syncStatus === JobStatus::PAUSED->value || $syncStatus === JobStatus::FAILED->value) ? __('forms.sync_retry') : __('forms.synchronise_with_eHealth') }}</span>
                </button>
            @endcan
        </div>

        <x-slot name="navigation">
            <div class="flex flex-col -my-4" x-data="{ showFilter: false }">
                <div class="flex mb-4 flex-col lg:flex-row items-stretch lg:items-end gap-2 lg:gap-4 w-full">
                    <div class="w-full lg:w-96">
                        <label for="searchByName"
                               class="text-sm font-medium text-gray-900 dark:text-white block mb-2 flex items-center gap-1"
                        >
                            @icon('search-outline', 'w-4.5 h-4.5')
                            <span>{{ __('equipments.search') }}</span>
                        </label>

                        <div class="form-group group w-full">
                            <input type="text"
                                   id="searchByName"
                                   placeholder=" "
                                   class="input peer"
                                   wire:model="searchByName"
                                   autocomplete="off"
                            />
                            <label for="searchByName" class="label">
                                {{ __('equipments.name_or_inventory_number') }}
                            </label>
                        </div>
                    </div>

                    <button @click="showFilter = !showFilter"
                            class="button-minor flex items-center justify-center gap-2 w-full lg:w-auto self-stretch lg:self-auto lg:-translate-y-[9px]"
                    >
                        @icon('adjustments', 'w-4 h-4')
                        <span>{{ __('forms.additional_search_parameters') }}</span>
                    </button>
                </div>

                {{-- Filters --}}
                <div x-cloak x-show="showFilter" x-transition>
                    <div class="form-row-3">
                        <div class="form-group group">
                            <select wire:model="typeFilter"
                                    name="type"
                                    id="type"
                                    class="peer input-select"
                            >
                                <option value="" selected>{{ __('forms.select') }}</option>
                                @foreach($this->dictionaries['device_definition_classification_type'] as $key => $type)
                                    <option value="{{ $key }}">{{ $type }}</option>
                                @endforeach
                            </select>
                            <label for="type" class="label peer-focus:text-blue-600 peer-valid:text-blue-600">
                                {{ __('equipments.type_medical_device') }}
                            </label>

                            @error('form.type')
                            <p class="text-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="form-group group">
                            <select wire:model="divisionFilter"
                                    name="divisionFilter"
                                    id="divisionFilter"
                                    class="peer input-select"
                            >
                                <option value="" selected>{{ __('forms.select') }}</option>
                                @foreach($divisions as $division)
                                    <option value="{{ $division['id'] }}">{{ $division['name'] }}</option>
                                @endforeach
                            </select>
                            <label for="divisionFilter"
                                   class="label peer-focus:text-blue-600 peer-valid:text-blue-600"
                            >
                                {{ __('forms.division_name') }}
                            </label>

                            @error('form.divisionFilter')
                            <p class="text-error">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="form-row-3">
                        {{-- Filter by status --}}
                        <div class="form-group group"
                             x-data="{ open: false, selectedStatuses: $wire.entangle('statusFilter') }"
                        >
                            <label for="statusFilter" class="label">{{ __('forms.status.label') }}</label>
                            <div class="relative">
                                <input type="text"
                                       id="statusFilter"
                                       class="input peer w-full cursor-pointer text-gray-500 dark:text-gray-400"
                                       placeholder="{{ __('forms.select') }}"
                                       @click="open = !open"
                                       :value="selectedStatuses.length ? selectedStatuses.map(status => {
                                           if (status === 'active') return '{{ __('equipments.status.active') }}';
                                           if (status === 'inactive') return '{{ __('equipments.status.inactive') }}';
                                           if (status === 'DRAFT') return '{{ __('equipments.status.draft') }}';
                                           if (status === 'entered_in_error') return '{{ __('equipments.status.entered_in_error') }}';
                                           return status;
                                       }).join(', ') : ''"
                                       readonly
                                />
                                @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 dark:text-gray-400 pointer-events-none')

                                <div x-show="open"
                                     @click.away="open = false"
                                     x-transition:enter="transition ease-out duration-100"
                                     x-transition:enter-start="transform opacity-0 scale-95"
                                     x-transition:enter-end="transform opacity-100 scale-100"
                                     x-transition:leave="transition ease-in duration-75"
                                     x-transition:leave-start="transform opacity-100 scale-100"
                                     x-transition:leave-end="transform opacity-0 scale-95"
                                     class="absolute z-10 mt-2 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-md shadow-lg"
                                >
                                    <ul class="py-2 px-3 space-y-2 text-sm text-gray-700 dark:text-gray-200">
                                        @foreach(Status::options() as $value => $label)
                                            <li>
                                                <label class="flex items-center space-x-2 cursor-pointer">
                                                    <input type="checkbox"
                                                           value="{{ $value }}"
                                                           wire:model="statusFilter"
                                                           class="rounded-sm text-blue-600 focus:ring-blue-500 border-gray-300 dark:bg-gray-800 dark:border-gray-600 dark:checked:bg-blue-600 dark:checked:border-transparent"
                                                    />
                                                    <span>{{ $label }}</span>
                                                </label>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </div>

                        {{-- Filter by availability status --}}
                        <div class="form-group group"
                             x-data="{ open: false, selectedStatuses: $wire.entangle('availabilityStatusFilter') }"
                        >
                            <label for="statusFilter" class="label">{{ __('forms.status.label') }}</label>
                            <div class="relative">
                                <input type="text"
                                       id="statusFilter"
                                       class="input peer w-full cursor-pointer text-gray-500 dark:text-gray-400"
                                       placeholder="{{ __('forms.select') }}"
                                       @click="open = !open"
                                       :value="selectedStatuses.length ? selectedStatuses.map(status => {
                                           if (status === 'available') return '{{ __('equipments.availability_status.available') }}';
                                           if (status === 'damaged') return '{{ __('equipments.availability_status.damaged') }}';
                                           if (status === 'destroyed') return '{{ __('equipments.availability_status.destroyed') }}';
                                           if (status === 'lost') return '{{ __('equipments.availability_status.lost') }}';
                                           return status;
                                       }).join(', ') : ''"
                                       readonly
                                />
                                @icon('chevron-down', 'w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 dark:text-gray-400 pointer-events-none')

                                <div x-show="open"
                                     @click.away="open = false"
                                     x-transition:enter="transition ease-out duration-100"
                                     x-transition:enter-start="transform opacity-0 scale-95"
                                     x-transition:enter-end="transform opacity-100 scale-100"
                                     x-transition:leave="transition ease-in duration-75"
                                     x-transition:leave-start="transform opacity-100 scale-100"
                                     x-transition:leave-end="transform opacity-0 scale-95"
                                     class="absolute z-10 mt-2 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-md shadow-lg"
                                >
                                    <ul class="py-2 px-3 space-y-2 text-sm text-gray-700 dark:text-gray-200">
                                        @foreach(AvailabilityStatus::options() as $value => $label)
                                            <li>
                                                <label class="flex items-center space-x-2 cursor-pointer">
                                                    <input type="checkbox"
                                                           value="{{ $value }}"
                                                           wire:model="availabilityStatusFilter"
                                                           class="rounded-sm text-blue-600 focus:ring-blue-500 border-gray-300 dark:bg-gray-800 dark:border-gray-600 dark:checked:bg-blue-600 dark:checked:border-transparent"
                                                    />
                                                    <span>{{ $label }}</span>
                                                </label>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-9 mt-6 flex flex-col sm:flex-row gap-2 w-full">
                    <button wire:click="search" type="submit" class="flex items-center gap-2 button-primary">
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('forms.search') }}</span>
                    </button>
                    <button type="button" wire:click="resetFilters" class="button-primary-outline-red">
                        {{ __('forms.reset_all_filters') }}
                    </button>
                </div>
            </div>
        </x-slot>
    </x-header-navigation>

    <div class="flow-root mt-8 shift-content pl-3.5"
         wire:key="equipments-table-page-{{ $equipments->total() }}-{{ $equipments->currentPage() }}"
    >
        <div class="max-w-screen-xl">
            @if($equipments->isNotEmpty())
                <div class="index-table-wrapper">
                    <table class="index-table">
                        <thead class="index-table-thead">
                        <tr>
                            <th class="index-table-th w-[15%]">{{ __('forms.name') }}</th>
                            <th class="index-table-th w-[10%]">{{ __('equipments.inventory_number') }}</th>
                            <th class="index-table-th w-[20%]">{{ __('forms.type') }}</th>
                            <th class="index-table-th w-[15%]">{{ __('forms.institution') }}</th>
                            <th class="index-table-th w-[10%]">{{ __('forms.created_at') }}</th>
                            <th class="index-table-th w-[10%]">{{ __('forms.status.label') }}</th>
                            <th class="index-table-th w-[14%]">{{ __('equipments.availability_status.label') }}</th>
                            <th class="index-table-th w-[6%]">{{ __('forms.action') }}</th>
                        </tr>
                        </thead>

                        <tbody>
                        @foreach ($equipments as $equipment)
                            <tr wire:key="equipment-{{ $equipment->id }}" class="index-table-tr">
                                <td class="index-table-td-primary">
                                    <ul>
                                        @foreach ($equipment->names as $name)
                                            <li>{{ $name->name }}</li>
                                        @endforeach
                                    </ul>
                                </td>
                                <td class="index-table-td">
                                    {{ $equipment->inventoryNumber ?? '-' }}
                                </td>
                                <td class="index-table-td">
                                    {{ $this->dictionaries['device_definition_classification_type'][$equipment->type] }}
                                </td>
                                <td class="index-table-td">
                                    {{ $equipment->division?->name ?? '-' }}
                                </td>
                                <td class="index-table-td">
                                    {{ $equipment->ehealthInsertedAt?->format('d.m.Y') ?? $equipment->createdAt->format('d.m.Y') }}
                                </td>
                                <td class="index-table-td">
                                    <span class="{{
                                        match($equipment->status) {
                                            Status::DRAFT => 'badge-dark',
                                            Status::ACTIVE => 'badge-green',
                                            Status::INACTIVE, Status::ENTERED_IN_ERROR => 'badge-red',
                                            default => ''
                                        }
                                    }}">
                                        {{ $equipment->status->label() }}
                                    </span>
                                </td>
                                <td class="index-table-td">
                                    <span class="{{
                                        match($equipment->availabilityStatus) {
                                            AvailabilityStatus::AVAILABLE => 'badge-green',
                                            AvailabilityStatus::DAMAGED, AvailabilityStatus::DESTROYED, AvailabilityStatus::LOST => 'badge-red',
                                            default => ''
                                        }
                                    }}">
                                        {{ $equipment->availabilityStatus->label() }}
                                    </span>
                                </td>
                                <td class="index-table-td-actions">
                                    <div class="flex justify-center relative">
                                        <div x-data="{
                                                 open: false,
                                                 toggle() { this.open ? this.close() : (this.$refs.button.focus(), this.open = true) },
                                                 close(focusAfter) { if (!this.open) return; this.open = false; focusAfter && focusAfter.focus() }
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
                                                    class="hover:text-primary cursor-pointer"
                                            >
                                                @icon('edit-user-outline', 'svg-hover-action w-6 h-6 text-gray-800 dark:text-white')
                                            </button>

                                            <div x-show="open"
                                                 wire:key="dropdown-{{ $equipment->id }}-{{ $equipment->status->value }}"
                                                 x-cloak
                                                 x-ref="panel"
                                                 x-transition.origin.top.left
                                                 @click.outside="close($refs.button)"
                                                 :id="$id('dropdown-button')"
                                                 class="absolute right-0 mt-2 w-auto min-w-[10rem] max-w-[20rem] rounded-md bg-white shadow-md z-50"
                                            >
                                                @if ($equipment->status === Status::ACTIVE || $equipment->status === Status::INACTIVE)
                                                    @can('view', $equipment)
                                                        <a href="{{ route('equipment.view', [legalEntity(), $equipment->id]) }}"
                                                           class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 hover:bg-gray-50"
                                                        >
                                                            @icon('eye', 'w-5 h-5 text-gray-600')
                                                            {{ __('forms.view') }}
                                                        </a>
                                                    @endcan

                                                    @can('updateStatus', $equipment)
                                                        <a href="#"
                                                           @click.prevent="$dispatch('open-update-status-modal', {
                                                               uuid: '{{ $equipment->uuid }}',
                                                               name: '{{ $equipment->names->first()->name }}',
                                                               status: '{{ $equipment->status }}'
                                                           })"
                                                           class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 hover:bg-gray-50"
                                                        >
                                                            @icon('edit', 'w-5 h-5 text-gray-600')
                                                            {{ __('equipments.update_status') }}
                                                        </a>
                                                    @endcan

                                                    @can('updateAvailabilityStatus', $equipment)
                                                        @if ($equipment->status === Status::ACTIVE)
                                                            <a href="#"
                                                               @click.prevent="$dispatch('open-update-availability-status-modal', {
                                                                   uuid: '{{ $equipment->uuid }}',
                                                                   name: '{{ $equipment->names->first()->name }}',
                                                                   status: '{{ $equipment->availabilityStatus }}'
                                                               })"
                                                               class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 hover:bg-gray-50"
                                                            >
                                                                @icon('edit', 'w-5 h-5 text-gray-600')
                                                                {{ __('equipments.update_availability_status') }}
                                                            </a>
                                                        @endif
                                                    @endcan
                                                @else
                                                    @can('view', $equipment)
                                                        <a href="{{ route('equipment.view', [legalEntity(), $equipment->id]) }}"
                                                           class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 hover:bg-gray-50"
                                                        >
                                                            @icon('eye', 'w-5 h-5 text-gray-600')
                                                            {{ __('forms.view') }}
                                                        </a>
                                                    @endcan

                                                    @can('edit', $equipment)
                                                        <a href="{{ route('equipment.edit', [legalEntity(), $equipment->id]) }}"
                                                           class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 hover:bg-gray-50"
                                                        >
                                                            @icon('edit', 'w-5 h-5 text-gray-600')
                                                            {{ __('forms.edit') }}
                                                        </a>
                                                    @endcan
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <fieldset class="fieldset !mx-auto mt-8 shift-content">
                    <legend class="legend relative -top-5">@icon('nothing-found', 'w-28 h-28')</legend>
                    <div class="p-4 rounded-lg bg-blue-100 flex items-start mb-4">
                        <div class="flex items-start gap-3">
                            <div class="flex-shrink-0 mt-0.5">
                                @icon('alert-circle', 'w-5 h-5 text-blue-500 mr-3 mt-1')
                            </div>
                            <div class="flex-1">
                                <p class="font-bold text-blue-800">
                                    {{ __('forms.nothing_found') }}
                                </p>
                                <p class="text-sm text-blue-600">
                                    {{ __('forms.changing_search_parameters') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </fieldset>
            @endif

            <div class="mt-8 pl-3.5 pb-8 lg:pl-8 2xl:pl-5">
                {{ $equipments->links() }}
            </div>
        </div>
    </div>

    @include('livewire.equipment.modals.update-status-modal')
    @include('livewire.equipment.modals.update-availability-modal')
</div>
