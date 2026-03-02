<?php

declare(strict_types=1);

namespace App\Rules\DivisionRules;

use Closure;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Models\Relations\Address;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Exceptions\CustomValidationException;

class AddressRule implements ValidationRule
{
    /**
     * List of address validation rule method names to be checked.
     *
     * Each method in this list should return a boolean indicating whether the rule passes.
     * Used in the validate() method to sequentially check all address-related rules.
     *
     * @var array
     */
    public const array ADDRESS_RULES_LIST = [
        'checkAddressType',
        'checkSettlementType',
        'checkStreetType',
        'checkZipCode',
        'checkMapping'
    ];

    protected string $message;

    protected array $dictionaries;

    protected array $division;

    public function __construct(array $division)
    {
        $this->division = $division;
        $this->message = __('divisions.errors.address.commonError');
        $this->dictionaries = dictionary()->getDictionaries(['ADDRESS_TYPE', 'SETTLEMENT_TYPE', 'STREET_TYPE']);
    }

    /**
     * Check that all bunch of the address' data is correct and valid
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (self::ADDRESS_RULES_LIST as $check) {
            if (!$this->$check()) {
                $this->throwError();
            }
        }
    }

    /**
     * Throw a custom validation exception with the current error message.
     *
     * This method is called when a address type rule fails validation.
     *
     * @return void
     * @throws CustomValidationException
     */
    protected function throwError(): void
    {
        throw new CustomValidationException($this->message(), 'custom');
    }

    /**
     * Set the custom error message for the validation rule.
     *
     * This message will be used when throwing a validation exception.
     *
     * @param  string  $message  The error message to set.
     * @return void
     */
    protected function setMessage(string $message): void
    {
        $this->message = $message;
    }

    /**
     * Get the current error message for the validation rule.
     *
     * @return string The error message.
     */
    protected function message(): string
    {
        return $this->message;
    }

    /**
     * Check that addresses.type exists in dictionaries
     *
     * @return bool
     */
    protected function checkAddressType(): bool
    {
        foreach ($this->division['addresses'] as $address) {
            $addressType = $address['type'] ?? '';

            if (!in_array($addressType, array_keys($this->dictionaries['ADDRESS_TYPE']))) {
                $this->setMessage(__('divisions.errors.address.type'));

                return false;
            }
        }

        return true;
    }

    /**
     * Check that addresses.settlement_type exists in dictionaries
     *
     * @return bool
     */
    protected function checkSettlementType(): bool
    {
        foreach ($this->division['addresses'] as $address) {
            $settlementType = $address['settlementType'] ?? '';

            if (!in_array($settlementType, array_keys($this->dictionaries['SETTLEMENT_TYPE']))) {
                $this->setMessage(__('divisions.errors.address.settlementType'));

                return false;
            }
        }

        return true;
    }

    /**
     * Check that addresses.street_type exists in dictionaries
     *
     * @return bool
     */
    protected function checkStreetType(): bool
    {
        foreach ($this->division['addresses'] as $address) {
            $streetType = $address['streetType'] ?? '';

            if (!in_array($streetType, array_keys($this->dictionaries['STREET_TYPE']))) {
                $this->setMessage(__('divisions.errors.address.streetType'));

                return false;
            }
        }

        return true;
    }

    /**
     * Check that addresses.zip has no more than 5 digits
     *
     * @return bool
     */
    protected function checkZipCode(): bool
    {
        foreach ($this->division['addresses'] as $address) {
            $zipCode = $address['zip'] ?? '';

            if (!empty($zipCode) && !preg_match('/^[0-9]{5}$/', $zipCode)) {
                $this->setMessage(__('divisions.errors.address.zip'));

                return false;
            }
        }

        return true;
    }

    /**
     * Check mapping legal_entity_type, division_type and address_type and its obligation
     *
     * @return bool
     */
    protected function checkMapping(): bool
    {
        $legalEntityType = legalEntity()->type->name;
        $divisionType = $this->division['type'];

        foreach ($this->division['addresses'] as $address) {
            $addressType = $address['type'] ?? '';

            if (!in_array($divisionType, Division::getValidDivisionTypes()) ||
                !in_array($legalEntityType, Division::getValidLegalEntityTypes()) ||
                !$this->checkAddressObligation($addressType, $legalEntityType)
            ) {
                $this->setMessage(__('divisions.errors.address.mapping'));

                return false;
            }
        }

        return true;
    }

    /**
     * Check if an address is obligatory for a given address type and legal entity type.
     *
     * @param  string  $addressType  The type of address (e.g., 'RESIDENCE', 'RECEPTION')
     * @param  string  $legalEntityType  The type of legal entity (e.g., 'PRIMARY_CARE', 'OUTPATIENT')
     * @return bool Returns true if the address is mandatory for the specified combination, false otherwise
     */
    protected function checkAddressObligation(string $addressType, string $legalEntityType): bool
    {
        if ($addressType === Address::RECEPTION_TYPE && $legalEntityType === LegalEntity::TYPE_OUTPATIENT) {
            return true;
        }

        if (Address::DEFAULT_TYPE === $addressType) {
            return true;
        }

        return false;
    }
}
