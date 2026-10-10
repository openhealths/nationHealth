<?php

declare(strict_types=1);

namespace App\Dto\Immunization;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Dto\FormCollection;
use App\Enums\Person\ImmunizationStatus;
use App\Mapping\Transforms\FhirCodeableConcept;
use App\Mapping\Transforms\FhirReference;
use App\Mapping\Transforms\MapObject;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: FormCollection::class)]
final class Ehealth
{
    use PreservesEhealthDocumentValues;

    #[Map(if: false)]
    public readonly string $id;
    #[Map(source: '[status?]', transform: [self::class, 'statusValue'])]
    public mixed $status;

    #[Map(source: '[notGiven]')]
    public mixed $notGiven;

    #[Map(source: '[vaccineCode]', transform: new FhirCodeableConcept('eHealth/vaccine_codes', includeText: true))]
    public array $vaccineCode;

    #[Map(if: false)]
    public readonly array $context;
    #[Map(source: '[date]', transform: [self::class, 'dateValue'])]
    public string $date;

    #[Map(source: '[primarySource]')]
    public mixed $primarySource;

    #[Map(source: '[primarySource]', transform: [self::class, 'performerValue'])]
    public ?array $performer;

    #[Map(source: '[primarySource]', transform: [self::class, 'reportOriginValue'])]
    public ?array $reportOrigin;

    #[Map(source: '[manufacturer?]', transform: [self::class, 'optionalValue'])]
    public mixed $manufacturer;

    #[Map(source: '[lotNumber?]', transform: [self::class, 'optionalValue'])]
    public mixed $lotNumber;

    #[Map(source: '[expirationDate?]', transform: [self::class, 'expirationValue'])]
    public ?string $expirationDate;

    #[Map(source: '[siteCode?]', transform: [self::class, 'siteValue'])]
    public ?array $site;

    #[Map(source: '[routeCode?]', transform: [self::class, 'routeValue'])]
    public ?array $route;

    #[Map(source: '[doseQuantityValue?]', transform: [self::class, 'doseValue'])]
    public ?array $doseQuantity;

    #[Map(source: '[notGiven]', transform: [[self::class, 'sourceObject'], new MapObject(EhealthExplanation::class)])]
    public EhealthExplanation $explanation;

    #[Map(source: '[vaccinationProtocols?]', transform: [[self::class, 'protocolRows'], new MapCollection(targetClass: EhealthProtocol::class), [self::class, 'nonempty']])]
    public ?array $vaccinationProtocols;

    public function __construct(string $id, string $encounter, #[Map(if: false)] private readonly string $employee, #[Map(if: false)] private readonly string $fallbackTime)
    {
        $this->id = $id;
        $this->context = new FhirReference('encounter', includeText: true)($encounter, $this, null);
    }

    public static function statusValue(mixed $value): string
    {
        return $value ?? ImmunizationStatus::COMPLETED->value;
    }

    public static function optionalValue(mixed $value): mixed
    {
        return empty($value) ? null : $value;
    }

    public static function dateValue(string $value, FormCollection $source): string
    {
        return convertToEHealthISO8601($value.' '.$source['time']);
    }

    public static function performerValue(mixed $value, FormCollection $source, self $target): ?array
    {
        return $value ? new FhirReference('employee', includeText: true)($source['performerEmployeeId'] ?? $target->employee, $source, null) : null;
    }

    public static function reportOriginValue(mixed $value, FormCollection $source): ?array
    {
        if ($value) {
            return null;
        }
        $concept = new FhirCodeableConcept('eHealth/immunization_report_origins', includeText: true)($source['reportOriginCode'], $source, null);
        $concept['text'] = $source['reportOriginText'] ?? '';

        return $concept;
    }

    public static function expirationValue(mixed $value, FormCollection $source, self $target): ?string
    {
        return empty($value) ? null : convertToEHealthISO8601($value.' '.(($source['expirationTime'] ?? '') ?: $target->fallbackTime));
    }

    public static function siteValue(mixed $value, FormCollection $source): ?array
    {
        return empty($value) ? null : new FhirCodeableConcept('eHealth/immunization_body_sites', includeText: true)($value, $source, null);
    }

    public static function routeValue(mixed $value, FormCollection $source): ?array
    {
        return empty($value) ? null : new FhirCodeableConcept('eHealth/vaccination_routes', includeText: true)($value, $source, null);
    }

    public static function doseValue(mixed $value, FormCollection $source): ?array
    {
        return empty($value) ? null : ['value' => $value, 'unit' => $source['doseQuantityUnit'], 'system' => 'eHealth/immunization_dosage_units', 'code' => $source['doseQuantityCode']];
    }

    public static function sourceObject(mixed $value, FormCollection $source): Collection
    {
        return new Collection($source->all());
    }

    public static function protocolRows(?array $value): array
    {
        return array_map(static fn (array $row): Collection => new Collection($row), array_values($value ?? []));
    }

    public static function nonempty(array $value): ?array
    {
        return $value ?: null;
    }
}
