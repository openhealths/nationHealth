<?php

declare(strict_types=1);

namespace App\Dto\HealthcareService;

use App\Core\Arr;
use App\Models\HealthcareService;
use App\Dto\Transform\CodeableConcept;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Properties without #[Map] are taken from the model attribute with the same name.
 * The division is not mapped: the form holds its uuid, while the model holds its id.
 */
#[Map(source: HealthcareService::class)]
class Form
{
    #[Map(source: '[category]', transform: [CodeableConcept::class, 'toArray'])]
    public array $category = [];

    public ?string $specialityType = null;

    public ?string $providingCondition = null;

    #[Map(source: '[type]', transform: [CodeableConcept::class, 'toArray'])]
    public ?array $type = null;

    public ?string $licenseId = null;

    public ?string $comment = null;

    #[Map(source: 'availableTime', transform: [self::class, 'camelCaseAvailableTime'])]
    public ?array $availableTime = null;

    public ?array $notAvailable = null;

    /**
     * Available time is stored with snake_case keys, while the form fields use camelCase.
     *
     * @param  array|null  $availableTime
     * @return array|null
     */
    public static function camelCaseAvailableTime(?array $availableTime): ?array
    {
        return $availableTime === null ? null : Arr::toCamelCase($availableTime);
    }

    /**
     * Values for filling the form.
     * An absent type is left out, so the form keeps its empty coding structure for the type select.
     *
     * @return array
     */
    public function toArray(): array
    {
        $values = get_object_vars($this);

        return $this->type === null ? Arr::except($values, ['type']) : $values;
    }
}
