@props ([
    'model' => 'showMergeSignatureDrawer', 'preview' => false,
    'idPrefix' => 'merge', 'onCancel' => null, 'header' => null,
])
@php
    $cancelAction = $onCancel ?? $model.' = false';
    $certificateAuthorities = $preview
        ? [['id' => 'preview-knedp', 'name' => 'КНЕДП «Дія»']]
        : signatureService()->getCertificateAuthorities();
@endphp
<x-dialog-drawer
    x-model="{{ $model }}"
    onCloseClick="{{ $model }} = false"
    maxWidth="4/5"
    :customWidth="$preview ? 'w-full lg:w-[calc(100%-19.5rem)]' : null"
    :overlayWidth="$preview ? '100%' : null"
    :stopClickPropagation="$preview"
    :zIndex="$preview ? '50' : '40'"
>
    @if ($header !== null)
        <x-slot name="header">
            {{ $header }}
        </x-slot>
    @endif
    <x-slot name="title">
        {{ __('preperson.merge.electronic_signature') }}
    </x-slot>

    <div
        x-data="{
            wasOpen: false,
            fileName: '',
            provider: '',
            password: '',
            uploading: false,
            uploadFinished: false,
        }"
        x-effect="
            if ({{ $model }}) { wasOpen = true; }
            else if (wasOpen) {
                wasOpen = false; fileName = ''; provider = ''; password = ''; uploading = false; uploadFinished = false;
                if ($refs.keyFile) $refs.keyFile.value = '';
                @unless ($preview)
                    $wire.set('form.knedp', '', false);
                    $wire.set('form.password', '', false);
                    $wire.set('form.keyContainerUpload', null, false);
                @endunless
            }
        "
    >
        <div class="space-y-6 max-w-2xl">
            <div>
                <label for="{{ $idPrefix }}Knedp" class="default-label"
                    >{{ __('forms.knedp') }} *</label
                >
                <select
                    class="{{ $preview ? 'input-select w-full' : 'input-modal w-full' }}"
                    @if ($preview)
                        x-model="provider"
                    @else
                        wire:model="form.knedp"
                    @endif
                    name="{{ $idPrefix }}Knedp"
                    id="{{ $idPrefix }}Knedp"
                >
                    <option value="" selected>{{ __('forms.select') }}</option>
                    @foreach ($certificateAuthorities as $certificateType)
                        <option
                            value="{{ $certificateType['id'] }}"
                            wire:key="merge-{{ $certificateType['id'] }}"
                        >
                            {{ $certificateType['name'] }}
                        </option>
                    @endforeach
                </select>
                @error ('form.knedp')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label
                    class="default-label"
                    >{{ __('preperson.merge.container_path') }}</label
                >
                <label
                    for="{{ $idPrefix }}KeyFile"
                    @dragover.prevent
                    @if ($preview)
                        @drop.prevent="
                            fileName = $event.dataTransfer.files[0]?.name ?? ''
                        "
                    @else
                        @drop.prevent="
                            if ($event.dataTransfer.files.length) {
                                $refs.keyFile.files = $event.dataTransfer.files;
                                $refs.keyFile.dispatchEvent(
                                    new Event('change', { bubbles: true }),
                                );
                            }
                        "
                    @endif
                    class="flex flex-col items-center justify-center w-full h-48 border-2 border-gray-300 border-dashed rounded-lg cursor-pointer bg-gray-50 dark:hover:bg-gray-800 dark:bg-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:hover:border-gray-500"
                >
                    <div
                        class="flex flex-col items-center justify-center pt-5 pb-6"
                    >
                        @icon ('upload', 'w-8 h-8 mb-4 text-gray-500 dark:text-gray-400')
                        <p class="mb-2 px-2 text-sm text-gray-500 dark:text-gray-400 text-center">
                            <span
                                class="font-semibold text-blue-600 dark:text-blue-400"
                                >{{ __('forms.drag_key_file') }}</span
                            >
                        </p>
                        <p class="px-2 text-xs text-gray-500 dark:text-gray-400 text-center">
                            {{ __('forms.key_file_extension_hint') }}
                        </p>
                    </div>
                    <input
                        id="{{ $idPrefix }}KeyFile"
                        type="file"
                        class="hidden"
                        accept=".dat,.pfx,.pk8,.zs2,.jks,.p7s"
                        @unless ($preview) wire:model="form.keyContainerUpload" @endunless
                        x-ref="keyFile"
                        @change="
                            fileName = $event.target.files[0]
                                ? $event.target.files[0].name
                                : '';
                            uploading = ! @js($preview) && fileName !== '';
                            uploadFinished = @js($preview);
                        "
                        @unless ($preview)
                            x-on:livewire-upload-start="
                                uploading = true;
                                uploadFinished = false;
                            "
                            x-on:livewire-upload-finish="
                                uploading = false;
                                uploadFinished = true;
                            "
                            x-on:livewire-upload-error="
                                uploading = false;
                                uploadFinished = false;
                            "
                            x-on:livewire-upload-cancel="
                                uploading = false;
                                uploadFinished = false;
                            "
                        @endunless
                    />
                </label>

                @unless ($preview)
                    <div
                        wire:loading
                        wire:target="form.keyContainerUpload"
                        class="text-sm text-gray-500 mt-2"
                    >
                        {{ __('general.loading') }}...
                    </div>
                @endunless

                <template
                    x-if="{{ $preview ? 'fileName' : 'fileName && uploadFinished' }}"
                >
                    <div
                        x-transition
                        class="text-sm text-green-700 mt-2 font-medium"
                    >
                        {!! __('forms.file_uploaded_success', ['name' => '<span class="font-semibold" x-text="fileName"></span>']) !!}
                    </div>
                </template>

                @error ('form.keyContainerUpload')
                    <p class="text-error mt-2">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="{{ $idPrefix }}Password" class="default-label"
                    >{{ __('forms.password') }} *</label
                >
                <x-password-field>
                    <input
                        @if ($preview)
                            x-model="password"
                        @else
                            wire:model="form.password"
                        @endif
                        type="password"
                        class="{{ $preview ? 'input w-full' : 'default-input w-full' }}"
                        id="{{ $idPrefix }}Password"
                        name="{{ $idPrefix }}Password"
                        autocomplete="{{ $preview ? 'off' : 'current-password' }}"
                    />
                </x-password-field>
                @error ('form.password')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex gap-3 pt-6">
                <button
                    type="button"
                    @click="{{ $cancelAction }}"
                    class="button-minor"
                >
                    {{ __('forms.cancel') }}
                </button>

                @if ($preview)
                    <button
                        type="button"
                        @click="
                            completeSignaturePreview({
                                provider,
                                fileName,
                                password,
                            })
                        "
                        class="button-primary"
                    >
                        {{ __('forms.sign') }}
                    </button>
                @else
                    <button
                        type="button"
                        wire:click="sign"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-50 cursor-not-allowed"
                        wire:target="sign,form.keyContainerUpload"
                        :disabled="uploading || !fileName || !uploadFinished"
                        class="button-primary"
                    >
                        <span
                            wire:loading.remove
                            wire:target="sign"
                            >{{ __('forms.sign') }}</span
                        >
                        <span wire:loading wire:target="sign"
                            >{{ __('forms.signature') }}...</span
                        >
                    </button>
                @endif
            </div>
        </div>
    </div>
</x-dialog-drawer>
