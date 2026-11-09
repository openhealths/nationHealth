<div>
    @if ($selectionMode)
        <div class="mb-8">
            <h3 class="modal-header mb-8 !border-b-0 pb-0">{{ __('dictionaries.service_catalog.search_service') }}</h3>

            @include('livewire.dictionary.parts.service-catalog-filters')
        </div>
    @else
        <x-header-navigation class="breadcrumb-form">
            <x-slot name="title">{{ __('dictionaries.service_catalog.title') }}</x-slot>

            <x-slot name="navigation">
                @include('livewire.dictionary.parts.service-catalog-filters')
            </x-slot>
        </x-header-navigation>
    @endif

    <p wire:dirty wire:target="filterCodeOrName" role="status" class="mt-4 text-sm text-gray-500 dark:text-gray-400">
        {{ __('dictionaries.service_catalog.search_pending') }}
    </p>

    <div wire:dirty.remove wire:target="filterCodeOrName" @class(['flow-root mt-8 pl-3.5', 'shift-content' => !$selectionMode,])>
        <div @class(['max-w-screen-xl' => !$selectionMode, 'w-full' => $selectionMode])>
            <div class="index-table-wrapper">
                <table class="index-table">
                    <thead class="index-table-thead">
                        <tr>
                            <th class="index-table-th w-[40%]">{{ __('forms.name') }}</th>
                            <th class="index-table-th w-[20%]">
                                {{ __('dictionaries.service_catalog.allowed_for_en') }}
                            </th>
                            <th class="index-table-th w-[20%]">{{ __('forms.code') }}</th>
                            <th class="index-table-th w-[20%]">{{ __('forms.status.label') }}</th>
                            @if ($selectionMode)
                                <th class="index-table-th w-[10%] text-center">{{ __('forms.action') }}</th>
                            @endif
                        </tr>
                    </thead>

                    <tbody
                        wire:key="service-catalog-tree-{{ $this->getId() }}-{{ md5(json_encode([$filterCodeOrName, $filterCategory, $filterServiceIsActive, $filterGroupIsActive, $filterRequestAllowed, $services->currentPage()])) }}"
                        x-data="{
                            openIds: {},

                            isOpen(id) {
                                return this.openIds[id] ?? this.$root.dataset.expanded === 'true';
                            },

                            toggle(id) {
                                this.openIds[id] = ! this.isOpen(id);
                            },
                        }"
                        data-expanded="{{ $this->hasActiveFilters() ? 'true' : 'false' }}"
                    >
                        @forelse ($services as $item)
                            @php
                                $itemId = $item['id'] ?? ('item-' . $loop->index);
                                $hasGroups = !empty($item['groups']);
                                $hasServices = !empty($item['services']);
                                $hasChildren = $hasGroups || $hasServices;
                            @endphp

                            {{-- Main category/service --}}
                            <tr class="index-table-tr">
                                <td class="index-table-td-primary">
                                    <div class="flex items-start gap-3">
                                        <div class="mt-1 w-6 flex-shrink-0">
                                            @if ($hasChildren)
                                                <button
                                                    type="button"
                                                    @click="toggle('{{ $itemId }}')"
                                                    class="inline-block cursor-pointer rounded p-0.5 hover:bg-gray-100 dark:hover:bg-gray-700"
                                                    :aria-expanded="isOpen('{{ $itemId }}')"
                                                >
                                                    <span
                                                        class="inline-block transition-transform duration-200"
                                                        :class="isOpen('{{ $itemId }}') ? 'rotate-0' : '-rotate-90'"
                                                    >
                                                        @icon('chevron-down', 'w-4 h-4 text-gray-800 dark:text-white')
                                                    </span>
                                                </button>
                                            @endif
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="font-semibold">{{ $item['name'] ?? '' }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="index-table-td">
                                    @if (!empty($item['request_allowed']))
                                        <span class="text-lg font-semibold text-green-600">+</span>
                                    @else
                                        <span class="text-lg font-semibold text-red-600">-</span>
                                    @endif
                                </td>
                                <td class="index-table-td font-semibold">{{ $item['code'] ?? '-' }}</td>
                                <td class="index-table-td">
                                    @if (!empty($item['is_active']))
                                        <span class="badge-green"> {{ __('forms.status.active') }} </span>
                                    @else
                                        <span class="badge-red"> {{ __('forms.status.non_active') }} </span>
                                    @endif
                                </td>
                                @if ($selectionMode)
                                    @include('livewire.dictionary.parts.service-group-selection', ['groupItem' => $item])
                                @endif
                            </tr>

                            {{-- Services directly under this item --}}
                            @if ($hasServices)
                                @foreach ($item['services'] as $service)
                                    <tr
                                        x-cloak
                                        class="index-table-tr bg-blue-50/50 dark:bg-blue-900/20"
                                        x-show="isOpen('{{ $itemId }}')"
                                        x-transition:enter="transition ease-out duration-150"
                                        x-transition:enter-start="opacity-0"
                                        x-transition:enter-end="opacity-100"
                                    >
                                        <td class="index-table-td-primary">
                                            <div class="flex items-start gap-3">
                                                <div class="mt-1 ml-6 w-6 flex-shrink-0">
                                                    @icon('chevron-right', 'w-3 h-3 text-gray-400 mt-1')
                                                </div>
                                                <div class="flex flex-col">
                                                    <span>{{ $service['name'] ?? '' }}</span>
                                                    @if (!empty($service['category']))
                                                        <span class="mt-1 inline-block w-fit rounded bg-gray-100 px-1 text-xs text-gray-600 dark:bg-gray-700">
                                                            {{ $this->dictionaries['SERVICE_CATEGORY'][$service['category']] }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                        <td class="index-table-td">
                                            @if (!empty($service['request_allowed']))
                                                <span class="text-lg font-semibold text-green-600">+</span>
                                            @else
                                                <span class="text-lg font-semibold text-red-600">-</span>
                                            @endif
                                        </td>
                                        <td class="index-table-td font-semibold">{{ $service['code'] ?? '-' }}</td>
                                        <td class="index-table-td">
                                            @if (!empty($service['is_active']))
                                                <span class="badge-green"> {{ __('forms.status.active') }} </span>
                                            @else
                                                <span class="badge-red"> {{ __('forms.status.non_active') }} </span>
                                            @endif
                                        </td>
                                        @if ($selectionMode)
                                            <td class="index-table-td text-center">
                                                @if (!empty($service['is_active']))
                                                    <button
                                                        type="button"
                                                        wire:click="selectService(@js($service['id']))"
                                                        class="inline-flex cursor-pointer items-center justify-center"
                                                        title="{{ __('forms.select') }}"
                                                    >
                                                        @icon('plus-circle', 'w-6 h-6')
                                                    </button>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            @endif

                            {{-- Groups under this item --}}
                            @if ($hasGroups)
                                @foreach ($item['groups'] as $group)
                                    @php
                                        $groupId = $group['id'] ?? ('group-' . $loop->parent->index . '-' . $loop->index);
                                        $groupHasGroups = !empty($group['groups']);
                                        $groupHasServices = !empty($group['services']);
                                        $groupHasChildren = $groupHasGroups || $groupHasServices;
                                    @endphp

                                    {{-- Group row --}}
                                    <tr
                                        x-cloak
                                        class="index-table-tr bg-gray-50/50 dark:bg-gray-800/50"
                                        x-show="isOpen('{{ $itemId }}')"
                                        x-transition:enter="transition ease-out duration-150"
                                        x-transition:enter-start="opacity-0"
                                        x-transition:enter-end="opacity-100"
                                    >
                                        <td class="index-table-td-primary">
                                            <div class="flex items-start gap-3">
                                                <div class="mt-1 ml-6 w-6 flex-shrink-0">
                                                    @if ($groupHasChildren)
                                                        <button
                                                            type="button"
                                                            @click="toggle('{{ $groupId }}')"
                                                            class="inline-block rounded p-0.5 hover:bg-gray-100 dark:hover:bg-gray-700"
                                                            :aria-expanded="isOpen('{{ $groupId }}')"
                                                        >
                                                            <span
                                                                class="inline-block transition-transform duration-200"
                                                                :class="isOpen('{{ $groupId }}') ? 'rotate-0' : '-rotate-90'"
                                                            >
                                                                @icon('chevron-down', 'w-4 h-4 text-gray-600 dark:text-gray-400')
                                                            </span>
                                                        </button>
                                                    @else
                                                        @icon('chevron-right', 'w-3 h-3 text-gray-400 mt-1')
                                                    @endif
                                                </div>
                                                <div class="flex flex-col">
                                                    <span class="font-medium">{{ $group['name'] ?? '' }}</span>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="index-table-td">
                                            @if (!empty($group['request_allowed']))
                                                <span class="text-lg font-semibold text-green-600">+</span>
                                            @else
                                                <span class="text-lg font-semibold text-red-600">-</span>
                                            @endif
                                        </td>
                                        <td class="index-table-td font-semibold">{{ $group['code'] ?? '-' }}</td>
                                        <td class="index-table-td">
                                            @if (!empty($group['is_active']))
                                                <span class="badge-green"> {{ __('forms.status.active') }} </span>
                                            @else
                                                <span class="badge-red"> {{ __('forms.status.non_active') }} </span>
                                            @endif
                                        </td>
                                        @if ($selectionMode)
                                            @include('livewire.dictionary.parts.service-group-selection', ['groupItem' => $group])
                                        @endif
                                    </tr>

                                    {{-- Subgroups --}}
                                    @if ($groupHasGroups)
                                        @foreach ($group['groups'] as $subgroup)
                                            @php
                                                $subgroupId = $subgroup['id'] ?? ('subgroup-' . $loop->parent->parent->index . '-' . $loop->parent->index . '-' . $loop->index);
                                                $subgroupHasServices = !empty($subgroup['services']);
                                            @endphp

                                            {{-- Subgroup row --}}
                                            <tr
                                                x-cloak
                                                class="index-table-tr bg-yellow-50/50 dark:bg-yellow-900/20"
                                                x-show="isOpen('{{ $itemId }}') && isOpen('{{ $groupId }}')"
                                                x-transition:enter="transition ease-out duration-150"
                                                x-transition:enter-start="opacity-0"
                                                x-transition:enter-end="opacity-100"
                                            >
                                                <td class="index-table-td-primary">
                                                    <div class="flex items-start gap-3">
                                                        <div class="mt-1 ml-12 w-6 flex-shrink-0">
                                                            @if ($subgroupHasServices)
                                                                <button
                                                                    type="button"
                                                                    @click="toggle('{{ $subgroupId }}')"
                                                                    class="inline-block rounded p-0.5 hover:bg-gray-100 dark:hover:bg-gray-700"
                                                                    :aria-expanded="isOpen('{{ $subgroupId }}')"
                                                                >
                                                                    <span
                                                                        class="inline-block transition-transform duration-200"
                                                                        :class="isOpen('{{ $subgroupId }}') ? 'rotate-0' : '-rotate-90'"
                                                                    >
                                                                        @icon('chevron-down', 'w-4 h-4 text-gray-600 dark:text-gray-400')
                                                                    </span>
                                                                </button>
                                                            @else
                                                                @icon('chevron-right', 'w-3 h-3 text-gray-400 mt-1')
                                                            @endif
                                                        </div>
                                                        <div class="flex flex-col">
                                                            <span>{{ $subgroup['name'] ?? '' }}</span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="index-table-td">
                                                    @if (!empty($subgroup['request_allowed']))
                                                        <span class="text-lg font-semibold text-green-600">+</span>
                                                    @else
                                                        <span class="text-lg font-semibold text-red-600">-</span>
                                                    @endif
                                                </td>
                                                <td class="index-table-td font-semibold">
                                                    {{ $subgroup['code'] ?? '-' }}
                                                </td>
                                                <td class="index-table-td">
                                                    @if (!empty($subgroup['is_active']))
                                                        <span class="badge-green">
                                                            {{ __('forms.status.active') }}
                                                        </span>
                                                    @else
                                                        <span class="badge-red">
                                                            {{ __('forms.status.non_active') }}
                                                        </span>
                                                    @endif
                                                </td>
                                                @if ($selectionMode)
                                                    @include('livewire.dictionary.parts.service-group-selection', ['groupItem' => $subgroup])
                                                @endif
                                            </tr>

                                            {{-- Services under subgroup --}}
                                            @if ($subgroupHasServices)
                                                @foreach ($subgroup['services'] as $service)
                                                    <tr
                                                        x-cloak
                                                        class="index-table-tr bg-green-50/50 dark:bg-green-900/20"
                                                        x-show="isOpen('{{ $itemId }}') && isOpen('{{ $groupId }}') && isOpen('{{ $subgroupId }}')"
                                                        x-transition:enter="transition ease-out duration-150"
                                                        x-transition:enter-start="opacity-0"
                                                        x-transition:enter-end="opacity-100"
                                                    >
                                                        <td class="index-table-td-primary">
                                                            <div class="flex items-start gap-3">
                                                                <div class="mt-1 ml-18 w-6 flex-shrink-0">
                                                                    @icon('chevron-right', 'w-3 h-3 text-gray-400 mt-1')
                                                                </div>
                                                                <div class="flex flex-col">
                                                                    <span>{{ $service['name'] ?? '' }}</span>
                                                                    @if (!empty($service['category']))
                                                                        <span class="mt-1 inline-block w-fit rounded bg-gray-100 px-1 text-xs text-gray-600 dark:bg-gray-700">
                                                                            {{ $this->dictionaries['SERVICE_CATEGORY'][$service['category']] }}
                                                                        </span>
                                                                    @endif
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="index-table-td">
                                                            @if (!empty($service['request_allowed']))
                                                                <span class="text-lg font-semibold text-green-600">+</span>
                                                            @else
                                                                <span class="text-lg font-semibold text-red-600">-</span>
                                                            @endif
                                                        </td>
                                                        <td class="index-table-td font-semibold">
                                                            {{ $service['code'] ?? '-' }}
                                                        </td>
                                                        <td class="index-table-td">
                                                            @if (!empty($service['is_active']))
                                                                <span class="badge-green">
                                                                    {{ __('forms.status.active') }}
                                                                </span>
                                                            @else
                                                                <span class="badge-red">
                                                                    {{ __('forms.status.non_active') }}
                                                                </span>
                                                            @endif
                                                        </td>
                                                        @if ($selectionMode)
                                                            <td class="index-table-td text-center">
                                                                @if (!empty($service['is_active']))
                                                                    <button
                                                                        type="button"
                                                                        wire:click="selectService(@js($service['id']))"
                                                                        class="inline-flex cursor-pointer items-center justify-center"
                                                                        title="{{ __('forms.select') }}"
                                                                    >
                                                                        @icon('plus-circle', 'w-6 h-6')
                                                                    </button>
                                                                @endif
                                                            </td>
                                                        @endif
                                                    </tr>
                                                @endforeach
                                            @endif
                                        @endforeach
                                    @endif

                                    {{-- Services directly under group --}}
                                    @if ($groupHasServices && !$groupHasGroups)
                                        @foreach ($group['services'] as $service)
                                            <tr
                                                x-cloak
                                                class="index-table-tr bg-green-50/50 dark:bg-green-900/20"
                                                x-show="isOpen('{{ $itemId }}') && isOpen('{{ $groupId }}')"
                                                x-transition:enter="transition ease-out duration-150"
                                                x-transition:enter-start="opacity-0"
                                                x-transition:enter-end="opacity-100"
                                            >
                                                <td class="index-table-td-primary">
                                                    <div class="flex items-start gap-3">
                                                        <div class="mt-1 ml-12 w-6 flex-shrink-0">
                                                            @icon('chevron-right', 'w-3 h-3 text-gray-400 mt-1')
                                                        </div>
                                                        <div class="flex flex-col">
                                                            <span>{{ $service['name'] ?? '' }}</span>
                                                            @if (!empty($service['category']))
                                                                <span class="mt-1 inline-block w-fit rounded bg-gray-100 px-1 text-xs text-gray-600 dark:bg-gray-700">
                                                                    {{ $this->dictionaries['SERVICE_CATEGORY'][$service['category']] }}
                                                                </span>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="index-table-td">
                                                    @if (!empty($service['request_allowed']))
                                                        <span class="text-lg font-semibold text-green-600">+</span>
                                                    @else
                                                        <span class="text-lg font-semibold text-red-600">-</span>
                                                    @endif
                                                </td>
                                                <td class="index-table-td font-semibold">
                                                    {{ $service['code'] ?? '-' }}
                                                </td>
                                                <td class="index-table-td">
                                                    @if (!empty($service['is_active']))
                                                        <span class="badge-green">
                                                            {{ __('forms.status.active') }}
                                                        </span>
                                                    @else
                                                        <span class="badge-red">
                                                            {{ __('forms.status.non_active') }}
                                                        </span>
                                                    @endif
                                                </td>
                                                @if ($selectionMode)
                                                    <td class="index-table-td text-center">
                                                        @if (!empty($service['is_active']))
                                                            <button
                                                                type="button"
                                                                wire:click="selectService(@js($service['id']))"
                                                                class="inline-flex cursor-pointer items-center justify-center"
                                                                title="{{ __('forms.select') }}"
                                                            >
                                                                @icon('plus-circle', 'w-6 h-6')
                                                            </button>
                                                        @endif
                                                    </td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    @endif
                                @endforeach
                            @endif
                        @empty
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($services->isEmpty())
                <x-nothing-found class="shift-content !mx-auto mt-8" maxWidth="" />
            @endif
        </div>
    </div>

    <div wire:dirty.remove wire:target="filterCodeOrName" class="pagination">{{ $this->services->links() }}</div>

    <x-forms.loading />
    <livewire:components.x-message :key="time()" />
</div>
