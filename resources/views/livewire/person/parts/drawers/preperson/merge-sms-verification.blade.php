<x-dialog-drawer
    x-model="showMergeSmsDrawer"
    onCloseClick="showMergeSmsDrawer = false"
    maxWidth="4/5"
>
    <x-slot name="title">
        {{ __('preperson.merge.auth_via_sms') }}
    </x-slot>

    <div x-data="{
        code: '41432',
        timer: 60,
        smsInterval: null,
        get maskedPhone() {
            const phone = selectedMergePatient?.phones?.[0]?.number || '+38095123xxxxx';
            if (phone.includes('xxxxx')) return phone;
            if (phone.length > 5) {
                return phone.substring(0, phone.length - 5) + 'xxxxx';
            }
            return phone;
        },
        startTimer() {
            this.timer = 60;
            if (this.smsInterval) clearInterval(this.smsInterval);
            this.smsInterval = setInterval(() => {
                if (this.timer > 0) this.timer--;
                else clearInterval(this.smsInterval);
            }, 1000);
        },
        init() { this.startTimer(); }
    }">

    <div class="mt-8 space-y-8">
        <div class="p-6 rounded-xl bg-gray-50 dark:bg-gray-800/40 border border-gray-100 dark:border-gray-700/50 flex gap-3.5">
            @icon('alert-circle', 'w-5 h-5 text-gray-500 dark:text-gray-400 shrink-0 mt-0.5')
            <div class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed">
                {{ __('preperson.merge.phone_access_check') }}
                <span class="font-semibold text-gray-900 dark:text-white" x-text="maskedPhone"></span>
            </div>
        </div>

        <div class="space-y-6">
            <h4 class="text-lg font-bold text-gray-900 dark:text-white">
                {{ __('preperson.merge.sms_code') }}
            </h4>

            <div class="flex items-end gap-6">
                <div class="form-group group !mb-0 max-w-xs flex-1">
                    <input type="text"
                           placeholder=" "
                           class="peer input !py-2.5"
                           x-model="code"
                           id="mergeSmsCode"
                           autocomplete="off"
                    >
                    <label class="label" for="mergeSmsCode">
                        {{ __('preperson.merge.sms_confirmation_code_label') }}
                    </label>
                </div>

                <div>
                    <button type="button"
                            :disabled="timer > 0"
                            class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg px-4 py-2.5 flex items-center gap-2 text-sm transition-colors disabled:opacity-70 whitespace-nowrap"
                            :class="timer > 0 ? 'cursor-not-allowed' : 'hover:bg-gray-50 dark:hover:bg-gray-700'"
                            @click="startTimer()"
                    >
                        @icon('mail', 'w-4 h-4 text-gray-600 dark:text-gray-400')
                        <span class="text-gray-700 dark:text-gray-200">
                            <span x-show="timer > 0">{{ __('patients.resend_again_in_seconds') }} <span x-text="timer"></span> {{ __('patients.seconds_short') }}</span>
                            <span x-show="timer === 0">{{ __('forms.send_again') }}</span>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="flex gap-3 mt-12">
        <button class="button-minor"
                type="button"
                @click="showMergeSmsDrawer = false; showMergeConfirmationDrawer = true"
        >
            {{ __('forms.back') }}
        </button>

        <button class="button-primary"
                type="button"
                :disabled="!code"
                @click="showMergeSmsDrawer = false; showMergeFinalConsentDrawer = true"
        >
            {{ __('forms.confirm') }}
        </button>
    </div>
</x-dialog-drawer>
