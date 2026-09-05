{{-- Documents inside drawer --}}
<div>
    <fieldset
        class="p-4 sm:p-8 sm:pb-10 mt-6 border border-gray-200 rounded-lg shadow dark:bg-gray-800 dark:border-gray-700 max-w-full">
        <legend class="legend">
            {{ __('patients.confidant_person_documents_relationship') }}
        </legend>

        <div class="overflow-x-auto mb-4">
            <table class="table-input w-full">
                <thead class="thead-input">
                <tr>
                    <th scope="col" class="th-input">{{ __('forms.type') }}</th>
                    <th scope="col" class="th-input">{{ __('forms.number') }}</th>
                    <th scope="col" class="th-input">{{ __('forms.issued_by') }}</th>
                    <th scope="col" class="th-input">{{ __('forms.issued_at') }}</th>
                    <th scope="col" class="th-input">{{ __('forms.active_to') }}</th>
                    <th scope="col" class="th-input text-center">{{ __('forms.action') }}</th>
                </tr>
                </thead>
                <tbody>
                <template x-for="(doc, index) in confidantPerson.documentsRelationship" :key="doc.type + '_' + index">
                    <tr>
                        <td class="td-input" x-text="documentRelationshipTypes[doc.type] || doc.type"></td>
                        <td class="td-input" x-text="doc.number"></td>
                        <td class="td-input" x-text="doc.issuedBy"></td>
                        <td class="td-input" x-text="doc.issuedAt"></td>
                        <td class="td-input" x-text="doc.activeTo || '-'"></td>
                        <td class="td-input text-center">
                            <div class="relative"
                                 x-data="{ openDropdown: false }"
                                 @click.outside="openDropdown = false"
                            >
                                <button @click="openDropdown = !openDropdown"
                                        type="button"
                                        class="cursor-pointer"
                                >
                                    @icon('edit-user-outline', 'w-6 h-6 text-gray-800 dark:text-gray-200')
                                </button>

                                <div x-show="openDropdown"
                                     x-transition
                                     x-cloak
                                     class="absolute right-0 z-10 w-36 bg-white rounded shadow-lg border border-gray-200 dark:bg-gray-700 dark:border-gray-600"
                                >
                                    <div class="py-1">
                                        <button type="button"
                                                class="flex items-center gap-2 w-full cursor-pointer px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200"
                                                @click="editDocument(index); openDropdown = false"
                                        >
                                            @icon('file-edit', 'w-4 h-4')
                                            {{ __('forms.edit') }}
                                        </button>
                                        <button type="button"
                                                class="flex items-center gap-2 w-full cursor-pointer px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 text-red-600 dark:text-red-400"
                                                @click="confidantPerson.documentsRelationship.splice(index, 1); openDropdown = false"
                                        >
                                            @icon('delete', 'w-4 h-4')
                                            {{ __('forms.delete') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                </template>
                </tbody>
            </table>
        </div>

        <button type="button" class="item-add" @click.prevent="resetForm(); showDocumentDrawer = true">
            {{ __('forms.add_document') }}
        </button>
    </fieldset>
</div>
