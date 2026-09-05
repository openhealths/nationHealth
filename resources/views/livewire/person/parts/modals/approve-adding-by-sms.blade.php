@use('App\Enums\Person\AuthStep')

<div wire:key="auth-step-add-sms-full">
    <legend class="legend">
        {{ __('patients.adding_authentication_method_SMS') }}
    </legend>

    <div class="space-y-8">
        <div class="space-y-6">
            <div class="form-row-3">
                <div class="form-group group">
                    <input type="tel"
                           placeholder=" "
                           class="peer input !py-2"
                           wire:model="form.phoneNumber"
                           id="phoneNumber"
                           x-mask="+380999999999"
                    />
                    <label class="label" for="phoneNumber">{{ __('+380') }}</label>
                </div>
            </div>

            <div class="form-row-3">
                <div class="form-group group">
                    <input type="text"
                           placeholder=" "
                           class="peer input !py-2"
                           wire:model="form.methodName"
                           id="add_sms_name"
                    />
                    <label class="label" for="add_sms_name">{{ __('patients.authentication_method_name') }}</label>
                </div>
            </div>
        </div>

        <div class="pt-4">
            <h3 class="text-xl font-bold mb-6 text-gray-900 dark:text-white">
                {{ __('patients.code_sms') }}
            </h3>

            <div class="form-row-4 flex items-end gap-4" style="display: flex; align-items: flex-end;">
                <div class="form-group group !mb-0" style="flex: 0 1 300px;">
                    <input type="text"
                           placeholder=" "
                           class="peer input !py-2"
                           wire:model="smsCode"
                           id="smsCode"
                    />
                    <label class="label" for="smsCode">{{ __('forms.confirmation_code_from_SMS') }}</label>
                </div>

                <div x-data="{
                         timer: 60,
                         init() {
                             let interval = setInterval(() => {
                                 if (this.timer > 0) this.timer--;
                                 else clearInterval(interval);
                             }, 1000);
                         }
                     }"
                     class="shrink-0"
                >
                    <button type="button"
                            :disabled="timer > 0"
                            class="bg-white border border-gray-200 rounded-lg px-4 py-2.5 flex items-center gap-2 text-sm transition-colors disabled:opacity-70 whitespace-nowrap"
                            :class="timer > 0 ? 'cursor-not-allowed' : 'hover:bg-gray-50'"
                            wire:click="resendSms"
                    >
                        @icon('mail', 'w-4 h-4 text-gray-600')
                        <span class="text-gray-700">
                            <span x-show="timer > 0">{{ __('patients.resend_again_in_seconds') }} <span x-text="timer"></span> {{ __('patients.seconds_short') }}</span>
                            <span x-show="timer === 0">{{ __('forms.send_again') }}</span>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-12 flex gap-3">
        <button type="button" @click="localStep = {{ AuthStep::INITIAL }}" class="button-minor">
            {{ __('forms.back') }}
        </button>

        <button type="button" @click="localStep = {{ AuthStep::INITIAL }}" class="button-outline-primary">
            {{ __('patients.to_authentication_methods') }}
        </button>

        <button type="button" wire:click="submitSmsMethod" class="button-primary">
            {{ __('forms.confirm') }}
        </button>
    </div>
</div>
