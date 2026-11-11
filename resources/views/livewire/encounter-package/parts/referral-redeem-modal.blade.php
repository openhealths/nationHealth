<!-- Referral Redeem Modal -->
<div x-data="{ show: $wire.entangle('showReferralRedeemModal') }" x-cloak>
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
                <h3 class="mb-2 text-lg font-bold text-gray-900 dark:text-gray-100">
                    {{ __('care-plan.referral_complete_title') }}
                </h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('referrals.messages.completion_record_hint') }}
                </p>
            </div>

            <div class="form-group mb-4">
                <label
                    for="referralCompletionType"
                    class="label"
                >{{ __('care-plan.referral_complete_emz_type') }}</label>
                <select id="referralCompletionType" wire:model.live="referralCompletionType" class="input">
                    @foreach ($referralCompletionTypes as $type)
                        <option value="{{ $type }}">{{ __('care-plan.emz_type.'.$type) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group mb-4">
                <label
                    for="referralCompletionUuid"
                    class="label"
                >{{ __('care-plan.referral_complete_emz_select') }}</label>
                <select id="referralCompletionUuid" wire:model="referralCompletionUuid" class="input">
                    <option value="">{{ __('forms.select') }}</option>
                    @foreach ($this->referralCompletionResources as $resource)
                        <option value="{{ $resource['uuid'] }}">{{ $resource['label'] }}</option>
                    @endforeach
                </select>
                @if ($this->referralCompletionResources === [])
                    <p class="text-sm text-amber-700 dark:text-amber-300">
                        {{ __('referrals.messages.completion_record_empty', ['type' => __('care-plan.emz_type.'.$referralCompletionType)]) }}
                    </p>
                @endif
            </div>

            @if ($completionRemainingQuantity > 0)
                <p class="mb-3 text-sm text-amber-700 dark:text-amber-300">
                    {{ __('referrals.remaining_quantity_warning') }}
                </p>
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" wire:model="acknowledgeRemainingQuantity" />
                    <span>{{ __('referrals.remaining_quantity_confirm') }}</span>
                </label>
            @endif
            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button type="button" @click="show = false" class="btn-secondary w-full sm:w-auto">
                    {{ __('forms.close') }}
                </button>
                <button
                    type="button"
                    wire:click="redeemReferral"
                    wire:loading.attr="disabled"
                    @disabled($this->referralCompletionResources === [])
                    class="btn-primary w-full sm:w-auto"
                >
                    {{ __('encounters.redeem_referral') }}
                </button>
            </div>
        </div>
    </div>
</div>
