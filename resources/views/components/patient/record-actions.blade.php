@props(['href' => null])

<div
    x-data="{
        open: false,

        toggle() {
            if (this.open) {
                return this.close();
            }

            this.$refs.button.focus();
            this.open = true;
        },

        close(focusAfter) {
            if (!this.open) return;

            this.open = false;
            focusAfter && focusAfter.focus();
        }
    }"
    @keydown.escape.prevent.stop="close($refs.button)"
    @focusin.window="!$el.contains($event.target) && close()"
    x-id="['record-actions']"
    class="relative flex justify-center"
>
    <button
        type="button"
        @click="toggle()"
        x-ref="button"
        :aria-expanded="open"
        :aria-controls="$id('record-actions')"
        aria-label="{{ __('forms.actions') }}"
        class="record-inner-action-btn cursor-pointer"
    >
        @icon('edit-user-outline', 'w-5 h-5')
    </button>

    <div
        x-show="open"
        x-cloak
        x-ref="panel"
        x-transition.origin.top.right
        @click.outside="close($refs.button)"
        :id="$id('record-actions')"
        class="absolute right-0 top-full z-50 mt-2 w-56 rounded-md border border-gray-200 bg-white py-1 shadow-md dark:border-gray-600 dark:bg-gray-700"
    >
        @if ($href)
            <a
                href="{{ $href }}"
                wire:navigate
                @click="close()"
                class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-gray-600 transition-colors hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-600"
            >
                @icon('eye', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                {{ __('forms.view_details') }}
            </a>
        @endif

        {{ $slot }}
    </div>
</div>
