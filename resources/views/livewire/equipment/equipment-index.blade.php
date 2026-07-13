@use('App\Enums\Equipment\Status')
@use('App\Enums\Equipment\AvailabilityStatus')
@use('App\Models\Equipment')
@use('App\Enums\JobStatus')

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
                <button type="button"
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
                               class="flex items-center gap-1 font-semibold text-gray-900 dark:text-white mb-2"
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
                            class="button-minor flex items-center justify-center gap-2 w-full lg:w-auto self-stretch lg:self-auto lg:-translate-y-2.25"
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
                        <x-forms.multiselect
                            bind="statusFilter"
                            :options="Status::options()"
                            label="{{ __('forms.status.label') }}"
                            placeholder="{{ __('forms.select') }}"
                        />

                        {{-- Filter by availability status --}}
                        <x-forms.multiselect
                            bind="availabilityStatusFilter"
                            :options="AvailabilityStatus::options()"
                            label="{{ __('forms.status.label') }}"
                            placeholder="{{ __('forms.select') }}"
                        />
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
        <div class="max-w-7xl">
            @if($equipments->isNotEmpty())
                <div class="index-table-wrapper">
                    <table class="index-table">
                        <thead class="index-table-thead">
                        <tr>
                            <th class="index-table-th w-[13%]">{{ __('forms.name') }}</th>
                            <th class="index-table-th w-[9%]">{{ __('equipments.inventory_number') }}</th>
                            <th class="index-table-th w-[15%]">{{ __('forms.type') }}</th>
                            <th class="index-table-th w-[13%]">{{ __('forms.institution') }}</th>
                            <th class="index-table-th w-[11%]">{{ __('forms.created_at') }}</th>
                            <th class="index-table-th w-[15%]">{{ __('forms.status.label') }}</th>
                            <th class="index-table-th w-[19%]">{{ __('equipments.availability_status.label') }}</th>
                            <th class="index-table-th w-[5%]">{{ __('forms.action') }}</th>
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
                                <td class="index-table-td !whitespace-nowrap">
                                    <span class="whitespace-nowrap">
                                        {{ $equipment->ehealthInsertedAt?->format(config('app.date_format')) ?? $equipment->createdAt->format(config('app.date_format')) }}
                                    </span>
                                </td>
                                <td class="index-table-td">
                                    <span class="inline-flex items-center whitespace-nowrap {{ $equipment->status->color() }}">
                                        {{ $equipment->status->label() }}
                                    </span>
                                </td>
                                <td class="index-table-td">
                                    <span class="inline-flex items-center whitespace-nowrap {{ $equipment->availabilityStatus->color() }}">
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
                                                 class="absolute right-0 mt-2 w-auto min-w-40 max-w-[20rem] rounded-md bg-white shadow-md z-50"
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
                                                               status: '{{ $equipment->status }}',
                                                               availabilityStatus: '{{ $equipment->availabilityStatus }}'
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
                <x-nothing-found />
            @endif

            @if($equipments->isNotEmpty())
                <div class="pagination">
                    {{ $equipments->links() }}
                </div>
            @endif
        </div>
    </div>

    @include('livewire.equipment.modals.update-status-modal')
    @include('livewire.equipment.modals.update-availability-modal')
</div>
