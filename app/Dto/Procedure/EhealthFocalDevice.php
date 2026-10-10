<?php

declare(strict_types=1);

namespace App\Dto\Procedure;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Mapping\Transforms\FhirCodeableConcept;
use App\Mapping\Transforms\FhirReference;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class EhealthFocalDevice
{
    use PreservesEhealthDocumentValues;
    #[Map(source: '[manipulatedId]', transform: new FhirReference('device', includeText: true))]
    public array $manipulated;
    #[Map(source: '[actionCode?]', if: [self::class, 'filled'], transform: new FhirCodeableConcept('procedure_focal_device_actions', includeText: true))]
    public array $action;
    public static function filled(mixed $value): bool
    {
        return !empty($value);
    }
}
