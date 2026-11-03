@props(['method', 'onlyActions' => [], 'exceptActions' => [], 'agreementText' => null])

@php
    $actionType = $this->actionType ?? null;
    $shouldRender = ($onlyActions === [] || in_array($actionType, $onlyActions, true)) && ($exceptActions === [] || !in_array($actionType, $exceptActions, true));
@endphp

@if ($shouldRender)
    <template x-teleport="body">
        <div
            x-data="{
                showSignatureModal: $wire.entangle('showSignatureModal'),
                fileName: '{{ __('forms.no_file_chosen') }}',
                noFileLabel: '{{ __('forms.no_file_chosen') }}',
                // Keep the visible filename across Livewire redraws (e.g. opening drawers
                // while the KEP modal stays open). Native <input type=file> cannot be restored.
                displayFileName() {
                    const stored = $wire.form?.keyContainerFileName;
                    if (stored) {
                        return stored;
                    }
                    if (this.fileName && this.fileName !== this.noFileLabel && ! String(this.fileName).startsWith('livewire-file:')) {
                        return this.fileName;
                    }
                    return this.noFileLabel;
                },
                setFileNameFromInput(event) {
                    const file = event.target.files?.[0];
                    if (file) {
                        this.fileName = file.name;
                        $wire.set('form.keyContainerFileName', file.name);
                    } else {
                        this.fileName = this.noFileLabel;
                        $wire.set('form.keyContainerFileName', '');
                    }
                },
                syncFileNameFromWire() {
                    const stored = $wire.form?.keyContainerFileName;
                    if (stored) {
                        this.fileName = stored;
                        return;
                    }
                    if ($wire.form?.keyContainerUpload) {
                        return;
                    }
                    this.fileName = this.noFileLabel;
                },
            }"
            x-effect="
                if (! showSignatureModal) {
                    if ($refs.keyContainerUpload) $refs.keyContainerUpload.value = '';
                    fileName = noFileLabel;
                } else {
                    syncFileNameFromWire();
                }
            "
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
                        <form>
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

                                {{-- KEP Provider --}}
                                <div>
                                    <label for="knedp" class="default-label">{{ __('forms.knedp') }} *</label>
                                    {{-- Deferred: avoid a Livewire round-trip on every KNEPD change before sign --}}
                                    <select class="input-modal" wire:model="form.knedp" name="knedp" id="knedp">
                                        <option value="" selected>{{ __('forms.select') }}</option>
                                        @foreach (signatureService()->getCertificateAuthorities() as $certificateType)
                                        <option
                                            value="{{ $certificateType['id'] }}"
                                            wire:key="{{ $certificateType['id'] }}"
                                        >
                                            {{ $certificateType['name'] }}
                                        </option>
                                        @endforeach
                                    </select>

                                    @error('form.knedp')
                                    <p class="text-error">{{ $message }}</p>
                                    @enderror
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
                                        <input
                                            type="file"
                                            wire:model="form.keyContainerUpload"
                                            class="hidden"
                                            id="keyContainerUpload"
                                            name="keyContainerUpload"
                                            x-ref="keyContainerUpload"
                                            accept=".dat,.pfx,.pk8,.zs2,.jks,.p7s"
                                            @change="setFileNameFromInput($event)"
                                            x-on:livewire-upload-finish="
                                                if ($wire.form?.keyContainerFileName) {
                                                    fileName = $wire.form.keyContainerFileName;
                                                }
                                            "
                                        />
                                    </div>
                                    <div
                                        wire:loading
                                        wire:target="form.keyContainerUpload"
                                        class="mt-2 text-sm text-gray-500"
                                    >
                                        {{ __('general.loading') }}...
                                    </div>

                                    @error('form.keyContainerUpload')
                                    <p class="text-error">{{ $message }}</p>
                                    @enderror
                                </div>

                                {{-- Password --}}
                                <div>
                                    <label for="password" class="default-label">{{ __('forms.password') }} *</label>
                                    <x-password-field>
                                        <input
                                            type="password"
                                            wire:model="form.password"
                                            class="default-input"
                                            id="password"
                                            name="password"
                                            autocomplete="current-password"
                                        />
                                    </x-password-field>

                                    @error('form.password')
                                    <p class="text-error">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                        </form>

                        <div class="mt-6 flex flex-row items-center gap-4 border-t border-gray-200 pt-6">
                            <button type="button" @click="showSignatureModal = false" class="button-minor">
                                {{ __('forms.cancel') }}
                            </button>
                            <button
                                wire:click="{{ $method }}"
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
@endif
