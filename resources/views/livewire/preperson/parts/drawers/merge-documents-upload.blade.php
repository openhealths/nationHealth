<x-dialog-drawer
    x-model="showMergeDocumentsDrawer"
    onCloseClick="showMergeDocumentsDrawer = false"
    maxWidth="4/5"
>
    <x-slot name="title">
        {{ __('patients.authentication_documents') }}
    </x-slot>

    <div x-data="{ selectedFiles: {} }">
        <div class="mt-8 space-y-8 max-w-2xl">
            @forelse($this->uploadedDocuments as $key => $document)
                <x-forms.form-group wire:key="merge-document-{{ $key }}">
                    <x-slot name="label">
                        <label class="default-label" for="mergeDocument{{ $key }}">
                            {{ $this->getDocumentLabel($document) }}
                        </label>
                    </x-slot>
                    <x-slot name="input">
                        <div
                            x-data="{ fileName: '{{ __('forms.no_file_chosen') }}' }"
                            x-effect="if (!showMergeDocumentsDrawer) { fileName = '{{ __('forms.no_file_chosen') }}'; }"
                            class="file-input-wrapper"
                        >
                            <label for="mergeDocument{{ $key }}" class="file-input-button">
                                {{ __('forms.choose_file') }}
                            </label>
                            <span class="file-input-text" x-text="fileName"></span>
                            <input
                                id="mergeDocument{{ $key }}"
                                type="file"
                                class="hidden"
                                accept="image/jpeg,image/jpg"
                                wire:model="mergeDocuments.{{ $key }}"
                                @change="
                                    fileName = $event.target.files[0]?.name ?? '{{ __('forms.no_file_chosen') }}';
                                    selectedFiles[{{ $key }}] = $event.target.files.length > 0;
                                "
                            >
                        </div>
                        <div class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                            {{ __('forms.max_file_size_and_format') }}
                        </div>
                        @error("mergeDocuments.$key")
                            <p class="text-error mt-1">{{ $message }}</p>
                        @enderror
                    </x-slot>
                </x-forms.form-group>
            @empty
                <x-nothing-found class="mx-auto" maxWidth="" />
            @endforelse
        </div>

        <div class="flex gap-3 mt-12">
            <button
                class="button-minor"
                type="button"
                @click="showMergeDocumentsDrawer = false; showMergeConfirmationDrawer = true"
            >
                {{ __('forms.cancel') }}
            </button>

            <button
                class="button-primary flex items-center gap-2"
                type="button"
                :disabled="Object.values(selectedFiles).filter(Boolean).length < {{ count($this->uploadedDocuments) }}"
                wire:loading.attr="disabled"
                wire:target="sendDocuments"
                @click="$wire.sendDocuments().then((sent) => {
                    if (sent) {
                        $wire.$parent.$refresh();
                        showMergeDocumentsDrawer = false;
                        showMergeFinalConsentDrawer = true;
                    }
                })"
            >
                <span>{{ __('forms.send_files') }}</span>
                @icon('arrow-right', 'w-4 h-4')
            </button>
        </div>
    </div>
</x-dialog-drawer>
