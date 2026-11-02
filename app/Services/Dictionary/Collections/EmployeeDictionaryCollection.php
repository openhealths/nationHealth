<?php

declare(strict_types=1);

namespace App\Services\Dictionary\Collections;

/**
 * Employee choices combine active eHealth codes with the published role mapping.
 * Labels deliberately include retired codes so existing documents remain readable.
 */
class EmployeeDictionaryCollection extends BasicDictionaryCollection
{
    public const array ROLE_DICTIONARIES = [
        'POSITION' => 'position',
        'SPECIALITY_TYPE' => 'speciality_type',
        'SPECIALITY_LEVEL' => 'speciality_level',
        'EDUCATION_DEGREE' => 'education_degree',
        'QUALIFICATION_TYPE' => 'qualification_type',
        'SPEC_QUALIFICATION_TYPE' => 'speciality_qualification_type',
    ];

    /** @return array<string, string> */
    public function employeeTypes(string $legalEntityType): array
    {
        $allowed = config('ehealth.legal_entity_employee_types.'.$legalEntityType, []);

        return array_intersect_key($this->activeOptions('EMPLOYEE_TYPE'), array_flip($allowed));
    }

    /** @return array<string, string> */
    public function activeOptions(string $dictionaryName): array
    {
        $dictionary = $this->firstWhere('name', $dictionaryName);

        if (!$dictionary || !($dictionary['is_active'] ?? true)) {
            return [];
        }

        return $this->byName($dictionaryName)->asCodeDescription()->all();
    }

    /** @return array<string, string> */
    public function optionsForEmployeeType(string $employeeType, string $dictionaryName): array
    {
        $options = $this->activeOptions($dictionaryName);
        $configKey = self::ROLE_DICTIONARIES[$dictionaryName] ?? null;

        if ($configKey === null) {
            return $options;
        }

        $allowed = config('ehealth.employee_type.'.$employeeType.'.'.$configKey, []);

        return array_intersect_key($options, array_flip($allowed));
    }

    /** @return array<string, array<string, string>> */
    public function optionsByEmployeeType(string $legalEntityType, string $dictionaryName): array
    {
        $options = [];

        foreach (array_keys($this->employeeTypes($legalEntityType)) as $employeeType) {
            $options[$employeeType] = $this->optionsForEmployeeType($employeeType, $dictionaryName);
        }

        return $options;
    }

    /** @return array<string, array<string, string>> */
    public function labels(array $dictionaryNames): array
    {
        $labels = [];

        foreach ($dictionaryNames as $name) {
            $labels[$name] = $this->contains('name', $name)
                ? $this->byName($name, onlyActive: false)->asCodeDescription()->all()
                : [];
        }

        return $labels;
    }
}
