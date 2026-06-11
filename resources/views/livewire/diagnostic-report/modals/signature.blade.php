<div>
    <template x-teleport="body">
        <div x-show="showSignatureModal"
             style="display: none"
             @keydown.escape.prevent.stop="showSignatureModal = false"
             role="dialog"
             aria-modal="true"
             class="modal"
        >
            <div x-transition.opacity class="fixed inset-0 bg-black/30"></div>
            <div x-transition @click="showSignatureModal = false" class="modal-wrapper">
                <div @click.stop x-trap.noscroll.inert="showSignatureModal"
                     class="modal-content w-full max-w-4xl mx-auto"
                >
                    {{-- Title --}}
                    <h3 class="modal-header">{{ __('forms.sign_with_KEP') }}</h3>

                    {{-- Content --}}
                    <div class="p-6">
                        {{-- Error display inside the modal --}}
                        @if (session()->has('error-modal'))
                            <div class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50" role="alert">
                                <span class="font-medium">{{ __('forms.error') }}!</span> {{ session('error-modal') }}
                            </div>
                        @endif

                        <div class="flex flex-col gap-6">
                            {{-- KEP Provider --}}
                            <x-forms.form-group>
                                <x-slot name="label">
                                    <x-forms.label class="default-label">{{ __('forms.knedp') }} *</x-forms.label>
                                </x-slot>
                                <x-slot name="input">
                                    <x-forms.select class="default-input" wire:model="form.knedp" id="knedp">
                                        <x-slot name="option">
                                            <option value="">{{__('forms.select')}}</option>
                                            @foreach(signatureService()->getCertificateAuthorities() as $certificateType)
                                                <option value="{{ $certificateType['id'] }}"
                                                        wire:key="{{ $certificateType['id'] }}"
                                                >
                                                    {{ $certificateType['name'] }}
                                                </option>
                                            @endforeach
                                        </x-slot>
                                    </x-forms.select>
                                </x-slot>

                                @error('form.knedp')
                                <x-forms.error>{{ $message }}</x-forms.error>@enderror
                            </x-forms.form-group>

                            {{-- Key File --}}
                            <x-forms.form-group>
                                <x-slot name="label">
                                    <x-forms.label class="default-label">{{ __('forms.key_container_upload') }} *
                                    </x-forms.label>
                                </x-slot>
                                <x-slot name="input">
                                    <div x-data="{ fileName: '{{ __('forms.no_file_chosen') }}' }" 
                                         x-effect="if (!showSignatureModal) { fileName = '{{ __('forms.no_file_chosen') }}'; if ($refs.keyContainerUpload) $refs.keyContainerUpload.value = ''; }"
                                         class="file-input-wrapper"
                                    >
                                        <label for="keyContainerUpload" class="file-input-button">
                                            {{ __('forms.choose_file') }}
                                        </label>
                                        <span class="file-input-text" x-text="fileName"></span>
                                        <input class="hidden"
                                               wire:model="form.keyContainerUpload"
                                               type="file"
                                               x-ref="keyContainerUpload"
                                               id="keyContainerUpload"
                                               accept=".dat,.pfx,.pk8,.zs2,.jks,.p7s"
                                               @change="fileName = $event.target.files[0] ? $event.target.files[0].name : '{{ __('forms.no_file_chosen') }}'"
                                        />
                                    </div>
                                    <div wire:loading wire:target="form.keyContainerUpload"
                                         class="text-sm text-gray-500 mt-2">Uploading...
                                    </div>
                                </x-slot>

                                @error('form.keyContainerUpload')
                                <x-forms.error>{{ $message }}</x-forms.error>@enderror
                            </x-forms.form-group>

                            {{-- Password --}}
                            <x-forms.form-group>
                                <x-slot name="label">
                                    <x-forms.label class="default-label">{{ __('forms.password') }} *</x-forms.label>
                                </x-slot>
                                <x-slot name="input">
                                    <x-forms.input class="default-input"
                                                   wire:model.defer="form.password"
                                                   type="password"
                                                   id="password"
                                    />
                                </x-slot>

                                @error('form.password')
                                <x-forms.error>{{ $message }}</x-forms.error>@enderror
                            </x-forms.form-group>
                        </div>

                        <div class="mt-6 flex flex-row items-center gap-4 border-t border-gray-200 pt-6">
                            <button type="button" @click="showSignatureModal = false" class="button-minor">
                                {{__('forms.cancel')}}
                            </button>

                            <button @click.prevent="$wire.sign(modalDiagnosticReport)"
                                    type="button"
                                    class="button-primary"
                                    wire:loading.attr="disabled"
                                    wire:target="sign"
                            >
                                <span wire:loading.remove wire:target="sign">{{ __('forms.sign') }}</span>
                                <span wire:loading wire:target="sign">{{ __('general.loading') }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
