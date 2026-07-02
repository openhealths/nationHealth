@props(['method'])

<template x-teleport="body">
    <div x-data="{
            showSignatureModal: $wire.entangle('showSignatureModal'),
            fileName: '{{ __('forms.no_file_chosen') }}',
            displayFileName() {
                const stored = $wire.form?.keyContainerFileName;
                if (stored) {
                    return stored;
                }
                if (this.fileName && !String(this.fileName).startsWith('livewire-file:')) {
                    return this.fileName;
                }
                return '{{ __('forms.no_file_chosen') }}';
            },
            setFileNameFromInput(event) {
                const file = event.target.files?.[0];
                if (file) {
                    this.fileName = file.name;
                    $wire.set('form.keyContainerFileName', file.name);
                } else {
                    this.fileName = '{{ __('forms.no_file_chosen') }}';
                    $wire.set('form.keyContainerFileName', '');
                }
            },
            syncFileNameFromWire() {
                const stored = $wire.form?.keyContainerFileName;
                if (stored) {
                    this.fileName = stored;
                    return;
                }
                const upload = $wire.form?.keyContainerUpload;
                if (!upload) {
                    this.fileName = '{{ __('forms.no_file_chosen') }}';
                    return;
                }
                if (typeof upload === 'string') {
                    if (upload.startsWith('livewire-file:')) {
                        return;
                    }
                    this.fileName = upload.split('/').pop() || this.fileName;
                    return;
                }
                if (upload?.name && !String(upload.name).startsWith('livewire-file:')) {
                    this.fileName = upload.name;
                }
            },
         }"
         x-effect="if (!showSignatureModal) { if ($refs.keyContainerUpload) $refs.keyContainerUpload.value = ''; } else { syncFileNameFromWire(); }"
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
                <h3 class="modal-header">@yield('title', __('forms.sign_with_KEP'))</h3>

                {{-- Content --}}
                <div class="p-6">
                    <form onsubmit="return false;">
                        <div class="flex flex-col gap-6">
                            @hasSection('custom-fields')
                                @yield('custom-fields')
                            @elseif(isset($customFields))
                                {{ $customFields }}
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
                                <div class="file-input-wrapper">
                                    <label for="keyContainerUpload" class="file-input-button">
                                        {{ __('forms.choose_file') }}
                                    </label>
                                    <span class="file-input-text" x-text="displayFileName()"></span>
                                    <input type="file"
                                           wire:model="form.keyContainerUpload"
                                           class="hidden"
                                           id="keyContainerUpload"
                                           name="keyContainerUpload"
                                           x-ref="keyContainerUpload"
                                           accept=".dat,.pfx,.pk8,.zs2,.jks,.p7s"
                                           @change="setFileNameFromInput($event)"
                                           x-on:livewire-upload-finish="if ($wire.form?.keyContainerFileName) { fileName = $wire.form.keyContainerFileName; }"
                                    >
                                </div>
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

                    <div class="mt-6 flex flex-row items-center gap-4 border-t border-gray-200 pt-6">
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
    </div>
</template>
