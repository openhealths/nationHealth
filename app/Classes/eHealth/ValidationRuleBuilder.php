<?php

declare(strict_types=1);

namespace App\Classes\eHealth;

class ValidationRuleBuilder
{
    /**
     * Generate validation rules for identifier-type relationship.
     * Pattern: field -> identifier -> type -> coding -> [code, system]
     */
    public static function identifierRules(string $field, bool $isRequired = false): array
    {
        $fieldRule = $isRequired ? 'required' : 'nullable';

        return [
            $field => [$fieldRule, 'array'],
            "$field.display_value" => ['nullable', 'string', 'max:255'],
            "$field.identifier" => ["required_with:$field", 'array'],
            "$field.identifier.type" => ["required_with:$field.identifier", 'array'],
            "$field.identifier.type.text" => ['nullable', 'string', 'max:255'],
            "$field.identifier.type.coding" => ["required_with:$field.identifier.type", 'array'],
            "$field.identifier.type.coding.*.code" => [
                "required_with:$field.identifier.type.coding",
                'string',
                'max:255'
            ],
            "$field.identifier.type.coding.*.system" => [
                "required_with:$field.identifier.type.coding",
                'string',
                'max:255'
            ],
            "$field.identifier.value" => ["required_with:$field.identifier", 'uuid']
        ];
    }

    /**
     * Generate validation rules for collections of identifier relationships.
     * Pattern: field -> * -> identifier -> type -> coding -> [code, system]
     */
    public static function identifierCollectionRules(string $field, bool $isRequired = false): array
    {
        $fieldRule = $isRequired ? 'required' : 'nullable';

        return [
            $field => [$fieldRule, 'array'],
            "$field.*.display_value" => ['nullable', 'string', 'max:255'],
            "$field.*.identifier" => ["required_with:$field", 'array'],
            "$field.*.identifier.type" => ["required_with:$field.*.identifier", 'array'],
            "$field.*.identifier.type.text" => ['nullable', 'string', 'max:255'],
            "$field.*.identifier.type.coding" => ["required_with:$field.*.identifier.type", 'array'],
            "$field.*.identifier.type.coding.*.code" => [
                "required_with:$field.*.identifier.type.coding",
                'string',
                'max:255'
            ],
            "$field.*.identifier.type.coding.*.system" => [
                "required_with:$field.*.identifier.type.coding",
                'string',
                'max:255'
            ],
            "$field.*.identifier.value" => ["required_with:$field.*.identifier", 'uuid']
        ];
    }

    /**
     * Generate validation rules for codeable concept.
     * Pattern: field -> coding -> [code, system]
     */
    public static function codeableConceptRules(string $field, bool $isRequired = false): array
    {
        $fieldRule = $isRequired ? 'required' : 'nullable';

        return [
            $field => [$fieldRule, 'array'],
            "$field.coding" => ["required_with:$field", 'array'],
            "$field.coding.*.code" => ["required_with:$field", 'string', 'max:255'],
            "$field.coding.*.system" => ["required_with:$field", 'string', 'max:255'],
            "$field.text" => ['nullable', 'string', 'max:255']
        ];
    }

    /**
     * Generate validation rules for collection of codeable concepts.
     * Pattern: field -> * -> coding -> [code, system]
     */
    public static function codeableConceptCollectionRules(string $field, bool $isRequired = false): array
    {
        $fieldRule = $isRequired ? 'required' : 'nullable';

        return [
            $field => [$fieldRule, 'array'],
            "$field.*.coding" => ["required_with:$field", 'array'],
            "$field.*.coding.*.code" => ["required_with:$field", 'string', 'max:255'],
            "$field.*.coding.*.system" => ["required_with:$field", 'string', 'max:255'],
            "$field.*.text" => ['nullable', 'string', 'max:255']
        ];
    }

    /**
     * Generate validation rules for performer/interpreter-type relationship.
     * Pattern: field -> reference -> identifier -> type -> coding
     */
    public static function referenceRules(string $field, bool $isRequired = false): array
    {
        $fieldRule = $isRequired ? 'required' : 'nullable';

        return [
            $field => [$fieldRule, 'array'],
            "$field.reference" => ["required_with:$field", 'array'],
            "$field.text" => ['nullable', 'string', 'max:255'],
            "$field.reference.display_value" => ['nullable', 'string', 'max:255'],
            "$field.reference.identifier" => ["required_with:$field.reference", 'array'],
            "$field.reference.identifier.type" => ["required_with:$field.reference.identifier", 'array'],
            "$field.reference.identifier.type.text" => ['nullable', 'string', 'max:255'],
            "$field.reference.identifier.type.coding" => ["required_with:$field.reference.identifier.type", 'array'],
            "$field.reference.identifier.type.coding.*.code" => [
                "required_with:$field.reference.identifier.type.coding",
                'string',
                'max:255'
            ],
            "$field.reference.identifier.type.coding.*.system" => [
                "required_with:$field.reference.identifier.type.coding",
                'string',
                'max:255'
            ],
            "$field.reference.identifier.value" => ["required_with:$field.reference.identifier", 'uuid']
        ];
    }

    /**
     * Generate validation rules for paper referral.
     */
    public static function paperReferralRules(string $field = 'paper_referral'): array
    {
        return [
            $field => ['nullable', 'array'],
            "$field.requisition" => ['nullable', 'string'],
            "$field.requester_legal_entity_name" => ['nullable', 'string'],
            "$field.requester_legal_entity_edrpou" => ["required_with:$field", 'string'],
            "$field.requester_employee_name" => ["required_with:$field", 'string'],
            "$field.service_request_date" => ["required_with:$field", 'date'],
            "$field.note" => ['nullable', 'string'],
        ];
    }

    /**
     * Generate validation rules for simple coding structure.
     * Pattern: field -> [code, system]
     */
    public static function codingRules(string $field, bool $isRequired = false): array
    {
        $fieldRule = $isRequired ? 'required' : 'nullable';

        return [
            $field => [$fieldRule, 'array'],
            "$field.code" => ["required_with:$field", 'string', 'max:255'],
            "$field.system" => ["required_with:$field", 'string', 'max:255']
        ];
    }

    /**
     * Generate validation rules for collection of coding.
     * Pattern: field -> * -> [code, system]
     */
    public static function codingCollectionRules(string $field, bool $isRequired = false): array
    {
        $fieldRule = $isRequired ? 'required' : 'nullable';

        return [
            $field => [$fieldRule, 'array'],
            "$field.*.code" => ["required_with:$field", 'string', 'max:255'],
            "$field.*.system" => ["required_with:$field", 'string', 'max:255']
        ];
    }

    /**
     * Generate validation rules for period fields.
     */
    public static function periodRules(string $field = 'effective_period', bool $isRequired = false): array
    {
        $fieldRule = $isRequired ? 'required' : 'nullable';

        return [
            $field => [$fieldRule, 'array'],
            "$field.start" => ["required_with:$field", 'date'],
            "$field.end" => ['nullable', 'date']
        ];
    }

    /**
     * Merge multiple rule sets together.
     */
    public static function merge(array ...$ruleSets): array
    {
        return array_merge(...$ruleSets);
    }
}
