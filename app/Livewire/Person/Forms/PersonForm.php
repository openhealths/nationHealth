<?php

declare(strict_types=1);

namespace App\Livewire\Person\Forms;

use App\Core\BaseForm;
use App\Enums\Person\AuthenticationMethod;
use App\Models\Relations\AuthenticationMethod as AuthenticationMethodModel;
use App\Rules\AlphaNumericWithSymbols;
use App\Rules\InDictionary;
use App\Rules\NameFields;
use App\Rules\PhoneNumber;
use App\Rules\TwoLettersFourToSixDigitsOrComplex;
use App\Rules\TwoLettersSixDigits;
use App\Rules\EightDigitsHyphenFiveDigits;
use App\Rules\Zip;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PersonForm extends BaseForm
{
    public const int NO_SELF_AUTH_AGE = 14;
    public const int NO_SELF_REGISTRATION_AGE = 16;
    protected const int PERSON_FULL_LEGAL_CAPACITY_AGE = 18;
    private const string EXPIRATION_DATE_ISSUED_AT_CONDITIONAL_TYPE = 'PERMANENT_RESIDENCE_PERMIT';
    private const string EXPIRATION_DATE_ISSUED_AT_REQUIRED_FROM = '2018-06-01';

    // For search
    public string $language = 'uk';
    public string $firstName = '';
    public string $lastName = '';
    public bool $noLastName = false;
    public string $birthDate = '';
    public string $secondName = '';
    public string $taxId = '';
    public string $phoneNumber = '';
    public string $documentType = '';
    public string $documentNumber = '';

    public array $person = [
        'names' => [
            [
                'language' => null,
                'noLastName' => false,
                'lastName' => null,
                'firstName' => null,
                'secondName' => null
            ]
        ],
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

    public array $uploadedDocuments = [];

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
                'before_or_equal:today',
                'after_or_equal:person.birthDate'
            ],
            'person.confidantPerson.documentsRelationship.*.activeTo' => ['nullable', 'date', 'after:today'],

            'person.authenticationMethods' => ['required', 'array', 'max:1'],
            'person.authenticationMethods.*.type' => ['required', new InDictionary('AUTHENTICATION_METHOD')],
            'person.authenticationMethods.*.phoneNumber' => [
                'nullable',
                'required_if:person.authenticationMethods.*.type,OTP',
                new PhoneNumber()
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

        if (!empty($this->person['birthDate'])) {
            $this->personAge = CarbonImmutable::parse($this->person['birthDate'])->age;

            $this->validateNecessityOfConfidantPerson();
        }

        if (!empty($this->person['confidantPerson']['documentsRelationship'])) {
            $this->addNumberDocumentsRelationshipValidation($createRules);
            $this->validateBirthCertificateAge();
        }

        if (!empty($this->person['confidantPerson']['personId'])) {
            $this->validateConfidantPersonAuthenticationMethod();
        } else {
            $this->validateNonConfidantAuthenticationMethod();
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
            'person.id' => ['nullable', 'uuid'],
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
        $expirationDateAfter = config('ehealth.person_documents_use_specific_expiration_date')
        && config('ehealth.person_documents_specific_expiration_date')
            ? config('ehealth.person_documents_specific_expiration_date')
            : 'today';

        // The address UI collects a single address, so its country selects the alphabet for every address field
        $isUkraineAddress = ($this->person['addresses'][0]['country'] ?? 'UA') === 'UA';

        $addressTextPattern = $isUkraineAddress
            ? 'regex:/^(?!.*[ЫЪЭЁыъэё@%&$^#])[a-zA-ZА-ЯҐЇІЄа-яґїіє0-9№.,\'"()\/_\- ]+$/u'
            : 'regex:/^[A-Za-z\s.\-\/\']+$/u';

        $addressBuildingPattern = $isUkraineAddress
            ? 'regex:/^[1-9]((?![ЫЪЭЁыъэё])()([А-ЯҐЇІЄа-яґїіє \/\'\-0-9])){0,20}$/u'
            : 'regex:/^[A-Za-z0-9\s\/-]+$/u';

        $rules = [
            'person.names' => ['required', 'array', 'min:1'],
            'person.names.*.language' => ['required', 'distinct', new InDictionary('LANGUAGE')],
            'person.names.*.noLastName' => ['boolean'],
            'person.names.*.firstName' => ['required', 'min:3'],
            'person.names.*.secondName' => ['nullable', 'min:3'],
            'person.birthDate' => ['required', 'date_format:' . config('app.date_format')],
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
                Rule::prohibitedIf(function () {
                    $foreignTypes = config('ehealth.identity_document_types_foreign');

                    return collect($this->person['documents'])
                        ->contains(
                            static fn (array $document): bool => in_array(
                                $document['type'] ?? null,
                                $foreignTypes,
                                true
                            )
                        );
                })
            ],

            'person.documents' => ['required', 'array'],
            'person.documents.*.type' => ['required', 'string', new InDictionary('DOCUMENT_TYPE')],
            'person.documents.*.issuedBy' => ['required', 'string', 'max:255'],
            'person.documents.*.issuedAt' => [
                'required',
                'date_format:' . config('app.date_format'),
                'before_or_equal:today',
                'after_or_equal:person.birthDate'
            ],
            'person.documents.*.expirationDate' => [
                'nullable',
                'date_format:' . config('app.date_format'),
                "after:$expirationDateAfter"
            ],

            'person.noTaxId' => ['nullable', 'boolean'],
            'person.taxId' => ['nullable', 'numeric', 'digits:10'],
            'person.secret' => ['required', 'string', 'min:6'],
            'person.email' => [
                'nullable',
                'email',
                'string',
                Rule::unique('persons', 'email')
                    ->when(
                        !empty($this->person['uuid']),
                        fn ($rule) => $rule->ignore($this->person['uuid'], 'uuid')
                    )
            ],

            'person.phones.*.type' => ['nullable', 'string', 'distinct', 'required_with:person.phones.*.number'],
            'person.phones.*.number' => [
                'nullable',
                'string',
                'regex:/^\+[0-9]{11,12}$/',
                'distinct',
                'required_with:person.phones.*.type'
            ],

            'person.addresses' => [
                'required',
                'array',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $residenceAddresses = collect($value)->where('type', 'RESIDENCE');

                    if ($residenceAddresses->count() !== 1) {
                        $fail(__('validation.custom.person.single_residence_address_required'));

                        return;
                    }

                    if (!$this->hasForeignDocument() && !$residenceAddresses->contains('country', 'UA')) {
                        $fail(__('validation.custom.person.ua_residence_address_required'));
                    }
                }
            ],
            'person.addresses.*.type' => ['required', new InDictionary('ADDRESS_TYPE')],
            'person.addresses.*.country' => ['required', new InDictionary('COUNTRY')],
            'person.addresses.*.area' => ['required', 'string', 'max:255', $addressTextPattern],
            'person.addresses.*.region' => [
                'sometimes',
                'required_unless:person.addresses.*.area,М.КИЇВ',
                $addressTextPattern
            ],
            'person.addresses.*.settlement' => ['required', 'string', 'max:255', $addressTextPattern],
            // Both are only part of the schema for Ukrainian addresses, so they are dropped from the payload otherwise
            'person.addresses.*.settlementId' => [
                'exclude_unless:person.addresses.*.country,UA',
                'required',
                'uuid'
            ],
            'person.addresses.*.streetType' => [
                'exclude_unless:person.addresses.*.country,UA',
                'nullable',
                new InDictionary('STREET_TYPE')
            ],
            'person.addresses.*.street' => [
                'nullable',
                'required_if:person.addresses.*.country,UA',
                'string',
                'max:255',
                $addressTextPattern
            ],
            'person.addresses.*.building' => ['nullable', $addressBuildingPattern],
            'person.addresses.*.apartment' => $isUkraineAddress
                ? ['nullable', 'string', 'max:255']
                : ['nullable', $addressBuildingPattern],
            'person.addresses.*.zip' => ['nullable', 'string', new Zip()],

            'person.emergencyContact.firstName' => ['required', 'min:3'],
            'person.emergencyContact.lastName' => ['required', 'min:3'],
            'person.emergencyContact.secondName' => ['nullable', 'min:3'],
            'person.emergencyContact.phones.*.type' => ['required', 'string', 'distinct'],
            'person.emergencyContact.phones.*.number' => ['required', 'string', 'regex:/^\+[0-9]{11,12}$/', 'distinct'],

            'processDisclosureDataConsent' => ['required', 'boolean:strict', Rule::in([true])],
            'patientSigned' => ['required', 'boolean:strict', Rule::in([false])]
        ];

        $this->normalizeNoTaxIdForForeignDocuments();
        $this->validateNoTaxIdFlag();
        $this->addTaxIdUniquenessValidation($rules);
        $this->addNamesLastNameValidation($rules);
        $this->addNamesAlphabetValidation($rules);
        $this->addEmergencyContactNameValidation($rules);
        $this->validateNamesLanguagePresence();

        if (!empty($this->person['documents'])) {
            // Must run first: it is the only one that covers every document, and validated() rebuilds the
            // documents array in rule order, so a partial rule set first would emit it out of order as a JSON object
            $this->addIssuingCountryValidation($rules);
            $this->addExpirationDateRuleIfRequired($rules);
            $this->addNumberDocumentsValidation($rules);
            $this->validateForeignDocumentsExclusivity();
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

    /**
     * Rules for searching a person, where the selected language defines the alphabet allowed in the name fields.
     *
     * @return array
     */
    public function rulesForSearch(): array
    {
        $language = $this->language ?: 'uk';
        $documentNumberRules = ['nullable', 'required_with:documentType', 'string', 'max:100'];
        $documentNumberFormatRule = $this->documentNumberFormatRule($this->documentType);

        if ($documentNumberFormatRule !== null) {
            $documentNumberRules[] = $documentNumberFormatRule;
        }

        $this->validateSearchWithoutLastName();

        return [
            'language' => ['required', new InDictionary('LANGUAGE')],
            'firstName' => ['required', 'string', new NameFields($language)],
            'lastName' => $this->noLastName ? ['prohibited'] : ['nullable', 'string', new NameFields($language)],
            'noLastName' => ['boolean'],
            'secondName' => ['nullable', 'string', new NameFields($language)],
            'birthDate' => ['required', 'date_format:' . config('app.date_format')],
            'taxId' => ['nullable', 'numeric'],
            'phoneNumber' => ['nullable', 'string', 'min:13', 'max:13'],
            'documentType' => ['nullable', 'required_with:documentNumber', new InDictionary('DOCUMENT_TYPE')],
            'documentNumber' => $documentNumberRules
        ];
    }

    /**
     * A search that omits the last name must be narrowed down by either a full document or the tax id.
     * Searching for a person who has no last name at all is exempt: it sends an empty last_name instead of omitting it.
     * Skipped until the mandatory fields are filled in, so that their own rules report first.
     *
     * @return void
     * @throws ValidationException
     */
    private function validateSearchWithoutLastName(): void
    {
        if (blank($this->firstName) || blank($this->birthDate)) {
            return;
        }

        if ($this->noLastName || filled($this->lastName) || filled($this->taxId)) {
            return;
        }

        if (filled($this->documentType) && filled($this->documentNumber)) {
            return;
        }

        throw ValidationException::withMessages([
            'lastName' => __('validation.custom.person.search_without_last_name_requires_document_or_tax_id')
        ]);
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
            'documentsRelationship.*.issuedAt' => ['required', 'date_format:' . config('app.date_format')],
            'documentsRelationship.*.activeTo' => ['nullable', 'date_format:' . config('app.date_format')]
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
            'documents.*.issuedAt' => ['required', 'date_format:' . config('app.date_format')]
        ];
    }

    /**
     * Name each document number after its own document, so an error tells the user which one is wrong.
     *
     * @return array
     */
    public function validationAttributes(): array
    {
        $attributes = [];

        foreach ($this->person['documents'] ?? [] as $index => $document) {
            if (blank($document['type'] ?? null)) {
                continue;
            }

            $attributes["person.documents.$index.number"] = __('validation.custom.person.document_number_of', [
                'document' => __('patients.documents.' . $document['type'])
            ]);
        }

        return $attributes;
    }

    public function messages(): array
    {
        $messages = [
            'person.unzr.required' => __('validation.custom.person.unzr_required_for_national_id'),
            'person.unzr.prohibited' => __('validation.custom.person.unzr_prohibited_for_foreign'),
            'person.taxId.unique' => __('validation.custom.person.tax_id_already_used'),
            'person.documents.*.issuingCountry.required' => __(
                'validation.custom.person.issuing_country_required_for_foreign'
            ),
            'person.names.min' => __('validation.custom.person.names_min'),
            'person.names.*.language.distinct' => __('validation.custom.person.names_language_distinct'),
            'person.names.*.lastName.required' => __('validation.custom.person.no_last_name_false_requires_last_name'),
            'person.names.*.lastName.prohibited' => __('validation.custom.person.no_last_name_true_requires_empty')
        ];

        $messages['person.documents.*.expirationDate.required_if'] = $this->getExpirationDateRequiredMessage();
        $messages['person.documents.*.expirationDate.required'] = $this->getExpirationDateRequiredMessage();
        $messages['person.documents.*.expirationDate.after'] = $this->getExpirationDateFutureMessage();

        return array_merge($messages, $this->getIssuingCountryMessages());
    }

    /**
     * Build per-document issuing_country messages that name the document type.
     *
     * @return array
     */
    private function getIssuingCountryMessages(): array
    {
        $uaOnlyTypes = config('ehealth.document_types_issuing_country_ua_only');
        $notUaTypes = config('ehealth.document_types_issuing_country_not_ua');

        $messages = [];

        foreach ($this->person['documents'] as $key => $document) {
            $type = $document['type'] ?? null;
            $documentType = __('patients.documents.' . $type) ?: $type;

            if (in_array($type, $notUaTypes, true)) {
                $messages["person.documents.$key.issuingCountry.not_in"] =
                    __('validation.custom.person.issuing_country_not_ua_for_type', ['document_type' => $documentType]);
            } elseif (in_array($type, $uaOnlyTypes, true)) {
                $messages["person.documents.$key.issuingCountry.in"] =
                    __(
                        'validation.custom.person.issuing_country_must_be_ua_for_type',
                        ['document_type' => $documentType]
                    );
            }
        }

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

        foreach ($this->person['documents'] as $index => $document) {
            if ($this->isExpirationDateRequiredForDocument($document, $requiredTypes)) {
                $rules["person.documents.$index.expirationDate"][] = 'required';
            }
        }
    }

    /**
     * Message for the document expiration_date future rule, using the specific date when configured.
     *
     * @return string
     */
    private function getExpirationDateFutureMessage(): string
    {
        if (
            config('ehealth.person_documents_use_specific_expiration_date')
            && config('ehealth.person_documents_specific_expiration_date')
        ) {
            return __('validation.custom.person.expiration_date_after_specific', [
                'date' => config('ehealth.person_documents_specific_expiration_date')
            ]);
        }

        return __('validation.custom.person.expiration_date_in_future');
    }

    /**
     * Message for the document expiration_date required rule, naming the document type that triggered it.
     *
     * @return string
     */
    private function getExpirationDateRequiredMessage(): string
    {
        if (!empty($this->person['documents'])) {
            $requiredTypes = config('ehealth.expiration_date_exists', []);

            foreach ($this->person['documents'] as $document) {
                if (
                    empty($document['expirationDate'])
                    && $this->isExpirationDateRequiredForDocument($document, $requiredTypes)
                ) {
                    $translatedType = __("patients.documents.{$document['type']}") ?: $document['type'];

                    return __(
                        'validation.custom.person.expiration_date_required_for_type',
                        ['document_type' => $translatedType]
                    );
                }
            }
        }

        return __('validation.custom.person.expiration_date_required_general');
    }

    /**
     * Determine whether a person document must have an expiration date.
     *
     * @param  array  $document
     * @param  array  $requiredTypes
     * @return bool
     */
    private function isExpirationDateRequiredForDocument(array $document, array $requiredTypes): bool
    {
        $documentType = $document['type'];

        if (!in_array($documentType, $requiredTypes, true)) {
            return false;
        }

        if ($documentType !== self::EXPIRATION_DATE_ISSUED_AT_CONDITIONAL_TYPE) {
            return true;
        }

        return $this->issuedAtRequiresExpirationDate($document['issuedAt'] ?? null);
    }

    /**
     * Check whether a conditional document issue date falls on or after the eHealth cutoff.
     *
     * @param  string|null  $issuedAt
     * @return bool
     */
    private function issuedAtRequiresExpirationDate(?string $issuedAt): bool
    {
        if (empty($issuedAt)) {
            return false;
        }

        $issuedAtDate = CarbonImmutable::createFromFormat(config('app.date_format'), $issuedAt)->startOfDay();

        return $issuedAtDate->greaterThanOrEqualTo(
            CarbonImmutable::parse(self::EXPIRATION_DATE_ISSUED_AT_REQUIRED_FROM)->startOfDay()
        );
    }

    /**
     * Add validation for document numbers based on different document types.
     * Foreign identity documents are skipped: their number is not validated.
     *
     * @param  array  $rules
     * @return void
     */
    private function addNumberDocumentsValidation(array &$rules): void
    {
        $foreignTypes = config('ehealth.identity_document_types_foreign');

        foreach ($this->person['documents'] as $key => $document) {
            $rules["person.documents.$key.number"] = ['required', 'string', 'max:100'];

            // Foreign identity documents keep the rules above, but have no fixed number format to check against
            if (in_array($document['type'], $foreignTypes, true)) {
                continue;
            }

            $formatRule = $this->documentNumberFormatRule($document['type']);

            if ($formatRule !== null) {
                $rules["person.documents.$key.number"][] = $formatRule;
            }
        }
    }

    /**
     * Get the number format rule that matches the given document type, or null when the type has no fixed format.
     *
     * @param  string  $documentType
     * @return mixed
     */
    private function documentNumberFormatRule(string $documentType): mixed
    {
        return match ($documentType) {
            'PASSPORT', 'REFUGEE_CERTIFICATE', 'COMPLEMENTARY_PROTECTION_CERTIFICATE' => new TwoLettersSixDigits(),
            'NATIONAL_ID' => 'digits:9',
            'BIRTH_CERTIFICATE', 'TEMPORARY_PASSPORT', 'CHILD_BIRTH_CERTIFICATE', 'MARRIAGE_CERTIFICATE',
            'DIVORCE_CERTIFICATE' => new AlphaNumericWithSymbols(),
            'TEMPORARY_CERTIFICATE' => new TwoLettersFourToSixDigitsOrComplex(),
            'BIRTH_CERTIFICATE_FOREIGN', 'PERMANENT_RESIDENCE_PERMIT' => 'string',
            default => null
        };
    }

    /**
     * Add validation for document numbers based on different document types.
     *
     * @param  array  $rules
     * @return void
     */
    private function addNumberDocumentsRelationshipValidation(array &$rules): void
    {
        foreach ($this->person['confidantPerson']['documentsRelationship'] as $key => $document) {
            if ($document['type'] === 'BIRTH_CERTIFICATE') {
                $rules["person.confidantPerson.documentsRelationship.$key.number"][] = new AlphaNumericWithSymbols();
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
        if (empty($this->person['birthDate'])) {
            return;
        }

        $personAge = CarbonImmutable::parse($this->person['birthDate'])->age;

        foreach ($this->person['confidantPerson']['documentsRelationship'] as $document) {
            if ($personAge >= self::PERSON_FULL_LEGAL_CAPACITY_AGE &&
                in_array(
                    $document['type'],
                    ['BIRTH_CERTIFICATE', 'BIRTH_CERTIFICATE_FOREIGN'],
                    true
                )
            ) {
                throw ValidationException::withMessages([
                    'person.confidantPerson.documentsRelationship' => __(
                        'validation.custom.person.invalid_relationship_document_for_age'
                    )
                ]);
            }
        }
    }

    /**
     * When a confidant person is submitted, the single authentication method must be THIRD_PERSON, point at that confidant,
     * and the confidant must not already be a THIRD_PERSON in the system more than the third_person_limit global parameter allows.
     *
     * @return void
     */
    private function validateConfidantPersonAuthenticationMethod(): void
    {
        $authenticationMethod = $this->person['authenticationMethods'][0] ?? [];
        $type = $authenticationMethod['type'] ?? null;

        // Let the required rule on person.authenticationMethods.*.type report a missing type first
        if (empty($type)) {
            return;
        }

        if ($type !== AuthenticationMethod::THIRD_PERSON->value) {
            throw ValidationException::withMessages([
                'person.authenticationMethods' => __(
                    'validation.custom.person.confidant_auth_method_must_be_third_person'
                )
            ]);
        }

        if (($authenticationMethod['value'] ?? null) !== $this->person['confidantPerson']['personId']) {
            throw ValidationException::withMessages([
                'person.authenticationMethods' => __('validation.custom.person.confidant_must_be_third_person_value')
            ]);
        }

        $thirdPersonLimit = config('ehealth.third_person_limit');

        $thirdPersonCount = AuthenticationMethodModel::query()
            ->whereType(AuthenticationMethod::THIRD_PERSON)
            ->whereValue($authenticationMethod['value'])
            ->where(static function (Builder $query): void {
                $query->whereNull('ehealth_ended_at')
                    ->orWhere('ehealth_ended_at', '>', now());
            })
            ->count();

        if ($thirdPersonCount >= $thirdPersonLimit) {
            throw ValidationException::withMessages([
                'person.authenticationMethods' => __(
                    'validation.custom.person.third_person_limit_exceeded',
                    ['limit' => $thirdPersonLimit]
                )
            ]);
        }
    }

    /**
     * When no confidant person is submitted, the single authentication method must be OTP or OFFLINE.
     *
     * @return void
     */
    private function validateNonConfidantAuthenticationMethod(): void
    {
        $authenticationMethod = $this->person['authenticationMethods'][0] ?? [];
        $type = $authenticationMethod['type'] ?? null;

        // Let the required rule on person.authenticationMethods.*.type report a missing type first
        if (empty($type)) {
            return;
        }

        if (!in_array($type, [AuthenticationMethod::OTP->value, AuthenticationMethod::OFFLINE->value], true)) {
            throw ValidationException::withMessages([
                'person.authenticationMethods' => __('validation.custom.person.non_confidant_auth_method_invalid')
            ]);
        }
    }

    /**
     * Do tax_id required if no_tax_id = false and persons age > NO_SELF_AUTH_AGE.
     *
     * @return void
     */
    private function validateNoTaxIdFlag(): void
    {
        $noTaxId = $this->person['noTaxId'] ?? null;
        $taxIdFilled = !empty($this->person['taxId']);

        // no_tax_id = true (refused the tax_id) must not carry a tax_id
        if ($noTaxId === true && $taxIdFilled) {
            throw ValidationException::withMessages([
                'person.taxId' => __('validation.custom.person.no_tax_id_true_requires_empty_tax_id')
            ]);
        }

        // a filled tax_id requires no_tax_id = false
        if ($taxIdFilled && $noTaxId !== false) {
            throw ValidationException::withMessages([
                'person.noTaxId' => __('validation.custom.person.tax_id_requires_no_tax_id_false')
            ]);
        }

        // no_tax_id = false above the self-auth age requires a tax_id
        if ($noTaxId === false && !$taxIdFilled && !empty($this->person['birthDate'])) {
            $personAge = CarbonImmutable::parse($this->person['birthDate'])->age;

            if ($personAge > self::NO_SELF_AUTH_AGE) {
                throw ValidationException::withMessages([
                    'person.taxId' => __('validation.custom.person.no_tax_id_false_requires_tax_id')
                ]);
            }
        }

        $foreignTypes = config('ehealth.identity_document_types_foreign');
        $submittedTypes = array_column($this->person['documents'] ?? [], 'type');
        $hasForeignDocument = (bool)array_intersect($submittedTypes, $foreignTypes);

        // a non-foreign document requires a defined no_tax_id (the foreign no_tax_id = null case is guaranteed by normalizeNoTaxIdForForeignDocuments)
        if (!$hasForeignDocument && $noTaxId === null) {
            throw ValidationException::withMessages([
                'person.noTaxId' => __('validation.custom.person.no_tax_id_cannot_be_null')
            ]);
        }
    }

    /**
     * When a foreign identity document is present and tax_id is missing, no_tax_id must be true: the person has no
     * Ukrainian tax number, and eHealth requires no_tax_id as a boolean.
     *
     * @return void
     */
    private function normalizeNoTaxIdForForeignDocuments(): void
    {
        if (!empty($this->person['taxId'])) {
            return;
        }

        $foreignTypes = config('ehealth.identity_document_types_foreign');

        $hasForeignDocument = collect($this->person['documents'] ?? [])
            ->contains(static fn (array $document): bool => in_array($document['type'] ?? null, $foreignTypes, true));

        if ($hasForeignDocument) {
            $this->person['noTaxId'] = true;
        }
    }

    /**
     * The issuing_country field is driven by chart parameters per document type:
     * UA-only types require UA, not-UA types require any other country from ISSUING_COUNTRY, and the remaining types leave the field empty.
     *
     * @param  array  $rules
     * @return void
     */
    private function addIssuingCountryValidation(array &$rules): void
    {
        $uaOnlyTypes = config('ehealth.document_types_issuing_country_ua_only');
        $notUaTypes = config('ehealth.document_types_issuing_country_not_ua');

        foreach ($this->person['documents'] as $key => $document) {
            $type = $document['type'] ?? null;

            $rules["person.documents.$key.issuingCountry"] = match (true) {
                in_array($type, $notUaTypes, true) => ['required', new InDictionary('ISSUING_COUNTRY'), 'not_in:UA'],
                in_array($type, $uaOnlyTypes, true) => ['required', 'in:UA'],
                // Outside both configurations any country is allowed, but the field itself is still required
                default => ['required', new InDictionary('ISSUING_COUNTRY')]
            };
        }
    }

    /**
     * When a foreign identity document is present, the documents array must contain only foreign types.
     *
     * @return void
     */
    private function validateForeignDocumentsExclusivity(): void
    {
        $foreignTypes = config('ehealth.identity_document_types_foreign');
        $submittedTypes = array_column($this->person['documents'], 'type');

        if (empty(array_intersect($submittedTypes, $foreignTypes))) {
            return;
        }

        if (!empty(array_diff($submittedTypes, $foreignTypes))) {
            throw ValidationException::withMessages([
                'person.documents' => __('validation.custom.person.only_foreign_documents_allowed')
            ]);
        }
    }

    /**
     * Apply the alphabet rule to each name group according to its selected language.
     *
     * @param  array  $rules
     * @return void
     */
    private function addNamesAlphabetValidation(array &$rules): void
    {
        foreach ($this->person['names'] ?? [] as $index => $name) {
            $language = $name['language'] ?? 'uk';

            if (!filter_var($name['noLastName'] ?? false, FILTER_VALIDATE_BOOL)) {
                $rules["person.names.$index.lastName"][] = new NameFields($language);
            }

            $rules["person.names.$index.firstName"][] = new NameFields($language);
            $rules["person.names.$index.secondName"][] = new NameFields($language);
        }
    }

    /**
     * The emergency contact name fields accept both Latin and Cyrillic letters when the person carries a foreign
     * identity document, and Cyrillic-only otherwise.
     *
     * @param  array  $rules
     * @return void
     */
    private function addEmergencyContactNameValidation(array &$rules): void
    {
        $nameRule = $this->hasForeignDocument()
            ? 'regex:/^[A-Za-zА-ЩЬЮЯҐЄІЇа-щьюяґєії\s\.\-\/\']+$/u'
            : new NameFields();

        $rules['person.emergencyContact.firstName'][] = $nameRule;
        $rules['person.emergencyContact.lastName'][] = $nameRule;
        $rules['person.emergencyContact.secondName'][] = $nameRule;
    }

    /**
     * Whether the submitted documents include an identity document type from IDENTITY_DOCUMENT_TYPES_FOREIGN.
     *
     * @return bool
     */
    private function hasForeignDocument(): bool
    {
        $foreignTypes = config('ehealth.identity_document_types_foreign');

        return collect($this->person['documents'] ?? [])
            ->contains(static fn (array $document): bool => in_array($document['type'] ?? null, $foreignTypes, true));
    }

    /**
     * When enabled by the VALIDATE_PERSON_TAX_ID_UNIQUENESS chart parameter, tax_id must be unique among existing
     * persons (ignoring the person currently being updated).
     *
     * @param  array  $rules
     * @return void
     */
    private function addTaxIdUniquenessValidation(array &$rules): void
    {
        if (!config('ehealth.validate_person_tax_id_uniqueness')) {
            return;
        }

        $rules['person.taxId'][] = Rule::unique('persons', 'tax_id')
            ->when(
                !empty($this->person['uuid']),
                fn ($rule) => $rule->ignore($this->person['uuid'], 'uuid')
            );
    }

    /**
     * The last_name field is required unless no_last_name is set, in which case it must be empty.
     *
     * @param  array  $rules
     * @return void
     */
    private function addNamesLastNameValidation(array &$rules): void
    {
        foreach ($this->person['names'] ?? [] as $index => $name) {
            $rules["person.names.$index.lastName"] = filter_var($name['noLastName'] ?? false, FILTER_VALIDATE_BOOL)
                ? ['prohibited']
                : ['required', 'min:3'];
        }
    }

    /**
     * A names entry with language 'en' is required for foreign documents, and an entry with 'uk' for non-foreign
     * documents or when a foreign document is accompanied by a tax_id.
     *
     * @return void
     */
    private function validateNamesLanguagePresence(): void
    {
        $foreignTypes = config('ehealth.identity_document_types_foreign');
        $submittedTypes = array_column($this->person['documents'] ?? [], 'type');
        $hasForeignDocument = (bool)array_intersect($submittedTypes, $foreignTypes);

        $languages = array_column($this->person['names'] ?? [], 'language');

        if ($hasForeignDocument && !in_array('en', $languages, true)) {
            throw ValidationException::withMessages([
                'person.names' => __('validation.custom.person.names_en_required_for_foreign')
            ]);
        }

        if (!$hasForeignDocument && !in_array('uk', $languages, true)) {
            throw ValidationException::withMessages([
                'person.names' => __('validation.custom.person.names_uk_required')
            ]);
        }

        if ($hasForeignDocument && !empty($this->person['taxId']) && !in_array('uk', $languages, true)) {
            throw ValidationException::withMessages([
                'person.names' => __('validation.custom.person.names_uk_required_with_tax_id')
            ]);
        }
    }

    /**
     * Validate necessity of confidant person.
     *
     * @return void
     */
    private function validateNecessityOfConfidantPerson(): void
    {
        // Below the self-registration age a confidant person is mandatory
        if ($this->personAge < self::NO_SELF_REGISTRATION_AGE && empty($this->person['confidantPerson']['personId'])) {
            throw ValidationException::withMessages([
                'person.confidantPerson' => __('validation.custom.person.confidant_person_required_for_children')
            ]);
        }

        // Between the self-registration age and the full legal capacity age
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
        $noSelfAuthAgeDocumentTypes = config('ehealth.no_self_auth_age_document_types');

        // Check submitted person document types exist in PERSON_REGISTRATION_DOCUMENT_TYPES config parameter
        // that contains values from DOCUMENT_TYPE dictionary
        $allAllowedTypes = array_merge($personLegalCapacityDocumentTypes, $personRegistrationDocumentTypes);

        foreach ($this->person['documents'] as $document) {
            if (!in_array($document['type'], (array)$allAllowedTypes, true)) {
                $documentTypeName = __('patients.documents.' . $document['type']) ?: $document['type'];
                throw ValidationException::withMessages([
                    'person.documents' => __(
                        'validation.custom.person.document_type_not_allowed',
                        ['document_type' => $documentTypeName]
                    )
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
                        'person.documents' => __(
                            'validation.custom.person.document_type_not_allowed_for_person',
                            ['document_type' => $documentTypeName]
                        )
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

        // Check if person age < prm.global_parameters.no_self_auth_age every document type is in NO_SELF_AUTH_AGE_DOCUMENT_TYPES
        if ($this->personAge < self::NO_SELF_AUTH_AGE) {
            $invalidTypes = array_diff($submittedTypes, $noSelfAuthAgeDocumentTypes);

            if (!empty($invalidTypes)) {
                $translatedTypes = array_map(static function (string $type): string {
                    return __('patients.documents.' . $type) ?: $type;
                }, $noSelfAuthAgeDocumentTypes);
                $allowedTypesList = implode(', ', $translatedTypes);

                throw ValidationException::withMessages([
                    'person.documents' => __(
                        'validation.custom.person.invalid_document_types_for_minor',
                        ['years' => $this->personAge, 'allowed_types' => $allowedTypesList]
                    )
                ]);
            }
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
                    'person.documents' => __(
                        'validation.custom.person.invalid_document_types_for_age',
                        ['allowed_types' => $allowedTypesList]
                    )
                ]);
            }
        }
    }
}
