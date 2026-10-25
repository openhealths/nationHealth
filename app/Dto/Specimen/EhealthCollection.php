<?php

declare(strict_types=1);

namespace App\Dto\Specimen;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Mapping\Transforms\FhirCodeableConcept;
use App\Mapping\Transforms\FhirReference;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class EhealthCollection
{
    use PreservesEhealthDocumentValues;

    #[Map(source: '[collectorId]', transform: [self::class, 'collector'])]
    public array $collector;

    #[Map(source: '[collectedDate?]', if: [self::class, 'isDateTime'], transform: [self::class, 'dateTime'])]
    public string $collectedDateTime;

    #[Map(source: '[collectedPeriodRange?]', if: [self::class, 'isPeriod'], transform: [self::class, 'period'])]
    public array $collectedPeriod;

    #[Map(source: '[durationValue?]', if: [self::class, 'filled'], transform: new UcumQuantity('durationCode'))]
    public array $duration;

    #[Map(source: '[quantityValue?]', if: [self::class, 'filled'], transform: new UcumQuantity('quantityCode'))]
    public array $quantity;

    #[Map(source: '[methodCode?]', if: [self::class, 'filled'], transform: new FhirCodeableConcept('specimen_collection_methods', includeText: true))]
    public array $method;

    #[Map(source: '[bodySiteCode?]', if: [self::class, 'filled'], transform: new FhirCodeableConcept('eHealth/body_sites', includeText: true))]
    public array $bodySite;

    #[Map(source: '[fastingStatusCode?]', if: [self::class, 'filled'], transform: new FhirCodeableConcept('fasting_statuses', includeText: true))]
    public array $fastingStatusCodeableConcept;

    #[Map(source: '[procedureId?]', if: [self::class, 'filled'], transform: new FhirReference('procedure', includeText: true))]
    public array $procedure;

    public static function collector(string $value, Collection $source): array
    {
        return new FhirReference($source['collectorType'] === 'patient' ? 'patient' : 'employee', includeText: true)($value, $source, null);
    }

    public static function isPeriod(mixed $value, Collection $source): bool
    {
        return $source['collectedType'] === 'period';
    }

    public static function isDateTime(mixed $value, Collection $source): bool
    {
        return !self::isPeriod($value, $source);
    }

    public static function dateTime(string $value, Collection $source): string
    {
        return convertToEHealthISO8601($value.' '.$source['collectedTime']);
    }

    public static function period(string $value, Collection $source): array
    {
        $bounds = array_map('trim', explode('—', $value));
        $period = ['start' => convertToEHealthISO8601($bounds[0].' '.$source['collectedPeriodStartTime'])];
        if (!empty($bounds[1]) && !empty($source['collectedPeriodEndTime'])) {
            $period['end'] = convertToEHealthISO8601($bounds[1].' '.$source['collectedPeriodEndTime']);
        }

        return $period;
    }

    public static function filled(mixed $value): bool
    {
        return !empty($value);
    }
}
