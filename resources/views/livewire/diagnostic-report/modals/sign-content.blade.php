<x-dialog-modal maxWidth="3xl">
    <x-slot name="title">
        {{ __('Накласти КЕП') }}
    </x-slot>

    <x-slot name="content">
        <div class="mb-4.5 flex flex-col gap-6 xl:flex-container">

            <x-forms.form-group class="">
                <x-slot name="label">
                    <x-forms.label class="default-label" for="knedp" name="label">
                        {{ __('forms.knedp') }} *
                    </x-forms.label>
                </x-slot>
                <x-slot name="input">
                    <x-forms.select class="default-input"
                                    wire:model="knedp"
                                    id="knedp"
                    >
                        <x-slot name="option">
                            @foreach($getCertificateAuthority as $certificateType)
                                <option value="{{ $certificateType['id'] }}">
                                    {{ $certificateType['name'] }}
                                </option>
                            @endforeach
                        </x-slot>
                    </x-forms.select>
                </x-slot>

                @error("knedp")
                <x-forms.error>
                    {{ $message }}
                </x-forms.error>
                @enderror
            </x-forms.form-group>

            <x-forms.form-group class="">
                <x-slot name="label">
                    <x-forms.label class="default-label" for="keyContainerUpload" name="label">
                        {{ __('forms.key_container_upload') }} *
                    </x-forms.label>
                </x-slot>
                <x-slot name="input">
                    <div x-data="{ fileName: '{{ __('forms.no_file_chosen') }}' }" class="file-input-wrapper">
                        <label for="keyContainerUpload" class="file-input-button">
                            {{ __('forms.choose_file') }}
                        </label>
                        <span class="file-input-text" x-text="fileName"></span>
                        <input class="hidden"
                               wire:model="file"
                               type="file"
                               id="keyContainerUpload"
                               accept=".dat,.pfx,.pk8,.zs2,.jks,.p7s"
                               @change="fileName = $event.target.files[0] ? $event.target.files[0].name : '{{ __('forms.no_file_chosen') }}'"
                        />
                    </div>
                </x-slot>

                @error("keyContainerUpload")
                <x-forms.error>
                    {{ $message }}
                </x-forms.error>
                @enderror
            </x-forms.form-group>

            <x-forms.form-group class="">
                <x-slot name="label">
                    <x-forms.label class="default-label" for="password" name="label">
                        {{ __('forms.password') }} *
                    </x-forms.label>
                </x-slot>
                <x-slot name="input">
                    <x-forms.input class="default-input"
                                   wire:model="password"
                                   type="password"
                                   id="password"
                    />
                </x-slot>
            </x-forms.form-group>

            <div class="mb-4.5 flex flex-col gap-6 xl:flex-row justify-between items-center">
                <div class="text-left">
                    <button wire:click.prevent="closeModal" class="button-minor">
                        {{ __('forms.back') }}
                    </button>
                </div>
                <div class="text-right">
                    <button @click.prevent="$wire.sign(modalDiagnosticReport)" type="button" class="button-primary">
                        {{ __('forms.send_for_approval') }}
                    </button>
                </div>
            </div>
        </div>
    </x-slot>
</x-dialog-modal>
