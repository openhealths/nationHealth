@use('App\Enums\Person\AuthenticationMethod')

<div
    x-data="{
        showAuthModal: $wire.entangle('showAuthModal'),
    }"
>
    @if ($smsResendLinkId !== null)
        <div wire:poll.3s="checkSmsResendStatus" class="hidden"></div>
    @endif
    <template x-teleport="body">
        <div
            x-show="showAuthModal"
            style="display: none"
            @keydown.escape.prevent.stop="showAuthModal = false"
            role="dialog"
            aria-modal="true"
            class="modal"
        >
            <div
                x-transition.opacity
                class="fixed inset-0 bg-black/30 backdrop-blur-sm"
                @click="showAuthModal = false"
            ></div>
            <div class="modal-wrapper">
                <div
                    @click.stop
                    x-trap.noscroll.inert="showAuthModal"
                    class="modal-content mx-auto w-full max-w-4xl rounded-2xl bg-white p-6 shadow-xl sm:p-8 dark:bg-gray-800"
                >
                    <div>
                        <legend class="legend mb-6 text-2xl font-bold text-gray-900 dark:text-white">
                            {{ __('care-plan.auth_modal_title') }}
                        </legend>

                        @if ($this->isOfflineAuthMethod())
                            <div class="space-y-4 text-sm text-gray-700 dark:text-gray-200">
                                @include('livewire.person.parts.patient-leaflet', [
                                    'isThirdPerson' => ($this->currentAuthMethod['type'] ?? null) === AuthenticationMethod::THIRD_PERSON->value,
                                ])

                                <p>{{ __('care-plan.approval_offline_help') }}</p>
                                <a
                                    href="https://ehealth.gov.ua/privacy_access.html"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-blue-600 underline dark:text-blue-400"
                                >
                                    {{ __('care-plan.approval_patient_notice') }}
                                </a>
                                <div class="form-row">
                                    <div class="form-group group flex items-start gap-3">
                                        <input
                                            type="checkbox"
                                            wire:model="offlineAcknowledged"
                                            id="offlineAcknowledged"
                                            name="offlineAcknowledged"
                                            class="default-checkbox mt-1"
                                        />
                                        <label class="default-p cursor-pointer" for="offlineAcknowledged">
                                            {{ __('care-plan.approval_offline_acknowledgement') }}
                                        </label>
                                    </div>
                                </div>
                                @error('offlineAcknowledged')
                                    <p class="text-error">{{ $message }}</p>
                                @enderror
                                @error('verificationCode')
                                    <p class="text-error">{{ $message }}</p>
                                @enderror
                            </div>
                        @else
                            <div class="mb-6">
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ __('care-plan.sms_sent_to_number') }}
                                    <strong class="ml-1 text-gray-900 dark:text-white">{{ $phoneNumber ?? '-' }}</strong>
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
                                            id="verificationCode"
                                            autocomplete="one-time-code"
                                            inputmode="numeric"
                                            maxlength="4"
                                        />
                                        <label class="label" for="verificationCode">
                                            {{ __('forms.confirmation_code_from_SMS') }}
                                        </label>
                                        @error('verificationCode')
                                            <p class="text-error">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div
                                        x-data="{
                                            timer: 60,
                                            interval: null,
                                            init() {
                                                this.startTimer();
                                                this.$watch('$wire.showAuthModal', (value) => {
                                                    if (value) this.startTimer();
                                                });
                                                this.$watch('$wire.smsResent', (value) => {
                                                    if (value) this.startTimer();
                                                });
                                            },
                                            startTimer() {
                                                this.timer = 60;
                                                if (this.interval) clearInterval(this.interval);
                                                this.interval = setInterval(() => {
                                                    if (this.timer > 0) {
                                                        this.timer--;
                                                    } else {
                                                        clearInterval(this.interval);
                                                    }
                                                }, 1000);
                                            },
                                            resetTimer() {
                                                if (this.timer === 0 && ! $wire.isSmsResending && ! $wire.smsResent) {
                                                    $wire.resendSms();
                                                }
                                            },
                                        }"
                                        class="shrink-0"
                                    >
                                        <button
                                            type="button"
                                            :disabled="timer > 0 || $wire.isSmsResending || $wire.smsResent"
                                            @click="resetTimer()"
                                            wire:loading.attr="disabled"
                                            wire:target="resendSms"
                                            class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm whitespace-nowrap transition-colors disabled:opacity-70 dark:border-gray-600 dark:bg-gray-700"
                                            :class="timer > 0 || $wire.isSmsResending || $wire.smsResent
                                                ? 'cursor-not-allowed text-gray-400 dark:text-gray-400'
                                                : 'hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 cursor-pointer'"
                                        >
                                            @icon('mail', 'w-4 h-4 text-gray-600 dark:text-gray-300')
                                            <span>
                                                <span x-show="$wire.isSmsResending">{{ __('care-plan.sms_resend_processing') }}</span>
                                                <span x-show="$wire.smsResent && ! $wire.isSmsResending">{{ __('care-plan.sms_resent') }}</span>
                                                <span x-show="timer > 0 && ! $wire.isSmsResending && ! $wire.smsResent"
                                                    >{{ __('patients.resend_again_in_seconds') }}
                                                    <span x-text="timer"></span>
                                                    {{ __('patients.seconds_short') }}</span>
                                                <span x-show="timer === 0 && ! $wire.isSmsResending && ! $wire.smsResent">{{ __('forms.send_again') }}</span>
                                            </span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                        @endif

                        <div class="mt-12 flex items-center justify-end gap-4">
                            <button type="button" @click="showAuthModal = false" class="button-minor px-6 py-2.5">
                                {{ __('forms.cancel') ?? 'Скасувати' }}
                            </button>

                            <button
                                type="button"
                                wire:click="verify"
                                wire:loading.attr="disabled"
                                wire:target="verify"
                                @disabled($isSmsResending)
                                @if ($this->isOfflineAuthMethod())
                                    :disabled="! $wire.offlineAcknowledged"
                                @endif
                                class="button-primary px-6 py-2.5 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {{ __('forms.confirm') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
