<div
    x-data="{
        showAuthModal: $wire.entangle('showAuthModal'),
        code: $wire.entangle('verificationCode'),
    }"
>
    <template x-teleport="body">
        <div
            x-show="showAuthModal"
            style="display: none"
            @keydown.escape.prevent.stop="showAuthModal = false"
            role="dialog"
            aria-modal="true"
            class="fixed inset-0 z-[100] overflow-y-auto"
        >
            {{-- Backdrop --}}
            <div
                x-show="showAuthModal"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity"
            ></div>

            <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-0">
                <div
                    x-show="showAuthModal"
                    x-transition:enter="ease-out duration-300"
                    x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-200"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    @click.stop
                    class="relative transform overflow-hidden rounded-2xl bg-white text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-md dark:bg-gray-800"
                >
                    @php
                        $authType = is_array($currentAuthMethod) ? ($currentAuthMethod['type'] ?? null) : null;
                        $isOfflineMethod = $authType === 'OFFLINE' || ($authType !== 'OTP' && !is_null($authType));
                    @endphp
                    <div class="px-6 pt-8 pb-4 text-center">
                        @if ($isOfflineMethod)
                            <div class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-emerald-50 dark:bg-emerald-900/30">
                                @icon('file-text', 'w-8 h-8 text-emerald-600 dark:text-emerald-400')
                            </div>
                            <h3 class="mb-2 text-2xl font-bold text-gray-900 dark:text-white">
                                Підтвердження за документами
                            </h3>
                            <div class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-left dark:border-emerald-700 dark:bg-emerald-900/20">
                                <p class="flex items-center gap-2 text-sm font-bold text-emerald-800 dark:text-emerald-200">
                                    @icon('check-circle', 'w-5 h-5 text-emerald-600 dark:text-emerald-400')
                                    Пацієнт авторизований за документами (СМС не потрібне, перевірте посвідчення особи)
                                </p>
                                <p class="mt-2 text-xs text-emerald-600 dark:text-emerald-300">
                                    Для активації плану лікування в ЕСОЗ переконайтеся у відповідності посвідчення особи
                                    пацієнта та натисніть «Підтвердити перевірку документів».
                                </p>
                            </div>
                        @else
                            <div class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-blue-50 dark:bg-blue-900/30">
                                @icon('mail', 'w-8 h-8 text-blue-600 dark:text-blue-400')
                            </div>
                            <h3 class="mb-2 text-2xl font-bold text-gray-900 dark:text-white">Підтвердження плану</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                Ми надіслали SMS з 4-значним кодом на номер пацієнта. Будь ласка, введіть його нижче.
                            </p>
                            @if ($authType === 'OTP')
                                <div class="mt-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-left dark:border-blue-700 dark:bg-blue-900/30">
                                    <p class="flex items-center gap-2 text-xs font-bold text-blue-800 dark:text-blue-200">
                                        @icon('phone', 'w-4 h-4 text-blue-600 dark:text-blue-400')
                                        Пацієнт підтверджує дозвіл через СМС {{ $currentAuthMethod['phone_number'] ?? '' }}
                                    </p>
                                </div>
                            @endif
                        @endif
                        @if (is_null($currentAuthMethod) && legalEntity()?->type?->name === 'OUTPATIENT')
                            <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-left dark:border-amber-700 dark:bg-amber-900/30">
                                <p class="text-xs font-semibold text-amber-700 dark:text-amber-300">
                                    ⚠️ Метод підтвердження не визначено
                                </p>
                                <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                    Пацієнт не має активної декларації з цим закладом в ЕСОЗ (типова поведінка тестового
                                    середовища). У тестовому середовищі введіть код <strong>1234</strong>.
                                </p>
                            </div>
                        @endif
                        @if (!$isOfflineMethod && config('app.env') !== 'production' && legalEntity()?->type?->name === 'OUTPATIENT')
                            <p class="mt-2 rounded-lg bg-blue-50 p-2 text-xs font-medium text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                                🔧 Режим тестування: Використовуйте тестовий код <strong>1234</strong>
                            </p>
                        @endif
                    </div>

                    <div class="px-8 py-6">
                        <div class="flex flex-col gap-6">
                            @if (!$isOfflineMethod)
                                {{-- OTP Input logic (big digits) --}}
                                <div class="flex justify-center gap-3">
                                    <input
                                        type="text"
                                        wire:model="verificationCode"
                                        maxlength="4"
                                        class="w-full rounded-xl border-2 border-gray-100 py-4 text-center text-4xl font-bold uppercase transition-all focus:border-blue-500 focus:ring-0 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                                        style="letter-spacing: 0.5em; padding-left: 0.5em"
                                        placeholder="••••"
                                        autocomplete="off"
                                        autofocus
                                    />
                                </div>

                                @error('verificationCode')
                                    <p class="text-center text-sm font-medium text-red-500">{{ $message }}</p>
                                @enderror

                                <button
                                    type="button"
                                    wire:click="verify"
                                    class="w-full rounded-xl bg-blue-600 py-4 text-lg font-bold text-white shadow-lg shadow-blue-500/30 transition-all hover:bg-blue-700 active:scale-[0.98]"
                                >
                                    АКТИВУВАТИ ПЛАН
                                </button>
                            @else
                                <button
                                    type="button"
                                    wire:click="verify"
                                    class="w-full rounded-xl bg-emerald-600 py-4 text-lg font-bold text-white shadow-lg shadow-emerald-500/30 transition-all hover:bg-emerald-700 active:scale-[0.98]"
                                >
                                    ПІДТВЕРДИТИ ПЕРЕВІРКУ ДОКУМЕНТІВ
                                </button>
                            @endif
                        </div>
                    </div>

                    @if (!$isOfflineMethod)
                        <div class="px-8 pb-8">
                            <div
                                x-data="{
                                    cooldown: 60,
                                    timer: null,
                                    startTimer() {
                                        this.cooldown = 60;
                                        if (this.timer) clearInterval(this.timer);
                                        this.timer = setInterval(() => {
                                            if (this.cooldown > 0) this.cooldown--;
                                            else clearInterval(this.timer);
                                        }, 1000);
                                    },
                                }"
                                x-init="
                                    startTimer();
                                    $watch('showAuthModal', (value) => {
                                        if (value) startTimer();
                                    });
                                "
                                class="text-center"
                            >
                                <button
                                    type="button"
                                    wire:click="resendSms"
                                    :disabled="cooldown > 0"
                                    @click="startTimer()"
                                    class="text-sm font-medium transition-colors"
                                    :class="cooldown > 0
                                        ? 'text-gray-400 cursor-not-allowed'
                                        : 'text-blue-600 hover:text-blue-800 underline'"
                                >
                                    <span x-show="cooldown > 0">Надіслати повторно через <span x-text="cooldown"></span> сек</span>
                                    <span x-show="cooldown <= 0">Надіслати код повторно</span>
                                </button>
                            </div>
                        </div>
                    @endif

                    <div class="flex justify-center bg-gray-50 px-6 py-4 dark:bg-gray-700/50">
                        <button
                            @click="showAuthModal = false"
                            class="text-sm font-medium text-gray-500 hover:text-gray-700"
                        >
                            Скасувати та повернутись
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
