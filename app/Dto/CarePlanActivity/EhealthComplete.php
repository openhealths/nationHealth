<?php

declare(strict_types=1);

namespace App\Dto\CarePlanActivity;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Livewire\CarePlan\CarePlanComponent;
use App\Mapping\Transforms\FhirCodeableConcept;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

/** Complete is an unsigned PATCH with transition fields only. */
#[Map(source: CarePlanComponent::class)]
final class EhealthComplete
{
    use PreservesEhealthDocumentValues;

    #[Map(source: 'statusReason', transform: [self::class, 'mapDetail'])]
    public array $detail;

    #[Map(source: 'outcomeCode', if: [self::class, 'hasOutcome'], transform: [[self::class, 'outcomes'], new MapCollection(targetClass: Outcome::class)])]
    public ?array $outcome_codeable_concept = null;

    #[Map(source: 'outcomeReferences', if: 'count', transform: [[self::class, 'references'], new MapCollection(targetClass: OutcomeReference::class)])]
    public ?array $outcome_reference = null;

    public static function mapDetail(string $code, CarePlanComponent $source): array
    {
        return ['status_reason' => new FhirCodeableConcept('eHealth/care_plan_activity_complete_reasons')($code, $source, null)];
    }

    public static function hasOutcome(mixed $value): bool
    {
        return (bool) $value;
    }

    public static function outcomes(string $code): array
    {
        return [(object) ['code' => $code]];
    }

    public static function references(array $values): array
    {
        return array_map(static fn (string $uuid): object => (object) ['uuid' => $uuid], $values);
    }
}
