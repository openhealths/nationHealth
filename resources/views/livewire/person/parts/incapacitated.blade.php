@use('App\Livewire\Person\PersonUpdate')

<fieldset class="fieldset"
          data-fieldset="incapacitated"
          x-data="{
              isIncapacitated: $wire.entangle('isIncapacitated'),
              showSignatureModal: $wire.showSignatureModal,
              showConfidantPersonDrawer: @if($this instanceof PersonUpdate) $wire.entangle('showConfidantPersonDrawer') @else false @endif,
              showDeactivateConfidantPersonDrawer: $wire.entangle('showDeactivateConfidantPersonDrawer'),
              showDocumentDrawer: false,
              showAuthDrawer: @if($this instanceof PersonUpdate) $wire.entangle('showAuthDrawer') @else false @endif,
              showSignatureDrawer: @if($this instanceof PersonUpdate) $wire.entangle('showSignatureDrawer') @else false @endif,
              showTerminateModal: @if($this instanceof PersonUpdate) $wire.entangle('showTerminateModal') @else false @endif,
              deactivateDocIndex: null,
              selectedPatient: null,
              confidantPerson: @if($this instanceof PersonUpdate) $wire.entangle('newConfidantPerson') @else $wire.entangle('selectedConfidantPersonData') ?? {} @endif,
              confidantPersons: @if($this instanceof PersonUpdate) $wire.entangle('form.person.confidantPersons') @else [] @endif,
              authenticationMethods: @if($this instanceof PersonUpdate) $wire.entangle('form.person.authenticationMethods') @else [] @endif,
              selectedConfidantIndex: null,
              documentRelationshipTypes: @js($this->dictionaries['DOCUMENT_RELATIONSHIP_TYPE']),
              documentTypes: @js($this->dictionaries['DOCUMENT_TYPE']),
              phoneTypes: @js($this->dictionaries['PHONE_TYPE']),
              newDocument: {
                  type: '',
                  typeLabel: '',
                  number: '',
                  issuedBy: '',
                  issuedAt: '',
                  expiryDate: ''
              },
              verificationCode: '',
              resetSearchFilters() {
                  this.selectedPatient = null;
                  $wire.form.firstName = '';
                  $wire.form.lastName = '';
                  $wire.form.birthDate = '';
                  $wire.form.secondName = '';
                  $wire.form.taxId = '';
                  $wire.form.phoneNumber = '';
                  $wire.form.birthCertificate = '';
                  $wire.confidantPerson = [];
              },
              timer: 60,
              isEditing: false,
              editingIndex: null,
              isEditingLegalRep: false,
              editingLegalRepIndex: null,

              addNewConfidant() {
                  if (this.newDocument.type && this.newDocument.number && this.newDocument.issuedBy && this.newDocument.issuedAt) {
                      // Initialize documentsRelationship array if it doesn't exist
                      if (!this.confidantPerson.documentsRelationship) {
                          this.confidantPerson.documentsRelationship = [];
                      }

                      const documentData = {
                          type: this.newDocument.type,
                          number: this.newDocument.number,
                          issuedBy: this.newDocument.issuedBy,
                          issuedAt: this.newDocument.issuedAt,
                          activeTo: this.newDocument.expiryDate
                      };

                      if (this.isEditing && this.editingIndex !== null) {
                          // Update existing document
                          this.confidantPerson.documentsRelationship[this.editingIndex] = documentData;
                          this.isEditing = false;
                          this.editingIndex = null;
                      } else {
                          // Add new document
                          this.confidantPerson.documentsRelationship.push(documentData);
                      }

                      // Reset form
                      this.newDocument = {
                          type: '',
                          typeLabel: '',
                          number: '',
                          issuedBy: '',
                          issuedAt: '',
                          expiryDate: ''
                      };

                      // Trigger entanglement by reassigning the whole object
                      this.confidantPerson = { ...this.confidantPerson };
                  }
              },

              editDocument(index) {
                  if (!this.confidantPerson || !this.confidantPerson.documentsRelationship) return;

                  const doc = this.confidantPerson.documentsRelationship[index];
                  this.newDocument.type = doc.type;
                  this.newDocument.typeLabel = doc.type;
                  this.newDocument.number = doc.number;
                  this.newDocument.issuedBy = doc.issuedBy;
                  this.newDocument.issuedAt = doc.issuedAt;
                  this.newDocument.expiryDate = doc.activeTo || '';
                  this.isEditing = true;
                  this.editingIndex = index;
                  this.showDocumentDrawer = true;
              },

              editLegalRepresentative(index) {
                  this.isEditingLegalRep = true;
                  this.editingLegalRepIndex = index;
                  this.showConfidantPersonDrawer = true;
              },

              saveConfidantPerson() {
                  if (this.isEditingLegalRep && this.editingLegalRepIndex !== null && this.selectedPatient) {
                      this.showConfidantPersonDrawer = false;
                      this.isEditingLegalRep = false;
                      this.editingLegalRepIndex = null;
                  }
              },

              resetForm() {
                  this.selectedPatient = null;
                  this.newDocument.type = '';
                  this.newDocument.typeLabel = '';
                  this.newDocument.number = '';
                  this.newDocument.issuedBy = '';
                  this.newDocument.issuedAt = '';
                  this.newDocument.expiryDate = '';
                  this.isEditing = false;
                  this.editingIndex = null;
                  this.isEditingLegalRep = false;
                  this.editingLegalRepIndex = null;
                  this.showDocumentDrawer = false;
              },

              addConfidantPersonToForm() {
                  if (this.confidantPerson && this.confidantPerson.documentsRelationship && this.confidantPerson.documentsRelationship.length > 0) {
                      // Update the form data directly without backend request
                      this.$wire.form.person.confidantPerson.documentsRelationship = this.confidantPerson.documentsRelationship;
                  }
              }
          }"
>
    <legend class="legend flex items-baseline gap-2">
        <input type="checkbox"
               class="default-checkbox mb-2"
               x-model="isIncapacitated"
               id="isIncapacitated"
        />
        <label for="isIncapacitated" class="cursor-pointer">
            {{ __('patients.incapacitated') }}
        </label>
    </legend>

    <div x-show="isIncapacitated" x-cloak x-transition>
        {{-- Legal Representatives Section --}}
        <div class="mb-6">
            <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                    {{ __('patients.confidant_persons') }}
                </h3>

                @if($this instanceof PersonUpdate)
                    <button type="button" class="button-sync" wire:click.prevent="syncConfidantPersons">
                        @icon('refresh', 'w-4 h-4 mr-2')
                        {{ __('patients.sync_confidant_persons') }}
                    </button>
                @endif
            </div>

            {{-- Combined table for both PersonUpdate and PersonCreate --}}
            <div wire:ignore
                 x-show="@if($this instanceof PersonUpdate) confidantPersons && confidantPersons.length > 0 @else confidantPerson && confidantPerson.documentsRelationship && confidantPerson.documentsRelationship.length > 0 @endif"
            >
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

                    @if($this instanceof PersonUpdate)
                        {{-- PersonUpdate: Multiple confidant persons --}}
                        <template x-for="(confidantPerson, confidantIndex) in confidantPersons"
                                  :key="'confidant-' + confidantIndex"
                        >
                            <tr>
                                {{-- Personal Data --}}
                                <td class="td-input align-top">
                                    <div class="font-bold text-gray-900 dark:text-white">
                                        <span x-text="confidantPerson.person.name || (confidantPerson.person.lastName + ' ' + confidantPerson.person.firstName + ' ' + (confidantPerson.person.secondName || ''))"
                                        ></span>
                                    </div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                    <span x-text="(confidantPerson.person?.gender) === 'MALE' ? '{{ __('patients.male') }}' : '{{ __('patients.female') }}'"></span>
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
                                {{-- Document --}}
                                <td class="td-input align-top">
                                    <div class="space-y-2">
                                        <template :key="'person-doc-' + confidantIndex + '-' + documentIndex"
                                                  x-for="(document, documentIndex) in (confidantPerson?.person?.documents)"
                                        >
                                            <div class="border-b border-gray-200 dark:border-gray-600 pb-2 last:border-b-0 last:pb-0">
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
                                {{-- Phone --}}
                                <td class="td-input align-top">
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
                                {{-- Relationship Active Until --}}
                                <td class="td-input align-top">
                                    <div class="text-gray-900 dark:text-white"
                                         x-text="confidantPerson.activeTo || '-'"
                                    ></div>
                                </td>
                                {{-- Relationship Confirmation Documents --}}
                                <td class="td-input align-top">
                                    <div class="space-y-2">
                                        <template :key="'relationship-doc-' + confidantIndex + '-' + docIndex"
                                                  x-for="(doc, docIndex) in (confidantPerson.documentsRelationship || [])"
                                        >
                                            <div class="border-b border-gray-200 dark:border-gray-600 pb-2 last:border-b-0 last:pb-0">
                                                <div class="text-gray-900 dark:text-white font-medium"
                                                     x-text="documentRelationshipTypes[doc.type] || doc.type"
                                                ></div>
                                                <div class="text-sm text-gray-500 dark:text-gray-400"
                                                     x-text="doc.number"
                                                ></div>
                                            </div>
                                        </template>
                                    </div>
                                </td>
                                {{-- Action --}}
                                <td class="td-input text-center align-top">
                                    <div class="relative"
                                         x-data="{ openDropdown: false }"
                                         @click.outside="openDropdown = false"
                                    >
                                        <button @click="openDropdown = !openDropdown"
                                                type="button"
                                                class="cursor-pointer p-1 rounded-full hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                                        >
                                            @icon('edit-user-outline', 'w-6 h-6 text-gray-800 dark:text-gray-200')
                                        </button>

                                        <div x-show="openDropdown"
                                             x-transition
                                             x-cloak
                                             class="absolute right-0 z-10 w-56 whitespace-nowrap bg-white rounded shadow-lg border border-gray-200 dark:bg-gray-700 dark:border-gray-600"
                                        >
                                            <div class="py-1">
                                                @if(!$this instanceof PersonUpdate)
                                                    <button type="button"
                                                            class="flex items-center gap-2 w-full px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 whitespace-nowrap"
                                                            @click="selectedConfidantIndex = confidantIndex; editLegalRepresentative(confidantIndex); openDropdown = false"
                                                    >
                                                        @icon('edit', 'w-4 h-4')
                                                        {{ __('forms.edit') }}
                                                    </button>
                                                @endif

                                                <template x-if="confidantPerson && confidantPerson.activeTo && new Date(confidantPerson.activeTo.split('.').reverse().join('-')) > new Date()">
                                                    <button type="button"
                                                            class="flex items-center gap-2 w-full px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 whitespace-nowrap"
                                                            @click.prevent="
                                                                selectedConfidantIndex = confidantIndex;
                                                                showDeactivateConfidantPersonDrawer = true;
                                                            "
                                                    >
                                                        @icon('close-circle', 'w-4 h-4 text-gray-600 dark:text-gray-300')
                                                        {{ __('patients.deactivate_relationship') }}
                                                    </button>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    @else
                        {{-- PersonCreate: Single confidant person --}}
                        <template x-for="(doc, docIndex) in confidantPerson.documentsRelationship"
                                  :key="'confidant-doc-' + docIndex"
                        >
                            <tr>
                                {{-- Personal Data - only show on first row --}}
                                <td class="td-input align-top" x-show="docIndex === 0">
                                    <div class="font-bold text-gray-900 dark:text-white">
                                        <span x-text="confidantPerson.lastName"></span>
                                        <span x-text="confidantPerson.firstName"></span>
                                        <span x-text="confidantPerson.secondName || ''"></span>
                                    </div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                    <span x-text="confidantPerson.gender === 'MALE' ? '{{ __('patients.male') }}' : (confidantPerson.gender === 'FEMALE' ? '{{ __('patients.female') }}' : '')"></span>
                                    </div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                        <span>{{ __('forms.rnokpp') }} </span>
                                        <span x-text="confidantPerson.taxId || '-'"></span>
                                    </div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400"
                                         x-show="confidantPerson.unzr"
                                    >
                                        <span>{{ __('patients.unzr') }} </span>
                                        <span x-text="confidantPerson.unzr || ''"></span>
                                    </div>
                                </td>
                                {{-- Document - only show on first row --}}
                                <td class="td-input align-top" x-show="docIndex === 0">
                                    <div class="space-y-2">
                                        <template :key="'person-doc-' + documentIndex"
                                                  x-for="(document, documentIndex) in (confidantPerson.documents || [])"
                                        >
                                            <div class="border-b border-gray-200 dark:border-gray-600 pb-2 last:border-b-0 last:pb-0">
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
                                {{-- Phone - only show on first row --}}
                                <td class="td-input align-top" x-show="docIndex === 0">
                                    <div x-show="!confidantPerson.phones?.length">
                                        <div class="text-gray-900 dark:text-white">-</div>
                                    </div>
                                    <template :key="'phone-' + phoneIndex"
                                              x-for="(phone, phoneIndex) in (confidantPerson.phones || [])"
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
                                    <div class="text-gray-900 dark:text-white" x-text="doc.activeTo || '-'"></div>
                                </td>
                                {{-- Relationship Confirmation Document - one per relationship document --}}
                                <td class="td-input align-top">
                                    <div class="text-gray-900 dark:text-white"
                                         x-text="documentRelationshipTypes[doc.type] || doc.type"
                                    ></div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400" x-text="doc.number"></div>
                                </td>
                                {{-- Action - one per relationship document --}}
                                <td class="td-input text-center align-top">
                                    <div class="relative"
                                         x-data="{ openDropdown: false }"
                                         @click.outside="openDropdown = false"
                                    >
                                        <button @click="openDropdown = !openDropdown"
                                                type="button"
                                                class="cursor-pointer p-1 rounded-full hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                                        >
                                            @icon('edit-user-outline', 'w-6 h-6 text-gray-800 dark:text-gray-200')
                                        </button>

                                        <div x-show="openDropdown"
                                             x-transition
                                             x-cloak
                                             class="absolute right-0 z-10 w-56 whitespace-nowrap bg-white rounded shadow-lg border border-gray-200 dark:bg-gray-700 dark:border-gray-600"
                                        >
                                            <div class="py-1">
                                                <button type="button"
                                                        class="flex items-center gap-2 w-full px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 whitespace-nowrap"
                                                        @click="editLegalRepresentative(docIndex); openDropdown = false"
                                                >
                                                    @icon('edit', 'w-4 h-4')
                                                    {{ __('forms.edit') }}
                                                </button>
                                                <button type="button"
                                                        class="flex items-center gap-2 w-full px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 text-red-600 dark:text-red-400 whitespace-nowrap"
                                                        @click="confidantPerson.documentsRelationship.splice(docIndex, 1); confidantPerson = { ...confidantPerson }; openDropdown = false"
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
                    @endif
                    </tbody>
                </table>
            </div>

            @unless(($this instanceof \App\Livewire\Person\PersonCreate || $this instanceof \App\Livewire\Person\PersonRequestEdit) && $this->selectedConfidantPersonId)
                <button type="button"
                        @click="
                            resetForm();
                            resetSearchFilters();
                            confidantPerson.documentsRelationship = [];
                            showConfidantPersonDrawer = true;
                        "
                        class="item-add my-5"
                >
                    {{ __('patients.add_confidant_person') }}
                </button>
            @endunless
        </div>

        @if($this instanceof PersonUpdate)
            @include('livewire.person.parts.drawers.confidant-person-relationship-requests')
            @include('livewire.person.parts.drawers.add-auth-verification')
            @include('livewire.person.parts.modals.terminate-relationship')
            @include('livewire.person.parts.drawers.deactivate-confidant-person')
        @endif

        @include('livewire.person.parts.drawers.add-confidant-person')
    </div>
</fieldset>
