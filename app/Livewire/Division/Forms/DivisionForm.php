<?php

declare(strict_types=1);

namespace App\Livewire\Division\Forms;

use App\Rules\Email;
use App\Models\Division;
use App\Traits\FormTrait;
use App\Rules\PhoneNumber;
use App\Rules\InDictionary;
use App\Rules\PhoneDuplicates;
use Livewire\Attributes\Validate;
use App\Rules\DivisionRules\TypeRule;
use App\Repositories\AddressRepository;
use App\Rules\DivisionRules\AddressRule;
use App\Rules\DivisionRules\LocationRule;
use App\Rules\DivisionRules\WorkingHoursRule;
use App\Exceptions\CustomValidationException;
use App\Rules\DivisionRules\LocationTypeRule;
use Livewire\Features\SupportFormObjects\Form;
use Illuminate\Validation\ValidationException;
use App\Rules\DivisionRules\LegalEntityStatusRule;

// TODO: (after divide DivisionForm onto three classes) rename this one to the DivisionForm
class DivisionForm extends Form
{
    use FormTrait;

    protected ?AddressRepository $addressRepository;

    /**
     * Indicates whether the reception address functionality is enabled for the division.
     *
     * @var bool
     */
    public bool $showReceptionAddress = false;

    #[Validate([
        'division.name' => 'required|min:6|max:255',
        'division.type' => 'required',
        'division.email' => ['required', 'email', new Email()],
        'division.addresses' => 'required',
    ])]

    public ?array $division = [
        'workingHours' => [
            'mon' => [[Division::WORKING_TIME_DEFAULT_START, Division::WORKING_TIME_DEFAULT_END]],
            'tue' => [[Division::WORKING_TIME_DEFAULT_START, Division::WORKING_TIME_DEFAULT_END]],
            'wed' => [[Division::WORKING_TIME_DEFAULT_START, Division::WORKING_TIME_DEFAULT_END]],
            'thu' => [[Division::WORKING_TIME_DEFAULT_START, Division::WORKING_TIME_DEFAULT_END]],
            'fri' => [[Division::WORKING_TIME_DEFAULT_START, Division::WORKING_TIME_DEFAULT_END]],
            'sat' => [[Division::WORKING_TIME_DEFAULT_START, Division::WORKING_TIME_DEFAULT_END]],
            'sun' => [[Division::WORKING_TIME_DEFAULT_START, Division::WORKING_TIME_DEFAULT_END]]
        ],
        'location' => [
            'latitude' => Division::LOCATION_DEFAULT_LATITUDE,
            'longitude' => Division::LOCATION_DEFAULT_LONGITUDE
        ],
        'phones' => []
    ];

    public string $search = '';

    public function boot(AddressRepository $addressRepository)
    {
        $this->addressRepository = $addressRepository;
    }

    /**
     * Get the current division form data as an array.
     *
     * @return array The division form data.
     */
    public function getDivision(): array
    {
        return $this->division;
    }

    /**
     * Set the division's form data.
     * Replaces the current division's form data with the provided array.
     *
     * @param  array  $division  The division data to set in the form.
     * @return void
     */
    public function setDivision(array $division)
    {
        $this->division = $division;
    }

    /**
     * Do form's validation (check correctness of filling the form fields)
     *
     * @return mixed
     */
    public function doValidation(): string
    {
        $this->resetErrorBag();

        $errors = [];

        $this->checkDefaultAddress();

        try {
            $errors = $this->component->addressValidation();

            if ($this->showReceptionAddress) {
                $errors1 = $this->component->receptionAddressValidation();
                $errors = \array_merge($errors, $errors1);
            }

            $this->validate();

            if (!empty($errors)) {
                throw ValidationException::withMessages($errors);
            }
        } catch (ValidationException $err) {
            $errors = \array_merge($err->errors(), $errors);

            // Throw an validation error from Division's side
            throw ValidationException::withMessages($errors);
        }

        $failMessage = $this->customRulesValidation();

        return $failMessage;
    }

    public function rules(): array
    {
        return [
            'division.externalId' => 'nullable|integer|gt:0',
            'division.location.longitude' => ['nullable', 'numeric', new LocationRule($this->division)],
            'division.location.latitude' => ['nullable', 'numeric', new LocationRule($this->division)],
            'division.phones' => 'required|array',
            'division.phones.*.number' => ['required', 'string', new PhoneNumber()],
            'division.phones.*.type' => [
                'required',
                'string',
                new InDictionary('PHONE_TYPE'),
                new PhoneDuplicates($this->division['phones'])
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'division.externalId.integer' => __('divisions.errors.external_id'),
            'division.email.required' => __('divisions.errors.email.required'),
            'division.email.email' => __('divisions.errors.email.wrong'),
            'division.phones.*.type' => __('divisions.errors.phone.type_required'),
            'division.phones.*.number' => __('divisions.errors.phone.number_required')
        ];
    }

    /**
     * Get the list of custom validation rules for the division form.
     *
     * These rules cover business logic validation such as:
     * - Legal entity status
     * - Location requirements for certain division types
     * - Address data validity
     * - Working hours schedule correctness
     * - Division type existence in dictionaries
     *
     * @return array An array of custom validation rule instances.
     */
    protected function customRules()
    {
        return [
            // Check that legal entity is in ‘ACTIVE’ or ‘SUSPENDED’ status
            new LegalEntityStatusRule(),
            // Check that location exists in request for legal entity with type PHARMACY
            new LocationTypeRule($this->division),
            // Check that all bunch of the address' data is correct and valid
            new AddressRule($this->division),
            // Check that working hours schedule is correct
            new WorkingHoursRule($this->division),
            // Check that Division type exists in dictionaries
            new TypeRule($this->division),
        ];
    }

    /**
     * Rules for business-logic validation
     *
     * @return string
     */
    protected function customRulesValidation(): string
    {
        foreach ($this->customRules() as $rule) {
            try {
                $rule->validate('', '', fn () => null);
            } catch (CustomValidationException $e) {
                return $e->getMessage();
            }
        }

        return '';
    }

    /**
     * Checks and validates the default address for the division.
     * For Division addresses where the area is 'М.КИЇВ' and the street type must be set as mandatory.
     *
     * @return void
     */
    protected function checkDefaultAddress(): void
    {
        foreach ($this->division['addresses'] as &$address) {
            if (isset($address['area']) && $address['area'] === 'М.КИЇВ' && empty($address['streetType'])) {
                $address['streetType'] = 'STREET';
            }
        }
    }
}
