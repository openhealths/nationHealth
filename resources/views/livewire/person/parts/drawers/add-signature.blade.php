{{-- Signature Drawer Overlay --}}
<div x-show="showSignatureDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     x-cloak
     @click="showSignatureDrawer = false"
     class="fixed top-16 left-0 right-0 bottom-0 bg-gray-900/70"
     style="z-index: 55;"
></div>

{{-- Signature Drawer --}}
<div x-show="showSignatureDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="translate-x-full"
     x-transition:enter-end="translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="translate-x-0"
     x-transition:leave-end="translate-x-full"
     x-cloak
     @click.stop
     class="fixed top-16 right-0 h-[calc(100vh-4rem)] bg-white dark:bg-gray-800 shadow-2xl"
     style="z-index: 60; width: calc(80% - 100px);"
     id="signature-drawer"
     tabindex="-1"
     x-data="{ fileUploaded: false, fileName: '' }"
>
    <div class="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-6 py-4">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">
            {{ __('forms.sign_with_KEP') }}
        </h2>
    </div>

    <div class="overflow-y-auto p-6 bg-white dark:bg-gray-800" style="height: calc(100% - 70px);">
        <div class="flex flex-col gap-6">
            {{-- KEP Provider --}}
            <div>
                <label for="drawerKnedp" class="default-label">{{ __('forms.knedp') }} *</label>
                <select class="input-modal w-full" wire:model="form.knedp" name="drawerKnedp" id="drawerKnedp">
                    <option value="" selected>{{ __('forms.select') }}</option>
                    @foreach(signatureService()->getCertificateAuthorities() as $certificateType)
                        <option value="{{ $certificateType['id'] }}" wire:key="{{ $certificateType['id'] }}">
                            {{ $certificateType['name'] }}
                        </option>
                    @endforeach
                </select>

                @error('form.knedp') <p class="text-error">{{ $message }}</p> @enderror
            </div>

            {{-- Key File with Drag & Drop --}}
            <div>
                <label class="default-label">{{ __('forms.key_container_upload') }} *</label>
                <label for="drawerKeyFile"
                       class="flex flex-col items-center justify-center w-full h-48 border-2 border-gray-300 border-dashed rounded-lg cursor-pointer bg-gray-50 dark:hover:bg-gray-800 dark:bg-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:hover:border-gray-500"
                >
                    <div class="flex flex-col items-center justify-center pt-5 pb-6">
                        <svg class="w-8 h-8 mb-4 text-gray-500 dark:text-gray-400"
                             aria-hidden="true"
                             xmlns="http://www.w3.org/2000/svg"
                             fill="none"
                             viewBox="0 0 20 16"
                        >
                            <path stroke="currentColor"
                                  stroke-linecap="round"
                                  stroke-linejoin="round"
                                  stroke-width="2"
                                  d="M13 13h3a3 3 0 0 0 0-6h-.025A5.56 5.56 0 0 0 16 6.5 5.5 5.5 0 0 0 5.207 5.021C5.137 5.017 5.071 5 5 5a4 4 0 0 0 0 8h2.167M10 15V6m0 0L8 8m2-2 2 2"
                            />
                        </svg>
                        <p class="mb-2 px-2 text-sm text-gray-500 dark:text-gray-400 text-center">
                            <span
                                class="font-semibold text-blue-600 dark:text-blue-400">{{ __('forms.drag_key_file') }}</span>
                            {{ __('forms.or_upload_from_device') }}
                        </p>
                        <p class="px-2 text-xs text-gray-500 dark:text-gray-400 text-center">
                            {{ __('forms.key_file_extension_hint') }}
                        </p>
                    </div>
                    <input wire:model="form.keyContainerUpload"
                           id="drawerKeyFile"
                           type="file"
                           class="hidden"
                           accept=".dat,.pfx,.pk8,.zs2,.jks,.p7s"
                           @change="fileUploaded = true; fileName = $event.target.files[0].name"
                    />

                    @error('form.keyContainerUpload') <p class="text-error">{{ $message }}</p> @enderror
                </label>
                <template x-if="fileUploaded">
                    <div x-transition class="text-sm text-green-700 mt-2">
                        {!! __('forms.file_uploaded_success', ['name' => '<span x-text="fileName"></span>']) !!}
                    </div>
                </template>
            </div>

            {{-- Password --}}
            <div>
                <label for="drawerPassword" class="default-label">{{ __('forms.password') }} *</label>
                <input wire:model="form.password"
                       type="password"
                       class="default-input w-full"
                       id="drawerPassword"
                       name="drawerPassword"
                       autocomplete="current-password"
                />

                @error('form.password') <p class="text-error">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex gap-3 mt-8">
            <button type="button"
                    @click="showSignatureDrawer = false"
                    class="button-minor"
            >
                {{ __('forms.cancel') }}
            </button>

            <button type="button"
                    wire:click.prevent="signConfidantPersonRelationship"
                    class="button-primary"
            >
                {{ __('forms.sign') }}
            </button>
        </div>
    </div>
</div>
