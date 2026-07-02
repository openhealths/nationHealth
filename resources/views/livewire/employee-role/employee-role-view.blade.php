@use(App\Enums\EmployeeRole\Status)

<section class="shift-content section-form w-full max-w-7xl">
    <div class="flex items-center justify-between gap-4 flex-wrap">
        <x-header-navigation class="breadcrumb-form flex-1 min-w-0">
            <x-slot name="title">{{ __('employee-roles.role') }}</x-slot>
        </x-header-navigation>
    </div>

    <div class="shift-content pl-3.5 mt-8">
        <fieldset class="fieldset">
            <legend class="legend">
                {{ $employeeRole->employee->fullName }}
                - {{ $this->dictionaryLabelByCode('SPECIALITY_TYPE', $employeeRole->healthcareService->specialityType) }}
            </legend>

            <div class="form-row-2">
                <div class="form-group group">
                    <label for="uuid" class="label">{{ __('employee-roles.id') }}</label>
                    <input
                        value="{{ $employeeRole->healthcareService->uuid }}"
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
                        value="{{ $this->dictionaryLabelByCode('SPECIALITY_TYPE', $employeeRole->healthcareService->specialityType) }}"
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
                    <label for="divisionName" class="label">{{ __('employee-roles.start_datetime') }}</label>
                    <input
                        value="{{ formatDisplayDateTime($employeeRole->startDate) }}"
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
                    <label for="fullName" class="label">{{ __('employee-roles.end_datetime') }}</label>
                    <input
                        value="{{ formatDisplayDateTime($employeeRole->endDate) ?: '-' }}"
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
                    <label for="ehealthInsertedAt" class="label">{{ __('employee-roles.updated_at') }}</label>
                    <input
                        value="{{ $employeeRole->ehealthUpdatedAt }}"
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
                    <label for="ehealthInsertedBy" class="label">{{ __('employee-roles.updated_by') }}</label>
                    <input
                        value="{{ $employeeRole->updatedByUser?->party?->fullName ?: $employeeRole->ehealthUpdatedBy }}"
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

            <a href="{{ route('employee-role.index', legalEntity()) }}" type="submit" class="button-minor">
                {{ __('forms.back') }}
            </a>
        </fieldset>
    </div>
</section>
