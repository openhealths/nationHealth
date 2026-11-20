<?php

declare(strict_types=1);

namespace App\Dto\Identifier;

use App\Dto\CodeableConcept\Model as CodeableConceptModel;
use App\Dto\Input\FormIdentifier;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Identifier from the form mapped to database attributes.
 */
#[Map(source: FormIdentifier::class)]
class Model
{
    #[Map(source: '[value]')]
    public string $value;

    #[Map(source: '[type]', transform: [CodeableConceptModel::class, 'map'])]
    public CodeableConceptModel $type;

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
