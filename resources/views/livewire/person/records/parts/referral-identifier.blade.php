<div x-data="{ copied: false, failed: false }">
    <div class="record-inner-label">{{ $identifierLabel }}</div>
    @if (filled($identifierValue))
        <div class="flex flex-wrap items-center gap-2">
            <span class="record-inner-value select-all break-all">{{ $identifierValue }}</span>
            <button
                type="button"
                class="text-link text-sm"
                @click="
                    copied = false;
                    failed = false;
                    if (! navigator.clipboard) {
                        failed = true;
                        return;
                    }
                    navigator.clipboard.writeText(@js($identifierValue))
                        .then(() => copied = true)
                        .catch(() => failed = true);
                "
            >
                <span x-show="! copied">{{ __('referrals.registry.copy_identifier') }}</span>
                <span x-show="copied" x-cloak>{{ __('referrals.registry.identifier_copied') }}</span>
            </button>
        </div>
        <span x-show="failed" x-cloak role="status" class="text-sm text-red-600">{{ __('referrals.registry.copy_identifier_failed') }}</span>
    @else
        <div class="record-inner-value">{{ __('referrals.registry.number_pending') }}</div>
    @endif
</div>
