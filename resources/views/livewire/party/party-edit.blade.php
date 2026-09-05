@use('App\Enums\User\Role')

<div>
    @php
        $currentUser = auth()->user();
        $isElevated = $currentUser->hasAllowedRole([Role::ADMIN, Role::HR, Role::OWNER, Role::PHARMACY_OWNER])
            || $currentUser->hasRole([
                Role::ADMIN->value,
                Role::HR->value,
                Role::OWNER->value,
                Role::PHARMACY_OWNER->value,
            ]);

        $permissions = [
            'employee_view' => $currentUser->can('employee:details') || $isElevated,
            'employee_write' => $currentUser->can('employee:write') || $isElevated,
            'employee_deactivate' => $currentUser->can('employee:deactivate') || $isElevated,
            'employee_admin_hr' => $isElevated,
            'request_view' => $currentUser->can('employee_request:read') || $isElevated,
            'request_write' => $currentUser->can('employee_request:write') || $isElevated,
            'request_delete' => $currentUser->can('employee_request:write') || $isElevated,
        ];
    @endphp

    <x-header-navigation class="breadcrumb-form shift-content">
        <x-slot name="title">{{ $pageTitle ?? '' }}</x-slot>

    </x-header-navigation>

    <div
        x-data="{
            showSignatureModal: $wire.entangle('showSignatureModal'),
            showRequestPreviewModal: $wire.entangle('showRequestPreviewModal')
        }"
        x-on:close-signature-modal.window="showSignatureModal = false"
        x-on:open-signature-modal.window="showSignatureModal = true"
        x-on:close-request-preview-modal.window="showRequestPreviewModal = false"
        x-on:open-request-preview-modal.window="showRequestPreviewModal = true"
    >

        <section class="section-form shift-content">
            <form wire:submit.prevent="save" class="form space-y-8">

                {{-- BLOCK 1: Personal data (partially blocked) --}}
                @include('livewire.employee.parts.party')

                {{-- BLOCK 2: Documents --}}
                @include('livewire.employee.parts.documents')

                {{-- BLOCK 3: Position Table --}}
                <fieldset class="fieldset">
                    <legend class="legend"><h2>{{ __('forms.positions') }}</h2></legend>
                    <table class="table-input w-inherit">
                        <thead class="thead-input">
                        <tr>
                            <th class="th-input">{{ __('forms.position') }}</th>
                            <th class="th-input">{{ __('forms.role') }}</th>
                            <th class="th-input">{{ __('forms.division') }}</th>
                            <th class="th-input">{{ __('forms.status.label') }}</th>
                            <th class="th-input text-center">{{ __('forms.actions') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($this->partyPositions as $position)
                            <tr wire:key="position-{{ $position->id }}">
                                <td class="td-input">{{ $this->dictionaries['POSITION'][$position->position] ?? $position->position }}</td>
                                <td class="td-input">{{ $this->dictionaries['EMPLOYEE_TYPE'][$position->employee_type] ?? $position->employee_type }}</td>
                                <td class="td-input">{{ $position->division->name ?? 'N/A' }}</td>
                                <td class="td-input">
                                    {{-- New status logic --}}
                                    @if($position instanceof \App\Models\Employee\Employee)
                                        @if($position->status?->value === 'APPROVED')
                                            <span class="badge-green">{{__('forms.status.active')}}</span>
                                        @else
                                            <span class="badge-red">{{__('forms.status.dismissed')}}</span>
                                        @endif
                                    @elseif($position instanceof \App\Models\Employee\EmployeeRequest)
                                        <span class="badge-yellow">{{__('forms.status.draft')}}</span>
                                    @endif
                                </td>
                                <td class="td-input text-center">
                                    @include('livewire.employee.parts.actions-dropdown', [
                                        'position' => $position,
                                        'permissions' => $permissions,
                                        'linksOnly' => true,
                                    ])
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="td-input text-center">{{ __('forms.no_positions_found') }}</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </fieldset>

                {{-- BOX 4: Buttons --}}
                @include('livewire.employee.parts.form-actions')

            </form>
        </section>

        @include('livewire.employee.parts.modals.request-preview-modal')
        @include('livewire.employee.parts.modals.signature-modal')
        <x-forms.loading/>

    </div>
</div>
