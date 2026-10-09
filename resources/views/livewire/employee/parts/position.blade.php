<fieldset class="fieldset">
    <legend class="legend">
        <h2>{{ __('forms.position') }}</h2>
    </legend>

    @php
        $positionOptions = $this->employeeTypePosition[$this->form->employeeType] ?? [];
        $customPositionAllowed = in_array(
            $this->form->employeeType,
            config('ehealth.employee_type_custom_position_allowed', []),
            true
        );
    @endphp

    <div class="form-row-3">
        {{-- 1. Employee Type: Locked based on component state --}}
        <div class="form-group">
            <select
                name="employeeType"
                id="employeeType"
                class="peer input appearance-none bg-white text-gray-500 dark:bg-gray-800 dark:text-gray-400"
                required
                wire:model.live="form.employeeType"
                :disabled="$wire.isPositionDataLocked || $wire.isCorePositionDataLocked"
            >
                <option value="" disabled selected hidden>{{ __('forms.role_choose') }}</option>

                @foreach ($this->dictionaries['EMPLOYEE_TYPE'] as $employeeTypes => $employeeTypeOption)
                    @if ($employeeTypes === 'OWNER')
                        @continue
                    @endif

                    <option value="{{ $employeeTypes }}">{{ $employeeTypeOption }}</option>
                @endforeach
            </select>
            <label for="employeeType" class="label">{{ __('forms.role') }}</label>
            @error('form.employeeType')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- 2. Position: dictionary options are server-rendered so the selected code matches the label. --}}
        <div class="form-group">
            @if ($this->isPositionDataLocked)
                <input
                    type="text"
                    id="position"
                    class="peer input text-gray-500 dark:text-gray-400"
                    value="{{ $this->positionDisplayLabel() }}"
                    disabled
                    placeholder=" "
                />
            @else
                @if ($customPositionAllowed)
                    <label class="mb-2 flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                        <input
                            type="checkbox"
                            wire:model.live="form.positionIsCustom"
                            :disabled="$wire.isPositionDataLocked || $wire.isCorePositionDataLocked"
                        />
                        {{ __('forms.position_custom_toggle') }}
                    </label>
                @endif

                @if ($customPositionAllowed && $this->form->positionIsCustom)
                    <input
                        type="text"
                        name="position"
                        id="position"
                        class="peer input text-gray-500 dark:text-gray-400"
                        required
                        wire:model="form.position"
                        wire:key="position-custom"
                        :disabled="$wire.isPositionDataLocked || $wire.isCorePositionDataLocked"
                        placeholder=" "
                    />
                @else
                    <select
                        name="position"
                        id="position"
                        class="peer input appearance-none bg-white text-gray-500 dark:bg-gray-800 dark:text-gray-400"
                        required
                        wire:model="form.position"
                        wire:key="position-dictionary-{{ $this->form->employeeType }}"
                        :disabled="$wire.isPositionDataLocked || $wire.isCorePositionDataLocked"
                    >
                        <option value="" disabled {{ $this->form->position === '' ? 'selected' : '' }} hidden>
                            {{ __('forms.select_position') }}
                        </option>
                        @foreach ($positionOptions as $positionKey => $positionName)
                            <option value="{{ $positionKey }}">{{ $positionName }}</option>
                        @endforeach
                    </select>
                @endif
            @endif
            <label for="position" class="label">{{ __('forms.position') }}</label>
            @error('form.position')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="form-row-3">
        {{-- 3. Start Date: Locked based on component state --}}
        <div class="form-group datepicker-wrapper relative w-full">
            <input
                wire:model="form.startDate"
                datepicker-format="{{ frontendDateFormat() }}"
                type="text"
                name="startDate"
                id="startDate"
                class="peer input datepicker-input appearance-none pl-10 text-gray-500 dark:text-gray-400"
                placeholder=" "
                required
                :disabled="$wire.isPositionDataLocked || $wire.isCorePositionDataLocked"
                datepicker-autohide
                datepicker-button="false"
            />
            <label for="startDate" class="wrapped-label">{{ __('forms.start_date_work') }}</label>
            @error('form.startDate')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- 4. Subdivision: Always unlocked for editing --}}
        <div class="form-group">
            <select
                name="division"
                id="division"
                class="peer input appearance-none bg-white text-gray-500 dark:bg-gray-800 dark:text-gray-400"
                wire:model="form.divisionId"
            >
                <option value="">{{ __('forms.select_division') }}</option>
                @foreach ($this->divisions as $division)
                    <option value="{{ $division['id'] }}">{{ $division['name'] }}</option>
                @endforeach
            </select>
            <label for="division" class="label">{{ __('forms.division') }}</label>
            @error('form.divisionId')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- 5. Email: Locked based on component state --}}
        @if (!empty($partyUsers))
            <div class="form-group" x-transition wire:key="party-user-email-select">
                <select
                    name="formEmail"
                    id="formEmail"
                    class="peer input appearance-none bg-white text-gray-500 dark:bg-gray-800 dark:text-gray-400"
                    required
                    wire:model="formEmail"
                    :disabled="$wire.isPositionDataLocked"
                >
                    <option value="" disabled>{{ __('forms.select_user_email') }}</option>
                    @foreach ($partyUsers as $user)
                        <option value="{{ $user->email }}">{{ $user->email }}</option>
                    @endforeach
                </select>
                <label for="formEmail" class="label">{{ __('forms.email') }}</label>
                @error('formEmail')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        @endif
    </div>
</fieldset>
