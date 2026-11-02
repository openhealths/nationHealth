@use('App\Enums\Person\AuthStep')

<div x-init="$wire.set('verificationCode', '', false)">
    <div
        x-data="{
            timer: 60,
            init() {
                setInterval(() => {
                    if (this.timer > 0) this.timer--;
                }, 1000);
            },
            resetTimer() {
                if (this.timer === 0) {
                    this.timer = 60;
                    $wire.resendCode();
                }
            },
        }"
    >
        <legend class="legend mt-6">{{ __('forms.confirmation_code_from_SMS') }}</legend>

        <div class="form-row-3 mt-4">
            <div class="form-group group">
                <input
                    type="text"
                    wire:model="verificationCode"
                    inputmode="numeric"
                    name="verificationCode"
                    id="verificationCode"
                    class="peer input"
                    placeholder=" "
                    autocomplete="off"
                    maxlength="4"
                    x-mask="9999"
                />
                <label for="verificationCode" class="label"> {{ __('patients.code_sms') }} </label>
            </div>

            <button type="button" @click="resetTimer()" :disabled="timer > 0" class="button-minor">
                @icon('mail', 'w-4 h-4 mr-2')
                <span>{{ __('forms.send_again') }}</span>
                <template x-if="timer > 0">
                    <span x-text="`(${timer}c)`"></span>
                </template>
            </button>
        </div>

        <div class="flex gap-4">
            <button type="button" @click="localStep = {{ AuthStep::COMPLETE_VERIFICATION }}" class="button-minor">
                {{ __('forms.back') }}
            </button>

            <button type="button" @click="localStep = {{ AuthStep::INITIAL }}" class="button-outline-primary">
                {{ __('patients.to_authentication_methods') }}
            </button>

            <button type="button" wire:click="approveUpdatingPhoneNumber" class="button-primary">
                {{ __('forms.confirm') }}
            </button>
        </div>
    </div>
</div>
