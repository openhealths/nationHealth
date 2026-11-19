<div x-data="{ copied: false, failed: false, copiedTimeout: null }">
    <div class="record-inner-label">{{ $identifierLabel }}</div>
    @if (filled($identifierValue))
        <div class="flex flex-wrap items-center gap-2">
            <span class="record-inner-value select-all break-all">{{ $identifierValue }}</span>
            <button
                type="button"
                class="text-link inline-flex shrink-0 items-center justify-center rounded p-1"
                aria-label="{{ __('referrals.registry.copy_identifier') }}"
                :title="copied ? @js(__('referrals.registry.identifier_copied')) : @js(__('referrals.registry.copy_identifier'))"
                @click="
                    clearTimeout(copiedTimeout);
                    copied = false;
                    failed = false;
                    if (! navigator.clipboard) {
                        failed = true;
                        return;
                    }
                    navigator.clipboard.writeText(@js($identifierValue))
                        .then(() => {
                            copied = true;
                            clearTimeout(copiedTimeout);
                            copiedTimeout = setTimeout(() => copied = false, 2000);
                        })
                        .catch(() => failed = true);
                "
            >
                <span x-show="! copied" aria-hidden="true">
                    @icon('copy', 'w-4 h-4')
                </span>
                <span x-show="copied" x-cloak aria-hidden="true">
                    @icon('check', 'w-4 h-4')
                </span>
            </button>
        </div>
        <span role="status" class="sr-only" x-text="copied ? @js(__('referrals.registry.identifier_copied')) : ''"></span>
        <span x-show="failed" x-cloak role="status" class="text-sm text-red-600">{{ __('referrals.registry.copy_identifier_failed') }}</span>
    @else
        <div class="record-inner-value">{{ __('referrals.registry.number_pending') }}</div>
    @endif
</div>
