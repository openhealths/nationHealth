<?php

declare(strict_types=1);

namespace App\Dto\CodeableConcept;

use App\Classes\eHealth\Api\Responses\Collections\EhealthCodeableConcept;
use App\Dto\Coding\Model as CodingModel;
use App\Dto\FormCodeableConcept;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

/**
 * Codeable concept from the form or a validated eHealth response mapped to database attributes.
 */
#[Map(source: EhealthCodeableConcept::class)]
#[Map(source: FormCodeableConcept::class)]
class Model
{
    /** @var list<CodingModel> */
    #[Map(source: '[coding]', transform: new MapCollection(targetClass: CodingModel::class))]
    public array $coding = [];

    #[Map(source: '[text]')]
    public ?string $text = null;

    /**
     * Response or form value mapped to this DTO, null when it is absent.
     * A form value with an empty coding structure means no value is selected, so it is absent too.
     * The form array is wrapped as FormCodeableConcept, since the mapper maps only objects.
     * The mapper does not map a nested response by itself, since the response declares no #[Map] target.
     *
     * @param  EhealthCodeableConcept|array|null  $codeableConcept
     * @return self|null
     */
    public static function map(EhealthCodeableConcept|array|null $codeableConcept): ?self
    {
        if (is_array($codeableConcept)) {
            $codeableConcept = empty($codeableConcept['coding'][0]['code']) ? null : new FormCodeableConcept($codeableConcept);
        }

        return $codeableConcept === null ? null : objectMapper()->map($codeableConcept, self::class);
    }

    /**
     * Codeable concept as database attributes.
     *
     * @return array{coding: list<array{system: string, code: string}>, text: string|null}
     */
    public function toArray(): array
    {
        return [
            'coding' => array_map(static fn (CodingModel $coding): array => $coding->toArray(), $this->coding),
            'text' => $this->text
        ];
    }
}
