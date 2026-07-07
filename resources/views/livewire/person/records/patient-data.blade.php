@use('App\Models\Person')
@use('App\Enums\Person\AuthenticationMethod')
@use('App\Enums\Person\VerificationStatus as Status')

<x-layouts.patient :personId="$personId" :patientFullName="$patientFullName" :activeTab="'patient-data'">
    <x-slot name="headerActions">
        <div class="flex flex-wrap items-center gap-2">
            @can('create', Encounter::class)
                <a href="{{ route('encounter.create', [legalEntity(), 'person' => $personId]) }}"
                   class="flex items-center gap-2 button-primary px-4 py-2 text-sm shadow-sm"
                >
                    @icon('plus', 'w-4 h-4')
                    {{ __('patients.starts_interacting') }}
                </a>
            @endcan

            <button type="button" class="button-primary-outline px-4 py-2 text-sm shadow-sm"
                    style="margin: 0 !important;">
                {{ __('patients.data_access') }}
            </button>

            <button type="button" class="button-sync px-4 py-2 text-sm shadow-sm flex items-center gap-2"
                    style="margin: 0 !important;">
                @icon('refresh', 'w-4 h-4')
                <span>{{ __('forms.synchronise_with_eHealth') }}</span>
            </button>



        </div>
    </x-slot>

    @php
        $patientModelForRelations = \App\Models\Person\Person::with(['addresses', 'documents'])->find($personId);
        $patientAddresses = $patientModelForRelations ? $patientModelForRelations->addresses : collect();
        $patientDocuments = $patientModelForRelations ? $patientModelForRelations->documents : collect();
        $address = collect($patientAddresses)->firstWhere('type', 'RESIDENCE') ?: collect($patientAddresses)->first();
        $address = $address ? \App\Core\Arr::toCamelCase($address->toArray()) : [];
        $documentsList = $patientDocuments ? \App\Core\Arr::toCamelCase($patientDocuments->toArray()) : [];

        $verificationStatusEnum = Status::from($verificationStatus);
    @endphp

    <div class="breadcrumb-form px-4 pt-4 pb-10 shift-content"
         x-data="{
             resetForm() {
                 confidantPerson.documentsRelationship = [];
             },
             resetSearchFilters() {
                 $wire.form.firstName = '';
                 $wire.form.lastName = '';
                 $wire.form.birthDate = '';
                 $wire.form.secondName = '';
                 $wire.form.taxId = '';
                 $wire.form.phoneNumber = '';
                 $wire.form.birthCertificate = '';
             },
             showConfidantPersonDrawer: $wire.entangle('showConfidantPersonDrawer'),
             showDeactivateConfidantPersonDrawer: @if($canManageConfidantRelationships) $wire.entangle('showDeactivateConfidantPersonDrawer') @else false @endif,
             showDocumentDrawer: false,
             showAuthDrawer: @if($canManageConfidantRelationships) $wire.entangle('showAuthDrawer') @else false @endif,
             showSignatureDrawer: @if($canManageConfidantRelationships) $wire.entangle('showSignatureDrawer') @else false @endif,
             showTerminateModal: @if($canManageConfidantRelationships) $wire.entangle('showTerminateModal') @else false @endif,
             deactivateDocIndex: null,
             selectedPatient: null,
             confidantPerson: $wire.entangle('newConfidantPerson'),
             confidantPersons: @if($canManageConfidantRelationships) $wire.entangle('confidantPersonRelationships') @else [] @endif,
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
             isEditing: false,
             editingIndex: null,
             isEditingLegalRep: false,
             editingLegalRepIndex: null,
             addNewConfidant() {
                 if (this.newDocument.type && this.newDocument.number && this.newDocument.issuedBy && this.newDocument.issuedAt) {
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
                         this.confidantPerson.documentsRelationship[this.editingIndex] = documentData;
                         this.isEditing = false;
                         this.editingIndex = null;
                     } else {
                         this.confidantPerson.documentsRelationship.push(documentData);
                     }

                     this.newDocument = {
                         type: '',
                         typeLabel: '',
                         number: '',
                         issuedBy: '',
                         issuedAt: '',
                         expiryDate: ''
                     };

                     this.confidantPerson = { ...this.confidantPerson };
                 }
             }
         }"
    >
        <div class="flex items-center gap-4 mb-4">
            @php $verificationStatusEnum = Status::from($verificationStatus); @endphp
            <p class="text-sm text-gray-700 dark:text-gray-300">
                <span class="font-medium">{{ __('patients.verification_in_eHealth') }}:</span>
                <span class="ml-1 {{ $verificationStatusEnum->color() }}">{{ $verificationStatusEnum->label() }}</span>
            </p>

            <button
                wire:click.once="getVerificationStatus"
                type="button"
                class="inline-flex items-center gap-2 px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 cursor-pointer"
            >
                {{ __('patients.update_status') }}
                @icon('refresh', 'w-4 h-4')
            </button>
        </div>

        <div id="accordion-open" data-accordion="open" class="flex flex-col gap-4">

            <div x-data="{ open: true }"
                 class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
                <h2>
                    <button
                        type="button"
                        class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                        @click="open = !open"
                        :aria-expanded="open"
                    >
                            <span class="text-base font-semibold text-gray-900 dark:text-white">
                                {{ __('patients.passport_data') }}
                            </span>
                        @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
                    </button>
                </h2>
                <div x-show="open" wire:ignore.self>
                    <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700 pt-6">
                        <div class="flex items-center justify-end mb-6">
                            <button
                                type="button"
                                class="flex items-center gap-1.5 text-blue-600 hover:text-blue-800 font-medium text-sm cursor-pointer" style="margin: 0 !important;"
                                wire:click.once="syncPersonDataFromEHealth()"
                            >
                                @icon('refresh', 'w-4 h-4')
                                <span>{{ $isSyncing ? __('forms.sync_retry') : __('patients.sync_personal_data') }}</span>
                            </button>
                        </div>

                        <div class="form-row-3">
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly
                                       value="{{ $form->person['lastName'] ?? '-' }}" />
                                <label class="label">{{ __('forms.last_name') }}</label>
                            </div>
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly
                                       value="{{ $form->person['firstName'] ?? '-' }}" />
                                <label class="label">{{ __('forms.first_name') }}</label>
                            </div>
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly
                                       value="{{ $form->person['secondName'] ?? '-' }}" />
                                <label class="label">{{ __('forms.second_name') }}</label>
                            </div>
                        </div>

                        <div class="form-row-3">
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly
                                       value="{{ !empty($form->person['birthDate']) ? \Carbon\Carbon::parse($form->person['birthDate'])->format('d.m.Y') : '-' }}" />
                                <label class="label">{{ __('forms.birth_date') }}</label>
                            </div>
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly
                                       value="{{ $form->person['birthSettlement'] ?? '-' }}" />
                                <label class="label">{{ __('forms.birth_settlement') }}</label>
                            </div>
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly
                                       value="{{ $form->person['birthCountry'] ?? '-' }}" />
                                <label class="label">{{ __('forms.birth_country') }}</label>
                            </div>
                        </div>

                        <div class="form-row-3">
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly
                                       value="{{ ($form->person['gender'] ?? '') === 'FEMALE' ? __('patients.female') : (($form->person['gender'] ?? '') === 'MALE' ? __('patients.male') : '-') }}" />
                                <label class="label">{{ __('forms.gender') }}</label>
                            </div>
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly
                                       value="{{ $form->person['taxId'] ?? '-' }}" />
                                <label class="label">{{ __('forms.rnokpp') }}</label>
                            </div>
                        </div>


                        <div class="mt-8">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">{{ __('patients.person_documents') }}</h3>
                            <table class="table-input w-full">
                                <thead class="thead-input">
                                <tr>
                                    <th scope="col" class="th-input">{{ __('forms.document_type') }}</th>
                                    <th scope="col" class="th-input">{{ __('forms.number') }}</th>
                                    <th scope="col" class="th-input">{{ __('forms.issued_by') }}</th>
                                    <th scope="col" class="th-input">{{ __('forms.issued_at') }}</th>
                                    <th scope="col" class="th-input">{{ __('forms.valid_until') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @if(!empty($documentsList))
                                    @foreach($documentsList as $document)
                                        <tr>
                                            <td class="td-input text-gray-900 dark:text-white font-medium">
                                                {{ $dictionaries['DOCUMENT_TYPE'][$document['type']] ?? $document['type'] }}
                                            </td>
                                            <td class="td-input text-gray-700 dark:text-gray-300">{{ $document['number'] ?? '-' }}</td>
                                            <td class="td-input text-gray-700 dark:text-gray-300">{{ $document['issuedBy'] ?? '-' }}</td>
                                            <td class="td-input text-gray-700 dark:text-gray-300">
                                                {{ !empty($document['issuedAt']) ? \Carbon\Carbon::parse($document['issuedAt'])->format('d.m.Y') : '-' }}
                                            </td>
                                            <td class="td-input text-gray-700 dark:text-gray-300">
                                                {{ !empty($document['expirationDate']) ? \Carbon\Carbon::parse($document['expirationDate'])->format('d.m.Y') : '-' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                @else
                                    <tr>
                                        <td colspan="5"
                                            class="td-input text-center text-gray-500 py-4">{{ __('patients.no_documents') }}</td>
                                    </tr>
                                @endif
                                </tbody>
                            </table>
                        </div>


                        <div class="mt-8">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white mb-4">{{ __('forms.address') }}</h3>

                            <div class="form-row-3">
                                <div class="form-group group">
                                    <input type="text" class="input peer" placeholder=" " readonly
                                           value="{{ $address['area'] ?? '-' }}" />
                                    <label class="label">{{ __('forms.area') }}</label>
                                </div>
                                <div class="form-group group">
                                    <input type="text" class="input peer" placeholder=" " readonly
                                           value="{{ $address['region'] ?? '-' }}" />
                                    <label class="label">{{ __('forms.region') }}</label>
                                </div>
                                <div class="form-group group">
                                    <input type="text" class="input peer" placeholder=" " readonly
                                           value="{{ $dictionaries['SETTLEMENT_TYPE'][$address['settlementType'] ?? ''] ?? ($address['settlementType'] ?? '-') }}" />
                                    <label class="label">{{ __('forms.settlement_type') }}</label>
                                </div>
                            </div>

                            <div class="form-row-3">
                                <div class="form-group group">
                                    <input type="text" class="input peer" placeholder=" " readonly
                                           value="{{ $address['settlement'] ?? '-' }}" />
                                    <label class="label">{{ __('forms.settlement') }}</label>
                                </div>
                                <div class="form-group group">
                                    <input type="text" class="input peer" placeholder=" " readonly
                                           value="{{ $dictionaries['STREET_TYPE'][$address['streetType'] ?? ''] ?? ($address['streetType'] ?? '-') }}" />
                                    <label class="label">{{ __('forms.street_type') }}</label>
                                </div>
                                <div class="form-group group">
                                    <input type="text" class="input peer" placeholder=" " readonly
                                           value="{{ $address['street'] ?? '-' }}" />
                                    <label class="label">{{ __('forms.street') }}</label>
                                </div>
                            </div>

                            <div class="form-row-3">
                                <div class="form-group group">
                                    <input type="text" class="input peer" placeholder=" " readonly
                                           value="{{ $address['building'] ?? '-' }}" />
                                    <label class="label">{{ __('forms.building') }}</label>
                                </div>
                                <div class="form-group group">
                                    <input type="text" class="input peer" placeholder=" " readonly
                                           value="{{ $address['apartment'] ?? '-' }}" />
                                    <label class="label">{{ __('forms.apartment') }}</label>
                                </div>
                                <div class="form-group group">
                                    <input type="text" class="input peer" placeholder=" " readonly
                                           value="{{ $address['zip'] ?? '-' }}" />
                                    <label class="label">{{ __('forms.zip_code') }}</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div x-data="{ open: true }"
                 class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
                <h2>
                    <button
                        type="button"
                        class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                        @click="open = !open"
                        :aria-expanded="open"
                    >
                            <span class="text-base font-semibold text-gray-900 dark:text-white">
                                {{ __('patients.contact_data') }}
                            </span>
                        @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
                    </button>
                </h2>
                <div x-show="open" wire:ignore.self>
                    <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700 pt-6">
                        <div class="flex items-center justify-end mb-6">
                            <button
                                type="button"
                                class="flex items-center gap-1.5 text-blue-600 hover:text-blue-800 font-medium text-sm cursor-pointer" style="margin: 0 !important;"
                                wire:click.once="syncPersonDataFromEHealth()"
                            >
                                @icon('refresh', 'w-4 h-4')
                                <span>{{ $isSyncing ? __('forms.sync_retry') : __('patients.sync_personal_data') }}</span>
                            </button>
                        </div>

                        @php
                            $phonesList = $form->person['phones'] ?? [];
                            $firstPhone = $phonesList[0] ?? null;
                            $otherPhones = array_slice($phonesList, 1);
                        @endphp

                        <div class="form-row-3">
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly
                                       value="{{ $firstPhone ? ($dictionaries['PHONE_TYPE'][$firstPhone['type']] ?? $firstPhone['type']) : '-' }}" />
                                <label class="label">{{ __('forms.phone_type') }}</label>
                            </div>
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly
                                       value="{{ $firstPhone['number'] ?? '-' }}" />
                                <label class="label">{{ __('forms.phone') }}</label>
                            </div>
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly
                                       value="{{ $form->person['email'] ?? '-' }}" />
                                <label class="label">{{ __('patients.email_address') }}</label>
                            </div>
                        </div>

                        @foreach($otherPhones as $phone)
                            <div class="form-row-3">
                                <div class="form-group group">
                                    <input type="text" class="input peer" placeholder=" " readonly
                                           value="{{ $dictionaries['PHONE_TYPE'][$phone['type']] ?? $phone['type'] }}" />
                                    <label class="label">{{ __('forms.phone_type') }}</label>
                                </div>
                                <div class="form-group group">
                                    <input type="text" class="input peer" placeholder=" " readonly
                                           value="{{ $phone['number'] ?? '-' }}" />
                                    <label class="label">{{ __('forms.phone') }}</label>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div x-data="{ open: true }"
                 class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
                <h2>
                    <button
                        type="button"
                        class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                        @click="open = !open"
                        :aria-expanded="open"
                    >
                            <span class="text-base font-semibold text-gray-900 dark:text-white">
                                {{ __('patients.emergency_contact') }}
                            </span>
                        @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
                    </button>
                </h2>
                <div x-show="open" wire:ignore.self>
                    <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700 pt-6">
                        <div class="flex items-center justify-end mb-6">
                            <button
                                type="button"
                                class="flex items-center gap-1.5 text-blue-600 hover:text-blue-800 font-medium text-sm cursor-pointer" style="margin: 0 !important;"
                                wire:click.once="syncPersonDataFromEHealth()"
                            >
                                @icon('refresh', 'w-4 h-4')
                                <span>{{ $isSyncing ? __('forms.sync_retry') : __('patients.sync_personal_data') }}</span>
                            </button>
                        </div>

                        @php
                            $emergency = $form->person['emergencyContact'] ?? [];
                            $emergencyPhones = $emergency['phones'] ?? [];
                            $firstEmergencyPhone = $emergencyPhones[0] ?? null;
                        @endphp

                        <div class="form-row-3">
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly
                                       value="{{ $emergency['lastName'] ?? '-' }}" />
                                <label class="label">{{ __('forms.last_name') }}</label>
                            </div>
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly
                                       value="{{ $emergency['firstName'] ?? '-' }}" />
                                <label class="label">{{ __('forms.first_name') }}</label>
                            </div>
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly
                                       value="{{ $emergency['secondName'] ?? '-' }}" />
                                <label class="label">{{ __('forms.second_name') }}</label>
                            </div>
                        </div>

                        <div class="form-row-3">
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly
                                       value="{{ $firstEmergencyPhone ? ($dictionaries['PHONE_TYPE'][$firstEmergencyPhone['type']] ?? $firstEmergencyPhone['type']) : '-' }}" />
                                <label class="label">{{ __('forms.phone_type') }}</label>
                            </div>
                            <div class="form-group group">
                                <input type="text" class="input peer" placeholder=" " readonly
                                       value="{{ $firstEmergencyPhone['number'] ?? '-' }}" />
                                <label class="label">{{ __('forms.phone') }}</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div x-data="{ open: true }"
                 class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl overflow-hidden shadow-sm">
                <h2>
                    <button
                        wire:click.once="getConfidantPersons"
                        type="button"
                        class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                        @click="open = !open"
                        :aria-expanded="open"
                    >
                            <span class="text-base font-semibold text-gray-900 dark:text-white">
                                {{ __('patients.patient_legal_representative') }}
                            </span>
                        @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
                    </button>
                </h2>
                <div x-show="open" wire:ignore.self>
                    <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700 pt-6">
                        <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                {{ __('patients.confidant_persons') }}
                            </h3>
                            <button type="button"
                                    class="flex items-center gap-1.5 text-blue-600 hover:text-blue-800 font-medium text-sm cursor-pointer"
                                    wire:click.prevent="syncConfidantPersons" style="margin: 0 !important;">
                                @icon('refresh', 'w-4 h-4')
                                <span>{{ __('patients.sync_legal_representatives') }}</span>
                            </button>
                        </div>

                        <table class="table-input w-full">
                            <thead class="thead-input">
                            <tr>
                                <th scope="col" class="th-input">{{ __('forms.personal_data') }}</th>
                                <th scope="col" class="th-input">{{ __('forms.document') }}</th>
                                <th scope="col" class="th-input">{{ __('forms.phone') }}</th>
                                <th scope="col" class="th-input">{{ __('patients.relationship_active_to') }}</th>
                                <th scope="col"
                                    class="th-input">{{ __('patients.relationship_confirmation_document') }}</th>
                                <th scope="col" class="th-input">{{ __('forms.status.label') }}</th>
                                <th scope="col" class="th-input text-center">{{ __('forms.action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @if(!empty($confidantPersonRelationships))
                                @foreach($confidantPersonRelationships as $key => $relationship)
                                    @php
                                        $cp = $relationship['confidantPerson'] ?? [];
                                        $cpPhones = $cp['phones'] ?? [];
                                        $firstCpPhone = $cpPhones[0] ?? null;
                                    @endphp
                                    <tr>
                                        <td class="td-input align-top text-gray-900 dark:text-white font-medium">
                                            <div>{{ $cp['lastName'] ?? '' }} {{ $cp['firstName'] ?? '' }} {{ $cp['secondName'] ?? '' }}</div>
                                            <div class="text-xs text-gray-500 font-normal mt-1">
                                                {{ __('forms.gender') }}
                                                : {{ ($cp['gender'] ?? '') === 'MALE' ? __('patients.male') : (($cp['gender'] ?? '') === 'FEMALE' ? __('patients.female') : '-') }}
                                            </div>
                                            <div class="text-xs text-gray-500 font-normal">
                                                {{ __('forms.rnokpp') }}: {{ $cp['taxId'] ?? '-' }}
                                            </div>
                                            @if(!empty($cp['unzr']))
                                                <div class="text-xs text-gray-500 font-normal">
                                                    {{ __('patients.unzr') }}: {{ $cp['unzr'] }}
                                                </div>
                                            @endif
                                        </td>
                                        <td class="td-input align-top">
                                            @if(!empty($cp['documents']))
                                                @foreach($cp['documents'] as $doc)
                                                    <div class="mb-1">
                                                        <span
                                                            class="font-medium">{{ $dictionaries['DOCUMENT_TYPE'][$doc['type']] ?? $doc['type'] }}</span><br />
                                                        <span
                                                            class="text-xs text-gray-500">{{ $doc['number'] ?? '' }}</span>
                                                    </div>
                                                @endforeach
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td class="td-input align-top">
                                            @if($firstCpPhone)
                                                <span
                                                    class="font-medium">{{ $dictionaries['PHONE_TYPE'][$firstCpPhone['type']] ?? $firstCpPhone['type'] }}</span>
                                                <br />
                                                <span
                                                    class="text-xs text-gray-500">{{ $firstCpPhone['number'] ?? '' }}</span>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td class="td-input align-top text-gray-900 dark:text-white font-medium">
                                            {{ !empty($relationship['activeTo']) ? \Carbon\Carbon::parse($relationship['activeTo'])->format('d.m.Y') : '-' }}
                                        </td>
                                        <td class="td-input align-top">
                                            @if(!empty($relationship['documentsRelationship']))
                                                @foreach($relationship['documentsRelationship'] as $docRel)
                                                    <div class="mb-1">
                                                        <span
                                                            class="font-medium">{{ $dictionaries['DOCUMENT_RELATIONSHIP_TYPE'][$docRel['type']] ?? $docRel['type'] }}</span><br />
                                                        <span
                                                            class="text-xs text-gray-500">{{ $docRel['number'] ?? '' }}</span>
                                                    </div>
                                                @endforeach
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td class="td-input align-top">
                                            @php
                                                $isActive = empty($relationship['activeTo']) || \Carbon\Carbon::parse($relationship['activeTo'])->isFuture();
                                            @endphp
                                            @if($isActive)
                                                <span
                                                    class="px-2 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded-full dark:bg-green-900 dark:text-green-200">
                                                                {{ __('patients.active_status') }}
                                                            </span>
                                            @else
                                                <span
                                                    class="px-2 py-1 text-xs font-semibold text-red-800 bg-red-100 rounded-full dark:bg-red-900 dark:text-red-200">
                                                                {{ __('patients.inactive_status') }}
                                                            </span>
                                            @endif
                                        </td>
                                        <td class="td-input text-center align-top">
                                            <div class="relative" x-data="{ openDropdown: false }"
                                                 @click.outside="openDropdown = false">
                                                <button @click="openDropdown = !openDropdown" type="button"
                                                        class="cursor-pointer p-1 rounded-full hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                                    @icon('edit-user-outline', 'w-6 h-6 text-gray-800 dark:text-gray-200')
                                                </button>
                                                <div x-show="openDropdown" x-transition x-cloak
                                                     class="absolute right-0 z-10 w-56 whitespace-nowrap bg-white rounded shadow-lg border border-gray-200 dark:bg-gray-700 dark:border-gray-600">
                                                    <div class="py-1">
                                                        @php
                                                            $isActiveVal = empty($relationship['activeTo']) || \Carbon\Carbon::parse($relationship['activeTo'])->isFuture();
                                                        @endphp
                                                        @if($isActiveVal)
                                                            <button type="button"
                                                                    class="flex items-center gap-2 w-full px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 whitespace-nowrap text-left"
                                                                    @click.prevent="
                                                                        $wire.selectedConfidantPersonId = '{{ $cp['uuid'] ?? '' }}';
                                                                        selectedConfidantIndex = '{{ $key }}';
                                                                        showDeactivateConfidantPersonDrawer = true;
                                                                        openDropdown = false;
                                                                    "
                                                            >
                                                                @icon('close-circle', 'w-4 h-4 text-gray-600 dark:text-gray-300')
                                                                {{ __('patients.deactivate_relationship') }}
                                                            </button>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            @else
                                <tr>
                                    <td colspan="7"
                                        class="td-input text-center text-gray-500 py-4">{{ __('patients.no_confidant_persons') }}</td>
                                </tr>
                            @endif
                            </tbody>
                        </table>

                        <button type="button"
                                @click="
                                    resetForm();
                                    resetSearchFilters();
                                    confidantPerson.documentsRelationship = [];
                                    showConfidantPersonDrawer = true;
                                "
                                class="text-blue-600 hover:text-blue-800 font-medium text-sm flex items-center gap-1.5 cursor-pointer mt-4"
                        >
                            @icon('plus', 'w-4 h-4')
                            <span>{{ __('patients.add_confidant_person') }}</span>
                        </button>
                    </div>

                    <div class="mt-8 border-t border-gray-100 dark:border-gray-700 pt-6 px-6 pb-6">
                        <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                {{ __('patients.confidant_relationship_requests') }}
                            </h3>
                            <button wire:click.prevent="syncConfidantPersonRelationshipRequestsList" type="button"
                                    class="flex items-center gap-1.5 text-blue-600 hover:text-blue-800 font-medium text-sm cursor-pointer"
                                    style="margin: 0 !important;">
                                @icon('refresh', 'w-4 h-4')
                                <span>{{ __('patients.sync_requests') }}</span>
                            </button>
                        </div>

                        <table class="table-input w-full">
                            <thead class="thead-input">
                            <tr>
                                <th scope="col" class="th-input">{{ __('ID') }}</th>
                                <th scope="col" class="th-input">{{ __('forms.status.label') }}</th>
                                <th scope="col" class="th-input">{{ __('forms.action') }}</th>
                                <th scope="col" class="th-input">{{ __('patients.channel') }}</th>
                                <th scope="col" class="th-input text-center">{{ __('forms.action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @if(!empty($confidantPersonRelationshipRequests))
                                @foreach($confidantPersonRelationshipRequests as $index => $req)
                                    <tr>
                                        <td class="td-input align-top text-sm text-gray-900 dark:text-white font-medium break-all">{{ $req['uuid'] }}</td>
                                        <td class="td-input align-top">
                                            @php
                                                $statusClass = match ($req['status']) {
                                                    \App\Enums\Person\ConfidantPersonRelationshipRequestStatus::APPROVED,
                                                    \App\Enums\Person\ConfidantPersonRelationshipRequestStatus::COMPLETED => 'text-green-800 bg-green-100 dark:bg-green-900 dark:text-green-200',
                                                    \App\Enums\Person\ConfidantPersonRelationshipRequestStatus::NEW => 'text-yellow-800 bg-yellow-100 dark:bg-yellow-900 dark:text-yellow-200',
                                                    \App\Enums\Person\ConfidantPersonRelationshipRequestStatus::CANCELLED => 'text-red-800 bg-red-100 dark:bg-red-900 dark:text-red-200',
                                                    default => 'text-gray-800 bg-gray-100 dark:bg-gray-700 dark:text-gray-200',
                                                };
                                            @endphp
                                            <span
                                                class="px-2 py-1 text-xs font-semibold rounded-full {{ $statusClass }}">
                                                            {{ $req['status']?->label() ?? '-' }}
                                                        </span>
                                        </td>
                                        <td class="td-input align-top text-gray-900 dark:text-white font-medium">
                                            {{ $req['action'] === 'INSERT' ? __('patients.activate_relationship') : __('patients.deactivate_relationship') }}
                                        </td>
                                        <td class="td-input align-top text-gray-900 dark:text-white font-medium">
                                            {{ $req['channel'] === 'MIS' ? __('patients.mis_system') : $req['channel'] }}
                                        </td>
                                        <td class="td-input text-center align-top">
                                            <div class="relative" x-data="{ openRequestDropdown: false }"
                                                 @click.outside="openRequestDropdown = false">
                                                <button @click="openRequestDropdown = !openRequestDropdown"
                                                        type="button"
                                                        class="cursor-pointer p-1 rounded-full hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                                    @icon('edit-user-outline', 'w-6 h-6 text-gray-800 dark:text-gray-200')
                                                </button>

                                                <div x-show="openRequestDropdown" x-transition x-cloak
                                                     class="absolute right-0 z-10 w-44 bg-white rounded shadow-lg border border-gray-200 dark:bg-gray-700 dark:border-gray-600">
                                                    <div class="py-1">
                                                        <button type="button"
                                                                class="flex items-center gap-2 w-full px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-left"
                                                                wire:click.prevent="approveFromRequest('{{ $req['uuid'] }}')"
                                                        >
                                                            {{ __('forms.confirm') }}
                                                        </button>
                                                        <button type="button"
                                                                class="flex items-center gap-2 w-full px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 text-red-600 dark:text-red-400 text-left"
                                                                wire:click.prevent="deactivateConfidantPersonRelationshipRequest('{{ $req['uuid'] }}')"
                                                        >
                                                            {{ __('patients.cancel_request') }}
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            @else
                                <tr>
                                    <td colspan="5"
                                        class="td-input text-center text-gray-500 py-4">{{ __('patients.no_requests') }}</td>
                                </tr>
                            @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div x-data="{ open: true }"
             class="mt-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl shadow-sm">
            <h2>
                <button
                    type="button"
                    class="flex items-center justify-between w-full px-6 py-4 text-left group cursor-pointer"
                    @click="open = !open"
                    :aria-expanded="open"
                >
                            <span class="text-base font-semibold text-gray-900 dark:text-white">
                                {{ __('patients.authentication_methods') }}
                            </span>
                    @icon('chevron-down', 'w-5 h-5 text-gray-400 transition-transform group-aria-expanded:rotate-180 shrink-0')
                </button>
            </h2>
            <div x-show="open" wire:ignore.self>
                <div class="px-6 pb-6 border-t border-gray-100 dark:border-gray-700 pt-6">
                    <div class="flex items-center justify-end mb-6">
                        <button type="button"
                                class="flex items-center gap-1.5 text-blue-600 hover:text-blue-800 font-medium text-sm cursor-pointer"
                                wire:click.prevent="syncAuthMethods" style="margin: 0 !important;">
                            @icon('refresh', 'w-4 h-4')
                            <span>{{ __('patients.sync_auth_methods') }}</span>
                        </button>
                    </div>

                    @if(!empty($authenticationMethods))
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            @foreach($authenticationMethods as $methodIndex => $method)
                                <div class="border border-gray-200 dark:border-gray-700 rounded-xl p-6 relative">
                                    <div class="flex items-start justify-between mb-4">
                                        <div>
                                            <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                                @if(($method['type'] ?? '') === 'OTP')
                                                    {{ __('patients.authentication_SMS') }}
                                                @elseif(($method['type'] ?? '') === 'THIRD_PERSON')
                                                    {{ __('patients.authentication_third_person') }}
                                                @elseif(($method['type'] ?? '') === 'OFFLINE')
                                                    {{ __('patients.authentication_documents') }}
                                                @else
                                                    {{ $method['type'] ?? '' }}
                                                @endif
                                            </h3>
                                            <p class="text-xs text-gray-500 mt-1">
                                                {{ __('patients.authentication_method_name') }}: <span
                                                    class="font-medium text-gray-700 dark:text-gray-300">{{ $method['alias'] ?: '-' }}</span>
                                            </p>
                                        </div>

                                        <!-- Action Menu -->
                                        <div class="relative" x-data="{ openOptions: false }"
                                             @click.outside="openOptions = false">
                                            <button @click="openOptions = !openOptions" type="button"
                                                    class="cursor-pointer p-1.5 rounded-full hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                                                @icon('edit-user-outline', 'w-5 h-5 text-gray-600 dark:text-gray-300')
                                            </button>
                                            <div x-show="openOptions" x-transition x-cloak
                                                 class="absolute right-0 z-10 w-56 whitespace-nowrap bg-white rounded shadow-lg border border-gray-200 dark:bg-gray-700 dark:border-gray-600">
                                                <div class="py-1">
                                                    @if(($method['type'] ?? '') === 'OTP')
                                                        <button type="button"
                                                                class="flex items-center gap-2 w-full px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-left"
                                                                @click.prevent="
                                                                            $wire.selectAuthMethod('{{ $method['uuid'] }}', '{{ $method['type'] }}', 1); {{-- CHANGE_PHONE_INITIAL --}}
                                                                            $wire.showAuthMethodModal = true;
                                                                            openOptions = false;
                                                                        "
                                                        >
                                                            {{ __('patients.change_phone_number') }}
                                                        </button>
                                                    @elseif(($method['type'] ?? '') === 'OFFLINE')
                                                        <button type="button"
                                                                class="flex items-center gap-2 w-full px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-left"
                                                                @click.prevent="
                                                                    $wire.selectAuthMethod('{{ $method['uuid'] }}', '{{ $method['type'] }}', 1); {{-- CHANGE_PHONE_INITIAL --}}
                                                                    $wire.showAuthMethodModal = true;
                                                                    openOptions = false;
                                                                "
                                                        >
                                                            {{ __('patients.change_method_to_sms') }}
                                                        </button>
                                                    @endif

                                                    <button type="button"
                                                            class="flex items-center gap-2 w-full px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-left"
                                                            @click.prevent="
                                                                $wire.selectAuthMethod('{{ $method['uuid'] }}', '{{ $method['type'] }}', 8); {{-- CHANGE_ALIAS --}}
                                                                $wire.showAuthMethodModal = true;
                                                                openOptions = false;
                                                            "
                                                    >
                                                        {{ __('patients.change_method_alias') }}
                                                    </button>

                                                    @if(($method['type'] ?? '') === 'THIRD_PERSON')
                                                        <button type="button"
                                                                class="flex items-center gap-2 w-full px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 text-red-600 dark:text-red-400 text-left"
                                                                wire:click.prevent="deactivateAuthMethod('{{ $method['uuid'] }}')"
                                                                @click="openOptions = false"
                                                        >
                                                            {{ __('patients.deactivate_method') }}
                                                        </button>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    @if(($method['type'] ?? '') === 'OTP')
                                        <div class="form-group group">
                                            <input type="text" class="input peer" placeholder=" " readonly
                                                   value="{{ $method['phoneNumber'] ?? '-' }}" />
                                            <label class="label">{{ __('forms.phone_number') }}</label>
                                        </div>
                                    @elseif(($method['type'] ?? '') === 'THIRD_PERSON')
                                        <div class="space-y-4">
                                            <div class="form-group group">
                                                <input type="text" class="input peer" placeholder=" " readonly
                                                       value="{{ $method['confidantPerson']['name'] ?? '-' }}" />
                                                <label class="label">{{ __('patients.confidant_person') }}</label>
                                            </div>
                                            <div class="form-row-2">
                                                <div class="form-group group">
                                                    <input type="text" class="input peer" placeholder=" " readonly
                                                           value="{{ $method['confidantPerson']['taxId'] ?? '-' }}" />
                                                    <label class="label">{{ __('forms.rnokpp') }}</label>
                                                </div>
                                                <div class="form-group group">
                                                    <input type="text" class="input peer" placeholder=" " readonly
                                                           value="{{ $method['confidantPerson']['unzr'] ?? '-' }}" />
                                                    <label class="label">{{ __('patients.unzr') }}</label>
                                                </div>
                                            </div>
                                            @if(!empty($method['confidantPerson']['phones']))
                                                <div class="form-group group">
                                                    <input type="text" class="input peer" placeholder=" " readonly
                                                           value="{{ $method['confidantPerson']['phones']['number'] ?? '-' }}" />
                                                    <label class="label">{{ __('forms.phone') }}</label>
                                                </div>
                                            @endif
                                        </div>
                                    @elseif(($method['type'] ?? '') === 'OFFLINE')
                                        <p class="text-sm text-gray-500">{{ __('patients.offline_auth_method_description') }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-gray-500 py-4">{{ __('patients.no_auth_methods') }}</p>
                    @endif

                    <div class="relative mt-4" x-data="{ openAdd: false }" @click.outside="openAdd = false">
                        <button @click="openAdd = !openAdd" type="button"
                                class="text-blue-600 hover:text-blue-800 font-medium text-sm flex items-center gap-1.5 cursor-pointer">
                            @icon('plus', 'w-4 h-4')
                            <span>{{ __('patients.add_authentication_method') }}</span>
                        </button>

                        <div x-show="openAdd" x-transition x-cloak
                             class="absolute left-0 mt-2 z-50 w-72 bg-white rounded shadow-lg border border-gray-200 dark:bg-gray-700 dark:border-gray-600">
                            <div class="py-1">
                                <button type="button"
                                        @click="
                                                    $wire.showAuthMethodModal = true;
                                                    $wire.authStep = 10;
                                                    openAdd = false;
                                                "
                                        class="flex items-center gap-2 w-full px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-left cursor-pointer"
                                >
                                    {{ __('patients.authentication_SMS') }}
                                </button>

                                <button type="button"
                                        wire:click.prevent="createOfflineAuthMethod"
                                        @click="openAdd = false; $wire.showAuthMethodModal = true;"
                                        class="flex items-center gap-2 w-full px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-left cursor-pointer"
                                >
                                    {{ __('patients.authentication_documents') }}
                                </button>

                                <button type="button"
                                        @click="
                                                    $wire.showAuthMethodModal = true;
                                                    $wire.authStep = 13;
                                                    openAdd = false;
                                                "
                                        class="flex items-center gap-2 w-full px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 text-left cursor-pointer"
                                >
                                    {{ __('patients.authentication_third_person') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <div class="mt-8 flex items-center gap-4">
        <a href="{{ route('persons.index', [legalEntity()]) }}" class="button-minor" style="margin: 0 !important;">
            {{ __('forms.back') }}
        </a>
        <a href="{{ route('persons.update', [legalEntity(), $personId]) }}" class="button-primary"
           style="margin: 0 !important;">
            {{ __('patients.edit_data') }}
        </a>
    </div>

    @if($canManageConfidantRelationships)
        @include('livewire.person.parts.drawers.add-auth-verification')
        @include('livewire.person.parts.modals.terminate-relationship')
        @include('livewire.person.parts.drawers.deactivate-confidant-person')
    @endif

    @include('livewire.person.parts.drawers.add-confidant-person')

    @if($showAuthMethodModal)
        @include('livewire.person.parts.modals.choose-auth-method')
    @endif

    @if($showConfirmationUpdateModal)
        @include('livewire.person.parts.modals.person-update-authentication')
    @endif

    </div>
    <x-forms.loading />
</x-layouts.patient>
