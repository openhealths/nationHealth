@props(['method', 'onlyActions' => [], 'exceptActions' => [], 'agreementText' => null])

@php
    $actionType = $this->actionType ?? null;
    $shouldRender = ($onlyActions === [] || in_array($actionType, $onlyActions, true)) && ($exceptActions === [] || !in_array($actionType, $exceptActions, true));
@endphp

@if ($shouldRender)
    <template x-teleport="body">
        <div
            x-data="{ showSignatureModal: $wire.entangle('showSignatureModal') }"
            x-show="showSignatureModal"
            x-cloak
            role="dialog"
            aria-modal="true"
            class="modal"
            @keydown.escape.prevent.stop="showSignatureModal = false"
        >
            <div x-transition.opacity class="fixed inset-0 bg-black/30" @click="showSignatureModal = false"></div>
            <div class="modal-wrapper">
                <div
                    class="modal-content mx-auto w-full max-w-4xl"
                    @click.stop
                    x-transition
                    x-trap.noscroll.inert="showSignatureModal"
                >
                    {{-- Title --}}
                    <h3 class="modal-header">@yield('title', __('forms.sign_with_KEP'))</h3>

                    {{-- Content --}}
                    <div class="p-6">
                        <form wire:submit="{{ $method }}">
                            <div class="flex flex-col gap-6">
                                {{-- Care-plan cancel/complete still @extends this layout and fill @section('custom-fields'). --}}
                                {{-- Encounter/contract flows pass a $customFields slot instead. Keep both. --}}
                                @hasSection('custom-fields')
                                @yield('custom-fields')
                                @elseif (isset($customFields))
                                {{ $customFields }}
                                @endif

                                @if (!empty($agreementText))
                                <div class="show-alert-warning">
                                    <p class="text-sm font-medium">{{ $agreementText }}</p>
                                </div>
                                @endif

                                <x-signature-fields />
                            </div>

                            <div class="mt-6 flex flex-row items-center gap-4 border-t border-gray-200 pt-6">
                                <button type="button" @click="showSignatureModal = false" class="button-minor">
                                    {{ __('forms.cancel') }}
                                </button>
                                <button
                                    type="submit"
                                    class="button-primary"
                                    wire:loading.attr="disabled"
                                    wire:loading.class="opacity-50 cursor-not-allowed"
                                    wire:target="{{ $method }}"
                                >
                                    <span wire:loading.remove wire:target="{{ $method }}">{{ __('forms.sign') }}</span>
                                    <span wire:loading wire:target="{{ $method }}">{{ __('forms.signature') }}...</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </template>
@endif
