@use('App\Enums\HealthcareService\Status')
@use('App\Enums\Status', 'DivisionStatus')
@use('App\Enums\JobStatus')
@use('App\Models\HealthcareService')

<div
    x-data="{
        serviceId: 0,
        textConfirmation: '',
        actionType: '',
        actionTitle: '',
        actionButtonText: '',
    }"
>
    <livewire:components.x-message :key="now()->timestamp" />
    <x-forms.loading />

    <x-header-navigation x-data="{ showFilter: false }" class="items-start">
        <x-slot name="title">{{ __('forms.services') }}</x-slot>

        <div class="mt-3 ml-0 flex flex-col gap-2 self-start sm:flex-row sm:flex-wrap">
            @can('create', HealthcareService::class)
                <div x-data="{ open: false, search: '' }" class="relative">
                    <button
                        @click="open = ! open"
                        @click.outside="
                            open = false;
                            search = '';
                        "
                        class="button-primary flex items-center gap-2"
                    >
                        @icon('plus', 'w-4 h-4')
                        {{ __('healthcare-services.add') }}
                    </button>

                    <div
                        x-show="open"
                        x-cloak
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="transform opacity-0 scale-95"
                        x-transition:enter-end="transform opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-75"
                        x-transition:leave-start="transform opacity-100 scale-100"
                        x-transition:leave-end="transform opacity-0 scale-95"
                        class="absolute left-0 z-50 mt-2 flex max-h-87.5 w-max max-w-100 min-w-65 flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-600 dark:bg-gray-700"
                    >
                        {{-- Search Header --}}
                        <div class="border-b border-gray-100 bg-gray-50/50 p-2 dark:border-gray-600 dark:bg-gray-800/50">
                            <div class="relative">
                                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5">
                                    @icon('search', 'w-3.5 h-3.5 text-gray-400')
                                </div>
                                <input
                                    type="text"
                                    x-model="search"
                                    placeholder="{{ __('forms.search') }}..."
                                    class="block w-full rounded-md border border-gray-200 bg-white py-1.5 pr-3 pl-8 text-xs focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
                                    @click.stop
                                />
                            </div>
                        </div>

                        {{-- List Container --}}
                        <div class="custom-scrollbar overflow-y-auto py-1">
                            @foreach ($divisions as $division)
                                @if ($division['status'] === DivisionStatus::ACTIVE->value)
                                    <a
                                        href="{{ route('healthcare-service.create', [legalEntity(), $division['id']]) }}"
                                        x-show="'{{ addslashes($division['name']) }}'.toLowerCase().includes(search.toLowerCase())"
                                        class="block px-5 py-3 text-sm wrap-break-word whitespace-normal text-gray-700 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                    >
                                        {{ __('healthcare-services.for_division', ['name' => $division['name']]) }}
                                    </a>
                                @endif
                            @endforeach

                            {{-- No results message --}}
                            <div
                                x-show="search !== ''"
                                x-init="
                                    $watch('search', () => {
                                        let items = $el.parentElement.querySelectorAll('a');
                                        let visible = Array.from(items).some((i) => i.style.display !== 'none');
                                        $el.style.display = visible ? 'none' : 'block';
                                    })
                                "
                                class="px-5 py-6 text-center text-xs text-gray-400"
                                style="display: none"
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
    <div class="shift-content mt-6 flex flex-wrap items-end justify-between pl-2.5">
        <div class="ml-3.5 flex flex-col gap-4">
            <div class="form-group group">
                <select
                    wire:model="typeFilter"
                    type="text"
                    name="specialityType"
                    id="specialityType"
                    class="input-select"
                >
                    <option value="" selected>{{ __('forms.select') }}</option>
                    @foreach ($dictionaries['SPECIALITY_TYPE'] as $key => $specialityType)
                        <option value="{{ $key }}">{{ $specialityType }}</option>
                    @endforeach
                </select>

                <label for="specialityType" class="label">{{ __('healthcare-services.specialisation') }}</label>
            </div>

            <div class="form-group group">
                <select
                    wire:model="divisionFilter"
                    type="text"
                    name="divisionName"
                    id="divisionName"
                    class="input-select"
                >
                    <option value="" selected>{{ __('forms.select') }}</option>
                    @foreach ($divisions as $division)
                        <option value="{{ $division['id'] }}">{{ $division['name'] }}</option>
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

            <div class="mt-4 mb-9 flex gap-2">
                @can('viewAny', HealthcareService::class)
                    <button wire:click.prevent="search" class="button-primary flex items-center gap-2">
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

    <div
        class="shift-content mt-8 flow-root pl-3.5"
        wire:key="healthcare-services-table-page-{{ $healthcareServices->total() }}-{{ $healthcareServices->currentPage() }}"
    >
        <div class="max-w-7xl">
            @if ($healthcareServices->isNotEmpty())
                <div class="index-table-wrapper">
                    <table class="index-table">
                        <thead class="index-table-thead">
                            <tr>
                                <th class="index-table-th w-[24%]">{{ __('healthcare-services.specialisation') }}</th>
                                <th class="index-table-th w-[24%]">{{ __('forms.division_name') }}</th>
                                <th class="index-table-th w-[18%]">
                                    {{ __('healthcare-services.providing_condition') }}
                                </th>
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

                                    <td class="index-table-td">{{ $service->division->name }}</td>

                                    <td class="index-table-td">
                                        {{ $dictionaries['PROVIDING_CONDITION'][$service->providingCondition] ?? '-' }}
                                    </td>

                                    <td class="index-table-td">
                                        {{ $service->ehealthInsertedAt?->format(config('app.date_format')) ?? $service->createdAt->format(config('app.date_format')) }}
                                    </td>

                                    <td class="index-table-td">
                                        <span
                                            class="{{
                                                match($service->status) {
                                                    Status::DRAFT => 'badge-dark',
                                                    Status::ACTIVE => 'badge-green',
                                                    Status::INACTIVE => 'badge-red',
                                                    default => ''
                                                }
                                            }}"
                                        >
                                            {{ $service->status->label() }}
                                        </span>
                                    </td>

                                    <td class="index-table-td-actions">
                                        @if ($service->division->status === DivisionStatus::ACTIVE)
                                            <div class="relative flex justify-center">
                                                <div
                                                    x-data="{
                                                        open: false,
                                                        toggle() {
                                                            this.open
                                                                ? this.close()
                                                                : (this.$refs.button.focus(), (this.open = true));
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
                                                        class="hover:text-primary cursor-pointer"
                                                    >
                                                        @icon('edit-user-outline', 'svg-hover-action w-6 h-6 text-gray-800 dark:text-gray-300')
                                                    </button>

                                                    <div
                                                        x-show="open"
                                                        wire:key="dropdown-{{ $service->id }}-{{ $service->status->value }}"
                                                        x-cloak
                                                        x-ref="panel"
                                                        x-transition.origin.top.left
                                                        @click.outside="close($refs.button)"
                                                        :id="$id('dropdown-button')"
                                                        class="absolute right-0 z-50 mt-2 w-auto max-w-[20rem] min-w-40 rounded-md border border-gray-200 bg-white shadow-md dark:border-gray-600 dark:bg-gray-700"
                                                    >
                                                        @if ($service->status === Status::ACTIVE)
                                                            @can('view', $service)
                                                                <a
                                                                    href="{{ route('healthcare-service.view', [legalEntity(), $service->division, $service->id]) }}"
                                                                    class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                                >
                                                                    @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                                    {{ __('forms.view') }}
                                                                </a>
                                                            @endcan

                                                            @can('update', $service)
                                                                <a
                                                                    href="{{ route('healthcare-service.update', [legalEntity(), $service->division, $service->id]) }}"
                                                                    class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                                >
                                                                    @icon('edit', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                                    {{ __('forms.update') }}
                                                                </a>
                                                            @endcan

                                                            @can('deactivate', $service)
                                                                <button
                                                                    type="button"
                                                                    wire:key="deactivate-{{ $service->id }}"
                                                                    @click.prevent="
                                                                        serviceId= {{ $service->getKey() }};
                                                                        textConfirmation = @js(__('healthcare-services.modals.deactivate.confirmation_text'));
                                                                        actionType = 'deactivate';
                                                                        actionTitle = @js(__('healthcare-services.modals.deactivate.title'));
                                                                        actionButtonText = @js(__('forms.deactivate'));
                                                                        open = ! open;
                                                                    "
                                                                    class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-red-600 last-of-type:rounded-b-md hover:bg-red-50 dark:text-red-400 dark:hover:bg-gray-600"
                                                                >
                                                                    @icon('delete', 'w-5 h-5 text-red-600 dark:text-red-400')
                                                                    {{ __('forms.deactivate') }}
                                                                </button>
                                                            @endcan
                                                        @elseif ($service->status === Status::DRAFT)
                                                            @can('edit', $service)
                                                                <a
                                                                    href="{{ route('healthcare-service.edit', [legalEntity(), $service->division->id, $service->id]) }}"
                                                                    class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                                >
                                                                    @icon('edit', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                                    {{ __('healthcare-services.continue') }}
                                                                </a>
                                                            @endcan

                                                            @can('delete', $service)
                                                                <button
                                                                    wire:click="delete({{ $service->getKey() }}); toggle()"
                                                                    @click="openDropdown = false"
                                                                    type="button"
                                                                    class="flex cursor-pointer items-center gap-3 py-2 pr-5 pl-4 text-nowrap text-red-500 hover:bg-gray-50 dark:text-red-400 dark:hover:bg-gray-600"
                                                                >
                                                                    @icon('delete', 'w-5 h-5 text-red-600 dark:text-red-400')
                                                                    {{ __('healthcare-services.delete') }}
                                                                </button>
                                                            @endcan
                                                        @else
                                                            @can('activate', $service)
                                                                <button
                                                                    type="button"
                                                                    wire:key="activate-{{ $service->id }}"
                                                                    @click.prevent="
                                                                        serviceId= {{ $service->getKey() }};
                                                                        textConfirmation = @js(__('healthcare-services.modals.activate.confirmation_text'));
                                                                        actionType = 'activate';
                                                                        actionTitle = @js(__('healthcare-services.modals.activate.title'));
                                                                        actionButtonText = @js(__('forms.activate'));
                                                                        open = ! open;
                                                                    "
                                                                    class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 first-of-type:rounded-t-md last-of-type:rounded-b-md hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
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

            @if ($healthcareServices->hasPages())
                <div class="pagination">{{ $healthcareServices->links() }}</div>
            @endif
        </div>
    </div>

    @include('livewire.division.healthcare-service.modal.confirmation-modal')
</div>
