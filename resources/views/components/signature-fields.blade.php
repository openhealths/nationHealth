@props(['model' => 'form', 'method', 'open' => null])

@php
    $keyContainerExtensions = ['dat', 'pfx', 'pk8', 'zs2', 'jks', 'p7s'];
@endphp

{{-- KNEDP, key and password for signing. The key is read in the browser, and the key and password are kept there until
     the signing action ($method) is called: they are passed as its arguments, so they never become component properties,
     the server does not return them in the component snapshot, no other request carries them, and the key file is never
     stored on the server --}}
<div
    x-data="{
        keyContainer: '',
        keyContainerFileName: '',
        keyContainerError: '',
        password: '',
        keyContainerReader: null,
        removeSigningInterceptor: null,

        init() {
            this.removeSigningInterceptor = $wire.$intercept(this.$root.dataset.method, ({ action }) => {
                action.params.push(this.keyContainer, this.password);
            });

            // Closing the dialog forgets the key and password; they are kept only for a retry in the open dialog
            if (this.$root.dataset.open) {
                this.$watch(this.$root.dataset.open, (open) => open || this.forgetKey());
            }
        },

        destroy() {
            this.removeSigningInterceptor();
        },

        forgetKey() {
            this.keyContainerReader?.abort();
            this.keyContainer = '';
            this.keyContainerFileName = '';
            this.keyContainerError = '';
            this.password = '';
        },

        readKeyContainer(event) {
            const file = event.target.files[0];

            // Cleared, so the same file can be chosen again
            event.target.value = '';

            if (! file) {
                return;
            }

            // The previous key is dropped at once, and its unfinished reading is stopped, so it is not signed with
            // while the new file is read or after reading fails
            this.keyContainerReader?.abort();
            this.keyContainer = '';
            this.keyContainerFileName = '';
            this.keyContainerError = '';

            // The file dialog can be switched to all files, so a file of another type is refused before it is read
            const extension = `.${file.name.split('.').pop().toLowerCase()}`;
            if (! event.target.accept.split(',').includes(extension)) {
                this.keyContainerError = this.$root.dataset.keyContainerExtensionError;

                return;
            }

            const reader = new FileReader();
            this.keyContainerReader = reader;
            reader.onload = () => {
                this.keyContainer = reader.result.split(',')[1];
                this.keyContainerFileName = file.name;
            };
            reader.onerror = () => {
                this.keyContainerError = this.$root.dataset.keyContainerReadError;
            };
            reader.readAsDataURL(file);
        },

        get chosenKeyContainerName() {
            return this.keyContainerFileName || this.$root.dataset.noFileLabel;
        }
    }"
    x-id="['signature-knedp', 'signature-key-container', 'signature-password']"
    data-method="{{ $method }}"
    data-open="{{ $open }}"
    data-no-file-label="{{ __('forms.no_file_chosen') }}"
    data-key-container-extension-error="{{ __('validation.extensions', [
        'attribute' => __('validation.attributes.keyContainer'),
        'values' => implode(', ', $keyContainerExtensions)
    ]) }}"
    data-key-container-read-error="{{ __('validation.uploaded', ['attribute' => __('validation.attributes.keyContainer')]) }}"
    class="flex flex-col gap-6"
>
    {{-- KEP Provider --}}
    <div>
        <label :for="$id('signature-knedp')" class="default-label">{{ __('forms.knedp') }} *</label>
        {{-- Deferred: avoid a Livewire round-trip on every KNEDP change before sign --}}
        <select class="input-modal" wire:model="{{ $model }}.knedp" name="knedp" :id="$id('signature-knedp')">
            <option value="" selected>{{ __('forms.select') }}</option>
            @foreach (signatureService()->getCertificateAuthorities() as $certificateType)
                <option value="{{ $certificateType['id'] }}" wire:key="{{ $certificateType['id'] }}">
                    {{ $certificateType['name'] }}
                </option>
            @endforeach
        </select>

        @error($model . '.knedp')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Key File --}}
    <div>
        <label :for="$id('signature-key-container')" class="default-label">
            {{ __('forms.key_container_upload') }} *
        </label>
        <div class="file-input-wrapper">
            <label :for="$id('signature-key-container')" class="file-input-button">
                {{ __('forms.choose_file') }}
            </label>
            <span class="file-input-text" x-text="chosenKeyContainerName"></span>
            <input
                type="file"
                class="hidden"
                :id="$id('signature-key-container')"
                accept=".{{ implode(',.', $keyContainerExtensions) }}"
                @change="readKeyContainer($event)"
            />
        </div>

        <p class="text-error" x-show="keyContainerError" x-text="keyContainerError"></p>

        @error($model . '.keyContainer')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Password --}}
    <div>
        <label :for="$id('signature-password')" class="default-label">{{ __('forms.password') }} *</label>
        <x-password-field>
            <input
                type="password"
                x-model="password"
                class="default-input"
                :id="$id('signature-password')"
                autocomplete="current-password"
            />
        </x-password-field>

        @error($model . '.password')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>
</div>
