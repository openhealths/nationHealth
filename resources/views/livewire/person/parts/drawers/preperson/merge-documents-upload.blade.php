<x-dialog-drawer
    x-model="showMergeDocumentsDrawer"
    onCloseClick="showMergeDocumentsDrawer = false"
    maxWidth="4/5"
>
    <x-slot name="title">
        {{ __('preperson.merge.auth_via_documents') }}
    </x-slot>

    <div x-data="{
        file1: '',
        file2: '',
        get canSubmit() {
            return this.file1 && this.file2;
        }
    }">

    <div class="mt-8 space-y-8 max-w-2xl">
        <x-forms.form-group>
            <x-slot name="label">
                <label class="default-label" for="documentUpload1">
                    {{ __('forms.birth_certificate') }}
                </label>
            </x-slot>
            <x-slot name="input">
                <div x-data="{ fileName: '{{ __('forms.no_file_chosen') }}' }"
                     x-effect="if (!showMergeDocumentsDrawer) { fileName = '{{ __('forms.no_file_chosen') }}'; if ($refs.documentUpload1) $refs.documentUpload1.value = ''; file1 = ''; }"
                     class="file-input-wrapper"
                >
                    <label for="documentUpload1" class="file-input-button">
                        {{ __('forms.choose_file') }}
                    </label>
                    <span class="file-input-text" x-text="fileName"></span>
                    <input
                        id="documentUpload1"
                        type="file"
                        class="hidden"
                        accept="image/jpeg,image/jpg"
                        x-ref="documentUpload1"
                        @change="fileName = $event.target.files[0] ? $event.target.files[0].name : '{{ __('forms.no_file_chosen') }}'; file1 = $event.target.files[0] ? $event.target.files[0].name : '';"
                    >
                </div>
                <div class="mt-1 text-sm text-gray-500 dark:text-gray-300">{{ __('forms.max_file_size_and_format') }}</div>
            </x-slot>
        </x-forms.form-group>

        <x-forms.form-group>
            <x-slot name="label">
                <label class="default-label" for="documentUpload2">
                    {{ __('forms.birth_certificate') }}
                </label>
            </x-slot>
            <x-slot name="input">
                <div x-data="{ fileName: '{{ __('forms.no_file_chosen') }}' }"
                     x-effect="if (!showMergeDocumentsDrawer) { fileName = '{{ __('forms.no_file_chosen') }}'; if ($refs.documentUpload2) $refs.documentUpload2.value = ''; file2 = ''; }"
                     class="file-input-wrapper"
                >
                    <label for="documentUpload2" class="file-input-button">
                        {{ __('forms.choose_file') }}
                    </label>
                    <span class="file-input-text" x-text="fileName"></span>
                    <input
                        id="documentUpload2"
                        type="file"
                        class="hidden"
                        accept="image/jpeg,image/jpg"
                        x-ref="documentUpload2"
                        @change="fileName = $event.target.files[0] ? $event.target.files[0].name : '{{ __('forms.no_file_chosen') }}'; file2 = $event.target.files[0] ? $event.target.files[0].name : '';"
                    >
                </div>
                <div class="mt-1 text-sm text-gray-500 dark:text-gray-300">{{ __('forms.max_file_size_and_format') }}</div>
            </x-slot>
        </x-forms.form-group>
    </div>

    <div class="flex gap-3 mt-12">
        <button class="button-minor"
                type="button"
                @click="showMergeDocumentsDrawer = false; showMergeConfirmationDrawer = true"
        >
            {{ __('forms.cancel') }}
        </button>

        <button class="button-primary flex items-center gap-2"
                type="button"
                :disabled="!canSubmit"
                @click="showMergeDocumentsDrawer = false; showMergeFinalConsentDrawer = true"
        >
            <span>{{ __('forms.send_files') }}</span>
            @icon('arrow-right', 'w-4 h-4')
        </button>
    </div>
</x-dialog-drawer>
