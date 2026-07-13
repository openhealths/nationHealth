@use('App\Enums\Status')
@use('App\Enums\JobStatus')
@use('App\Models\HealthcareService')

<div
    x-data="{
         serviceId: 0,
         textConfirmation: '',
         actionType: '',
         actionTitle: '',
         actionButtonText: ''
     }"
>
    <livewire:components.x-message :key="now()->timestamp"/>
    <x-forms.loading/>

    <x-header-navigation x-data="{ showFilter: false }" class="items-start">
        <x-slot name="title">{{ __('forms.services') }}</x-slot>

        <div class="mt-3 ml-0 flex flex-col sm:flex-row sm:flex-wrap gap-2 self-start">
            @can('create', HealthcareService::class)
                <div x-data="{ open: false, search: '' }" class="relative">
                    <button @click="open = !open"
                            @click.outside="open = false; search = ''"
                            class="button-primary flex items-center gap-2"
                    >
                        @icon('plus', 'w-4 h-4')
                        {{ __('healthcare-services.add') }}
                    </button>

                    <div x-show="open"
                         x-cloak
                         x-transition:enter="transition ease-out duration-100"
                         x-transition:enter-start="transform opacity-0 scale-95"
                         x-transition:enter-end="transform opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-75"
                         x-transition:leave-start="transform opacity-100 scale-100"
                         x-transition:leave-end="transform opacity-0 scale-95"
                         class="absolute left-0 mt-2 w-max min-w-65 max-w-100 max-h-87.5 overflow-hidden flex flex-col rounded-lg bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 shadow-lg z-50"
                    >
                        {{-- Search Header --}}
                        <div class="p-2 border-b border-gray-100 dark:border-gray-600 bg-gray-50/50 dark:bg-gray-800/50">
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none">
                                    @icon('search', 'w-3.5 h-3.5 text-gray-400')
                                </div>
                                <input type="text"
                                       x-model="search"
                                       placeholder="{{ __('forms.search') }}..."
                                       class="block w-full pl-8 pr-3 py-1.5 text-xs bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 dark:text-gray-200"
                                       @click.stop
                                >
                            </div>
                        </div>

                        {{-- List Container --}}
                        <div class="overflow-y-auto custom-scrollbar py-1">
                            @foreach($divisions as $division)
                                @if($division['status'] === Status::ACTIVE->value)
                                    <a href="{{ route('healthcare-service.create', [legalEntity(), $division['id']]) }}"
                                       x-show="'{{ addslashes($division['name']) }}'.toLowerCase().includes(search.toLowerCase())"
                                       class="block px-5 py-3 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors whitespace-normal wrap-break-word"
                                    >
                                        {{ __('healthcare-services.for_division', ['name' => $division['name']]) }}
                                    </a>
                                @endif
                            @endforeach

                            {{-- No results message --}}
                            <div x-show="search !== ''"
                                 x-init="$watch('search', () => {
                                     let items = $el.parentElement.querySelectorAll('a');
                                     let visible = Array.from(items).some(i => i.style.display !== 'none');
                                     $el.style.display = visible ? 'none' : 'block';
                                 })"
                                 class="px-5 py-6 text-xs text-gray-400 text-center"
                                 style="display: none;"
                            >
                                {{ __('forms.nothing_found') }}
                            </div>
                        </div>
                    </div>
                </div>
            @endcan

            @can('sync', HealthcareService::class)
                <button
                    wire:click="{{ !$this->isSync ? 'sync' : '' }}"
                    class="{{ $this->isSync ? 'button-sync-disabled' : 'button-sync' }} flex items-center gap-2 whitespace-nowrap"
                    {{ $this->isSync ? 'disabled' : '' }}
                >
                    @icon('refresh', 'w-4 h-4')
                    <span>{{ ($syncStatus === JobStatus::PAUSED->value || $syncStatus === JobStatus::FAILED->value) ? __('forms.sync_retry') : __('forms.synchronise_with_eHealth') }}</span>
                </button>
            @endcan
        </div>
    </x-header-navigation>

    {{-- Filters --}}
    <div class="shift-content flex flex-wrap items-end justify-between pl-2.5 mt-6">
        <div class="ml-3.5 flex flex-col gap-4">
            <div class="form-group group">
                <select wire:model="typeFilter"
                        type="text"
                        name="specialityType"
                        id="specialityType"
                        class="input-select"
                >
                    <option value="" selected>{{ __('forms.select') }}</option>
                    @foreach($dictionaries['SPECIALITY_TYPE'] as $key => $specialityType)
                        <option value="{{ $key }}"> {{ $specialityType }}</option>
                    @endforeach
                </select>

                <label for="specialityType" class="label">{{ __('healthcare-services.specialisation') }}</label>
            </div>

            <div class="form-group group">
                <select wire:model="divisionFilter"
                        type="text"
                        name="divisionName"
                        id="divisionName"
                        class="input-select"
                >
                    <option value="" selected>{{ __('forms.select') }}</option>
                    @foreach($divisions as $division)
                        <option value="{{ $division['id'] }}"> {{ $division['name'] }}</option>
                    @endforeach
                </select>

                <label for="divisionName" class="label">{{ __('forms.division_name') }}</label>
            </div>

            <x-forms.multiselect
                bind="status"
                :options="[
                    'ACTIVE' => __('forms.status.active'),
                    'INACTIVE' => __('forms.status.non_active'),
                ]"
                label="{{ __('forms.status.label') }}"
                placeholder="{{ __('forms.select') }}"
                :live="true"
            />

            <div class="mb-9 mt-4 flex gap-2">
                @can('viewAny', HealthcareService::class)
                    <button wire:click.prevent="search" class="flex items-center gap-2 button-primary">
                        @icon('search', 'w-4 h-4')
                        <span>{{ __('forms.search') }}</span>
                    </button>
                    <button type="button" wire:click="resetFilters" class="button-primary-outline-red">
                        {{ __('forms.reset_all_filters') }}
                    </button>
                @endcan
            </div>
        </div>
    </div>

    <div class="flow-root mt-8 shift-content pl-3.5"
         wire:key="healthcare-services-table-page-{{ $healthcareServices->total() }}-{{ $healthcareServices->currentPage() }}"
    >
        <div class="max-w-7xl">
            @if($healthcareServices->isNotEmpty())
                <div class="index-table-wrapper">
                    <table class="index-table">
                        <thead class="index-table-thead">
                        <tr>
                            <th class="index-table-th w-[24%]">{{ __('healthcare-services.specialisation') }}</th>
                            <th class="index-table-th w-[24%]">{{ __('forms.division_name') }}</th>
                            <th class="index-table-th w-[18%]">{{ __('healthcare-services.providing_condition') }}</th>
                            <th class="index-table-th w-[14%]">{{ __('forms.created_at') }}</th>
                            <th class="index-table-th w-[14%]">{{ __('healthcare-services.status') }}</th>
                            <th class="index-table-th w-[6%]">{{ __('forms.action') }}</th>
                        </tr>
                        </thead>

                        <tbody>
                        @foreach ($healthcareServices as $service)
                            <tr wire:key="healthcare-service-{{ $service->id }}" class="index-table-tr">
                                <td class="index-table-td-primary">
                                    {{ $dictionaries['SPECIALITY_TYPE'][$service->specialityType] ?? '-' }}
                                </td>

                                <td class="index-table-td">
                                    {{ $service->division->name }}
                                </td>

                                <td class="index-table-td">
                                    {{ $dictionaries['PROVIDING_CONDITION'][$service->providingCondition] ?? '-' }}
                                </td>

                                <td class="index-table-td">
                                    {{ $service->ehealthInsertedAt?->format(config('app.date_format')) ?? $service->createdAt->format(config('app.date_format')) }}
                                </td>

                                <td class="index-table-td">
                                    <span class="{{
                                        match($service->status) {
                                            Status::DRAFT => 'badge-dark',
                                            Status::ACTIVE => 'badge-green',
                                            Status::INACTIVE => 'badge-red',
                                            default => ''
                                        }
                                    }}">
                                        {{ $service->status->label() }}
                                    </span>
                                </td>

                                <td class="index-table-td-actions">
                                    @if($service->division->status === Status::ACTIVE)
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
                                                    @icon('edit-user-outline', 'svg-hover-action w-6 h-6 text-gray-800 dark:text-gray-300')
                                                </button>

                                                <div x-show="open"
                                                     wire:key="dropdown-{{ $service->id }}-{{ $service->status->value }}"
                                                     x-cloak
                                                     x-ref="panel"
                                                     x-transition.origin.top.left
                                                     @click.outside="close($refs.button)"
                                                     :id="$id('dropdown-button')"
                                                     class="absolute right-0 mt-2 w-auto min-w-40 max-w-[20rem] rounded-md bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 shadow-md z-50"
                                                >
                                                    @if ($service->status === Status::ACTIVE)
                                                        @can('view', $service)
                                                            <a href="{{ route('healthcare-service.view', [legalEntity(), $service->division, $service->id]) }}"
                                                               class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600"
                                                            >
                                                                @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                                {{ __('forms.view') }}
                                                            </a>
                                                        @endcan

                                                        @can('update', $service)
                                                            <a href="{{ route('healthcare-service.update', [legalEntity(), $service->division, $service->id]) }}"
                                                               class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600"
                                                            >
                                                                @icon('edit', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                                {{ __('forms.update') }}
                                                            </a>
                                                        @endcan

                                                        @can('deactivate', $service)
                                                            <button type="button"
                                                                    wire:key="deactivate-{{ $service->id }}"
                                                                    @click.prevent="
                                                                        serviceId= {{ $service->getKey() }};
                                                                        textConfirmation = @js(__('healthcare-services.modals.deactivate.confirmation_text'));
                                                                        actionType = 'deactivate';
                                                                        actionTitle = @js(__('healthcare-services.modals.deactivate.title'));
                                                                        actionButtonText = @js(__('forms.deactivate'));
                                                                        open = !open;
                                                                    "
                                                                    class="cursor-pointer flex items-center gap-2 w-full last-of-type:rounded-b-md px-4 py-2.5 text-left text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-gray-600"
                                                            >
                                                                @icon('delete', 'w-5 h-5 text-red-600 dark:text-red-400')
                                                            {{ __('forms.deactivate') }}
                                                            </button>
                                                        @endcan
                                                    @elseif($service->status === Status::DRAFT)
                                                        @can('edit', $service)
                                                            <a href="{{ route('healthcare-service.edit', [legalEntity(), $service->division->id, $service->id]) }}"
                                                               class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600"
                                                            >
                                                                @icon('edit', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                                {{ __('healthcare-services.continue') }}
                                                            </a>
                                                        @endcan

                                                        @can('delete', $service)
                                                            <button wire:click="delete({{ $service->getKey() }}); toggle()"
                                                                    @click="openDropdown = false"
                                                                    type="button"
                                                                    class="cursor-pointer text-nowrap text-red-500 dark:text-red-400 flex gap-3 items-center py-2 pl-4 pr-5 hover:bg-gray-50 dark:hover:bg-gray-600"
                                                            >
                                                                @icon('delete', 'w-5 h-5 text-red-600 dark:text-red-400')
                                                                {{ __('healthcare-services.delete') }}
                                                            </button>
                                                        @endcan
                                                    @else
                                                        @can('activate', $service)
                                                            <button type="button"
                                                                    wire:key="activate-{{ $service->id }}"
                                                                    @click.prevent="
                                                                        serviceId= {{ $service->getKey() }};
                                                                        textConfirmation = @js(__('healthcare-services.modals.activate.confirmation_text'));
                                                                        actionType = 'activate';
                                                                        actionTitle = @js(__('healthcare-services.modals.activate.title'));
                                                                        actionButtonText = @js(__('forms.activate'));
                                                                        open = !open;
                                                                    "
                                                                    class="cursor-pointer flex items-center gap-2 w-full first-of-type:rounded-t-md last-of-type:rounded-b-md px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600"
                                                            >
                                                                @icon('check-circle', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                                {{ __('forms.activate') }}
                                                            </button>
                                                        @endcan
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <x-nothing-found />
            @endif

            @if($healthcareServices->hasPages())
                <div class="pagination">
                    {{ $healthcareServices->links() }}
                </div>
            @endif
        </div>
    </div>

    @include('livewire.division.healthcare-service.modal.confirmation-modal')
</div>
