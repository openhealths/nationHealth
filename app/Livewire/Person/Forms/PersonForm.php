<?php

declare(strict_types=1);

namespace App\Livewire\Person\Forms;

use App\Core\BaseForm;
use App\Rules\AlphaNumericWithSymbols;
use App\Rules\InDictionary;
use App\Rules\NameFields;
use App\Rules\TwoLettersFourToSixDigitsOrComplex;
use App\Rules\TwoLettersSixDigits;
use App\Rules\EightDigitsHyphenFiveDigits;
use App\Rules\Zip;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PersonForm extends BaseForm
{
    protected const int NO_SELF_AUTH_AGE = 14;
    protected const int NO_SELF_REGISTRATION_AGE = 14;
    protected const int PERSON_FULL_LEGAL_CAPACITY_AGE = 18;

    // For search
    public string $firstName;
    public string $lastName;
    public string $birthDate;
    public string $secondName;
    public string $taxId;
    public string $phoneNumber;
    public string $birthCertificate;

    public array $person = [
        'documents' => [],
        'phones' => [['type' => null, 'number' => null]],
        'emergencyContact' => [
            'phones' => [['type' => null, 'number' => null]]
        ],
        'confidantPerson' => ['documentsRelationship' => []],
        'authenticationMethods' => [['type' => null]]
    ];

    public array $addresses = [];

    public bool $processDisclosureDataConsent = true;

    /**
     * Mark 'information from the leaflet was communicated to the patient'
     *
     * @var bool
     */
    public bool $patientSigned = false;

    public string $authorizeWith;

    public int $verificationCode;

    public array $uploadedDocuments;

    private int $personAge;

    public function rulesForCreate(): array
    {
        $createRules = [
            'person.confidantPerson' => ['nullable', 'array'],
            'person.confidantPerson.personId' => [
                'nullable',
                'uuid',
                'required_with:person.confidantPerson.documentsRelationship'
            ],
            'person.confidantPerson.documentsRelationship.*.type' => [
                'required',
                'string',
                new InDictionary('DOCUMENT_RELATIONSHIP_TYPE')
            ],
            'person.confidantPerson.documentsRelationship.*.number' => ['required', 'string', 'max:255'],
            'person.confidantPerson.documentsRelationship.*.issuedBy' => ['required', 'string', 'max:255'],
            'person.confidantPerson.documentsRelationship.*.issuedAt' => [
                'required',
                'date',
                'before:today',
                'after:person.birthDate'
            ],
            'person.confidantPerson.documentsRelationship.*.activeTo' => ['nullable', 'date', 'after:tomorrow'],

            'person.authenticationMethods' => ['required', 'array'],
            'person.authenticationMethods.*.type' => ['required', new InDictionary('AUTHENTICATION_METHOD')],
            'person.authenticationMethods.*.phoneNumber' => [
                'nullable',
                'required_if:person.authenticationMethods.*.type,OTP',
                'regex:/^\+38[0-9]{10}$/'
            ],
            'person.authenticationMethods.*.value' => [
                'nullable',
                'required_if:person.authenticationMethods.*.type,THIRD_PERSON',
                'string'
            ],
            'person.authenticationMethods.*.alias' => [
                'nullable',
                'required_if:person.authenticationMethods.*.type,THIRD_PERSON',
                'string'
            ]
        ];

        if (!empty($this->person['documentsRelationship'])) {
            $this->addNumberDocumentsRelationshipValidation($createRules);
            $this->validateBirthCertificateAge();
        }

        if (!empty($this->person['birthDate'])) {
            $this->personAge = CarbonImmutable::parse($this->person['birthDate'])->age;

            $this->validateNecessityOfConfidantPerson();
        }

        return array_merge($this->basicRules(), $createRules);
    }

    /**
     * List of rules that used when updating person data.
     *
     * @return array
     */
    public function rulesForUpdate(): array
    {
        $updateRules = [
            'authorizeWith' => ['nullable', 'uuid']
        ];

        return array_merge($this->basicRules(), $updateRules);
    }

    /**
     * Rules that used for create and update.
     *
     * @return array
     */
    protected function basicRules(): array
    {
        $rules = [
            'person.firstName' => ['required', 'min:3', new NameFields()],
            'person.lastName' => ['required', 'min:3', new NameFields()],
            'person.secondName' => ['nullable', 'min:3', new NameFields()],
            'person.birthDate' => ['required', 'date_format:d.m.Y'],
            'person.birthCountry' => ['required', 'string'],
            'person.birthSettlement' => ['required', 'string'],
            'person.gender' => ['required', 'string', new InDictionary('GENDER')],
            'person.unzr' => [
                'nullable',
                new EightDigitsHyphenFiveDigits(),
                Rule::requiredIf(function () {
                    return collect($this->person['documents'])
                        ->contains(static fn (array $document) => $document['type'] === 'NATIONAL_ID');
                }),
            ],

            'person.documents' => ['required', 'array'],
            'person.documents.*.type' => ['required', 'string', new InDictionary('DOCUMENT_TYPE')],
            'person.documents.*.number' => ['required', 'string', 'max:255'],
            'person.documents.*.issuedBy' => ['required', 'string', 'max:255'],
            'person.documents.*.issuedAt' => [
                'required',
                'date_format:d.m.Y',
                'before:today',
                'after:person.birthDate'
            ],
            'person.documents.*.expirationDate' => ['nullable', 'date_format:d.m.Y', 'after:today'],

            'person.noTaxId' => ['nullable', 'boolean'],
            'person.taxId' => ['nullable', 'required_if:person.noTaxId,false', 'numeric', 'digits:10'],
            'person.secret' => ['required', 'string', 'min:6'],
            'person.email' => ['nullable', 'email', 'string'],

            'person.phones.*.type' => ['nullable', 'string', 'distinct', 'required_with:person.phones.*.number'],
            'person.phones.*.number' => [
                'nullable',
                'string',
                'regex:/^\+38[0-9]{10}$/',
                'distinct',
                'required_with:person.phones.*.type'
            ],

            'person.addresses.*.type' => ['required', new InDictionary('ADDRESS_TYPE')],
            'person.addresses.*.country' => ['required', new InDictionary('COUNTRY')],
            'person.addresses.*.area' => ['required', 'string', 'max:255'],
            'person.addresses.*.region' => ['sometimes', 'required_unless:person.addresses.*.area,М.КИЇВ'],
            'person.addresses.*.settlement' => ['required', 'string', 'max:255'],
            'person.addresses.*.settlementType' => ['required', new InDictionary('SETTLEMENT_TYPE')],
            'person.addresses.*.settlementId' => ['required', 'uuid'],
            'person.addresses.*.streetType' => ['nullable', new InDictionary('STREET_TYPE')],
            'person.addresses.*.street' => ['nullable', 'string', 'max:255'],
            'person.addresses.*.building' => ['nullable', 'string', 'max:255'],
            'person.addresses.*.apartment' => ['nullable', 'string', 'max:255'],
            'person.addresses.*.zip' => ['nullable', 'string', new Zip()],

            'person.emergencyContact.firstName' => ['required', 'min:3', new NameFields()],
            'person.emergencyContact.lastName' => ['required', 'min:3', new NameFields()],
            'person.emergencyContact.secondName' => ['nullable', 'min:3', new NameFields()],
            'person.emergencyContact.phones.*.type' => ['required', 'string', 'distinct'],
            'person.emergencyContact.phones.*.number' => ['required', 'string', 'regex:/^\+38[0-9]{10}$/', 'distinct'],

            'processDisclosureDataConsent' => ['required', 'boolean:strict', Rule::in([true])],
            'patientSigned' => ['required', 'boolean:strict', Rule::in([false])]
        ];

        $this->addNoTaxIdValidation($rules);

        if (!empty($this->person['documents'])) {
            $this->addExpirationDateRuleIfRequired($rules);
            $this->addNumberDocumentsValidation($rules);
        }

        if (!empty($this->person['birthDate'])) {
            $this->personAge = CarbonImmutable::parse($this->person['birthDate'])->age;

            if (!empty($this->person['documents'])) {
                $this->validateDocumentsForMinorPerson();
                $this->validatePersonDocuments();
            }
        }

        return $rules;
    }

    public function rulesForSearch(): array
    {
        return [
            'firstName' => ['required', 'min:3'],
            'lastName' => ['required', 'min:3'],
            'secondName' => ['nullable', 'min:3'],
            'birthDate' => ['required', 'date_format:d.m.Y'],
            'taxId' => ['nullable', 'numeric'],
            'phoneNumber' => ['nullable', 'string', 'min:13', 'max:13'],
            'birthCertificate' => ['nullable', 'string']
        ];
    }

    public function rulesForApprove(): array
    {
        return ['verificationCode' => ['required', 'numeric', 'digits:4']];
    }

    public function rulesForFiles(): array
    {
        return ['uploadedDocuments.*' => ['required', 'file', 'mimes:jpeg,jpg', 'max:10000']];
    }

    public function rulesForCreateNewConfidantPersonRelationshipRequest(): array
    {
        return [
            'confidantPersonId' => ['required', 'uuid'],
            'documentsRelationship' => ['required', 'array'],
            'documentsRelationship.*.type' => ['required', new InDictionary('DOCUMENT_RELATIONSHIP_TYPE')],
            'documentsRelationship.*.number' => ['required', 'string', 'max:255'],
            'documentsRelationship.*.issuedBy' => ['required', 'string', 'max:255'],
            'documentsRelationship.*.issuedAt' => ['required', 'date_format:d.m.Y'],
            'documentsRelationship.*.activeTo' => ['nullable', 'date_format:d.m.Y']
        ];
    }

    public function rulesForDeactivateConfidantPerson(): array
    {
        return [
            'confidantPersonRelationUuid' => ['required', 'uuid'],
            'documents' => ['required', 'array'],
            'documents.*.type' => ['required', 'string', new InDictionary('DOCUMENT_RELATIONSHIP_TYPE')],
            'documents.*.number' => ['required', 'string', 'max:255'],
            'documents.*.issuedBy' => ['required', 'string', 'max:255'],
            'documents.*.issuedAt' => ['required', 'date_format:d.m.Y']
        ];
    }

    public function messages(): array
    {
        $messages = [
            'person.unzr.required' => __('validation.custom.person.unzr_required_for_national_id'),
            'person.taxId.required_if' => __('validation.custom.person.tax_id_required_when_not_absent')
        ];

        // Add translated message for document expiration date
        $messages['person.documents.*.expirationDate.required_if'] = $this->getExpirationDateRequiredMessage();

        return $messages;
    }

    /**
     * Do expirationDate required if a specific document type was selected.
     *
     * @param  array  $rules
     * @return void
     */
    private function addExpirationDateRuleIfRequired(array &$rules): void
    {
        $requiredTypes = config('ehealth.expiration_date_exists');

        // Simply add required_if rule that checks if document type is in required types
        $rules['person.documents.*.expirationDate'][] = 'required_if:person.documents.*.type,' . implode(
            ',',
            $requiredTypes
        );
    }

    /**
     * Format translation message with translated document type.
     *
     * @return string
     */
    private function getExpirationDateRequiredMessage(): string
    {
        // Find which document type is causing the validation error
        if (!empty($this->person['documents'])) {
            $requiredTypes = config('ehealth.expiration_date_exists', []);

            foreach ($this->person['documents'] as $document) {
                if (empty($document['expirationDate'] && in_array($document['type'], $requiredTypes, true))) {
                    $translatedType = __("patients.documents.{$document['type']}") ?: $document['type'];

                    return __('validation.custom.person.expiration_date_required_for_type', ['document_type' => $translatedType]);
                }
            }
        }

        return __('validation.custom.person.expiration_date_required_general');
    }

    /**
     * Add validation for document numbers based on different document types.
     *
     * @param  array  $rules
     * @return void
     */
    private function addNumberDocumentsValidation(array &$rules): void
    {
        foreach ($this->person['documents'] as $key => $document) {
            $rules["person.documents.$key.number"][] = match ($document['type']) {
                'PASSPORT', 'REFUGEE_CERTIFICATE', 'COMPLEMENTARY_PROTECTION_CERTIFICATE' => new TwoLettersSixDigits(),
                'NATIONAL_ID' => 'digits:9',
                'BIRTH_CERTIFICATE', 'TEMPORARY_PASSPORT', 'CHILD_BIRTH_CERTIFICATE', 'MARRIAGE_CERTIFICATE',
                'DIVORCE_CERTIFICATE' => new AlphaNumericWithSymbols(),
                'TEMPORARY_CERTIFICATE' => new TwoLettersFourToSixDigitsOrComplex(),
                'BIRTH_CERTIFICATE_FOREIGN', 'PERMANENT_RESIDENCE_PERMIT' => 'string',
                default => null
            };
        }
    }

    /**
     * Add validation for document numbers based on different document types.
     *
     * @param  array  $rules
     * @return void
     */
    private function addNumberDocumentsRelationshipValidation(array &$rules): void
    {
        foreach ($this->person['documentsRelationship'] as $key => $document) {
            if ($document['type'] === 'BIRTH_CERTIFICATE') {
                $rules["person.documentsRelationship.$key.number"][] = new AlphaNumericWithSymbols();
            }
        }
    }

    /**
     * Validate birth certificate documents based on person age for relationship documents
     *
     * @return void
     */
    private function validateBirthCertificateAge(): void
    {
        foreach ($this->person['documentsRelationship'] as $document) {
            if ($this->personAge >= self::PERSON_FULL_LEGAL_CAPACITY_AGE &&
                in_array(
                    $document['type'],
                    ['BIRTH_CERTIFICATE', 'BIRTH_CERTIFICATE_FOREIGN'],
                    true
                )
            ) {
                throw ValidationException::withMessages([
                    'person.documents' => __('validation.custom.person.invalid_relationship_document_for_age')
                ]);
            }
        }
    }

    /**
     * Do tax_id required if no_tax_id = false and persons age > NO_SELF_AUTH_AGE.
     *
     * @param  array  $rules
     * @return void
     */
    private function addNoTaxIdValidation(array &$rules): void
    {
        if (!empty($this->person['taxId']) && (!empty($this->person['birthDate']) && $this->person['birthDate'] > self::NO_SELF_AUTH_AGE)) {
            $rules['person.taxId'][] = 'required';
        }
    }

    /**
     * Validate necessity of confidant person.
     *
     * @return void
     */
    private function validateNecessityOfConfidantPerson(): void
    {
        // If age less than 18 then check that confidant_person is submitted
        if ($this->personAge < self::NO_SELF_REGISTRATION_AGE && empty($this->person['confidantPerson']['personId'])) {
            throw ValidationException::withMessages([
                'person.confidantPerson' => __('validation.custom.person.confidant_person_required_for_children')
            ]);
        }

        // If age between 14 and 18 then
        if ($this->personAge > self::NO_SELF_REGISTRATION_AGE && $this->personAge < self::PERSON_FULL_LEGAL_CAPACITY_AGE) {
            $personLegalCapacityDocumentTypes = config('ehealth.person_legal_capacity_document_types');
            $hasLegalCapacityDocument = false;

            foreach ($this->person['documents'] as $document) {
                if (in_array($document['type'], $personLegalCapacityDocumentTypes, true)) {
                    $hasLegalCapacityDocument = true;
                    break;
                }
            }

            // if none of persons documents has type from PERSON_LEGAL_CAPACITY_DOCUMENT_TYPES config parameter - check that confidant_person is submitted
            if (!$hasLegalCapacityDocument && empty($this->person['confidantPerson']['personId'])) {
                throw ValidationException::withMessages([
                    'person.confidantPerson' => __('validation.custom.person.confidant_person_required_for_minor')
                ]);
            }

            // Else if at least one of submitted person document types exist in PERSON_LEGAL_CAPACITY_DOCUMENT_TYPES config parameter - check that confidant_person is not submitted
            if ($hasLegalCapacityDocument && !empty($this->person['confidantPerson']['personId'])) {
                throw ValidationException::withMessages([
                    'person.confidantPerson' => __('validation.custom.person.confidant_person_must_be_capable')
                ]);
            }
        }
    }

    /**
     * Check that document types BIRTH_CERTIFICATE or BIRTH_CERTIFICATE_FOREIGN are submitted if person age < NO_SELF_AUTH_AGE.
     *
     * @return void
     */
    private function validateDocumentsForMinorPerson(): void
    {
        if ($this->personAge < self::NO_SELF_AUTH_AGE) {
            $requiredDocumentTypes = ['BIRTH_CERTIFICATE', 'BIRTH_CERTIFICATE_FOREIGN'];
            $hasRequiredDocument = false;

            foreach ($this->person['documents'] as $document) {
                if (in_array($document['type'], $requiredDocumentTypes, true)) {
                    $hasRequiredDocument = true;
                    break;
                }
            }

            if (!$hasRequiredDocument) {
                throw ValidationException::withMessages([
                    'person.documents' => __('validation.custom.person.birth_documents_required')
                ]);
            }
        }
    }

    /**
     * Validate person documents.
     *
     * @return void
     */
    private function validatePersonDocuments(): void
    {
        $personLegalCapacityDocumentTypes = config('ehealth.person_legal_capacity_document_types');
        $personRegistrationDocumentTypes = config('ehealth.person_registration_document_types');
        $selfAuthAgeDocumentTypes = config('ehealth.self_auth_age_document_types');

        // Check submitted person document types exist in PERSON_REGISTRATION_DOCUMENT_TYPES config parameter
        // that contains values from DOCUMENT_TYPE dictionary
        $allAllowedTypes = array_merge($personLegalCapacityDocumentTypes, $personRegistrationDocumentTypes);

        foreach ($this->person['documents'] as $document) {
            if (!in_array($document['type'], (array)$allAllowedTypes, true)) {
                $documentTypeName = __('patients.documents.' . $document['type']) ?: $document['type'];
                throw ValidationException::withMessages([
                    'person.documents' => __('validation.custom.person.document_type_not_allowed', ['document_type' => $documentTypeName])
                ]);
            }
        }

        // Check document types from PERSON_LEGAL_CAPACITY_DOCUMENT_TYPES config parameter (that prove persons legal capacity) are not submitted
        // if persons age is less than no_self_registration_age global parameter or greater than person_full_legal_capacity_age global parameter
        if ($this->personAge < self::NO_SELF_REGISTRATION_AGE || $this->personAge > self::PERSON_FULL_LEGAL_CAPACITY_AGE) {
            foreach ($this->person['documents'] as $document) {
                if (in_array($document['type'], $personLegalCapacityDocumentTypes, true)) {
                    $documentTypeName = __('patients.documents.' . $document['type']) ?: $document['type'];
                    throw ValidationException::withMessages([
                        'person.documents' => __('validation.custom.person.document_type_not_allowed_for_person', ['document_type' => $documentTypeName])
                    ]);
                }
            }
        }

        // If at least one document type from PERSON_LEGAL_CAPACITY_DOCUMENT_TYPES config parameter is submitted,
        // check that at least one document type from PERSON_REGISTRATION_DOCUMENT_TYPES is submitted
        $submittedLegalCapacityDocuments = array_intersect(
            array_column($this->person['documents'], 'type'),
            $personLegalCapacityDocumentTypes
        );

        if (!empty($submittedLegalCapacityDocuments)) {
            $submittedRegistrationDocuments = array_intersect(
                array_column($this->person['documents'], 'type'),
                $personRegistrationDocumentTypes
            );

            if (empty($submittedRegistrationDocuments)) {
                throw ValidationException::withMessages([
                    'person.documents' => __('validation.custom.person.registration_document_required')
                ]);
            }

            // If at least one document type from PERSON_LEGAL_CAPACITY_DOCUMENT_TYPES config parameter is submitted,
            // check that at least one document type from PERSON_REGISTRATION_DOCUMENT_TYPES is submitted
            if (count($submittedLegalCapacityDocuments) > 1) {
                throw ValidationException::withMessages([
                    'person.documents' => __('validation.custom.person.only_one_legal_capacity_document')
                ]);
            }
        }

        // Check that document types NATIONAL_ID and PASSPORT both do not exist in request
        $submittedTypes = array_column($this->person['documents'], 'type');
        $hasNationalId = in_array('NATIONAL_ID', $submittedTypes, true);
        $hasPassport = in_array('PASSPORT', $submittedTypes, true);

        if ($hasNationalId && $hasPassport) {
            throw ValidationException::withMessages([
                'person.documents' => __('validation.custom.person.national_id_passport_mutual_exclusion')
            ]);
        }

        // Check if person age > prm.global_parameters.no_self_auth_age check existence SELF_AUTH_AGE_DOCUMENT_TYPES
        if ($this->personAge > self::NO_SELF_AUTH_AGE) {
            $hasSelfAuthType = (bool)array_intersect($submittedTypes, $selfAuthAgeDocumentTypes);

            if (!$hasSelfAuthType) {
                $translatedTypes = array_map(static function ($type) {
                    return __('patients.documents.' . $type) ?: $type;
                }, $selfAuthAgeDocumentTypes);
                $allowedTypesList = implode(', ', $translatedTypes);

                throw ValidationException::withMessages([
                    'person.documents' => __('validation.custom.person.invalid_document_types_for_age', ['allowed_types' => $allowedTypesList])
                ]);
            }
        }
    }
}
