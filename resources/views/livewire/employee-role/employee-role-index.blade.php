@use('App\Enums\EmployeeRole\Status')
@use('App\Enums\JobStatus')
@use('App\Models\EmployeeRole')

<div>
    <x-header-navigation class="items-start">
        <x-slot name="title">{{ __('employee-roles.label') }}</x-slot>

        <div class="mt-3 ml-0 flex flex-col gap-2 self-start pl-4 sm:flex-row sm:flex-wrap sm:pl-0">
            @can('create', EmployeeRole::class)
                <a
                    href="{{ route('employee-role.create', [legalEntity()]) }}"
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
            <div class="-my-4 flex flex-col">
                <form wire:submit.prevent="applyFilters">
                    <div>
                        <div class="form-row-3">
                            <x-forms.combobox
                                :options="$employees"
                                bind="employeeIdFilter"
                                bindValue="uuid"
                                bindParam="label"
                                :label="__('employee-roles.search_by_employee')"
                            />
                        </div>

                        <div class="form-row-3">
                            <x-forms.combobox
                                :options="$healthcareServices"
                                bind="healthcareServiceIdFilter"
                                bindValue="uuid"
                                bindParam="label"
                                :label="__('employee-roles.healthcareServiceId')"
                            />
                        </div>

                        @php
                            $statusOptions = [
                                'ACTIVE' => __('forms.status.active'),
                                'INACTIVE' => __('forms.status.non_active')
                            ];
                        @endphp

                        <div class="form-row-3">
                            <x-forms.multiselect
                                bind="statusFilter"
                                :options="$statusOptions"
                                label="{{ __('forms.status.label') }}"
                                :showAllIfEmpty="true"
                                :live="true"
                            />
                        </div>
                    </div>

                    <div class="mt-6 mb-9 flex w-full flex-col gap-2 sm:flex-row">
                        <button type="submit" class="button-primary flex items-center gap-2">
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

    <div
        class="shift-content mt-8 flow-root pl-3.5"
        wire:key="employee-roles-table-page-{{ $employeeRoles->total() }}-{{ $employeeRoles->currentPage() }}"
    >
        <div class="max-w-7xl">
            @if ($employeeRoles->isNotEmpty())
                <div class="index-table-wrapper">
                    <table class="index-table">
                        <thead class="index-table-thead">
                            <tr>
                                <th class="index-table-th w-1/5">{{ __('employees.doctor_full_name') }}</th>
                                <th class="index-table-th w-[15%]">{{ __('employee-roles.speciality_type') }}</th>
                                <th class="index-table-th w-[18%]">{{ __('forms.divisions') }}</th>
                                <th class="index-table-th w-[15%]">{{ __('forms.providing_condition') }}</th>
                                <th class="index-table-th w-[10%]">{{ __('employee-roles.start_date') }}</th>
                                <th class="index-table-th w-[10%]">{{ __('employee-roles.end_date') }}</th>
                                <th class="index-table-th w-[13%]">{{ __('employee-roles.status') }}</th>
                                <th class="index-table-th w-[6%]">{{ __('forms.action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($employeeRoles as $employeeRole)
                                <tr wire:key="{{ $employeeRole->id }}" class="index-table-tr">
                                    <td class="index-table-td-primary">{{ $employeeRole->employee->fullName }}</td>
                                    <td class="index-table-td">
                                        {{ $dictionaries['SPECIALITY_TYPE'][$employeeRole->healthcareService->specialityType] }}
                                    </td>
                                    <td class="index-table-td">
                                        {{ $employeeRole->healthcareService->division->name }}
                                    </td>
                                    <td class="index-table-td">
                                        {{ $dictionaries['PROVIDING_CONDITION'][$employeeRole->healthcareService->providingCondition] }}
                                    </td>
                                    <td class="index-table-td">{{ formatDisplayDate($employeeRole->startDate) }}</td>
                                    <td class="index-table-td">
                                        {{ formatDisplayDate($employeeRole->endDate) ?: '-' }}
                                    </td>
                                    <td class="index-table-td">
                                        <span class="{{ $employeeRole->status->color() }}">
                                            {{ $employeeRole->status->label() }}
                                        </span>
                                    </td>
                                    <td class="index-table-td-actions">
                                        @if ($employeeRole->status === Status::ACTIVE)
                                            <div class="relative flex justify-center">
                                                <div
                                                    x-data="{
                                                        open: false,
                                                        show: false,
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
                                                        x-cloak
                                                        x-ref="panel"
                                                        x-transition.origin.top.left
                                                        @click.outside="close($refs.button)"
                                                        :id="$id('dropdown-button')"
                                                        class="absolute right-0 z-50 mt-2 w-auto max-w-[20rem] min-w-40 rounded-md border border-gray-200 bg-white shadow-md dark:border-gray-600 dark:bg-gray-700"
                                                    >
                                                        @can('view', $employeeRole)
                                                            <a
                                                                href="{{ route('employee-role.view', [legalEntity(), $employeeRole->id]) }}"
                                                                class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
                                                            >
                                                                @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                                                {{ __('forms.view') }}
                                                            </a>
                                                        @endcan

                                                        @can('deactivate', $employeeRole)
                                                            <button
                                                                @click.prevent="show = true"
                                                                class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-sm text-red-600 last-of-type:rounded-b-md hover:bg-red-50 dark:text-red-400 dark:hover:bg-gray-600"
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
                <x-nothing-found />
            @endif

            @if ($employeeRoles->isNotEmpty())
                <div class="pagination">{{ $employeeRoles->links() }}</div>
            @endif
        </div>
    </div>

    <livewire:components.x-message :key="time()" />
    <x-forms.loading />
</div>
