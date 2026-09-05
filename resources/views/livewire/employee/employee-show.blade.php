<div>
    {{-- Header Navigation with shift-content class for alignment --}}
    <x-header-navigation class="breadcrumb-form shift-content">
        <x-slot name="title">
            @if ($employee instanceof \App\Models\Employee\EmployeeRequest)
                {{ __('forms.view_employee_request') }}
            @else
                {{ __('forms.view_employee') }}
            @endif
            {{ $employee->party->fullName ?? '' }}
        </x-slot>

        {{-- SYNC button --}}
        <div class="flex items-center gap-2">
            @if ($employee instanceof \App\Models\Employee\Employee)
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
    <div class="form shift-content shift-content mt-6 space-y-8">
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-gray-500">{{ __('forms.employee_uuid') }}</dt>
                <dd class="font-mono break-all">{{ $employee->uuid ?? $employee->id }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">{{ __('forms.status.label') }}</dt>
                <dd>
                    @if ($employee instanceof \App\Models\Employee\EmployeeRequest && $employee->isPendingEhealth())
                        Підписано в ЄСОЗ
                    @else
                        {{ $employee->status?->label() ?? $employee->status }}
                    @endif
                </dd>
            </div>
            @if ($employee instanceof \App\Models\Employee\EmployeeRequest)
                <div>
                    <dt class="text-gray-500">{{ __('forms.inserted_at') }}</dt>
                    <dd>{{ ($employee->inserted_at ?? $employee->created_at)?->format('d.m.Y H:i') ?? '—' }}</dd>
                </div>
            @else
                <div>
                    <dt class="text-gray-500">{{ __('forms.end_date') }}</dt>
                    <dd>
                        {{ $employee->end_date ? \Illuminate\Support\Carbon::parse($employee->end_date)->format('d.m.Y') : '—' }}
                    </dd>
                </div>
            @endif
        </dl>

        @if ($employee instanceof \App\Models\Employee\Employee && $employee->party)
            @php
                $verificationDetails = \App\Services\Party\PartyVerificationCache::get($employee->party->uuid ?? '')['details'] ?? [];
                $unverifiedStreams = collect(['drfo', 'dracs_death', 'dms_passport'])
                    ->filter(fn (string $stream) => ($verificationDetails[$stream]['verification_status'] ?? null) === 'NOT_VERIFIED');
            @endphp
            @can('viewVerification', $employee->party)
                <div class="space-y-2 rounded-lg border border-gray-200 p-4">
                    <div class="flex items-center justify-between gap-4">
                        <h3 class="font-semibold">{{ __('party_verification.label') }}</h3>
                        <a
                            href="{{ route('party.verification.show', ['legalEntity' => legalEntity()->id, 'party' => $employee->party->id]) }}"
                            class="text-sm text-blue-600 hover:underline"
                        >
                            {{ __('employees.open_verification') }}
                        </a>
                    </div>
                    @if ($unverifiedStreams->isNotEmpty())
                        <div
                            class="rounded-lg bg-red-50 p-4 text-sm text-red-800 dark:bg-gray-800 dark:text-red-400"
                            role="alert"
                        >
                            <h4 class="font-bold">{{ __('party_verification.warning.header') }}</h4>
                            <ul class="mt-2 list-inside list-disc space-y-1">
                                @foreach ($unverifiedStreams as $stream)
                                    <li>{{ __('party_verification.warning.' . $stream) }}</li>
                                @endforeach
                            </ul>
                            <p class="mt-3">{{ __('party_verification.warning.footer') }}</p>
                        </div>
                    @endif
                </div>
            @endcan
        @endif
        {{-- Fieldset is always disabled in a "show" view --}}
        <fieldset disabled class="space-y-8">
            @include('livewire.employee.parts.party')
            @include('livewire.employee.parts.documents')
            @include('livewire.employee.parts.position')

            {{-- Professional data for medical employee types (3.23.3.2.1) --}}
            @if (in_array($this->form->employeeType, config('ehealth.medical_employees', []), true))
                <div class="space-y-8">
                    @include('livewire.employee.parts.education')
                    @include('livewire.employee.parts.specialities')
                    @include('livewire.employee.parts.science_degree')
                    @include('livewire.employee.parts.qualifications')
                </div>
            @endif
        </fieldset>

        {{-- Bottom buttons (Back and Edit only) --}}
        <div class="mt-6 flex items-center justify-between border-t border-gray-200 pt-6 dark:border-gray-700">
            <a href="{{ route('employee.index', ['legalEntity' => legalEntity()->id]) }}" class="button-minor">
                &larr; {{ __('forms.back_to_list') }}
            </a>

            @if ($employee instanceof \App\Models\Employee\Employee)
                <div class="flex items-center gap-3">
                    @can('deactivate', $employee)
                        <button
                            type="button"
                            wire:click="showModalDeactivate({{ $employee->id }})"
                            class="button-minor text-red-600"
                        >
                            {{ __('forms.deactivate') }}
                        </button>
                    @endcan
                    @can('update', $employee)
                        <a
                            href="{{ route('employee.edit', ['legalEntity' => $employee->legal_entity_id, 'employee' => $employee->id]) }}"
                            class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:outline-none"
                        >
                            {{ __('forms.edit') }}
                        </a>
                    @endcan
                </div>
            @else
                @can('update', $employee)
                    <a
                        href="{{ route('employee-request.edit', ['legalEntity' => $employee->legal_entity_id, 'employee_request' => $employee->id]) }}"
                        class="inline-flex items-center justify-center rounded-md border border-transparent bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:outline-none"
                    >
                        {{ __('forms.edit') }}
                    </a>
                @endcan
            @endif
        </div>
    </div>
    @if ($employee instanceof \App\Models\Employee\Employee)
        @include('livewire.employee.parts.modals.deactivate-modal')
    @endif
</div>
