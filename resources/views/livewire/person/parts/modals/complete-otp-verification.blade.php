@use('App\Enums\Person\AuthStep')

<div x-init="$wire.set('code', '', false)">
    <legend class="legend">{{ __('patients.authentication_SMS') }}</legend>

    <div class="mt-4 mb-8 flex items-start rounded-lg bg-gray-100 p-4 dark:bg-slate-800">
        @icon('alert-circle', 'w-5 h-5 text-slate-600 dark:text-slate-400 mr-3 mt-0.5')
        <p class="text-sm text-gray-800 dark:text-gray-200">
            {{ __('patients.please_check_patient_number') }}
            <span class="font-bold text-slate-900 dark:text-white">{{ $phoneNumber }}</span>
        </p>
    </div>

    <legend class="legend">{{ __('patients.code_sms') }}</legend>

    <div class="form-row-3 mt-4">
        <div class="form-group group">
            <input
                type="text"
                wire:model="code"
                inputmode="numeric"
                name="code"
                id="code"
                class="peer input @error('code') input-error @enderror"
                placeholder=" "
                autocomplete="off"
                maxlength="4"
                x-mask="9999"
            />
            <label for="code" class="label"> {{ __('forms.confirmation_code_from_SMS') }} </label>

            @error('code')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="flex gap-4">
        <button type="button" @click="localStep = {{ AuthStep::CHANGE_PHONE_INITIAL }}" class="button-minor">
            {{ __('forms.back') }}
        </button>

        <button type="button" @click="localStep = {{ AuthStep::INITIAL }}" class="button-outline-primary">
            {{ __('patients.to_authentication_methods') }}
        </button>

        <button type="button" wire:click="completeVerifyingOwnership" class="button-primary">
            {{ __('forms.confirm') }}
        </button>
    </div>
</div>
