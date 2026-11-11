<?php

declare(strict_types=1);

namespace App\Dto\CodeableConcept;

use App\Dto\Coding\Form as CodingForm;
use App\Models\MedicalEvents\Sql\CodeableConcept as SqlCodeableConcept;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

/**
 * Codeable concept from the database mapped to form values.
 * Read by index, since the property path coding would call the coding() relation method of the model.
 */
#[Map(source: SqlCodeableConcept::class)]
class Form
{
    /** @var list<CodingForm> */
    #[Map(source: '[coding]', transform: new MapCollection(targetClass: CodingForm::class))]
    public array $coding = [];

    #[Map(source: '[text]')]
    public ?string $text = null;

    /**
     * Related codeable concept of a model mapped to this DTO, null when it is absent.
     * The mapper does not map a nested model by itself, since the model declares no #[Map] target.
     *
     * @param  SqlCodeableConcept|null  $codeableConcept
     * @return self|null
     */
    public static function map(?SqlCodeableConcept $codeableConcept): ?self
    {
        return $codeableConcept === null ? null : objectMapper()->map($codeableConcept, self::class);
    }

    /**
     * Codeable concept as form values.
     *
     * @return array{coding: list<array{system: string, code: string}>, text: string|null}
     */
    public function toArray(): array
    {
        return [
            'coding' => array_map(static fn (CodingForm $coding): array => $coding->toArray(), $this->coding),
            'text' => $this->text
        ];
    }
}
