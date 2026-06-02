<div
    x-dialog
    x-cloak
    class="fixed left-0 right-0 bottom-0 {{ $topClass }} overflow-hidden {{ $backdropClickThrough ? 'pointer-events-none' : '' }}"
    style="z-index: {{ $zVal }};"
    {{ $attributes }}
>
    <!-- Overlay backdrop -->
    @if(!$noBackdrop)
        <div x-dialog:overlay 
             x-transition.opacity 
             class="fixed {{ $topClass }} bottom-0 right-0 bg-gray-900/40 {{ $backdropClickThrough ? 'pointer-events-none' : '' }}"
             style="width: {{ $overlayWidth ?? '80%' }};"
             @if($onCloseClick) @click="{{ $onCloseClick }}" @endif
        ></div>
    @endif

    <!-- Panel Container -->
    <div class="fixed {{ $topClass }} bottom-0 right-0 flex {{ $resolvedWidth }} pointer-events-auto"
         style="z-index: {{ $pZVal }};"
         @if($stopClickPropagation)
             @click.stop
             @mousedown.stop
             @mouseup.stop
             @pointerdown.stop
         @endif
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
            <div class="h-full flex flex-col bg-white dark:bg-gray-800 shadow-xl overflow-y-auto {{ $topClass !== 'top-0' ? 'p-8' : 'pt-20 p-8' }} relative">
                <!-- Header -->
                @isset($title)
                    <h3 class="modal-header !border-b-0 pb-0 mb-6" x-dialog:title>
                        {{ $title }}
                    </h3>
                @endisset

                <!-- Slot Content -->
                <div class="flex-1">
                    {{ $slot }}
                </div>
            </div>
        </div>
    </div>
</div>
