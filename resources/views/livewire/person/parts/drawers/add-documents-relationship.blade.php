{{-- Document drawer works for both PersonUpdate and PersonCreate --}}

{{-- Document Drawer Overlay --}}
<div x-show="showDocumentDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     x-cloak
     @click="showDocumentDrawer = false"
     class="fixed inset-0 bg-gray-900/50"
     style="z-index: 55;"
></div>

{{-- Document Drawer --}}
<div x-show="showDocumentDrawer"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="translate-x-full"
     x-transition:enter-end="translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="translate-x-0"
     x-transition:leave-end="translate-x-full"
     x-cloak
     class="fixed top-0 right-0 h-screen pt-16 bg-white dark:bg-gray-800 shadow-2xl"
     style="z-index: 60; width: calc(80% - 35px);"
     id="add-document-drawer"
     tabindex="-1"
>
    <div class="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-6 py-4">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white" x-text="isEditing ? '{{ __('forms.edit_document') }}' : '{{ __('forms.add_document') }}'">
        </h2>
    </div>

    <div class="overflow-y-auto p-6 bg-white dark:bg-gray-800" style="height: calc(100% - 70px);">
        <div class="mt-4">
            <div class="form-row-3">
                <div class="form-group group">
                    <select name="documentRelationshipType"
                            id="documentRelationshipType"
                            class="input-select peer"
                            x-model="newDocument.type"
                            @change="newDocument.typeLabel = $event.target.options[$event.target.selectedIndex].text"
                            required
                    >
                        <option value="" selected>{{ __('forms.select') }}</option>
                        @foreach($this->dictionaries['DOCUMENT_RELATIONSHIP_TYPE'] as $key => $document)
                            <option value="{{ $key }}">{{ $document }}</option>
                        @endforeach
                    </select>
                    <label for="documentRelationshipType" class="label">
                        {{ __('forms.document_type') }}
                    </label>
                </div>

                <div class="form-group group">
                    <input type="text"
                           name="documentNumber"
                           id="documentNumber"
                           class="input peer"
                           placeholder=" "
                           autocomplete="off"
                           x-model="newDocument.number"
                           required
                    />
                    <label for="documentNumber" class="label">
                        {{ __('forms.document_number') }}
                    </label>
                </div>

                <div class="form-group group">
                    <input type="text"
                           name="documentIssuedBy"
                           id="documentIssuedBy"
                           class="input peer"
                           placeholder=" "
                           autocomplete="off"
                           x-model="newDocument.issuedBy"
                           required
                    />
                    <label for="documentIssuedBy" class="label">
                        {{ __('forms.issued_by') }}
                    </label>
                </div>
            </div>

            <div class="form-row-3">
                <div class="form-group group">
                    <div class="datepicker-wrapper">
                        <input type="text"
                               datepicker-max-date="{{ now()->format('d.m.Y') }}"
                               datepicker-format="dd.mm.yyyy"
                               name="documentIssueDate"
                               id="documentIssueDate"
                               class="datepicker-input with-leading-icon input peer"
                               placeholder=" "
                               autocomplete="off"
                               x-model="newDocument.issuedAt"
                               required
                        />
                        <label for="documentIssueDate" class="wrapped-label">
                            {{ __('forms.issued_date') }}
                        </label>
                    </div>
                </div>

                <div class="form-group group">
                    <div class="datepicker-wrapper">
                        <input type="text"
                               datepicker-min-date="{{ now()->format('d.m.Y') }}"
                               datepicker-format="dd.mm.yyyy"
                               name="documentExpiryDate"
                               id="documentExpiryDate"
                               class="datepicker-input with-leading-icon input peer"
                               placeholder=" "
                               autocomplete="off"
                               x-model="newDocument.expiryDate"
                        />
                        <label for="documentExpiryDate" class="wrapped-label">
                            {{ __('forms.expiry_date') }}
                        </label>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-start gap-3">
                <button type="button"
                        class="button-minor"
                        @click="showDocumentDrawer = false"
                >
                    {{ __('forms.cancel') }}
                </button>

                <button type="button"
                        class="button-primary"
                        :disabled="!newDocument.type || !newDocument.number || !newDocument.issuedBy || !newDocument.issuedAt"
                        @click="addNewConfidant(); showDocumentDrawer = false"
                >
                    <span x-text="isEditing ? '{{ __('forms.save') }}' : '{{ __('forms.add_document') }}'">
                        {{ __('forms.add_document') }}
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>

@if($this instanceof PersonUpdate)
    @include('livewire.person.parts.drawers.add-auth-verification')
@endif
