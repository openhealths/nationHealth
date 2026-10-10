<?php

declare(strict_types=1);

namespace App\Dto\ClinicalImpression;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Dto\FormCollection;
use App\Dto\Shared\ClinicalReference;
use App\Enums\ClinicalImpression\Status;
use App\Mapping\Transforms\FhirCodeableConcept;
use App\Mapping\Transforms\FhirReference;
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

    #[Map(source: '[codeCode]', transform: new FhirCodeableConcept('eHealth/clinical_impression_patient_categories', includeText: true))]
    public array $code;

    #[Map(if: false)]
    public readonly array $encounter;
    #[Map(source: '[effectivePeriodStartDate]', transform: [self::class, 'periodValue'])]
    public array $effectivePeriod;

    #[Map(source: '[assessorEmployeeId?]', transform: [self::class, 'assessorValue'])]
    public array $assessor;

    #[Map(source: '[description?]', transform: [self::class, 'optionalValue'])]
    public mixed $description;

    #[Map(source: '[previous?]', transform: [self::class, 'previousValue'])]
    public ?array $previous;

    #[Map(source: '[problems?]', transform: [[self::class, 'problemRows'], new MapCollection(targetClass: ClinicalReference::class), [self::class, 'nonempty']])]
    public ?array $problems;

    #[Map(source: '[summary?]', transform: [self::class, 'optionalValue'])]
    public mixed $summary;

    #[Map(source: '[findings?]', transform: [[self::class, 'findingRows'], new MapCollection(targetClass: EhealthFinding::class), [self::class, 'nonempty']])]
    public ?array $findings;

    #[Map(source: '[supportingInfo?]', transform: [[self::class, 'supportingRows'], new MapCollection(targetClass: ClinicalReference::class), [self::class, 'nonempty']])]
    public ?array $supportingInfo;

    #[Map(source: '[note?]', transform: [self::class, 'optionalValue'])]
    public mixed $note;

    public function __construct(string $id, string $encounter, #[Map(if: false)] private readonly string $employee)
    {
        $this->id = $id;
        $this->encounter = new FhirReference('encounter', includeText: true)($encounter, $this, null);
    }

    public static function statusValue(mixed $value): string
    {
        return $value ?? Status::COMPLETED->value;
    }

    public static function periodValue(string $value, FormCollection $source): array
    {
        return ['start' => convertToEHealthISO8601($value.' '.$source['effectivePeriodStartTime']), 'end' => convertToEHealthISO8601($source['effectivePeriodEndDate'].' '.$source['effectivePeriodEndTime'])];
    }

    public static function assessorValue(mixed $value, FormCollection $source, self $target): array
    {
        return new FhirReference('employee', includeText: true)($value ?? $target->employee, $source, null);
    }

    public static function optionalValue(mixed $value): mixed
    {
        return empty($value) ? null : $value;
    }

    public static function previousValue(?array $value, FormCollection $source): ?array
    {
        return empty($value) ? null : new FhirReference('clinical_impression', includeText: true)($value[0]['id'], $source, null);
    }

    public static function problemRows(?array $value): array
    {
        return array_map(static fn (array $row): Collection => new Collection(['uuid' => $row['id'], 'type' => 'condition']), array_values($value ?? []));
    }

    public static function findingRows(?array $value): array
    {
        return array_map(static fn (array $row): Collection => new Collection($row), array_values($value ?? []));
    }

    public static function supportingRows(?array $value): array
    {
        return array_map(static fn (array $row): Collection => new Collection(['uuid' => $row['uuid'], 'type' => $row['type']]), array_values($value ?? []));
    }

    public static function nonempty(array $value): ?array
    {
        return $value ?: null;
    }
}
