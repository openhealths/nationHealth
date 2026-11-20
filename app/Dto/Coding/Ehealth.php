<?php

declare(strict_types=1);

namespace App\Dto\Coding;

use App\Dto\Input\FormCoding;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Coding from the form in the format expected by the eHealth API.
 */
#[Map(source: FormCoding::class)]
class Ehealth
{
    #[Map(source: '[system]')]
    public string $system;

    #[Map(source: '[code]')]
    public string $code;

    /**
     * Form value mapped to this DTO.
     * The array is wrapped as FormCoding, since the mapper maps only objects.
     *
     * @param  array  $coding
     * @return self
     */
    public static function map(array $coding): self
    {
        return objectMapper()->map(new FormCoding($coding), self::class);
    }
}
