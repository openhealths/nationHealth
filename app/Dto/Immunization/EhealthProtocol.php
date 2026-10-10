<?php

declare(strict_types=1);

namespace App\Dto\Immunization;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Dto\Shared\ClinicalConcept;
use App\Mapping\Transforms\FhirCodeableConcept;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: Collection::class)]
final class EhealthProtocol
{
    use PreservesEhealthDocumentValues;

    #[Map(source: '[authorityCode]', transform: new FhirCodeableConcept('eHealth/vaccination_authorities', includeText: true))]
    public array $authority;

    #[Map(source: '[targetDiseaseCodes?]', transform: [[self::class, 'diseaseRows'], new MapCollection(targetClass: ClinicalConcept::class)])]
    public array $targetDiseases;

    #[Map(source: '[doseSequence?]', transform: [self::class, 'optionalValue'])]
    public mixed $doseSequence;

    #[Map(source: '[series?]', transform: [self::class, 'optionalValue'])]
    public mixed $series;

    #[Map(source: '[seriesDoses?]', transform: [self::class, 'optionalValue'])]
    public mixed $seriesDoses;

    #[Map(source: '[description?]', transform: [self::class, 'optionalValue'])]
    public mixed $description;

    public static function diseaseRows(?array $value): array
    {
        return collect($value ?? [])->filter()->map(static fn (string $code): Collection => new Collection(['code' => $code, 'system' => 'eHealth/vaccination_target_diseases']))->values()->all();
    }

    public static function optionalValue(mixed $value): mixed
    {
        return empty($value) ? null : $value;
    }
}
