
<form wire:submit.prevent="verifyCode">
    <legend class="legend mb-6 text-2xl font-bold text-gray-900 dark:text-white">
        {{ __('care-plan.auth_modal_title') }}
    </legend>

    <div class="mb-6">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('care-plan.sms_sent_to_number') }}
            <strong class="ml-1 text-gray-900 dark:text-white">
                {{ $smsPhone ?? '-' }}
            </strong>
        </p>
    </div>

    <div class="pt-2">
        <h3 class="mb-6 text-xl font-bold text-gray-900 dark:text-white">
            {{ __('patients.code_sms') }}
        </h3>

        <div class="flex items-end gap-4" style="display: flex; align-items: flex-end">
            <div
                class="form-group group !mb-0"
                style="flex: 0 1 320px; max-width: 320px; width: 100%"
            >
                <input
                    type="text"
                    placeholder=" "
                    class="peer input @error('verificationCode') input-error @enderror"
                    wire:model="verificationCode"
                    id="approval-verification-code"
                    autocomplete="one-time-code"
                    inputmode="numeric"
                    maxlength="4"
                    x-init="$nextTick(() => $el.focus())"
                />

                <label class="label" for="approval-verification-code">
                    {{ __('forms.confirmation_code_from_SMS') }}
                </label>

                @error('verificationCode')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>

            <div
                x-data="{
                    timer: 0,
                    interval: null,

                    init() {
                        this.startTimer();
                    },

                    startTimer() {
                        const target = @js($smsResendAvailableAt ?? 0) * 1000;

                        if (this.interval) clearInterval(this.interval);

                        const updateTimer = () => {
                            this.timer = Math.max(
                                0,
                                Math.ceil((target - Date.now()) / 1000)
                            );
                        };

                        updateTimer();

                        if (this.timer > 0) {
                            this.interval = setInterval(() => {
                                updateTimer();

                                if (this.timer === 0) {
                                    clearInterval(this.interval);
                                    this.interval = null;
                                }
                            }, 1000);
                        }
                    },

                    resend() {
                        if (this.timer === 0 && !$wire.smsResent) {
                            $wire.resendSms();
                        }
                    },

                    destroy() {
                        if (this.interval) clearInterval(this.interval);
                    }
                }"
                class="shrink-0"
            >
                <button
                    type="button"
                    :disabled="timer > 0 || $wire.smsResent"
                    @click="resend()"
                    wire:loading.attr="disabled"
                    wire:target="resendSms"
                    class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm whitespace-nowrap transition-colors disabled:opacity-70 dark:border-gray-600 dark:bg-gray-700"
                    :class="timer > 0 || $wire.smsResent
                        ? 'cursor-not-allowed text-gray-400 dark:text-gray-400'
                        : 'hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 cursor-pointer'"
                >
                    @icon('mail', 'w-4 h-4 text-gray-600 dark:text-gray-300')

                    <span>
                        <span x-show="$wire.smsResent" x-cloak>
                            {{ __('approval.create.sms_already_resent') }}
                        </span>

                        <span x-show="timer > 0 && !$wire.smsResent" x-cloak>
                            {{ __('patients.resend_again_in_seconds') }}
                            <span x-text="timer"></span>
                            {{ __('patients.seconds_short') }}
                        </span>

                        <span x-show="timer === 0 && !$wire.smsResent" x-cloak>
                            {{ __('forms.send_again') }}
                        </span>
                    </span>
                </button>
            </div>
        </div>

        @if($smsResent)
            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                {{ __('approval.create.sms_request_accepted') }}
            </p>
        @endif

        @error('smsResend')
            <p class="text-error mt-4">{{ $message }}</p>
        @enderror
    </div>

    <div class="mt-12 flex items-center justify-end gap-4">
        <button
            type="button"
            wire:click="closeDrawer"
            class="button-minor px-6 py-2.5"
        >
            {{ __('forms.cancel') }}
        </button>

        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="verifyCode"
            class="button-primary px-6 py-2.5 disabled:cursor-not-allowed disabled:opacity-50"
        >
            {{ __('forms.confirm') }}
        </button>
    </div>
</form>
