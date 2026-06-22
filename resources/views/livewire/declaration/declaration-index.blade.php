@use('App\Enums\Declaration\Status')
@use('App\Enums\JobStatus')
@use('Carbon\CarbonImmutable')
@use('\App\Enums\User\Role')

@php
    $hasLegators = legalEntity()->legators->isNotEmpty();
@endphp

<div>
    <livewire:components.x-message :key="now()->timestamp"/>

    <x-header-navigation x-data="{ showFilter: false }">
        <x-slot name="title">{{ __('forms.declarations') }}</x-slot>

        <div class="ml-auto flex items-center gap-2 mt-2 lg:mt-0">
            <button :key="sync-button"
                    wire:click="{{ !$this->isSync ? 'sync' : '' }}"
                    class="{{ $this->isSync ? 'button-sync-disabled' : 'button-sync' }} flex items-center gap-2 whitespace-nowrap"
                {{ $this->isSync ? 'disabled' : '' }}
            >
                @icon('refresh', 'w-4 h-4')
                <span>{{ ($syncStatus === JobStatus::PAUSED->value || $syncStatus === JobStatus::FAILED->value) ? __('forms.sync_retry') : __('forms.synchronise_with_eHealth') }}</span>
            </button>
        </div>

        <x-slot name="navigation">
            <div class="flex">
                <div class="w-full">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-1">
                            @icon('search-outline', 'w-4 h-4.5 text-gray-800 dark:text-white')
                            <p class="default-p">{{ __('declarations.search') }}</p>
                        </div>

                        @isset($countActive)
                            <div class="flex items-center gap-4 pl-30">
                                <p class="default-p">{{ __('declarations.count_active') }}:</p>
                                <span class="badge-green">{{ $countActive }}</span>
                            </div>
                        @endisset
                    </div>
                    <div class="flex items-end gap-3 mt-1">
                        <div class="form-group group top-3 grow max-w-xs">
                            <input type="text"
                                   id="searchByName"
                                   placeholder=" "
                                   class="input peer"
                                   wire:model="searchByName"
                                   autocomplete="off"
                            />
                            <label for="searchByName" class="label">
                                {{ __('patients.patient_full_name') }}
                            </label>
                        </div>

                        <button class="flex items-center gap-2 button-minor h-11 min-w-max px-4"
                                @click="showFilter = !showFilter"
                        >
                            @icon('adjustments', 'w-4 h-4')
                            <span x-text="showFilter ? '{{ __('forms.additional_search_parameters') }}' : '{{ __('forms.additional_search_parameters') }}'">
                                {{ __('forms.additional_search_parameters') }}
                            </span>
                        </button>
                    </div>

                    {{-- Show additional filters --}}
                    <div x-show="showFilter" x-cloak x-transition class="mt-8" x-data="{ openType: false }">
                        @if(Auth::user()->hasAllowedRole(Role::OWNER))
                            @include('livewire.declaration.parts.owner-filters')
                        @else
                            @include('livewire.declaration.parts.basic-filters')
                        @endif
                    </div>
                </div>
            </div>

            <div class="mb-9 mt-6 flex gap-2">
                <button wire:click.prevent="search" class="flex items-center gap-2 button-primary">
                    @icon('search', 'w-4 h-4')
                    <span>{{ __('patients.search') }}</span>
                </button>
                <button type="button" wire:click="resetFilters" class="button-primary-outline-red">
                    {{ __('forms.reset_all_filters') }}
                </button>
            </div>
        </x-slot>
    </x-header-navigation>

    <div class="flow-root mt-4 shift-content pl-3.5"
         wire:key="declarations-table-page-{{ $declarations->total() }}-{{ $declarations->currentPage() }}"
    >
        <div class="max-w-7xl">
            @if($declarations->isNotEmpty())
                <div class="relative shadow-md sm:rounded-lg">
                    <table class="table-input w-full min-w-250">
                            <thead class="thead-input">
                            <tr>
                                <th scope="col" class="th-input w-[25%]">{{ __('forms.full_name') }}</th>
                                <th scope="col" class="th-input w-[15%]">{{ __('forms.number') }}</th>
                                <th scope="col" class="th-input w-[15%]">{{ __('forms.birth_date_abbreviated') }}</th>
                                <th scope="col" class="th-input w-[25%]">{{ __('employees.doctor') }}</th>
                                <th scope="col" class="th-input w-[15%]">{{ __('forms.status.label') }}</th>
                                <th scope="col" class="th-input w-[5%]">{{ __('forms.action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($declarations as $declaration)
                                <tr wire:key="{{ $declaration->declarationNumber }}">
                                    <td class="td-input">{{ $declaration->person->fullName }}</td>
                                    <td class="td-input">{{ $declaration->declarationNumber }}</td>
                                    <td class="td-input">{{ CarbonImmutable::parse($declaration->person->birth_date)->format(config('app.date_format')) }}</td>
                                    <td class="td-input">{{ $declaration->employee->fullName }}</td>

                                    <td class="td-input">
                                        <span class="{{
                                            match($declaration->status) {
                                                Status::DRAFT => 'badge-dark',
                                                Status::NEW, Status::APPROVED => 'badge-yellow',
                                                Status::ACTIVE => $hasLegators && $declaration->reorganizedEmployeeDeclaration && !$declaration->hasParentDeclaration() ? 'badge-yellow' : 'badge-green',
                                                Status::REJECTED, Status::CANCELLED, Status::TERMINATED => 'badge-red',
                                                default => ''
                                            }
                                        }}">
                                            @if($declaration->type === 'declaration')
                                                @if ($hasLegators && $declaration->reorganizedEmployeeDeclaration && $declaration->status == Status::ACTIVE)
                                                    {{ __('declarations.status.to_be_resigned') }}
                                                @else
                                                    {{ !$declaration->hasParentDeclaration() ? $declaration->status->label() : __('declarations.status.resigned') }}
                                                @endif
                                            @else
                                                {{ $declaration->status->label() }}
                                            @endif
                                        </span>
                                    </td>

                                    <td
                                        x-data="{ openDropdown: false }"
                                        class="relative td-input text-center overflow-visible"
                                    >
                                        @if(
                                            $declaration->status === Status::REJECTED ||
                                            $declaration->status === Status::TERMINATED ||
                                            $declaration->status === Status::CANCELLED ||
                                            ($declaration->type === 'declaration' && !$declaration->reorganizedEmployeeDeclaration)
                                        )
                                            @can('view', $declaration)
                                                <a href="{{ route('declaration.view', [legalEntity(), $declaration->id]) }}"
                                                   class="cursor-pointer"
                                                >
                                                    @icon('eye', 'w-6 h-6 text-gray-800 dark:text-white')
                                                </a>
                                            @endcan
                                        @else
                                            <button @click.stop="openDropdown = !openDropdown" type="button"
                                                    class="cursor-pointer"
                                            >
                                                @icon('edit-user-outline', 'w-6 h-6 text-gray-800 dark:text-white')
                                            </button>
                                        @endif

                                        <div x-show="openDropdown"
                                             @click.outside="openDropdown = false"
                                             x-transition
                                             class="absolute right-0 mt-2 z-10 w-fit bg-white rounded divide-y divide-gray-100 shadow"
                                             style="display: none"
                                        >
                                            @if($declaration->type === 'request')
                                                @if($declaration->status === Status::DRAFT)
                                                    <a href="{{ route('declaration.edit', [legalEntity(), $declaration->person->id, $declaration->id]) }}"
                                                       @click="openDropdown = false"
                                                       class="cursor-pointer text-[#222222] text-nowrap flex gap-3 items-center py-2 pl-4 pr-10 hover:bg-gray-100"
                                                    >
                                                        @icon('check-circle', 'w-5 h-5 text-green-500')
                                                        {{ __('declarations.continue') }}
                                                    </a>

                                                    <button wire:click="delete({{ $declaration->getKey() }})"
                                                            @click="openDropdown = false"
                                                            class="cursor-pointer text-nowrap text-red-500 flex gap-3 items-center py-2 pl-4 pr-5 hover:bg-gray-100 w-full text-left"
                                                    >
                                                        @icon('delete', 'w-5 h-5')
                                                        {{ __('declarations.delete') }}
                                                    </button>
                                                @endif

                                                @if($declaration->status === Status::NEW)
                                                    @can('approve', $declaration)
                                                        <button @click="openDropdown = false"
                                                                wire:click="approve({{ $declaration->person->id }}, {{ $declaration->id }})"
                                                                class="cursor-pointer text-[#222222] text-nowrap flex gap-3 items-center py-2 pl-4 pr-19 hover:bg-gray-100 w-full text-left"
                                                        >
                                                            @icon('check-circle', 'w-5 h-5 text-green-500')
                                                            {{ __('declarations.approve') }}
                                                        </button>
                                                    @endcan

                                                    @can('reject', $declaration)
                                                        <button wire:click="reject('{{ $declaration['uuid'] }}')"
                                                                @click="openDropdown = false"
                                                                class="cursor-pointer text-nowrap text-red-500 flex gap-3 items-center py-2 pl-4 pr-5 hover:bg-gray-100 w-full text-left"
                                                        >
                                                            @icon('delete', 'w-5 h-5')
                                                            {{ __('declarations.reject_declaration_request') }}
                                                        </button>
                                                    @endcan
                                                @endif

                                                @if($declaration->status === Status::APPROVED)
                                                    @can('sign', $declaration)
                                                        <button @click="openDropdown = false"
                                                                wire:click="sign({{ $declaration->person->id }}, {{ $declaration->id }})"
                                                                class="cursor-pointer text-[#222222] text-nowrap flex gap-3 items-center py-2 pl-4 pr-19 hover:bg-gray-100 w-full text-left"
                                                        >
                                                            @icon('check-circle', 'w-5 h-5 text-green-500')
                                                            {{ __('declarations.sign') }}
                                                        </button>
                                                    @endcan

                                                    @can('reject', $declaration)
                                                        <button wire:click="reject('{{ $declaration['uuid'] }}')"
                                                                @click="openDropdown = false"
                                                                class="cursor-pointer text-nowrap text-red-500 flex gap-3 items-center py-2 pl-4 pr-5 hover:bg-gray-100 w-full text-left"
                                                        >
                                                            @icon('delete', 'w-5 h-5')
                                                            {{ __('declarations.reject_declaration_request') }}
                                                        </button>
                                                    @endcan
                                                @endif
                                            @else
                                                @if($hasLegators)
                                                    @can('resign', $declaration)
                                                        <button
                                                            @click="
                                                                openDropdown = false;
                                                                $wire.resign({{ $declaration->id }});
                                                            "
                                                            class="cursor-pointer text-[#222222] text-nowrap flex gap-3 items-center py-2 pl-4 pr-19 hover:bg-gray-100 w-full text-left"
                                                        >
                                                            @icon('check-circle', 'w-5 h-5 text-green-500')
                                                            {{ __('declarations.resign') }}
                                                        </button>
                                                    @endcan

                                                    @can('view', $declaration)
                                                        <a type="button" @click="openDropdown = false"
                                                            href="{{ route('declaration.view', [legalEntity(), $declaration->id]) }}"
                                                            class="cursor-pointer text-[#222222] text-nowrap flex gap-3 items-center py-2 pl-4 pr-19 hover:bg-gray-100 w-full text-left"
                                                        >
                                                            @icon('eye', 'w-6 h-6 text-gray-800 dark:text-white')
                                                            {{ __('declarations.show_declaration') }}
                                                        </a>
                                                    @endcan
                                                @endif
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                </div>

                <div class="pagination">
                    {{ $declarations->links() }}
                </div>
            @else
                <x-nothing-found />
            @endif
        </div>
    </div>

    <livewire:components.x-message :key="time()" />
    <x-forms.loading />
</div>
