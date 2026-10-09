{{-- Print form returned by eHealth, shown verbatim (TV 3.8.1.1.5.1 / 3.8.2.8.3.1). --}}
<x-dialog-modal maxWidth="3xl" :id="$modalId" wire:model.live="showPrintModal">
    <x-slot name="title">{{ __('compositions.print.title') }}</x-slot>

    <x-slot name="content">
        <iframe
            id="{{ $iframeId }}"
            title="{{ __('compositions.print.title') }}"
            srcdoc="{{ $printFormHtml }}"
            class="block w-full border-0 bg-white"
            style="min-height: 600px; color-scheme: only light"
            sandbox="allow-same-origin"
        ></iframe>
    </x-slot>

    <x-slot name="footer">
        <button
            type="button"
            onclick="document.getElementById('{{ $iframeId }}').contentWindow.print()"
            @isset($printButtonId) id="{{ $printButtonId }}" @endisset
            class="button-primary px-5 py-2 text-sm"
        >
            {{ __('compositions.actions.print') }}
        </button>
        <button type="button" wire:click="closePrintModal" class="button-primary-outline ml-2 px-5 py-2 text-sm">
            {{ __('forms.close') }}
        </button>
    </x-slot>
</x-dialog-modal>
