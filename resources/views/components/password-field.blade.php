@props(['class' => ''])

<div {{ $attributes->class(['password-field relative', $class]) }}>
    {{ $slot }}

    <button
        type="button"
        x-data="{
            visible: false,
            disabled: false,
            observer: null,
            init() {
                const input = this.$el.parentElement.querySelector('input');
                const sync = () => {
                    this.visible = input.type === 'text';
                    this.disabled = input.disabled;
                };
                sync();
                this.observer = new MutationObserver(sync);
                this.observer.observe(input, { attributes: true, attributeFilter: ['type', 'disabled'] });
            },
            destroy() {
                this.observer?.disconnect();
            },
            toggle() {
                const input = this.$el.parentElement.querySelector('input');
                const start = input.selectionStart;
                const end = input.selectionEnd;
                input.type = input.type === 'password' ? 'text' : 'password';
                this.visible = input.type === 'text';
                input.focus({ preventScroll: true });
                input.setSelectionRange(start, end);
            }
        }"
        @click="toggle()"
        :disabled="disabled"
        :aria-pressed="visible"
        :aria-label="visible ? @js(__('forms.hide_password')) : @js(__('forms.show_password'))"
        aria-label="{{ __('forms.show_password') }}"
        aria-pressed="false"
        x-cloak
        class="absolute top-0 right-0 z-20 flex h-10 w-10 cursor-pointer items-center justify-center rounded text-gray-500 hover:text-blue-600 focus-visible:outline-2 focus-visible:outline-blue-600 disabled:cursor-not-allowed disabled:opacity-50 dark:text-gray-400 dark:hover:text-blue-400"
    >
        <span x-show="!visible">@icon('eye', 'h-5 w-5')</span>
        <span x-show="visible" x-cloak>@icon('eye-off', 'h-5 w-5')</span>
    </button>
</div>
