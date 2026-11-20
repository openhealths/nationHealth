<?php

declare(strict_types=1);

namespace App\Dto\Identifier;

use App\Dto\CodeableConcept\Ehealth as CodeableConceptEhealth;
use App\Dto\Input\FormIdentifier;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Identifier from the form in the format expected by the eHealth API.
 */
#[Map(source: FormIdentifier::class)]
class Ehealth
{
    #[Map(source: '[type]', transform: [CodeableConceptEhealth::class, 'map'])]
    public CodeableConceptEhealth $type;

    #[Map(source: '[value]')]
    public string $value;

    /**
     * Form value mapped to this DTO.
     * The array is wrapped as FormIdentifier, since the mapper maps only objects.
     *
     * @param  array  $identifier
     * @return self
     */
    public static function map(array $identifier): self
    {
        return objectMapper()->map(new FormIdentifier($identifier), self::class);
    }
}
