<?php

declare(strict_types=1);

namespace App\Rules\DivisionRules;

use Closure;
use App\Models\Division;
use App\Exceptions\CustomValidationException;
use Illuminate\Contracts\Validation\ValidationRule;

class TypeRule implements ValidationRule
{
    /**
     * List of division type rule method names to be checked during validation.
     *
     * Each method in this list should return a boolean indicating whether the rule passes.
     * Used in the validate() method to sequentially check all division type rules.
     *
     * @var array
     */
    public const array DIVISION_TYPE_RULES_LIST = [
        'checkDivisionType',
        'checkMapping'
    ];

    protected string $message;

    protected array $dictionaries;

    protected array $division;

    public function __construct(array $division)
    {
        $this->division = $division;

        $this->message = __('validation.attributes.healthcareService.error.division.commonError');
    }

    /**
     * Division main rules validation
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (self::DIVISION_TYPE_RULES_LIST as $check) {
            if (!$this->$check()) {
                $this->throwError();
            }
        }
    }

    /**
     * Throw a custom validation exception with the current error message.
     *
     * This method is called when a division type rule fails validation.
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
     * Check that type exists in dictionaries
     *
     * @return bool
     */
    protected function checkDivisionType(): bool
    {
        $divisionType = $this->division['type'];
        $dictionary = dictionary()->basics()->byName('DIVISION_TYPE')->asCodeDescription()->toArray();

        if (!in_array($divisionType, array_keys($dictionary))) {
            $this->setMessage(__('validation.attributes.healthcareService.error.division.type'));

            return false;
        }

        return true;
    }

    /**
     * Check mapping of legal_entity_type and division type
     *
     * @return bool
     */
    protected function checkMapping(): bool
    {
        $legalEntityType = legalEntity()->type->name;
        $divisionType = $this->division['type'];

        if (in_array($divisionType, Division::getValidDivisionTypes()) &&
            in_array($legalEntityType, Division::getValidLegalEntityTypes())
        ) {
            return true;
        }

        $this->setMessage(__('validation.attributes.healthcareService.error.division.mapping'));

        return false;
    }
}
