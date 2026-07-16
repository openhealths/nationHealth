<fieldset class="fieldset"
          x-data="{
              employeeType: $wire.entangle('form.employeeType'),
              employeeTypePosition: @js($this->employeeTypePosition)
          }"
          x-init="$watch('employeeType', (value) => {
              /* Reset position only if fields are not locked (Creation/Add mode) */
              if (!$wire.isPositionDataLocked && !$wire.isCorePositionDataLocked) {
                  $wire.set('form.position', '', false);
                  if (document.getElementById('position')) {
                      document.getElementById('position').value = '';
                  }
              }
          })"
>
    <legend class="legend">
        <h2>{{ __('forms.position') }}</h2>
    </legend>

    <div class="form-row-3">
        {{-- 1. Employee Type: Locked based on component state --}}
        <div class="form-group">
            <select name="employeeType"
                    id="employeeType"
                    class="peer input appearance-none bg-white text-gray-500 dark:bg-gray-800 dark:text-gray-400"
                    required
                    wire:model.live="form.employeeType"
                    x-model="employeeType"
                    :disabled="$wire.isPositionDataLocked || $wire.isCorePositionDataLocked">
                <option value="" disabled selected hidden>{{ __('forms.role_choose') }}</option>

                @foreach($this->dictionaries['EMPLOYEE_TYPE'] as $employeeTypes => $employeeTypeOption)
                    @if($employeeTypes === 'OWNER')
                        @continue
                    @endif

                    <option value="{{ $employeeTypes }}">{{ $employeeTypeOption }}</option>
                @endforeach

            </select>
            <label for="employeeType" class="label">{{ __('forms.role') }}</label>
            @error('form.employeeType') <p class="text-error">{{ $message }}</p> @enderror
        </div>

        {{-- 2. Position: Locked based on component state --}}
        <div class="form-group">
            @if(in_array($this->form->employeeType, config('ehealth.employee_type_custom_position_allowed', []), true))
                <input type="text"
                       name="position"
                       id="position"
                       class="peer input text-gray-500 dark:text-gray-400"
                       required
                       wire:model="form.position"
                       :disabled="$wire.isPositionDataLocked || $wire.isCorePositionDataLocked"
                       placeholder=" "/>
            @else
                <select name="position" id="position" class="peer input appearance-none bg-white text-gray-500 dark:bg-gray-800 dark:text-gray-400" required wire:model="form.position" :disabled="$wire.isPositionDataLocked || $wire.isCorePositionDataLocked">
                    <option value="" disabled selected hidden>{{ __('forms.select_position') }}</option>
                    <div x-show="employeeType && employeeTypePosition[employeeType]" x-cloak>
                        <template x-for="(positionName, positionKey) in employeeTypePosition[employeeType]" :key="positionKey">
                            <option :value="positionKey" x-text="positionName"></option>
                        </template>
                    </div>
                </select>
            @endif
            <label for="position" class="label">{{ __('forms.position') }}</label>
            @error('form.position') <p class="text-error">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="form-row-3">
        {{-- 3. Start Date: Locked based on component state --}}
        <div class="form-group datepicker-wrapper relative w-full">
            <input wire:model="form.startDate"
                   datepicker-format="{{ frontendDateFormat() }}"
                   type="text" name="startDate"
                   id="startDate"
                   class="peer input pl-10 appearance-none datepicker-input text-gray-500 dark:text-gray-400"
                   placeholder=" "
                   required
                   :disabled="$wire.isPositionDataLocked || $wire.isCorePositionDataLocked"
                   datepicker-autohide
                   datepicker-button="false"/>
            <label for="startDate" class="wrapped-label">{{ __('forms.start_date_work') }}</label>
            @error('form.startDate') <p class="text-error">{{$message}}</p> @enderror
        </div>

        {{-- 4. Subdivision: Always unlocked for editing --}}
        <div class="form-group">
            <select name="division" id="division" class="peer input appearance-none bg-white text-gray-500 dark:bg-gray-800 dark:text-gray-400" wire:model="form.divisionId">
                <option value="">{{ __('forms.select_division') }}</option>
                @foreach($this->divisions as $division)
                    <option value="{{ $division['id'] }}">{{ $division['name'] }}</option>
                @endforeach
            </select>
            <label for="division" class="label">{{ __('forms.division') }}</label>
            @error('form.divisionId') <p class="text-error">{{ $message }}</p> @enderror
        </div>

        {{-- 5. Email: Locked based on component state --}}
        @if (!empty($partyUsers))
            <div class="form-group" x-transition wire:key="party-user-email-select">
                <select name="formEmail" id="formEmail"
                        class="peer input appearance-none bg-white text-gray-500 dark:bg-gray-800 dark:text-gray-400"
                        required wire:model="formEmail"
                        :disabled="$wire.isPositionDataLocked">
                    <option value="" disabled>{{ __('forms.select_user_email') }}</option>
                    @foreach($partyUsers as $user)
                        <option value="{{ $user->email }}">{{ $user->email }}</option>
                    @endforeach
                </select>
                <label for="formEmail" class="label">{{ __('forms.email') }}</label>
                @error('formEmail') <p class="text-error">{{ $message }}</p> @enderror
            </div>
        @endif
    </div>
</fieldset>
