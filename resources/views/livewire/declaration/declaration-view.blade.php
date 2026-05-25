@use('Carbon\CarbonImmutable')

<section class="shift-content section-form w-full max-w-7xl">
    <div class="flex items-center justify-between gap-4 flex-wrap w-full">
        <x-header-navigation class="breadcrumb-form flex-1 min-w-0">
            <x-slot name="title">
                {{ __('declarations.label') }} - {{ $declaration->person->fullName }}
            </x-slot>
        </x-header-navigation>

        <div class="shrink-0">
            <button @click="
                        let printWindow = window.open('', '_blank');
                        printWindow.document.body.innerHTML = $wire.printableContent;
                        printWindow.focus();
                        printWindow.print();
                    "
                    class="button-minor flex items-center gap-2">
                @icon('printer', 'w-4 h-4 dark:text-white')
                {{ __('declarations.print_declaration') }}
            </button>
        </div>
    </div>

    <div class="shift-content w-full">
        <fieldset class="fieldset">
            <legend class="legend">
                {{ __('declarations.label') }} № {{ $declaration->declarationNumber }}
            </legend>

            <div class="form-row-2">
                <div class="form-group group">
                    <label for="uuid" class="label">
                        {{ __('declarations.id') }}
                    </label>
                    <input value="{{ $declaration->uuid }}"
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
                    <label for="startDate" class="label">
                        {{ __('declarations.start_date') }}
                    </label>
                    <input value="{{ CarbonImmutable::parse($declaration->startDate)->format(config('app.date_format')) }}"
                           type="text"
                           name="startDate"
                           id="startDate"
                           class="input peer"
                           placeholder=" "
                           disabled
                           autocomplete="off"
                    />
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group group">
                    <label for="status" class="label">
                        {{ __('declarations.status.label') }}
                    </label>
                    <input value="{{ $declaration->status->label() }}"
                           type="text"
                           name="status"
                           id="status"
                           class="input peer"
                           placeholder=" "
                           disabled
                           autocomplete="off"
                    />
                </div>

                <div class="form-group group">
                    <label for="endDate" class="label">
                        {{ __('declarations.end_date') }}
                    </label>
                    <input value="{{ CarbonImmutable::parse($declaration->endDate)->format(config('app.date_format')) }}"
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
                    <label for="reason" class="label">
                        {{ __('declarations.change_reason_if_exist') }}
                    </label>
                    <input value="{{ $declaration->reason ? __("declarations.reason.$declaration->reason") : '' }}"
                           type="text"
                           name="reason"
                           id="reason"
                           class="input peer"
                           placeholder=" "
                           disabled
                           autocomplete="off"
                    />
                </div>

                <div class="form-group group">
                    <label for="reasonDescription" class="label">
                        {{ __('declarations.change_reason_description_if_exist') }}
                    </label>
                    <input value="{{ $declaration->reasonDescription }}"
                           type="text"
                           name="reasonDescription"
                           id="reasonDescription"
                           class="input peer"
                           placeholder=" "
                           disabled
                           autocomplete="off"
                    />
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group group">
                    <label for="patientFullName" class="label">
                        {{ __('patients.patient_full_name') }}
                    </label>
                    <input value="{{ $declaration->person->fullName }}"
                           type="text"
                           name="patientFullName"
                           id="patientFullName"
                           class="input peer"
                           placeholder=" "
                           disabled
                           autocomplete="off"
                    />
                </div>

                <div class="form-group group">
                    <label for="birthDate" class="label">
                        {{ __('patients.patient_birth_date') }}
                    </label>
                    <input value="{{ CarbonImmutable::parse($declaration->person->birth_date)->format(config('app.date_format')) }}"
                           type="text"
                           name="birthDate"
                           id="birthDate"
                           class="input peer"
                           placeholder=" "
                           disabled
                           autocomplete="off"
                    />
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group group">
                    <label for="employeeFullName" class="label">
                        {{ __('employees.doctor_full_name') }}
                    </label>
                    <input value="{{ $declaration->employee->fullName }}"
                           type="text"
                           name="employeeFullName"
                           id="employeeFullName"
                           class="input peer"
                           placeholder=" "
                           disabled
                           autocomplete="off"
                    />
                </div>

                <div class="form-group group">
                    <label for="employeePosition" class="label">
                        {{ __('employees.doctor_position') }}
                    </label>
                    <input value="{{ $dictionary[$declaration->employee->position] }}"
                           type="text"
                           name="employeePosition"
                           id="employeePosition"
                           class="input peer"
                           placeholder=" "
                           disabled
                           autocomplete="off"
                    />
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group group">
                    <label for="divisionName" class="label">
                        {{ __('forms.division_name') }}
                    </label>
                    <input value="{{ $declaration->division->name }}"
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
                    <label for="patientLastName" class="label">
                        {{ __('declarations.method_of_filling_declaration') }}
                    </label>
                    <input value="МІС"
                           type="text"
                           name="patientLastName"
                           id="patientLastName"
                           class="input peer"
                           placeholder=" "
                           disabled
                           autocomplete="off"
                    />
                </div>
            </div>
        </fieldset>

        <a href="{{ url()->previous() }}" type="submit" class="button-minor">
            {{ __('forms.back') }}
        </a>

    </div>
</section>
