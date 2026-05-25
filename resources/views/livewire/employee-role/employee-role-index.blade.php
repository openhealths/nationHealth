@use('App\Enums\Status')
@use('App\Enums\JobStatus')
@use('App\Models\EmployeeRole')

<div>
    <x-header-navigation class="items-start">

        <x-slot name="title">
            {{ __('employee-roles.label') }}
        </x-slot>

        <div class="mt-3 ml-0 flex flex-col sm:flex-row sm:flex-wrap gap-2 self-start pl-4 sm:pl-0">
            @can('create', EmployeeRole::class)
                <a href="{{ route('employee-role.create', [legalEntity()]) }}"
                   class="button-primary flex items-center gap-2"
                >
                    @icon('plus', 'w-4 h-4')
                    {{ __('employee-roles.new') }}
                </a>
            @endcan

            <button
                type="button"
                wire:click="{{ !$this->isSync ? 'sync' : '' }}"
                class="{{ $this->isSync ? 'button-sync-disabled' : 'button-sync' }} flex items-center gap-2 whitespace-nowrap"
                {{ $this->isSync ? 'disabled' : '' }}
            >
                @icon('refresh', 'w-4 h-4')
                <span>{{ ($syncStatus === JobStatus::PAUSED->value || $syncStatus === JobStatus::FAILED->value) ? __('forms.sync_retry') : __('forms.synchronise_with_eHealth') }}</span>
            </button>
        </div>

        <x-slot name="navigation">
            <div class="flex flex-col -my-4">
                <form wire:submit.prevent="applyFilters">
                    <div>
                        <div class="form-row-3">
                            <div class="form-group group">
                                <input type="search"
                                       id="employeeSearch"
                                       placeholder=" "
                                       class="input peer pl-8"
                                       wire:model="employeeSearch"
                                       autocomplete="off"
                                />
                                <label for="employeeSearch" class="label pl-8">
                                    {{ __('employee-roles.search_by_employee') }}
                                </label>
                                @icon('search', 'w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none')
                            </div>
                        </div>

                        <div class="form-row-3">
                            <div class="form-group group">
                                <select wire:model="specialityTypeFilter"
                                        id="specialityType"
                                        class="input-select"
                                >
                                    <option value="" selected>{{ __('employee-roles.speciality_type') }}</option>
                                    @foreach($healthcareServiceSpecialityTypes as $type)
                                        <option value="{{ $type }}">
                                            {{ $dictionaries['SPECIALITY_TYPE'][$type] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="form-row-3">
                            <div class="form-group group">
                                <label for="statusFilter" class="label">{{ __('forms.status.label') }}</label>
                                <div class="relative"
                                     x-data="{ open: false, selectedStatuses: $wire.entangle('statusFilter') }"
                                >
                                    <input type="text"
                                           id="statusFilter"
                                           class="input peer"
                                           placeholder="Активні, Не активний"
                                           @click="open = !open"
                                           :value="selectedStatuses.length ? selectedStatuses.map(status => {
                                                       if (status === 'ACTIVE') return 'Активний';
                                                       if (status === 'INACTIVE') return 'Не активний';
                                                       return status;
                                                   }).join(', ') : 'Активні, Не активний'
                                           "
                                           readonly
                                    />
                                    <svg stroke="currentColor"
                                         class="w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 dark:text-gray-400 pointer-events-none"
                                         fill="none"
                                         stroke-width="2"
                                         viewBox="0 0 24 24"
                                    >
                                        <path d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                    <div x-show="open"
                                         @click.away="open = false"
                                         x-transition
                                         class="absolute z-10 mt-2 w-full bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-md shadow-lg"
                                    >
                                        <ul class="py-2 px-3 space-y-2 text-sm text-gray-700 dark:text-gray-200">
                                            <li>
                                                <label class="flex items-center space-x-2 cursor-pointer">
                                                    <input type="checkbox"
                                                           value="ACTIVE"
                                                           wire:model="statusFilter"
                                                           class="rounded-sm text-blue-600 focus:ring-blue-500 border-gray-300 dark:bg-gray-800 dark:border-gray-600 dark:checked:bg-blue-600 dark:checked:border-transparent"
                                                    />
                                                    <span>{{ __('forms.active') }}</span>
                                                </label>
                                            </li>
                                            <li>
                                                <label class="flex items-center space-x-2 cursor-pointer">
                                                    <input type="checkbox"
                                                           value="INACTIVE"
                                                           wire:model="statusFilter"
                                                           class="rounded-sm text-blue-600 focus:ring-blue-500 border-gray-300 dark:bg-gray-800 dark:border-gray-600 dark:checked:bg-blue-600 dark:checked:border-transparent"
                                                    />
                                                    <span>{{ __('forms.status.non_active') }}</span>
                                                </label>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-9 mt-6 flex flex-col sm:flex-row gap-2 w-full">
                        <button type="submit" class="flex items-center gap-2 button-primary">
                            @icon('search', 'w-4 h-4')
                            <span>{{ __('forms.search') }}</span>
                        </button>

                        <button type="button" wire:click="resetFilters" class="button-primary-outline-red">
                            {{ __('forms.reset_all_filters') }}
                        </button>
                    </div>
                </form>
            </div>
        </x-slot>
    </x-header-navigation>

    <div class="flow-root mt-8 shift-content pl-3.5"
         wire:key="employee-roles-table-page-{{ $employeeRoles->total() }}-{{ $employeeRoles->currentPage() }}"
    >
        <div class="max-w-screen-xl">
            @if($employeeRoles->isNotEmpty())
                <div class="index-table-wrapper">
                    <table class="index-table">
                        <thead class="index-table-thead">
                        <tr>
                            <th class="index-table-th w-[20%]">{{ __('employees.doctor_full_name') }}</th>
                            <th class="index-table-th w-[15%]">{{ __('employee-roles.speciality_type') }}</th>
                            <th class="index-table-th w-[18%]">{{ __('forms.divisions') }}</th>
                            <th class="index-table-th w-[15%]">{{ __('employee-roles.providing_condition') }}</th>
                            <th class="index-table-th w-[10%]">{{ __('forms.created_at') }}</th>
                            <th class="index-table-th w-[10%]">{{ __('employee-roles.end_date') }}</th>
                            <th class="index-table-th w-[10%]">{{ __('employee-roles.status') }}</th>
                            <th class="index-table-th w-[6%]">{{ __('forms.action') }}</th>
                        </tr>
                        </thead>
                        <tbody>

                        @foreach ($employeeRoles as $employeeRole)
                            <tr wire:key="{{ $employeeRole->id }}" class="index-table-tr">
                                <td class="index-table-td-primary">
                                    {{ $employeeRole->employee->fullName }}
                                </td>
                                <td class="index-table-td">
                                    {{ $dictionaries['SPECIALITY_TYPE'][$employeeRole->healthcareService->specialityType] }}
                                </td>
                                <td class="index-table-td">
                                    {{ $employeeRole->healthcareService->division->name }}
                                </td>
                                <td class="index-table-td">
                                    {{ $dictionaries['PROVIDING_CONDITION'][$employeeRole->healthcareService->providingCondition] }}
                                </td>
                                <td class="index-table-td">
                                    {{ $employeeRole->startDate->format(config('app.date_format')) }}
                                </td>
                                <td class="index-table-td">
                                    {{ $employeeRole->endDate?->format(config('app.date_format')) }}
                                </td>
                                <td class="index-table-td">
                                    <span class="{{
                                        match($employeeRole->status) {
                                            Status::ACTIVE => 'badge-green',
                                            Status::INACTIVE => 'badge-red',
                                            default => ''
                                        }
                                    }}">
                                        {{ $employeeRole->status->label() }}
                                    </span>
                                </td>
                                <td class="index-table-td-actions">
                                    @if($employeeRole->status === Status::ACTIVE)
                                        <div class="flex justify-center relative">
                                            <div x-data="{
                                                     open: false,
                                                     show: false,
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
                                                     x-cloak
                                                     x-ref="panel"
                                                     x-transition.origin.top.left
                                                     @click.outside="close($refs.button)"
                                                     :id="$id('dropdown-button')"
                                                     class="absolute right-0 mt-2 w-auto min-w-[10rem] max-w-[20rem] rounded-md bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 shadow-md z-50"
                                                >
                                                    @can('deactivate', $employeeRole)
                                                        <button @click.prevent="show = true"
                                                                class="cursor-pointer flex items-center gap-2 w-full last-of-type:rounded-b-md px-4 py-2.5 text-left text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-gray-600"
                                                        >
                                                            @icon('delete', 'w-5 h-5 text-red-600 dark:text-red-400')
                                                            {{ __('forms.deactivate') }}
                                                        </button>

                                                        @include('livewire.employee-role.modals.deactivate-modal')
                                                    @endcan
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
                <fieldset class="fieldset pl-[3.5px] ml-0 mr-auto w-full max-w-full">
                    <legend class="legend relative -top-5 ml-0">
                        @icon('nothing-found', 'w-28 h-28')
                    </legend>

                    <div class="p-4 rounded-lg bg-blue-100 flex items-start mb-4 max-w-2xl">
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

            @if($employeeRoles->isNotEmpty())
                <div class="mt-8 pl-3.5 pb-8 lg:pl-8 2xl:pl-5">
                    {{ $employeeRoles->links() }}
                </div>
            @endif
        </div>
    </div>

    <livewire:components.x-message :key="time()" />
    <x-forms.loading />
</div>
