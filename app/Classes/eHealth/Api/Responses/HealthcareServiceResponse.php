<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Responses;

use Illuminate\Support\Str;
use stdClass;

/**
 * Validated single healthcare service response data.
 * Top-level keys become camelCase properties, the same names as in the form and the DTO.
 * Nested values are kept as they came from eHealth.
 */
final class HealthcareServiceResponse extends stdClass
{
    /**
     * @param  array  $data
     */
    public function __construct(array $data)
    {
        foreach ($data as $key => $value) {
            $this->{Str::camel($key)} = $value;
        }
    }
}
