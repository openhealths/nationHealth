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
        actionButtonText: ''
    }"
>
    <livewire:components.x-message :key="now()->timestamp"/>
    <x-forms.loading/>

    <x-header-navigation
        x-data="{ showFilter: false }"
        class="items-start"
    >
        <x-slot name="title">
            {{ __('forms.services') }}
        </x-slot>

        <div class="mt-3 ml-0 flex flex-col sm:flex-row sm:flex-wrap gap-2 self-start">
            @can('create', HealthcareService::class)
                <div
                    x-data="{
                        open: false,
                        search: '',
                        selectedDivisions: []
                    }"
                    @click.outside="open = false; search = ''"
                    class="relative"
                >
                    <button
                        @click="open = !open"
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
                        class="absolute right-0 mt-2 w-72 rounded-xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-lg p-4 z-50"
                    >
                        <div class="relative mb-3">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                @icon('search', 'w-4 h-4 text-blue-500')
                            </div>
                            <input
                                type="text"
                                x-model="search"
                                placeholder="{{ __('forms.search') }}"
                                class="w-full pl-9 pr-3 py-1.5 text-xs rounded-lg border border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-gray-700 dark:text-gray-200"
                                @click.stop
                            />
                        </div>

                        <div class="space-y-2 max-h-48 overflow-y-auto pr-1">
                            @foreach($divisions as $division)
                                @if($division['status'] === DivisionStatus::ACTIVE->value)
                                    <label
                                        x-show="'{{ addslashes($division['name']) }}'.toLowerCase().includes(search.toLowerCase())"
                                        class="flex items-center gap-2.5 text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 p-1.5 rounded cursor-pointer"
                                    >
                                        <input
                                            type="checkbox"
                                            value="{{ $division['id'] }}"
                                            x-model="selectedDivisions"
                                            class="w-4 h-4 text-blue-600 rounded border-gray-300 focus:ring-blue-500"
                                        />
                                        <span>
                                            {{ __('healthcare-services.for_division', ['name' => $division['name']]) }}
                                        </span>
                                    </label>
                                @endif
                            @endforeach
                        </div>

                        <div x-show="selectedDivisions.length > 0" class="mt-3">
                            <a
                                :href="'{{ route('healthcare-service.create', [legalEntity(), 0]) }}'.replace('/0/', '/' + (selectedDivisions[0] || 1) + '/')"
                                class="block w-full button-primary text-center"
                            >
                                {{ __('healthcare-services.create_service') }}
                            </a>
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
                    <span>
                        {{ ($syncStatus === JobStatus::PAUSED->value || $syncStatus === JobStatus::FAILED->value) ? __('forms.sync_retry') : __('forms.synchronise_with_eHealth') }}
                    </span>
                </button>
            @endcan
        </div>
    </x-header-navigation>

    <div class="shift-content flex flex-wrap items-end justify-between pl-2.5 mt-6">
        <div class="ml-3.5 flex flex-col gap-4 w-full max-w-sm">
            <div class="form-group group">
                <select
                    wire:model="typeFilter"
                    name="specialityType"
                    id="specialityType"
                    class="input-select"
                >
                    <option value="" selected>
                        {{ __('forms.select') }}
                    </option>
                    @foreach($dictionaries['SPECIALITY_TYPE'] ?? [] as $key => $specialityType)
                        <option value="{{ $key }}">
                            {{ $specialityType }}
                        </option>
                    @endforeach
                </select>

                <label for="specialityType" class="label">
                    {{ __('healthcare-services.specialisation') }}
                </label>
            </div>

            <div class="form-group group">
                <select
                    wire:model="divisionFilter"
                    name="divisionName"
                    id="divisionName"
                    class="input-select"
                >
                    <option value="" selected>
                        {{ __('forms.select') }}
                    </option>
                    @foreach($divisions as $division)
                        <option value="{{ $division['id'] }}">
                            {{ $division['name'] }}
                        </option>
                    @endforeach
                </select>

                <label for="divisionName" class="label">
                    {{ __('forms.division_name') }}
                </label>
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

            <div class="mb-6 mt-2 flex gap-3">
                @can('viewAny', HealthcareService::class)
                    <button
                        wire:click.prevent="search"
                        class="button-primary flex items-center gap-2"
                    >
                        @icon('search', 'w-4 h-4')
                        <span>
                            {{ __('forms.search') }}
                        </span>
                    </button>
                    <button
                        type="button"
                        wire:click="resetFilters"
                        class="button-primary-outline-red"
                    >
                        {{ __('forms.reset_all_filters') }}
                    </button>
                @endcan
            </div>
        </div>
    </div>

    <div
        class="flow-root mt-6 shift-content pl-3.5"
        wire:key="healthcare-services-table-page-{{ $healthcareServices->total() }}-{{ $healthcareServices->currentPage() }}"
    >
        <div class="max-w-7xl">
            @if($healthcareServices->isNotEmpty())
                <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-sm overflow-hidden">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-gray-50/60 dark:bg-gray-700/40 border-b border-gray-100 dark:border-gray-700">
                                <th class="px-6 py-4 text-[11px] font-bold tracking-wider text-gray-700 uppercase dark:text-gray-300 w-[15%]">
                                    {{ __('forms.division') }}
                                </th>
                                <th class="px-6 py-4 text-[11px] font-bold tracking-wider text-gray-700 uppercase dark:text-gray-300 w-[30%]">
                                    {{ __('healthcare-services.category') }}
                                </th>
                                <th class="px-6 py-4 text-[11px] font-bold tracking-wider text-gray-700 uppercase dark:text-gray-300 w-[30%]">
                                    {{ __('healthcare-services.type') }}
                                </th>
                                <th class="px-6 py-4 text-[11px] font-bold tracking-wider text-gray-700 uppercase dark:text-gray-300 w-[12%]">
                                    {{ __('forms.created_at') }}
                                </th>
                                <th class="px-6 py-4 text-[11px] font-bold tracking-wider text-gray-700 uppercase dark:text-gray-300 w-[13%]">
                                    {{ __('forms.status.label') }}
                                </th>
                                <th class="px-6 py-4 text-[11px] font-bold tracking-wider text-gray-700 uppercase dark:text-gray-300 w-[5%] text-center">
                                    {{ __('forms.action') }}
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($healthcareServices as $service)
                                <tr wire:key="healthcare-service-{{ $service->id }}" class="hover:bg-gray-50/50 dark:hover:bg-gray-700/30 transition-colors">
                                    <td class="px-6 py-4 text-xs font-semibold text-gray-800 dark:text-gray-200">
                                        {{ $service->division?->name ?? '-' }}
                                    </td>

                                    <td class="px-6 py-4 text-xs text-gray-700 dark:text-gray-300">
                                        {{ $service->category?->coding?->first()?->display ?? $dictionaries['SPECIALITY_TYPE'][$service->specialityType] ?? '-' }}
                                    </td>

                                    <td class="px-6 py-4 text-xs text-gray-700 dark:text-gray-300">
                                        {{ $service->type?->coding?->first()?->display ?? $dictionaries['PROVIDING_CONDITION'][$service->providingCondition] ?? '-' }}
                                    </td>

                                    <td class="px-6 py-4 text-xs text-gray-600 dark:text-gray-400">
                                        {{ $service->ehealthInsertedAt?->format('d.m.Y') ?? $service->createdAt?->format('d.m.Y') ?? '-' }}
                                    </td>

                                    <td class="px-6 py-4 text-xs whitespace-nowrap">
                                        @if($service->status === Status::ACTIVE)
                                            <span class="badge-green">
                                                {{ $service->status->label() }}
                                            </span>
                                        @else
                                            <span class="badge-red">
                                                {{ $service->status->label() }}
                                            </span>
                                        @endif
                                    </td>

                                    <td class="px-6 py-4 text-xs text-center">
                                        @if($service->division?->status === DivisionStatus::ACTIVE)
                                            <div class="flex justify-center relative">
                                                <div
                                                    x-data="{
                                                        open: false,
                                                        toggle() { this.open ? this.close() : (this.$refs.button.focus(), this.open = true) },
                                                        close(focusAfter) { if (!this.open) return; this.open = false; focusAfter && focusAfter.focus() }
                                                    }"
                                                    @keydown.escape.prevent.stop="close($refs.button)"
                                                    @focusin.window="!$refs.panel.contains($event.target) && close()"
                                                    x-id="['dropdown-button']"
                                                    class="relative"
                                                >
                                                    <button
                                                        @click="toggle()"
                                                        x-ref="button"
                                                        :aria-expanded="open"
                                                        :aria-controls="$id('dropdown-button')"
                                                        type="button"
                                                        class="hover:text-blue-600 cursor-pointer text-gray-700 dark:text-gray-300"
                                                    >
                                                        @icon('edit-user-outline', 'w-5 h-5')
                                                    </button>

                                                    <div
                                                        x-show="open"
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
                                                                <a
                                                                    href="{{ route('healthcare-service.view', [legalEntity(), $service->division_id, $service->id]) }}"
                                                                    class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600"
                                                                >
                                                                    @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                                    {{ __('forms.view') }}
                                                                </a>
                                                            @endcan

                                                            @can('update', $service)
                                                                <a
                                                                    href="{{ route('healthcare-service.update', [legalEntity(), $service->division_id, $service->id]) }}"
                                                                    class="flex items-center gap-2 w-full px-4 py-2.5 text-left text-sm text-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-600"
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
                                                                        open = !open;
                                                                    "
                                                                    class="cursor-pointer flex items-center gap-2 w-full last-of-type:rounded-b-md px-4 py-2.5 text-left text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-gray-600"
                                                                >
                                                                    @icon('delete', 'w-5 h-5 text-red-600 dark:text-red-400')
                                                                    {{ __('forms.deactivate') }}
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
                <div class="pagination mt-4">
                    {{ $healthcareServices->links() }}
                </div>
            @endif
        </div>
    </div>

    @include('livewire.division.healthcare-service.modal.confirmation-modal')
</div>
