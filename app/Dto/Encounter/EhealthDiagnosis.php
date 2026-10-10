<?php

declare(strict_types=1);

namespace App\Dto\Encounter;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Mapping\Transforms\FhirCodeableConcept;
use App\Mapping\Transforms\FhirReference;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class EhealthDiagnosis
{
    use PreservesEhealthDocumentValues;

    #[Map(source: '[conditionId]', transform: new FhirReference('condition', includeText: true))]
    public array $condition;

    #[Map(source: '[roleCode]', transform: new FhirCodeableConcept('eHealth/diagnosis_roles', includeText: true))]
    public array $role;

    #[Map(source: '[rank?]', transform: [self::class, 'rankValue'])]
    public mixed $rank;

    public static function rankValue(mixed $value): mixed
    {
        return empty($value) ? null : $value;
    }
}
