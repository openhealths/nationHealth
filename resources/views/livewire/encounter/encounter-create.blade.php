<div>
    @include('livewire.encounter.encounter')

    <!-- Referral Redeem Modal -->
    <div x-data="{ show: @entangle('showReferralRedeemModal') }" x-cloak>
        <div
            x-show="show"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-gray-900/50 backdrop-blur-sm transition-opacity"
        >
            <div
                class="relative w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-gray-800"
                @click.away="show = false"
            >
                <div class="mb-4 text-center">
                    <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400">
                        @icon('info-circle', 'w-6 h-6')
                    </div>
                    <h3 class="mb-2 text-lg font-bold text-gray-900 dark:text-gray-100">Взаємодію успішно створено</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Бажаєте одразу погасити прив'язане направлення?
                    </p>
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button type="button" wire:click="closeRedeemModal" class="btn-secondary w-full sm:w-auto">
                        Закрити
                    </button>
                    <button type="button" wire:click="redeemReferral" class="btn-primary w-full sm:w-auto">
                        Погасити направлення
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
