<?php

declare(strict_types=1);

namespace App\Livewire\LegalEntity\Forms;

use Carbon\Carbon;
use Livewire\Form;
use App\Rules\Name;
use App\Models\User;
use App\Rules\Email;
use App\Rules\TaxId;
use App\Rules\AgeCheck;
use App\Rules\Cyrillic;
use App\Rules\BirthDate;
use App\Rules\DateFormat;
use App\Rules\ExpiryDate;
use App\Rules\PhoneNumber;
use App\Rules\InDictionary;
use App\Rules\UniqueEdrpou;
use App\Models\LegalEntity;
use App\Rules\DocumentNumber;
use App\Rules\PhoneDuplicates;
use Illuminate\Support\Facades\Log;
use App\Exceptions\CustomValidationException;
use Illuminate\Validation\ValidationException;

class LegalEntitiesForms extends Form
{
    public string $type = LegalEntity::TYPE_PRIMARY_CARE;

    protected string $positionKeys;

    public string $edrpou = '';

    public ?array $owner = [];

    public ?array $phones = [];

    public string $website = '';

    public string $email = '';

    public ?array $residenceAddress = [];

    public bool $beneficiaryShow = false;

    public bool $receiverFundsCodeShow = false;

    public bool $archivationShow = false;

    public bool $accreditationShow = false;

    public ?array $accreditation = [
        'category' => null,
        'orderNo' => null,
        'orderDate' => null,
        'issuedDate' => null,
        'expiryDate' => null,
    ];

    public array|null $license = [];

    public ?array $archive = [];

    public ?string $receiverFundsCode = '';

    public ?string $beneficiary = '';

    public ?array $publicOffer = [];

    public array $security = [
        'redirect_uri' => 'https://openhealths.com/ehealth/oauth',
    ];

    public function rules(): array
    {
        $rules = [
            'edrpou' => ['required', 'regex:/^(\d{8,10}|[А-ЯЁЇІЄҐ]{2}\d{6})$/', new UniqueEdrpou($this->type)],
            'owner.lastName' => ['required', 'min:3', new Name()],
            'owner.firstName' => ['required', 'min:3', new Name()],
            'owner.secondName' => ['nullable', new Name()],
            'owner.gender' => 'required|string',
            'owner.birthDate' => ['required', new DateFormat(), 'before_or_equal:today', new BirthDate($this->owner['email'] ?? ''), new AgeCheck()],
            'owner.noTaxId' => 'boolean|nullable',
            'owner.taxId' => ['required_unless:owner.noTaxId,true', 'string', new TaxId()],
            'owner.documents.type' => ['required','string', new InDictionary('DOCUMENT_TYPE')],
            'owner.documents.number' => ['required', 'string', new DocumentNumber($this->owner['documents']['type'] ?? '')],
            'owner.documents.issuedAt' => [
                'nullable',
                new DateFormat(),
                'before_or_equal:today',
                function ($attribute, $value, $fail) {
                    $birthDate = Carbon::parse($this->owner['birthDate']);
                    $issuedAt = Carbon::parse($value);

                    if ($issuedAt->lt($birthDate)) {
                        $fail(__('validation.attributes.errors.documentIssuedAtBirth'));
                    } elseif ($issuedAt->lt($birthDate->copy()->addYears(14))) {
                        $fail(__('validation.attributes.errors.documentIssuedAtAge'));
                    }
                }
            ],
            'owner.phones' => 'required|array',
            'owner.phones.*.number' => ['required', 'string', new PhoneNumber()],
            'owner.phones.*.type' => [
                'required',
                'string',
                new InDictionary('PHONE_TYPE'),
                new PhoneDuplicates($this->owner['phones'])
            ],
            'owner.email' => ['required','email',new Email()],
            'owner.position' => ['required','string', new InDictionary('POSITION')],
            'email' => ['required','email',new Email()],
            'website' => ['sometimes', 'regex:/^(https?:\/\/)?(www\.)?[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9]))+$/i'],
            'phones' => 'required|array',
            'phones.*.number' => ['required', 'string', new PhoneNumber()],
            'phones.*.type' => [
                'required',
                'string',
                new InDictionary('PHONE_TYPE'),
                new PhoneDuplicates($this->phones)
            ],
            'phones.*.note' => 'nullable|string',
            'accreditation' => 'nullable|array',
            'accreditation.category' => $this->accreditationShow ? 'required|string' : 'nullable',
            'accreditation.orderNo' => $this->accreditationShow ? 'required|string|min:2' : 'nullable|string',
            'accreditation.orderDate' => ['nullable', new DateFormat(), 'before_or_equal:today'],
            'accreditation.issuedDate' => ['nullable', new DateFormat(), 'before_or_equal:today'],
            'accreditation.expiryDate' => ['nullable', new DateFormat(), new ExpiryDate($this->accreditation['issuedDate'] ?? '')],
            'license.type' => 'required|string',
            'license.issuedBy' => ['required', 'string','min:3',new Cyrillic()],
            'license.issuedDate' => ['required', new DateFormat(), 'before_or_equal:today'],
            'license.activeFromDate' => ['required', new DateFormat()],
            'license.expiryDate' => ['nullable', new DateFormat(), new ExpiryDate($this->license['activeFromDate'] ?? '')],
            'license.orderNo' => 'required|string',
            'license.licenseNumber' => ['nullable', 'string', 'regex:/^(?!.*[ЫЪЭЁыъэё@$^#])[a-zA-ZА-ЯҐЇІЄа-яґїіє0-9№\"!\^\*)\]\[(&._-].*$/'],
            'receiverFundsCode' => [
                'nullable',
                'string',
                'regex:/^[0-9]+$/'
            ],
            'beneficiary' => [
                'nullable',
                'string',
                new Cyrillic(),
                "regex:/^(?!.*[ЫЪЭЁыъэё@%&$^#])[А-ЯҐЇІЄа-яґїіє’\'\- ]+$/u",
            ],
            'archive' => 'nullable|array',
            'archive.*.date' => ['required_if:archivationShow,true', new DateFormat(), 'before_or_equal:today'],
            'archive.*.place' => 'required_if:archivationShow,true|string',
        ];

        if (($this->accreditation['category'] ?? null) !== 'NO_ACCREDITATION' && $this->accreditationShow) {
            $rules['accreditation.orderDate'] = ['required', new DateFormat(), 'before_or_equal:today'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'edrpou.required' => __('validation.attributes.errors.requiredField'),
            'edrpou.regex' => __('validation.attributes.errors.wrongFieldFormat'),
            'edrpou.unique_edrpou' => __('validation.attributes.errors.numberExist'),
            'owner.firstName.required' => __('validation.attributes.errors.requiredFirstName'),
            'owner.lastName.required' => __('validation.attributes.errors.requiredLastName'),
            'owner.birthDate.required' => __('validation.attributes.errors.requiredBirthDate'),
            'owner.birthDate.before_or_equal' => __('validation.attributes.errors.ownerAge'),
            'owner.age_check' => __('validation.attributes.errors.requiredField'),
            'owner.gender' => __('validation.attributes.errors.requiredField'),
            'owner.phones' => __('validation.attributes.errors.requiredContactPhone'),
            'owner.taxId.required_unless' => __('validation.attributes.errors.requiredTaxId'),
            'owner.documents.type.required' => __('validation.attributes.errors.requiredDocumentType'),
            'owner.documents.issuedAt.before_or_equal' => __('validation.attributes.errors.expiryDateGreat'),
            'owner.position.required' => __('validation.attributes.errors.requiredPostion'),
            'owner.email.unique' => __('validation.attributes.errors.requiredEmail'),
            'owner.phones.required' => __('validation.attributes.errors.requiredPhone'),
            'owner.phones.array' => __('validation.attributes.errors.requiredPhoneArray'),
            'owner.phones.*.number.required' => __('validation.attributes.errors.requiredPhoneNumber'),
            'owner.phones.*.number.regex' => __('validation.attributes.errors.requiredPhoneNumberMax'),
            'owner.phones.*.type.required' => __('validation.attributes.errors.requiredPhoneType'),
            'owner.phones.*.type.' . InDictionary::class => __('validation.attributes.errors.requiredPhoneTypeSpeciality'),
            'website.required' => __('validation.attributes.errors.requiredField'),
            'website' => __('validation.attributes.errors.wrongFieldFormat'),
            'phones.required' => __('validation.attributes.errors.requiredPhone'),
            'phones.array' => __('validation.attributes.errors.requiredPhoneArray'),
            'phones.*.number.required' => __('validation.attributes.errors.requiredPhoneNumber'),
            'phones.*.number.regex' => __('validation.attributes.errors.requiredPhoneNumberMax'),
            'phones.*.type.required' => __('validation.attributes.errors.requiredPhoneType'),
            'phones.*.type.' . InDictionary::class => __('validation.attributes.errors.requiredPhoneTypeSpeciality'),
            'accreditation.category.required_if' => __('validation.attributes.errors.requiredCategory'),
            'accreditation.orderNo.required' => __('validation.attributes.errors.requiredOrderNumber'),
            'accreditation.orderDate.required_if' => __('validation.attributes.errors.requiredOrderDate'),
            'accreditation.orderDate.before_or_equal' => __('validation.attributes.errors.expiryDateGreat'),
            'accreditation.issuedDate.before_or_equal' => __('validation.attributes.errors.expiryDateGreat'),
            'accreditation.orderNo.min' => __('validation.attributes.errors.wrongFieldFormat') . '('. __('validation.attributes.errors.minLen2') . ')',
            'accreditation.category' => __('validation.attributes.errors.wrongFieldFormat'),
            'accreditation.orderNo' => __('validation.attributes.errors.wrongFieldFormat'),
            'license.issuedDate' => __('validation.attributes.errors.requiredIssuedDate'),
            'license.issuedDate.before_or_equal' => __('validation.attributes.errors.expiryDateGreat'),
            'license.activeFromDate' => __('validation.attributes.errors.requiredActiveFromDate'),
            'license.issuedBy.min' => __('validation.attributes.errors.wrongFieldFormat') . '('. __('validation.attributes.errors.minLen3') . ')',
            'license.issuedBy' => __('validation.attributes.errors.requiredIssuedBy'),
            'license.orderNo' => __('validation.attributes.errors.requiredOrderNumber'),
            'license.licenseNumber.regex' => __('validation.attributes.errors.onlyNumericLatin'),
            'receiverFundsCode' => __('validation.attributes.errors.wrongFieldFormat') . '('. __('validation.attributes.errors.onlyNumeric') . ')',
            'receiverFundsCode.required' => __('validation.attributes.errors.nonEmpty'),
            'receiverFundsCode.regex' => __('validation.attributes.errors.wrongSymbols'),
            'beneficiary.min' => __('validation.attributes.errors.wrongFieldFormat') . '('. __('validation.attributes.errors.minLen3') . ')',
            'beneficiary.required' => __('validation.attributes.errors.nonEmpty'),
            'beneficiary.regex' => __('validation.attributes.errors.wrongSymbols'),
            'beneficiary' => __('validation.attributes.errors.wrongFieldFormat') . '('. __('validation.attributes.errors.onlyCyrillic') . ')',
            'archive.*.date.required_if' => __('validation.attributes.errors.requiredField'),
            'archive.*.date.before_or_equal' => __('validation.attributes.errors.expiryDateGreat'),
            'archive.*.place.required_if' => __('validation.attributes.errors.requiredField'),
        ];
    }

    public function allFieldsValidate()
    {
        $errors = [];

        try {
            $errors = $this->component->addressValidation();

            try {
                $this->rulesForSignificancy();
            } catch (ValidationException $e) {
                $errors = array_merge($e->errors(), $errors);
            }

            $this->validate();

            if (!empty($errors)) {
                throw ValidationException::withMessages($errors);
            }
        } catch (ValidationException $err) {
            $errors = array_merge($err->errors(), $errors);

            // Throw an validation error from Division's side
            throw ValidationException::withMessages($errors);
        }
    }

    public function rulesForAddresses()
    {
        $errors = [];

        $errors = $this->component->addressValidation();

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @throws ValidationException
     */
    public function rulesForEdrpou(): array
    {
        return $this->validate($this->rulesForModel('edrpou')->toArray());
    }

    /**
     * @throws ValidationException
     */
    public function rulesForOwner(): void
    {
        $this->validate($this->rulesForModel('owner')->toArray());

        $user = User::where('email', $this->owner['email'])->first();

        $userTaxId = $user?->party?->taxId;

        if ($user &&
            filled($userTaxId) &&
            isset($this->owner['taxId']) &&
            $userTaxId !== $this->owner['taxId']
        ) {
            Log::error("rulesForOwner: user with specified email exists and has different tax ID");

            throw ValidationException::withMessages([
                'legalEntityForm.owner.email' => __('forms.email_restriction'),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function rulesForContact(): void
    {
        // Validate email
        $emailRules = $this->rulesForModel('email')->toArray();

        // Validate website
        $websiteRules = $this->rulesForModel('website')->toArray();

        // Validate phones array rules
        $phonesRules = $this->rulesForModel('phones')->toArray();

        $modelRules = array_merge($emailRules, $websiteRules, $phonesRules);

        $this->validate($modelRules);
    }

    /**
     * @throws ValidationException
     */
    public function rulesForAccreditation(): void
    {
        // Validate accreditation array rules
        $this->validate($this->rulesForModel('accreditation')->toArray());
    }

    /**
     * @throws ValidationException
     */
    public function rulesForLicense()
    {
        // Validate license array rules
        $this->validate($this->rulesForModel('license')->toArray());
    }

    /**
     * @throws ValidationException
     */
    public function rulesForAdditionalInformation(): void
    {
        // Validate archive array rules
        $archiveRules = $this->rulesForModel('archive')->toArray();

        // Validate beneficiary
        $beneficiaryRules = $this->rulesForModel('beneficiary')->toArray();

        // Validate receiver_funds_code
        $fundsCodeRules = $this->rulesForModel('receiverFundsCode')->toArray();

        $modelRules = array_merge($archiveRules, $beneficiaryRules, $fundsCodeRules);

        $this->validate($modelRules);
    }

    public function rulesForSignificancy()
    {
        $this->component->validate($this->component->getRules());
    }

    /**
     * Rules for business-logic validation
     *
     * @return string
     */
    public function customRulesValidation(): bool
    {
        foreach ($this->customRules() as $rule) {
            try {
                $rule->validate('', '', fn () => null);
            } catch (CustomValidationException $e) {
                $this->component->dispatch('flashMessage', ['message' => $e->getMessage(), 'type' => 'error']);

                return false;
            }
        }

        return true;
    }

    /**
     * TODO: add rule for next cases: Or remove this after MVP (if not needed)
     *  - Check custom validation rules (mostly for business-logic)
     *
     * @return array
     */
    protected function customRules(): array
    {
        // Place here the custom validation rules to be checked through creation/updating of the LegalEntity
        return [
            new PhoneDuplicates($this->phones),
            new PhoneDuplicates($this->owner['phones'])
        ];
    }

    /**
     * Handles updates to the beneficiary value.
     *
     * @param  string  $value  The updated beneficiary value.
     * @return void
     */
    public function onBeneficiaryUpdated(string $value): void
    {
        $this->beneficiaryShow = !empty($value);
    }

    /**
     * Handle updates to the receiver funds code value.
     *
     * @param  string  $value  The updated receiver funds code.
     * @return void
     */
    public function onReceiverFundsCodeUpdated(string $value): void
    {
        $this->receiverFundsCodeShow = !empty($value);
    }
}
