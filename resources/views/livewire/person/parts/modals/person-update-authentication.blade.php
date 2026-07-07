<div x-data="{ showAuthModal: $wire.entangle('showConfirmationUpdateModal') }">
    <template x-teleport="body">
        <div x-cloak
            x-show="showAuthModal"
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
                                <input wire:model="form.verificationCode"
                                       id="verificationCode"
                                       name="verificationCode"
                                       maxlength="4"
                                       type="text"
                                       class="input-modal"
                                       placeholder=" "
                                       autocomplete="off"
                                       required
                                >

                                @error('form.verificationCode')
                                <p class="text-error">
                                    {{ $message }}
                                </p>
                                @enderror
                            </div>

                            <div class="form-group flex items-end">
                                <button
                                    :disabled="($wire.form.verificationCode ?? '').length !== 4"
                                    wire:click.prevent="approvePersonalDataUpdate"
                                    type="button"
                                    class="button-primary"
                                >
                                    {{ __('forms.confirm') }}
                                </button>
                            </div>
                        </div>

                        {{-- Resend SMS --}}
                        <div class="form-group">
                            <button type="button"
                                    wire:click.prevent="resendApprovalSmsCode"
                                    @click="resetCooldown(); startCooldown()"
                                    x-data="{
                                        cooldown: 60,
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
                                    x-effect="if (!modalOpened) { modalOpened = true; startCooldown(); }"
                                    :disabled="cooldown > 0"
                                    :class="{ 'cursor-not-allowed': cooldown > 0 }"
                                    class="button-minor gap-2"
                            >
                                @icon('mail', 'w-4 h-4 text-gray-800 dark:text-white')
                                <span
                                    x-text="cooldown > 0 ? `Відправити ще раз (через ${cooldown} с)` : 'Відправити ще раз'">
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
