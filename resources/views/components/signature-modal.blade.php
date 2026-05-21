@props(['method'])

<template x-teleport="body">
    <div x-data="{ showSignatureModal: $wire.entangle('showSignatureModal') }"
         x-show="showSignatureModal"
         x-cloak
         role="dialog"
         aria-modal="true"
         class="modal"
         @keydown.escape.prevent.stop="showSignatureModal = false"
    >
        <div x-transition.opacity class="fixed inset-0 bg-black/30" @click="showSignatureModal = false"></div>
        <div class="modal-wrapper">
            <div class="modal-content w-full max-w-4xl mx-auto"
                 @click.stop
                 x-transition
                 x-trap.noscroll.inert="showSignatureModal"
            >
                {{-- Title --}}
                <h3 class="modal-header">{{ __('forms.sign_with_KEP') }}</h3>

                {{-- Content --}}
                <div class="p-6">
                    <form>
                        <div class="flex flex-col gap-6">
                            @if(method_exists($this, 'getStatusReasonsProperty') && isset($this->actionType) && in_array($this->actionType, ['cancel', 'revoke', 'complete', 'cancel_activity', 'complete_activity']))
                                <div>
                                    <label for="statusReason" class="default-label">{{ __('care-plan.status_reason') }} *</label>
                                    <select class="input-modal" wire:model="statusReason" name="statusReason" id="statusReason">
                                        <option value="" selected>{{__('forms.select')}}</option>
                                        @foreach($this->statusReasons as $code => $description)
                                            <option value="{{ $code }}" wire:key="reason-{{ $code }}">
                                                {{ $description }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('statusReason') <p class="text-error">{{ $message }}</p> @enderror
                                </div>
                            @endif

                            {{-- KEP Provider --}}
                            <div>
                                <label for="knedp" class="default-label">{{ __('forms.knedp') }} *</label>
                                <select class="input-modal" wire:model="form.knedp" name="knedp" id="knedp">
                                    <option value="" selected>{{__('forms.select')}}</option>
                                    @foreach(signatureService()->getCertificateAuthorities() as $certificateType)
                                        <option value="{{ $certificateType['id'] }}"
                                                wire:key="{{ $certificateType['id'] }}"
                                        >
                                            {{ $certificateType['name'] }}
                                        </option>
                                    @endforeach
                                </select>

                                @error('form.knedp') <p class="text-error">{{ $message }}</p> @enderror
                            </div>

                            {{-- Key File --}}
                            <div>
                                <label for="keyContainerUpload" class="default-label">
                                    {{ __('forms.key_container_upload') }} *
                                </label>
                                <input type="file"
                                       wire:model="form.keyContainerUpload"
                                       class="default-input cursor-pointer"
                                       id="keyContainerUpload"
                                       name="keyContainerUpload"
                                       accept=".dat,.pfx,.pk8,.zs2,.jks,.p7s"
                                >
                                <div wire:loading
                                     wire:target="form.keyContainerUpload"
                                     class="text-sm text-gray-500 mt-2"
                                >
                                    {{ __('general.loading') }}...
                                </div>

                                @error('form.keyContainerUpload') <p class="text-error">{{ $message }}</p> @enderror
                            </div>

                            {{-- Password --}}
                            <div>
                                <label for="password" class="default-label">{{ __('forms.password') }} *</label>
                                <input type="password"
                                       wire:model="form.password"
                                       class="default-input"
                                       id="password"
                                       name="password"
                                       autocomplete="current-password"
                                />

                                @error('form.password') <p class="text-error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </form>
                </div>

                <div class="modal-footer">
                    <button type="button" @click="showSignatureModal = false" class="button-minor">
                        {{ __('forms.cancel') }}
                    </button>
                    <button wire:click="{{ $method }}"
                            type="button"
                            class="button-primary"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50 cursor-not-allowed"
                            wire:target="{{ $method }}"
                    >
                        <span wire:loading.remove wire:target="{{ $method }}">{{ __('forms.sign') }}</span>
                        <span wire:loading wire:target="{{ $method }}">{{ __('forms.signature') }}...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
