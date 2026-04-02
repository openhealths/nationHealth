<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\EHealthRequest as Request;

class PatientApiBase extends Request
{
    protected const string URL = '/api/patients';

    /**
     * Replace eHealth property names with the ones used in the application.
     * E.g., id => uuid, inserted_at => ehealth_inserted_at.
     */
    protected function replaceEHealthPropNames(array $properties): array
    {
        $mapping = $this->propertyMapping();
        $replaced = [];

        foreach ($properties as $name => $value) {
            $replaced[$mapping[$name] ?? $name] = $value;
        }

        return $replaced;
    }

    protected function propertyMapping(): array
    {
        return [
            'id' => 'uuid',
            'inserted_at' => 'ehealth_inserted_at',
            'updated_at' => 'ehealth_updated_at',
        ];
    }
}
