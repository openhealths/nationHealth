@use('App\Enums\Person\AuthStep')
@use('App\Enums\Person\Gender')

<div x-data="{
         confidantPersons: $wire.entangle('form.person.confidantPersons'),
         authenticationMethods: $wire.entangle('authenticationMethods'),
         documentRelationshipTypes: @js($this->dictionaries['DOCUMENT_RELATIONSHIP_TYPE']),
         documentTypes: @js($this->dictionaries['DOCUMENT_TYPE']),
         phoneTypes: @js($this->dictionaries['PHONE_TYPE']),
         get availableConfidantPersons() {
             // Filter out confidant persons who already have authentication methods
             const existingAuthPersonUuids = this.authenticationMethods
                 .filter(method => method.type === 'THIRD_PERSON')
                 .map(method => method.value);

             return this.confidantPersons.filter(confidantPerson =>
                 !existingAuthPersonUuids.includes(confidantPerson.person.uuid)
             );
         }
     }"
>
    <legend class="legend">
        {{ __('patients.add_auth_method_third_person') }}
    </legend>

    <table class="table-input w-full">
        <thead class="thead-input">
        <tr>
            <th scope="col" class="th-input">{{ __('forms.personal_data') }}</th>
            <th scope="col" class="th-input">{{ __('forms.document') }}</th>
            <th scope="col" class="th-input">{{ __('forms.phone') }}</th>
            <th scope="col" class="th-input">{{ __('patients.relationship_active_to') }}</th>
            <th scope="col" class="th-input">{{ __('patients.relationship_confirmation_document') }}</th>
            <th scope="col" class="th-input text-center">{{ __('forms.action') }}</th>
        </tr>
        </thead>
        <tbody>

        {{-- Show message when no available confidant persons --}}
        <template x-if="availableConfidantPersons.length === 0">
            <tr>
                <td colspan="6" class="td-input text-center py-8">
                    <div class="text-gray-500 dark:text-gray-400">
                        <p class="text-lg font-medium mb-2">{{ __('patients.no_available_confidants') }}</p>
                        <p class="text-sm">{{ __('patients.all_confidants_have_auth') }}</p>
                    </div>
                </td>
            </tr>
        </template>

        {{-- Show only confidant persons without existing auth methods --}}
        <template x-if="availableConfidantPersons.length > 0">
            <template x-for="(confidantPerson, confidantIndex) in availableConfidantPersons"
                      :key="'confidant-' + confidantIndex"
            >
                <template x-for="(doc, docIndex) in confidantPerson.documentsRelationship"
                          :key="'confidant-' + confidantIndex + '-doc-' + docIndex"
                >
                    <tr>
                        {{-- Personal Data - only show on first row for each confidant --}}
                        <td class="td-input align-top" x-show="docIndex === 0">
                            <div class="font-bold text-gray-900 dark:text-white">
                            <span
                                x-text="confidantPerson.person.name || (confidantPerson.person.lastName + ' ' + confidantPerson.person.firstName + ' ' + (confidantPerson.person.secondName || ''))"
                            ></span>
                            </div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">
                            <span
                                x-text="confidantPerson.person?.gender === '{{ Gender::MALE->value }}' ? '{{ Gender::MALE->label() }}' : (confidantPerson.person?.gender === '{{ Gender::FEMALE->value }}' ? '{{ Gender::FEMALE->label() }}' : '')"
                            ></span>
                            </div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                <span>{{ __('forms.rnokpp') }} </span>
                                <span x-text="confidantPerson.person?.taxId || '-'"></span>
                            </div>
                            <div class="text-sm text-gray-500 dark:text-gray-400"
                                 x-show="confidantPerson.person?.unzr"
                            >
                                <span>{{ __('patients.unzr') }} </span>
                                <span x-text="confidantPerson?.person?.unzr"></span>
                            </div>
                        </td>
                        {{-- Document - only show on first row for each confidant --}}
                        <td class="td-input align-top" x-show="docIndex === 0">
                            <div class="space-y-2">
                                <template :key="'person-doc-' + confidantIndex + '-' + documentIndex"
                                          x-for="(document, documentIndex) in (confidantPerson?.person?.documents)"
                                >
                                    <div
                                        class="border-b border-gray-200 dark:border-gray-600 pb-2 last:border-b-0 last:pb-0">
                                        <div class="text-gray-900 dark:text-white font-medium"
                                             x-text="documentTypes[document.type] || document.type"
                                        ></div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400"
                                             x-text="document.number"
                                        ></div>
                                    </div>
                                </template>
                            </div>
                        </td>
                        {{-- Phone - only show on first row for each confidant --}}
                        <td class="td-input align-top" x-show="docIndex === 0">
                            <div x-show="!confidantPerson.person?.phones?.length">
                                <div class="text-gray-900 dark:text-white">-</div>
                            </div>
                            <template :key="'phone-' + confidantIndex + '-' + phoneIndex"
                                      x-for="(phone, phoneIndex) in (confidantPerson?.person?.phones || [])"
                            >
                                <div>
                                    <div class="text-gray-900 dark:text-white"
                                         x-text="phoneTypes[phone.type] || '-'"
                                    ></div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400"
                                         x-text="phone.number || '-'"
                                    ></div>
                                </div>
                            </template>
                        </td>
                        {{-- Relationship Active Until - one per relationship document --}}
                        <td class="td-input align-top">
                            <div class="text-gray-900 dark:text-white"
                                 x-text="confidantPerson.activeTo || '-'"
                            ></div>
                        </td>
                        {{-- Relationship Confirmation Document - one per relationship document --}}
                        <td class="td-input align-top">
                            <div class="text-gray-900 dark:text-white"
                                 x-text="documentRelationshipTypes[doc.type] || doc.type"
                            ></div>
                            <div class="text-sm text-gray-500 dark:text-gray-400" x-text="doc.number"></div>
                        </td>
                        {{-- Action - only show on first row for each confidant --}}
                        <td class="td-input align-top text-center" x-show="docIndex === 0">
                            <button type="button"
                                    @click="$wire.chooseConfidantFromRelation(confidantPerson.person.uuid)"
                                    class="button-primary text-sm"
                            >
                                {{ __('forms.select') }}
                            </button>
                        </td>
                    </tr>
                </template>
            </template>
        </template>
        </tbody>
    </table>

    <div class="mt-12 flex gap-3">
        <button type="button" @click="localStep = {{ AuthStep::INITIAL }}" class="button-minor">
            {{ __('forms.back') }}
        </button>

        <button type="button" @click="showAuthMethodModal = false" class="button-outline-primary">
            {{ __('patients.new_confidant_person') }}
        </button>
    </div>
</div>
