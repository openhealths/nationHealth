@use('App\Enums\Person\AuthStep')

<div>
    <legend class="legend mb-8 text-2xl font-bold">{{ __('patients.confirm_new_phone') }}</legend>

    <div class="form-row-3">
        <div class="form-group">
            <input
                type="tel"
                placeholder=" "
                class="peer input @error('form.phoneNumber') input-error @enderror"
                value="{{ $form->phoneNumber }}"
                readonly
            />
            <label class="label">{{ __('forms.phone') }}</label>

            @error('form.phoneNumber')
                <p class="text-error">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="mt-8 flex gap-3">
        <button type="button" @click="localStep = {{ AuthStep::CHANGE_PHONE_INITIAL }}" class="button-minor">
            {{ __('forms.back') }}
        </button>

        <button type="button" wire:click="updatePhoneNumber" class="button-primary">{{ __('forms.confirm') }}</button>
    </div>
</div>
