<div x-data="{ showAuthModal: @entangle('showAuthModal') }">
    <template x-teleport="body">
        <div x-show="showAuthModal"
             style="display: none"
             @keydown.escape.prevent.stop="showAuthModal = false"
             role="dialog"
             aria-modal="true"
             class="modal"
        >
            <div x-transition.opacity class="fixed inset-0 bg-black/30"></div>
            <div x-transition @click="showAuthModal = false" class="modal-wrapper">
                <div @click.stop
                     x-trap.noscroll.inert="showAuthModal"
                     class="modal-content w-full max-w-2xl mx-auto"
                >
                    <h2 class="mb-8 text-2xl font-semibold text-gray-900 dark:text-white text-center">
                        {{ __('forms.authentication') }}
                    </h2>

                    <form>
                        <div class="form-row-2">
                            <div class="form-group">
                                <label for="verificationCode" class="label-modal">
                                    {{ __('forms.confirmation_code_from_SMS') }}
                                </label>
                                <input wire:model="verificationCode"
                                       id="verificationCode"
                                       name="verificationCode"
                                       maxlength="4"
                                       type="text"
                                       class="input-modal"
                                       placeholder=" "
                                       autocomplete="off"
                                       required
                                >

                                @error('verificationCode')
                                <p class="text-error">
                                    {{ $message }}
                                </p>
                                @enderror
                            </div>

                            <div class="form-group flex items-end">
                                <button wire:click.prevent="{{ $verifyAction ?? 'verify' }}" type="button" class="button-primary">
                                    {{ __('forms.confirm') }}
                                </button>
                            </div>
                        </div>

                        {{-- Resend SMS --}}
                        <div class="form-row">
                            <div class="form-group">
                                <button type="button"
                                        x-data="{
                                            cooldown: 60,
                                            sentOnce: @entangle('smsResent'),
                                            interval: null,
                                            modalOpened: false,
                                            startCooldown() {
                                                if (this.interval) {
                                                    clearInterval(this.interval);
                                                    this.interval = null;
                                                }

                                                this.cooldown = 60;

                                                if (this.cooldown > 0) {
                                                    this.interval = setInterval(() => {
                                                        if (this.cooldown > 0) {
                                                            this.cooldown--;
                                                        } else {
                                                            clearInterval(this.interval);
                                                            this.interval = null;
                                                        }
                                                    }, 1000);
                                                }
                                            },
                                            resetCooldown() {
                                                this.cooldown = 60;
                                                if (this.interval) {
                                                    clearInterval(this.interval);
                                                    this.interval = null;
                                                }
                                            }
                                        }"
                                        x-init=""
                                        x-effect="if (showAuthModal && !modalOpened) { modalOpened = true; startCooldown(); }"
                                        wire:click.prevent="{{ $resendAction ?? 'resendSms' }}"
                                        @click="if (!sentOnce) {
                                            resetCooldown();
                                            startCooldown();
                                        }"
                                        :disabled="cooldown > 0 || sentOnce"
                                        :class="{ 'cursor-not-allowed': cooldown > 0 || sentOnce }"
                                        class="button-minor gap-2"
                                >
                                    @icon('mail', 'w-4 h-4 text-gray-800 dark:text-white')
                                    <span x-text="sentOnce ? 'СМС вже відправлено' : (cooldown > 0 ? `Відправити ще раз (через ${cooldown} с)` : '{{ __('forms.send_again') }}')">
                                    </span>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>
</div>
