@props(['model' => 'form'])

@php
    $keyContainerExtensions = ['dat', 'pfx', 'pk8', 'zs2', 'jks', 'p7s'];
@endphp

{{-- KNEDP, key and password for signing. The key is read in the browser and set without a request, so it reaches the
     server only with the request that submits the form, and the key file is never stored there --}}
<div
    x-data="{
        keyContainerFileName: '',
        keyContainerError: '',

        readKeyContainer(event) {
            const file = event.target.files[0];

            // Cleared, so the same file can be chosen again
            event.target.value = '';

            if (! file) {
                return;
            }

            // The file dialog can be switched to all files, so a file of another type is refused before it is read
            const extension = `.${file.name.split('.').pop().toLowerCase()}`;
            if (! event.target.accept.split(',').includes(extension)) {
                this.keyContainerFileName = '';
                this.keyContainerError = this.$root.dataset.keyContainerExtensionError;
                $wire.set(`${this.$root.dataset.model}.keyContainer`, '', false);

                return;
            }

            this.keyContainerError = '';
            const reader = new FileReader();
            reader.onload = () => {
                this.keyContainerFileName = file.name;
                $wire.set(`${this.$root.dataset.model}.keyContainer`, reader.result.split(',')[1], false);
            };
            reader.readAsDataURL(file);
        },

        get chosenKeyContainerName() {
            return $wire.get(`${this.$root.dataset.model}.keyContainer`) && this.keyContainerFileName
                ? this.keyContainerFileName
                : this.$root.dataset.noFileLabel;
        }
    }"
    x-id="['signature-knedp', 'signature-key-container', 'signature-password']"
    data-model="{{ $model }}"
    data-no-file-label="{{ __('forms.no_file_chosen') }}"
    data-key-container-extension-error="{{ __('validation.extensions', [
        'attribute' => __('validation.attributes.keyContainer'),
        'values' => implode(', ', $keyContainerExtensions)
    ]) }}"
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
                wire:model="{{ $model }}.password"
                class="default-input"
                :id="$id('signature-password')"
                name="password"
                autocomplete="current-password"
            />
        </x-password-field>

        @error($model . '.password')
            <p class="text-error">{{ $message }}</p>
        @enderror
    </div>
</div>
