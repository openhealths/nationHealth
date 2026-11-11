<?php

declare(strict_types=1);

namespace App\Dto\CodeableConcept;

use App\Dto\Coding\Ehealth as CodingEhealth;
use App\Dto\FormCodeableConcept;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

/**
 * Codeable concept from the form in the format expected by the eHealth API.
 */
#[Map(source: FormCodeableConcept::class)]
class Ehealth
{
    /** @var list<CodingEhealth> */
    #[Map(source: '[coding]', transform: new MapCollection(targetClass: CodingEhealth::class))]
    public array $coding = [];

    #[Map(source: '[text]')]
    public ?string $text = null;

    /**
     * Form value mapped to this DTO, null when no value is selected and the form keeps an empty coding structure.
     * The array is wrapped as FormCodeableConcept, since the mapper maps only objects.
     *
     * @param  array|null  $codeableConcept
     * @return self|null
     */
    public static function map(?array $codeableConcept): ?self
    {
        return empty($codeableConcept['coding'][0]['code'])
            ? null
            : objectMapper()->map(new FormCodeableConcept($codeableConcept), self::class);
    }
}
