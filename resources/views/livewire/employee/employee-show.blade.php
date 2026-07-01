@use('App\Enums\User\Role')

<div>
    {{-- Header Navigation with shift-content class for alignment --}}
    <x-header-navigation class="breadcrumb-form shift-content">
        <x-slot name="title">
            @if($employee instanceof \App\Models\Employee\EmployeeRequest)
                {{ __('forms.view_employee_request') }}
            @else
                {{ __('forms.view_employee') }}
            @endif
            {{ $employee->party->fullName ?? '' }}
        </x-slot>

        {{-- SYNC button --}}
        <div class="flex items-center gap-2">
            @if($employee instanceof \App\Models\Employee\Employee)
                @can('syncEmployee', $employee)
                    <button
                        wire:click="sync"
                        wire:loading.attr="disabled"
                        type="button"
                        class="button-sync flex items-center gap-2"
                    >
                        <span>{{ __('forms.synchronise_with_eHealth') }}</span>
                    </button>
                @endcan
            @endif
        </div>
    </x-header-navigation>

    {{-- Main content also received shift-content --}}
    <div class="form shift-content space-y-8 shift-content mt-6">
        {{-- Fieldset is always disabled in a "show" view --}}
        <fieldset disabled class="space-y-8">
            @include('livewire.employee.parts.party')
            @include('livewire.employee.parts.documents')
            @include('livewire.employee.parts.position')

            {{-- Doctor-specific fields --}}
            @if ($this->form->employeeType === Role::DOCTOR->value)
                <div class="space-y-8">
                    @include('livewire.employee.parts.education')
                    @include('livewire.employee.parts.specialities')
                    @include('livewire.employee.parts.science_degree')
                    @include('livewire.employee.parts.qualifications')
                </div>
            @endif
        </fieldset>

        {{-- Bottom buttons (Back and Edit only) --}}
        <div class="mt-6 flex justify-between items-center border-t border-gray-200 dark:border-gray-700 pt-6">
            <a href="{{ route('employee.index', ['legalEntity' => legalEntity()->id]) }}" class="button-minor">
                &larr; {{ __('forms.back_to_list') }}
            </a>

            @if ($employee instanceof \App\Models\Employee\Employee)
                @can('update', $employee)
                    <a href="{{ route('employee.edit', ['legalEntity' => $employee->legal_entity_id, 'employee' => $employee->id]) }}"
                       class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        {{ __('forms.edit') }}
                    </a>
                @endcan
            @else
                @can('update', $employee)
                    <a href="{{ route('employee-request.edit', ['legalEntity' => $employee->legal_entity_id, 'employee_request' => $employee->id]) }}"
                       class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        {{ __('forms.edit') }}
                    </a>
                @endcan
            @endif
        </div>
    </div>
</div>
