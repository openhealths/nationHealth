<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthRequest as Request;

class PatientApiBase extends Request
{
    protected const string URL = '/api/patients';

    /**
     * Replace recursively eHealth property names with the ones used in the application.
     * E.g., id => uuid, inserted_at => ehealth_inserted_at.
     */
    protected function replaceEHealthPropNames(array $properties): array
    {
        $mapping = $this->propertyMapping();
        $replaced = [];

        foreach ($properties as $name => $value) {
            $newName = $mapping[$name] ?? $name;
            $replaced[$newName] = is_array($value) ? $this->replaceEHealthPropNames($value) : $value;
        }

        return $replaced;
    }

    protected function propertyMapping(): array
    {
        return [
            'id' => 'uuid',
            'inserted_at' => 'ehealth_inserted_at',
            'inserted_by' => 'ehealth_inserted_by',
            'updated_at' => 'ehealth_updated_at'
        ];
    }
}
