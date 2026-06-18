@if(isset($contract) && isset($data))
    @if($docs = data_get($data, 'urgent.documents'))
        <fieldset class="fieldset">
            <legend class="legend">{{ __('contracts.documents_archive') }}</legend>
            <ul class="show-docs-list">
                @foreach($docs as $doc)
                    <li class="flex items-center gap-2">
                        @icon('download', 'w-4 h-4 text-blue-500 dark:text-blue-400')
                        <a href="{{ $doc['url'] }}" target="_blank" class="show-docs-link">
                            {{ __('contracts.upload_signed_archive') }} ({{ $doc['type'] }})
                        </a>
                    </li>
                @endforeach
            </ul>
        </fieldset>
    @endif
@else
    <fieldset class="fieldset">
        <legend class="legend">
            <h2>{{ __('forms.uploading_documents') }}</h2>
        </legend>
        <div>
            <p class="default-p mb-6">{{ __('contracts.statute_md5_info') }}</p>
            <div class="flex flex-col gap-3">
                <div x-data="{ fileName: '{{ isset($this->form->statuteMd5) && is_object($this->form->statuteMd5) ? $this->form->statuteMd5->getClientOriginalName() : __('forms.no_file_chosen') }}' }" class="file-input-wrapper @error('form.statuteMd5') border-red-500 @enderror">
                    <label for="statuteMd5" class="file-input-button">
                        {{ __('forms.choose_file') }}
                    </label>
                    <span class="file-input-text" x-text="fileName"></span>
                    <input id="statuteMd5"
                           type="file"
                           wire:model="form.statuteMd5"
                           name="statuteMd5"
                           class="hidden"
                           @change="fileName = $event.target.files[0] ? $event.target.files[0].name : '{{ __('forms.no_file_chosen') }}'"
                    />
                </div>
                @if(isset($this->form->statuteMd5) && is_object($this->form->statuteMd5))
                    <p class="text-sm text-gray-700">
                        {{ __('forms.files_selected') }}: {{ $this->form->statuteMd5->getClientOriginalName() }}
                    </p>
                @endif
                <p class="text-xs text-gray-500">{{ __('forms.max_file_size_and_format') }}</p>
                @error('form.statuteMd5')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
        <div>
            <p class="default-p mb-6">{{ __('contracts.additional_document_md5_info') }}</p>
            <div class="flex flex-col gap-3">
                <div x-data="{ fileName: '{{ isset($this->form->additionalDocumentMd5) && is_object($this->form->additionalDocumentMd5) ? $this->form->additionalDocumentMd5->getClientOriginalName() : __('forms.no_file_chosen') }}' }" class="file-input-wrapper @error('form.additionalDocumentMd5') border-red-500 @enderror">
                    <label for="additionalDocumentMd5" class="file-input-button">
                        {{ __('forms.choose_file') }}
                    </label>
                    <span class="file-input-text" x-text="fileName"></span>
                    <input id="additionalDocumentMd5"
                           type="file"
                           wire:model="form.additionalDocumentMd5"
                           name="additionalDocumentMd5"
                           class="hidden"
                           @change="fileName = $event.target.files[0] ? $event.target.files[0].name : '{{ __('forms.no_file_chosen') }}'"
                    />
                </div>
                @if(isset($this->form->additionalDocumentMd5) && is_object($this->form->additionalDocumentMd5))
                    <p class="text-sm text-gray-700">
                        {{ __('forms.files_selected') }}: {{ $this->form->additionalDocumentMd5->getClientOriginalName() }}
                    </p>
                @endif
                <p class="text-xs text-gray-500">{{ __('forms.max_file_size_and_format') }}</p>
                @error('form.additionalDocumentMd5')
                    <p class="text-error">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </fieldset>
@endif
