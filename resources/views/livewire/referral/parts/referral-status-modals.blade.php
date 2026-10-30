<x-modal id="referral-recall-modal" wire:model="showRecallModal" maxWidth="3xl">
    <form
        @submit.prevent
        wire:key="referral-recall-form-{{ $pendingStatusReferral }}-{{ $pendingStatusAction }}"
        role="dialog"
        aria-modal="true"
        aria-labelledby="referral-recall-title"
        class="bg-white p-6 text-left md:p-8 dark:bg-gray-800"
    >
        <div class="max-w-3xl">
            <h2 id="referral-recall-title" class="mb-6 text-lg font-bold text-gray-900 dark:text-white">
                {{ __('referrals.modals.cancel.title') }}
            </h2>
            <p class="mb-8 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                {{ __('referrals.modals.cancel.warning') }}
            </p>
            <label for="referralRecallExplanation" class="label-modal">
                {{ __('referrals.modals.cancel.reason_label') }}
            </label>
            <textarea
                id="referralRecallExplanation"
                wire:model="statusExplanatoryLetter"
                rows="5"
                class="input-modal"
                placeholder="{{ __('forms.write_comment_here') }}"
                required
            ></textarea>
            @error('statusExplanatoryLetter')
                <p class="text-error mt-1">{{ $message }}</p>
            @enderror
            <div class="mt-8 flex flex-wrap items-center justify-start gap-4">
                <button type="button" wire:click="cancelStatusChange" class="button-minor">
                    {{ __('forms.cancel') }}
                </button>
                <button type="button" disabled class="button-danger disabled:cursor-not-allowed disabled:opacity-50">
                    {{ __('referrals.modals.cancel.btn_confirm') }}
                </button>
            </div>
        </div>
    </form>
</x-modal>

<x-modal id="referral-error-modal" wire:model="showErrorModal" maxWidth="3xl">
    <form
        @submit.prevent
        wire:key="referral-error-form-{{ $pendingStatusReferral }}-{{ $pendingStatusAction }}"
        role="dialog"
        aria-modal="true"
        aria-labelledby="referral-error-title"
        class="bg-white p-6 text-left md:p-8 dark:bg-gray-800"
    >
        <div class="max-w-3xl">
            <h2 id="referral-error-title" class="mb-6 text-lg font-bold text-gray-900 dark:text-white">
                {!! __('referrals.modals.error.title') !!}
            </h2>
            <p class="mb-8 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                {!! __('referrals.modals.error.warning') !!}
            </p>
            <div class="form-group group mb-6">
                <select id="referralErrorReason" wire:model="errorReason" class="input-select peer w-full" required>
                    <option value="">{{ __('referrals.modals.error.dropdown_placeholder') }}</option>
                    <option value="entered_in_error">{{ __('referrals.modals.error.entered_in_error') }}</option>
                    <option value="other">{{ __('referrals.modals.error.other') }}</option>
                </select>
                <label for="referralErrorReason" class="label">
                    {{ __('referrals.modals.error.reason_dropdown_label') }}
                </label>
                @error('errorReason')
                    <p class="text-error mt-1">{{ $message }}</p>
                @enderror
            </div>
            <label for="referralErrorExplanation" class="label-modal">
                {{ __('referrals.modals.error.reason_label') }}
            </label>
            <textarea
                id="referralErrorExplanation"
                wire:model="statusExplanatoryLetter"
                rows="5"
                class="input-modal"
                placeholder="{{ __('forms.write_comment_here') }}"
                required
            ></textarea>
            @error('statusExplanatoryLetter')
                <p class="text-error mt-1">{{ $message }}</p>
            @enderror
            <div class="mt-8 flex flex-wrap items-center justify-start gap-4">
                <button type="button" wire:click="cancelStatusChange" class="button-minor">
                    {{ __('forms.cancel') }}
                </button>
                <button type="button" disabled class="button-danger disabled:cursor-not-allowed disabled:opacity-50">
                    {{ __('referrals.modals.error.btn_confirm') }}
                </button>
            </div>
        </div>
    </form>
</x-modal>
