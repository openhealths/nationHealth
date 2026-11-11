<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses;

use Illuminate\Support\Str;

/**
 * Validated single healthcare service response data.
 * Top-level keys become camelCase properties, the same names as in the form and the DTO.
 * Every validated field is declared, so the strict property accessor reads an omitted optional field as null.
 * Nested values are kept as they came from eHealth.
 */
final class HealthcareServiceResponse
{
    public string $uuid;

    /**
     * eHealth division uuid; the list sync replaces it with the local division id.
     */
    public string|int $divisionId;

    /**
     * eHealth legal entity uuid; the list sync replaces it with the local legal entity id.
     */
    public string|int $legalEntityId;

    public array $category;

    public ?array $type = null;

    public ?string $specialityType = null;

    public ?string $providingCondition = null;

    public ?string $licenseId = null;

    public ?array $licensedHealthcareService = null;

    public ?string $comment = null;

    public ?array $coverageArea = null;

    public ?array $availableTime = null;

    public ?array $notAvailable = null;

    public string $status;

    public bool $isActive;

    public string $ehealthInsertedAt;

    public string $ehealthInsertedBy;

    public string $ehealthUpdatedAt;

    public string $ehealthUpdatedBy;

    /**
     * @param  array  $data  Validated response data with snake_case keys
     */
    public function __construct(array $data)
    {
        foreach ($data as $key => $value) {
            $this->{Str::camel($key)} = $value;
        }
    }
}
