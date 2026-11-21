<div
    x-dialog
    x-cloak
    class="fixed left-0 right-0 bottom-0 {{ $topClass }} overflow-hidden {{ $backdropClickThrough ? 'pointer-events-none' : '' }}"
    style="z-index: {{ $zVal }};"
    @if ($stopClickPropagation)
        {{-- Shield sibling dialogs, including the area outside the backdrop. --}}
        @click.stop="if ($event.target === $el) $dialog.close();"
        @mousedown.stop
        @mouseup.stop
        @pointerdown.stop
        @keydown.escape.stop
    @endif
    {{ $attributes }}
>
    @if ($header !== null)
        <div
            data-dialog-surface
            aria-hidden="true"
            class="fixed top-0 bottom-0 right-0 {{ $resolvedWidth }} bg-white dark:bg-gray-800 pointer-events-none"
            style="z-index: {{ $pZVal }};"
        ></div>
        <div
            data-dialog-header
            class="relative"
            style="z-index: {{ $pZVal + 1 }};"
            @click.stop
            x-data="{
                observer: null,
                init() {
                    const header = this.$el.querySelector('[data-app-header]');
                    if (!header) return;
                    const resize = () => this.$el.closest('[x-dialog]').style.setProperty('--drawer-header-height', header.getBoundingClientRect().bottom + 'px');
                    resize();
                    this.observer = new ResizeObserver(resize);
                    this.observer.observe(header, { box: 'border-box' });
                },
                destroy() { this.observer?.disconnect(); },
            }"
        >
            {{ $header }}
        </div>
    @endif
    <!-- Overlay backdrop -->
    @if (!$noBackdrop)
        <div
            x-dialog:overlay
            x-transition.opacity
            class="fixed {{ $topClass }} bottom-0 right-0 bg-gray-900/40 {{ $backdropClickThrough ? 'pointer-events-none' : '' }}"
            style="width: {{ $overlayWidth ?? '80%' }};"
            @if ($onCloseClick) @click="{{ $onCloseClick }}" @endif
        ></div>
    @endif

    <!-- Panel Container -->
    <div
        class="fixed {{ $topClass }} bottom-0 right-0 flex max-lg:max-w-full max-lg:min-w-0 {{ $resolvedWidth }} pointer-events-auto"
        style="z-index: {{ $pZVal }}; {{ $header !== null ? 'top: max(0px, calc(var(--drawer-header-height, var(--header-height, 64px)) - 1px));' : '' }}"
    >
        <div
            x-dialog:panel
            x-transition:enter="transition ease-out duration-300 transform"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-300 transform"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="h-full w-full"
        >
            <div class="responsive-content h-full max-lg:min-w-0 flex flex-col bg-white dark:bg-gray-800 shadow-xl overflow-y-auto {{ $header !== null || $topClass !== 'top-0' ? 'p-4 sm:p-8' : 'p-4 pt-20 sm:p-8 sm:pt-20' }} relative">
                <!-- Header -->
                @isset($title)
                    <h3 class="modal-header mb-6 !border-b-0 pb-0" x-dialog:title>{{ $title }}</h3>
                @endisset

                <!-- Slot Content -->
                <div class="flex-1 max-lg:min-w-0">{{ $slot }}</div>
            </div>
        </div>
    </div>
</div>
