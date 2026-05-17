<div x-data="{ 
    showAuthModal: $wire.entangle('showAuthModal'),
    code: $wire.entangle('verificationCode')
}">
    <template x-teleport="body">
        <div x-show="showAuthModal"
             style="display: none"
             @keydown.escape.prevent.stop="showAuthModal = false"
             role="dialog"
             aria-modal="true"
             class="fixed inset-0 z-[100] overflow-y-auto"
        >
            {{-- Backdrop --}}
            <div x-show="showAuthModal" 
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity"
            ></div>

            <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-0">
                <div x-show="showAuthModal"
                     x-transition:enter="ease-out duration-300"
                     x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                     x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                     x-transition:leave="ease-in duration-200"
                     x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                     x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                     @click.stop
                     class="relative transform overflow-hidden rounded-2xl bg-white dark:bg-gray-800 text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-md"
                >
                    <div class="px-6 pt-8 pb-4 text-center">
                        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-blue-50 dark:bg-blue-900/30 mb-6">
                            @icon('mail', 'w-8 h-8 text-blue-600 dark:text-blue-400')
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Підтвердження плану</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Ми надіслали SMS з 4-значним кодом на номер пацієнта. Будь ласка, введіть його нижче.
                        </p>
                    </div>

                    <div class="px-8 py-6">
                        <div class="flex flex-col gap-6">
                            {{-- OTP Input logic (big digits) --}}
                            <div class="flex justify-center gap-3">
                                <input type="text" 
                                       wire:model="verificationCode"
                                       maxlength="4"
                                       class="w-full text-center text-4xl font-bold py-4 rounded-xl border-2 border-gray-100 focus:border-blue-500 focus:ring-0 dark:bg-gray-700 dark:border-gray-600 dark:text-white transition-all uppercase"
                                       style="letter-spacing: 0.5em; padding-left: 0.5em;"
                                       placeholder="••••"
                                       autocomplete="off"
                                       autofocus
                                >
                            </div>

                            @error('verificationCode')
                                <p class="text-sm text-red-500 text-center font-medium">{{ $message }}</p>
                            @enderror

                            <button type="button" 
                                    wire:click="verify"
                                    class="w-full py-4 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold text-lg shadow-lg shadow-blue-500/30 transition-all active:scale-[0.98]"
                            >
                                АКТИВУВАТИ ПЛАН
                            </button>
                        </div>
                    </div>

                    <div class="px-8 pb-8">
                        <div x-data="{
                            cooldown: 60,
                            timer: null,
                            startTimer() {
                                this.cooldown = 60;
                                if(this.timer) clearInterval(this.timer);
                                this.timer = setInterval(() => {
                                    if(this.cooldown > 0) this.cooldown--;
                                    else clearInterval(this.timer);
                                }, 1000);
                            }
                        }" 
                        x-init="startTimer(); $watch('showAuthModal', value => { if(value) startTimer(); })"
                        class="text-center"
                        >
                            <button type="button"
                                    wire:click="resendSms"
                                    :disabled="cooldown > 0"
                                    @click="startTimer()"
                                    class="text-sm font-medium transition-colors"
                                    :class="cooldown > 0 ? 'text-gray-400 cursor-not-allowed' : 'text-blue-600 hover:text-blue-800 underline'"
                            >
                                <span x-show="cooldown > 0">Надіслати повторно через <span x-text="cooldown"></span> сек</span>
                                <span x-show="cooldown <= 0">Надіслати код повторно</span>
                            </button>
                        </div>
                    </div>

                    <div class="bg-gray-50 dark:bg-gray-700/50 px-6 py-4 flex justify-center">
                        <button @click="showAuthModal = false" class="text-sm text-gray-500 hover:text-gray-700 font-medium">
                            Скасувати та повернутись
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
