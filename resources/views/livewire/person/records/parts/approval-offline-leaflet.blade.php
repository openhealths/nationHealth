<div class="space-y-3 rounded-xl border p-4">
    <p class="text-sm">
        {{ __('approval.create.recommend_otp') }}
    </p>

    <p class="text-sm">
        {{ __('approval.create.offline_leaflet_notice') }}
    </p>

    <a
        href="https://ehealth.gov.ua/privacy_access.html"
        target="_blank"
        rel="noopener noreferrer"
        class="text-blue-600 text-sm underline"
    >
        {{ __('approval.create.open_leaflet') }}
    </a>

    <label class="flex cursor-pointer items-center gap-2 text-sm">
        <input
            type="checkbox"
            wire:model="leafletConfirmed"
            class="default-checkbox h-5 w-5 shrink-0 cursor-pointer"
        >
        {{ __('approval.create.leaflet_confirmed') }}
    </label>

    @error('leafletConfirmed')
        <p class="text-error">{{ $message }}</p>
    @enderror
</div>
