<div x-data="{ showUploadingDocumentsModal: $wire.entangle('showUploadingDocumentsModal') }">
    <template x-teleport="body">
        <div
            x-show="showUploadingDocumentsModal"
            style="display: none"
            @keydown.escape.prevent.stop="showUploadingDocumentsModal = false"
            role="dialog"
            aria-modal="true"
            class="modal"
        >
            <div x-transition.opacity class="fixed inset-0 bg-black/30"></div>
            <div x-transition @click="showUploadingDocumentsModal = false" class="modal-wrapper">
                <div
                    @click.stop
                    x-trap.noscroll.inert="showUploadingDocumentsModal"
                    class="modal-content mx-auto w-full max-w-4xl"
                >
                    <h2 class="mb-8 text-center text-2xl font-semibold text-gray-900 dark:text-white">
                        {{ __('forms.uploading_documents') }}
                    </h2>

                    @foreach ($uploadedDocuments as $key => $document)
                        <div class="flex pb-4" wire:key="{{ $key }}">
                            <div class="grow">
                                <label
                                    class="mb-3 block text-sm font-medium text-gray-900 dark:text-white"
                                    for="fileInput-{{ $key }}"
                                >
                                    {{ __('patients.documents.' . Str::afterLast($document['type'], '.')) }}
                                </label>
                                <div class="flex items-center gap-4">
                                    <input
                                        type="file"
                                        class="block cursor-pointer rounded-lg border border-gray-300 bg-gray-50 text-sm text-gray-900 focus:outline-none xl:w-1/2 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-400 dark:placeholder-gray-400"
                                        id="fileInput-{{ $key }}"
                                        wire:model.live="form.uploadedDocuments.{{ $key }}"
                                        accept=".jpeg,.jpg"
                                    />
                                </div>

                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-300">
                                    {{ __('forms.max_file_size_and_format') }}
                                </p>

                                @error("form.uploadedDocuments.$key")
                                    <p class="text-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    @endforeach

                    <div class="form-group group">
                        <button
                            wire:click.prevent="sendFiles"
                            class="button-primary mt-8 flex items-center gap-2"
                            type="button"
                        >
                            {{ __('forms.send_files') }}
                            @icon('arrow-right', 'w-5 h-5 text-white dark:text-white')
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
