@props(['visible', 'clear'])

<button
    type="button"
    class="dropdown-clear"
    x-cloak
    x-show="{{ $visible }}"
    @mousedown.prevent
    @click.stop="
        if (! $refs.trigger.matches(':disabled') && ! $refs.trigger.readOnly) {
            $refs.trigger.focus();
            {{ $clear }}
        }
    "
    aria-label="{{ __('forms.clear_selection') }}"
    title="{{ __('forms.clear_selection') }}"
>
    <svg
        aria-hidden="true"
        width="16"
        height="16"
        viewBox="0 0 20 20"
        fill="none"
        stroke="currentColor"
        stroke-width="1.6"
    >
        <path
            d="m6 6 8 8m0-8-8 8"
            stroke-linecap="round"
        />
    </svg>
</button>
<svg
    class="dropdown-chevron"
    aria-hidden="true"
    width="16"
    height="16"
    viewBox="0 0 20 20"
    fill="none"
    stroke="currentColor"
    stroke-width="1.6"
>
    <path
        d="m5.5 8 4.5 4.5L14.5 8"
        stroke-linecap="round"
        stroke-linejoin="round"
    />
</svg>
