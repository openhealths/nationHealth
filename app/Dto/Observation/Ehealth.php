<?php

declare(strict_types=1);

namespace App\Dto\Observation;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Dto\FormCollection;
use App\Dto\Shared\ClinicalConcept;
use App\Dto\Shared\ClinicalReference;
use App\Enums\Person\ObservationStatus;
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
    public string $status;

    #[Map(source: '[categoryCode]', transform: [[self::class, 'categoryRows'], new MapCollection(targetClass: ClinicalConcept::class)])]
    public array $categories;

    #[Map(source: '[codeCode]', transform: [self::class, 'codeValue'])]
    public array $code;

    #[Map(source: '[issuedDate]', transform: [self::class, 'issuedValue'])]
    public string $issued;

    #[Map(source: '[primarySource]')]
    public mixed $primarySource;

    #[Map(if: false)]
    public readonly ?array $context;
    #[Map(if: false)]
    public readonly ?array $diagnosticReport;
    #[Map(source: '[effectiveType?]', transform: [self::class, 'periodValue'])]
    public ?array $effectivePeriod;

    #[Map(source: '[effectiveDate?]', transform: [self::class, 'effectiveValue'])]
    public ?string $effectiveDateTime;

    #[Map(source: '[primarySource]', transform: [[self::class, 'performerRows'], new MapCollection(targetClass: ClinicalReference::class), [self::class, 'performerResult']])]
    public ?array $performer;

    #[Map(source: '[primarySource]', transform: [self::class, 'reportOriginValue'])]
    public ?array $reportOrigin;

    #[Map(source: '[interpretationCode?]', transform: [self::class, 'interpretationValue'])]
    public ?array $interpretation;

    #[Map(source: '[comment?]', transform: [self::class, 'optionalValue'])]
    public mixed $comment;

    #[Map(source: '[methodCode?]', transform: [self::class, 'methodValue'])]
    public ?array $method;

    #[Map(source: '[bodySiteCode?]', transform: [self::class, 'bodyValue'])]
    public ?array $bodySite;

    #[Map(source: '[reactionOn?]', transform: [self::class, 'reactionValue'])]
    public ?array $reactionOn;

    #[Map(source: '[valueQuantityValue?]', transform: [self::class, 'quantityValue'])]
    public ?array $valueQuantity;

    #[Map(source: '[valueCodeableConcept?]', transform: [self::class, 'conceptValue'])]
    public ?array $valueCodeableConcept;

    #[Map(source: '[valueSampledData?]', transform: [[self::class, 'sampledSource'], new MapObject(EhealthSampledData::class)])]
    public ?EhealthSampledData $valueSampledData;

    #[Map(source: '[valueString?]')]
    public mixed $valueString;

    #[Map(source: '[valueBoolean?]')]
    public mixed $valueBoolean;

    #[Map(source: '[valueDate?]', transform: [self::class, 'dateTimeValue'])]
    public ?string $valueDateTime;

    #[Map(source: '[valueTime?]', transform: [self::class, 'timeValue'])]
    public ?string $valueTime;

    #[Map(source: '[components?]', transform: [[self::class, 'componentRows'], new MapCollection(targetClass: EhealthComponent::class), [self::class, 'nonempty']])]
    public ?array $components;

    #[Map(source: '[deviceId?]', transform: [self::class, 'deviceValue'])]
    public ?array $device;

    #[Map(source: '[specimenId?]', transform: [self::class, 'specimenValue'])]
    public ?array $specimen;

    public function __construct(string $id, #[Map(if: false)] private readonly string $employee, ?string $encounter = null, ?string $diagnosticReport = null)
    {
        $this->id = $id;
        $this->context = empty($encounter) ? null : new FhirReference('encounter', includeText: true)($encounter, $this, null);
        $this->diagnosticReport = empty($diagnosticReport) ? null : new FhirReference('diagnostic_report', includeText: true)($diagnosticReport, $this, null);
    }

    public static function statusValue(mixed $value): string
    {
        return $value ?? ObservationStatus::VALID->value;
    }

    public static function categoryRows(string $value, FormCollection $source): array
    {
        return [new Collection(['code' => $value, 'system' => $source['categorySystem']])];
    }

    public static function codeValue(string $value, FormCollection $source): array
    {
        return new FhirCodeableConcept($source['codeSystem'], includeText: true)($value, $source, null);
    }

    public static function issuedValue(string $value, FormCollection $source): string
    {
        return convertToEHealthISO8601($value.' '.$source['issuedTime']);
    }

    public static function periodValue(mixed $value, FormCollection $source): ?array
    {
        $bounds = array_map('trim', explode('—', $source['effectivePeriodRange'] ?? ''));

        return $value === 'period' && !empty($bounds[0]) ? ['start' => convertToEHealthISO8601($bounds[0].' '.$source['effectivePeriodStartTime']), 'end' => convertToEHealthISO8601((empty($bounds[1]) ? $bounds[0] : $bounds[1]).' '.$source['effectivePeriodEndTime'])] : null;
    }

    public static function effectiveValue(mixed $value, FormCollection $source): ?string
    {
        return ($source['effectiveType'] ?? 'date_time') === 'date_time' && !empty($value) && !empty($source['effectiveTime']) ? convertToEHealthISO8601($value.' '.$source['effectiveTime']) : null;
    }

    public static function performerRows(mixed $value, FormCollection $source, self $target): array
    {
        return $value ? [new Collection(['uuid' => ($source['performerEmployeeId'] ?? '') ?: $target->employee, 'type' => 'employee'])] : [];
    }

    public static function performerResult(array $value, FormCollection $source): ?array
    {
        return $source['primarySource'] ? $value : null;
    }

    public static function reportOriginValue(mixed $value, FormCollection $source): ?array
    {
        if ($value) {
            return null;
        }
        $concept = new FhirCodeableConcept('eHealth/report_origins', includeText: true)($source['reportOriginCode'], $source, null);
        $concept['text'] = $source['reportOriginText'] ?? '';

        return $concept;
    }

    public static function optionalValue(mixed $value): mixed
    {
        return empty($value) ? null : $value;
    }

    public static function quantityValue(mixed $value, FormCollection $source): ?array
    {
        return empty($value) ? null : ['value' => $value, 'comparator' => $source['valueQuantityComparator'], 'unit' => $source['valueQuantityUnit'], 'system' => $source['valueQuantitySystem'], 'code' => $source['valueQuantityCode']];
    }

    public static function conceptValue(mixed $value, FormCollection $source): ?array
    {
        return $value === null ? null : new FhirCodeableConcept($source['dictionaryName'], includeText: true)($value, $source, null);
    }

    public static function sampledSource(mixed $value, FormCollection $source): ?Collection
    {
        return $value === null ? null : new Collection($source->all());
    }

    public static function dateTimeValue(mixed $value, FormCollection $source): ?string
    {
        return $value !== null && isset($source['valueTime']) ? convertToEHealthISO8601($value.' '.$source['valueTime']) : null;
    }

    public static function timeValue(mixed $value, FormCollection $source): ?string
    {
        return $value !== null && !isset($source['valueDate']) ? $value.':00' : null;
    }

    public static function componentRows(?array $value): array
    {
        return collect($value ?? [])->filter(static fn (array $row): bool => !empty($row['valueCode']))->map(static fn (array $row): Collection => new Collection($row))->values()->all();
    }

    public static function nonempty(array $value): ?array
    {
        return $value ?: null;
    }

    public static function interpretationValue(mixed $value, FormCollection $source): ?array
    {
        return empty($value) ? null : new FhirCodeableConcept('eHealth/observation_interpretations', includeText: true)($value, $source, null);
    }

    public static function methodValue(mixed $value, FormCollection $source): ?array
    {
        return empty($value) ? null : new FhirCodeableConcept('eHealth/observation_methods', includeText: true)($value, $source, null);
    }

    public static function bodyValue(mixed $value, FormCollection $source): ?array
    {
        return empty($value) ? null : new FhirCodeableConcept('eHealth/body_sites', includeText: true)($value, $source, null);
    }

    public static function reactionValue(mixed $value, FormCollection $source): ?array
    {
        return empty($value) ? null : new FhirReference('immunization', includeText: true)($value, $source, null);
    }

    public static function deviceValue(mixed $value, FormCollection $source): ?array
    {
        return empty($value) ? null : new FhirReference('equipment', includeText: true)($value, $source, null);
    }

    public static function specimenValue(mixed $value, FormCollection $source): ?array
    {
        return empty($value) ? null : new FhirReference('specimen', includeText: true)($value, $source, null);
    }
}
