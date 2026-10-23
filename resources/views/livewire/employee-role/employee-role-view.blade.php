@use(App\Enums\EmployeeRole\Status)

<section class="shift-content section-form w-full max-w-7xl">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <x-header-navigation class="breadcrumb-form min-w-0 flex-1">
            <x-slot name="title">{{ __('employee-roles.role') }}</x-slot>

            <x-slot name="actions">
                <button
                    wire:click.prevent="sync"
                    wire:loading.attr="disabled"
                    type="button"
                    class="button-sync flex items-center gap-2 px-4 py-2 text-sm shadow-sm"
                >
                    @icon('refresh', 'w-4 h-4')
                    <span>{{ __('forms.synchronise_with_eHealth') }}</span>
                </button>
            </x-slot>
        </x-header-navigation>
    </div>

    <div class="shift-content mt-8 pl-3.5">
        <fieldset class="fieldset">
            <legend class="legend">
                {{ $employeeRole->employee->fullName }} - {{ $dictionaries['SPECIALITY_TYPE'][$employeeRole->healthcareService->specialityType] }}
            </legend>

            <div class="form-row-2">
                <div class="form-group group">
                    <label for="uuid" class="label">{{ __('employee-roles.id') }}</label>
                    <input
                        value="{{ $employeeRole->uuid }}"
                        type="text"
                        name="uuid"
                        id="uuid"
                        class="input peer"
                        placeholder=" "
                        disabled
                        autocomplete="off"
                    />
                </div>

                <div class="form-group group">
                    <label for="specialityType" class="label">{{ __('employee-roles.speciality_type') }}</label>
                    <input
                        value="{{ $dictionaries['SPECIALITY_TYPE'][$employeeRole->healthcareService->specialityType] }}"
                        type="text"
                        name="specialityType"
                        id="specialityType"
                        class="input peer"
                        placeholder=" "
                        disabled
                        autocomplete="off"
                    />
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group group">
                    <label for="divisionName" class="label">{{ __('forms.division_name') }}</label>
                    <input
                        value="{{ $employeeRole->healthcareService->division->name }}"
                        type="text"
                        name="divisionName"
                        id="divisionName"
                        class="input peer"
                        placeholder=" "
                        disabled
                        autocomplete="off"
                    />
                </div>

                <div class="form-group group">
                    <label for="fullName" class="label">{{ __('forms.full_name') }}</label>
                    <input
                        value="{{ $employeeRole->employee->fullName }}"
                        type="text"
                        name="fullName"
                        id="fullName"
                        class="input peer"
                        placeholder=" "
                        disabled
                        autocomplete="off"
                    />
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group group">
                    <label for="startDate" class="label">{{ __('employee-roles.start_datetime') }}</label>
                    <input
                        value="{{ formatDisplayDateTime($employeeRole->startDate) }}"
                        type="text"
                        name="startDate"
                        id="startDate"
                        class="input peer"
                        placeholder=" "
                        disabled
                        autocomplete="off"
                    />
                </div>

                <div class="form-group group">
                    <label for="endDate" class="label">{{ __('employee-roles.end_datetime') }}</label>
                    <input
                        value="{{ formatDisplayDateTime($employeeRole->endDate) ?: '-' }}"
                        type="text"
                        name="endDate"
                        id="endDate"
                        class="input peer"
                        placeholder=" "
                        disabled
                        autocomplete="off"
                    />
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group group">
                    <label for="status" class="label">{{ __('forms.status.label') }}</label>
                    <input
                        value="{{ $employeeRole->status->label() }}"
                        type="text"
                        name="status"
                        id="status"
                        class="input peer"
                        placeholder=" "
                        disabled
                        autocomplete="off"
                    />
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group group">
                    <label for="ehealthInsertedAt" class="label">{{ __('employee-roles.inserted_at') }}</label>
                    <input
                        value="{{ $employeeRole->ehealthInsertedAt }}"
                        type="text"
                        name="ehealthInsertedAt"
                        id="ehealthInsertedAt"
                        class="input peer"
                        placeholder=" "
                        disabled
                        autocomplete="off"
                    />
                </div>

                <div class="form-group group">
                    <label for="ehealthInsertedBy" class="label">{{ __('employee-roles.inserted_by') }}</label>
                    <input
                        value="{{ $employeeRole->insertedByUser?->party?->fullName ?: $employeeRole->ehealthInsertedBy }}"
                        type="text"
                        name="ehealthInsertedBy"
                        id="ehealthInsertedBy"
                        class="input peer"
                        placeholder=" "
                        disabled
                        autocomplete="off"
                    />
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group group">
                    <label for="ehealthUpdatedAt" class="label">{{ __('employee-roles.updated_at') }}</label>
                    <input
                        value="{{ $employeeRole->ehealthUpdatedAt }}"
                        type="text"
                        name="ehealthUpdatedAt"
                        id="ehealthUpdatedAt"
                        class="input peer"
                        placeholder=" "
                        disabled
                        autocomplete="off"
                    />
                </div>

                <div class="form-group group">
                    <label for="ehealthUpdatedBy" class="label">{{ __('employee-roles.updated_by') }}</label>
                    <input
                        value="{{ $employeeRole->updatedByUser?->party?->fullName ?: $employeeRole->ehealthUpdatedBy }}"
                        type="text"
                        name="ehealthUpdatedBy"
                        id="ehealthUpdatedBy"
                        class="input peer"
                        placeholder=" "
                        disabled
                        autocomplete="off"
                    />
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-4">
                <a href="{{ route('employee-role.index', legalEntity()) }}" class="button-minor">
                    {{ __('forms.back') }}
                </a>

                @can('view', $employeeRole->employee)
                    <a
                        href="{{ route('employee.show', [legalEntity(), 'employee' => $employeeRole->employee->id]) }}"
                        class="button-primary-outline"
                    >
                        {{ __('employee-roles.view_employee') }}
                    </a>
                @endcan

                @can('view', $employeeRole->healthcareService)
                    <a
                        href="{{ route('healthcare-service.view', [legalEntity(), $employeeRole->healthcareService->divisionId, $employeeRole->healthcareService->id]) }}"
                        class="button-primary-outline"
                    >
                        {{ __('employee-roles.view_healthcare_service') }}
                    </a>
                @endcan
            </div>
        </fieldset>
    </div>

    <livewire:components.x-message :key="now()->timestamp" />
</section>
