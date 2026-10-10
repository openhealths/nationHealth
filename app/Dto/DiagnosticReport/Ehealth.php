<?php

declare(strict_types=1);

namespace App\Dto\DiagnosticReport;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Dto\FormCollection;
use App\Dto\Shared\ClinicalConcept;
use App\Dto\Shared\ClinicalReference;
use App\Enums\Person\DiagnosticReportStatus;
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
    #[Map(if: false)]
    public readonly string $status;
    #[Map(source: '[codeValue]', transform: new FhirReference('service', includeText: true))]
    public array $code;

    #[Map(source: '[categoryCode]', transform: [[self::class, 'categoryRows'], new MapCollection(targetClass: ClinicalConcept::class)])]
    public array $category;

    #[Map(source: '[issuedDate]', transform: [self::class, 'issuedValue'])]
    public string $issued;

    #[Map(if: false)]
    public readonly array $recordedBy;
    #[Map(source: '[primarySource]')]
    public mixed $primarySource;

    #[Map(if: false)]
    public readonly array $managingOrganization;
    #[Map(source: '[effectiveType?]', transform: [self::class, 'effectiveValue'])]
    public ?string $effectiveDateTime;

    #[Map(source: '[effectiveType?]', transform: [self::class, 'periodValue'])]
    public ?array $effectivePeriod;

    #[Map(source: '[basedOnIdentifier?]', transform: [self::class, 'basedOnValue'])]
    public ?array $basedOn;

    #[Map(source: '[paperReferralRequesterLegalEntityEdrpou?]', transform: [[self::class, 'paperSource'], new MapObject(\App\Dto\PaperReferral\Ehealth::class)])]
    public ?\App\Dto\PaperReferral\Ehealth $paperReferral;

    #[Map(if: false)]
    public readonly ?array $encounter;
    #[Map(source: '[conclusion?]', transform: [self::class, 'optionalValue'])]
    public mixed $conclusion;

    #[Map(source: '[conclusionCode?]', transform: [self::class, 'conclusionValue'])]
    public ?array $conclusionCode;

    #[Map(source: '[specimenIds?]', transform: [[self::class, 'specimenRows'], new MapCollection(targetClass: ClinicalReference::class), [self::class, 'nonempty']])]
    public ?array $specimens;

    #[Map(source: '[usedReferences?]', transform: [[self::class, 'equipmentRows'], new MapCollection(targetClass: ClinicalReference::class), [self::class, 'equipmentResult']])]
    public ?array $usedReferences;

    #[Map(source: '[divisionId?]', transform: [self::class, 'divisionValue'])]
    public ?array $division;

    #[Map(source: '[primarySource]', transform: [[self::class, 'performerRows'], new MapCollection(targetClass: ClinicalReference::class), [self::class, 'performerResult']])]
    public ?array $performer;

    #[Map(source: '[primarySource]', transform: [self::class, 'reportOriginValue'])]
    public ?array $reportOrigin;

    #[Map(source: '[resultsInterpreterEmployeeId?]', transform: [self::class, 'interpreterValue'])]
    public ?array $resultsInterpreter;

    public function __construct(string $id, DiagnosticReportStatus $status, string $legalEntity, #[Map(if: false)] private readonly string $employee, ?string $encounter = null)
    {
        $this->id = $id;
        $this->status = $status->value;
        $this->recordedBy = new FhirReference('employee', includeText: true)($employee, $this, null);
        $this->managingOrganization = new FhirReference('legal_entity', includeText: true)($legalEntity, $this, null);
        $this->encounter = empty($encounter) ? null : new FhirReference('encounter', includeText: true)($encounter, $this, null);
    }

    public static function categoryRows(string $value): array
    {
        return [new Collection(['code' => $value, 'system' => 'eHealth/diagnostic_report_categories'])];
    }

    public static function issuedValue(string $value, FormCollection $source): string
    {
        return convertToEHealthISO8601($value.' '.$source['issuedTime']);
    }

    public static function effectiveValue(mixed $value, FormCollection $source): ?string
    {
        return $value === 'date_time' ? convertToEHealthISO8601($source['effectiveDate'].' '.$source['effectiveTime']) : null;
    }

    public static function periodValue(mixed $value, FormCollection $source): ?array
    {
        if ($value !== 'period') {
            return null;
        }
        $period = ['start' => convertToEHealthISO8601($source['effectivePeriodStartDate'].' '.$source['effectivePeriodStartTime'])];
        if (!empty($source['effectivePeriodEndDate']) && !empty($source['effectivePeriodEndTime'])) {
            $period['end'] = convertToEHealthISO8601($source['effectivePeriodEndDate'].' '.$source['effectivePeriodEndTime']);
        }

        return $period;
    }

    public static function basedOnValue(mixed $value, FormCollection $source): ?array
    {
        return ($source['referralType'] ?? null) === 'electronic' && !empty($value) ? new FhirReference('service_request', includeText: true)($value, $source, null) : null;
    }

    public static function paperSource(mixed $value, FormCollection $source): ?FormCollection
    {
        return empty($value) ? null : $source;
    }

    public static function optionalValue(mixed $value): mixed
    {
        return empty($value) ? null : $value;
    }

    public static function conclusionValue(mixed $value, FormCollection $source): ?array
    {
        return empty($value) ? null : new FhirCodeableConcept('eHealth/ICD10_AM/condition_codes', includeText: true)($value, $source, null);
    }

    public static function specimenRows(?array $value): array
    {
        return array_map(static fn (string $id): Collection => new Collection(['uuid' => $id, 'type' => 'specimen']), array_values($value ?? []));
    }

    public static function equipmentRows(?array $value): array
    {
        return collect($value ?? [])->pluck('id')->filter()->unique()->map(static fn (string $id): Collection => new Collection(['uuid' => $id, 'type' => 'equipment']))->values()->all();
    }

    public static function equipmentResult(array $value, FormCollection $source): ?array
    {
        return empty($source['usedReferences']) ? null : $value;
    }

    public static function divisionValue(mixed $value, FormCollection $source): ?array
    {
        return empty($value) ? null : new FhirReference('division', includeText: true)($value, $source, null);
    }

    public static function performerRows(mixed $value, FormCollection $source, self $target): array
    {
        return $value ? collect($source['performerEmployeeIds'] ?? [])->push($source['resultsInterpreterEmployeeId'] ?? null)->push($target->employee)->filter()->unique()->map(static fn (string $id): Collection => new Collection(['uuid' => $id, 'type' => 'employee']))->values()->all() : [];
    }

    public static function performerResult(array $value, FormCollection $source): ?array
    {
        return $source['primarySource'] ? array_map(static fn (object $row): array => ['reference' => $row], $value) : null;
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

    public static function interpreterValue(mixed $value, FormCollection $source): ?array
    {
        return empty($value) ? null : ['reference' => new FhirReference('employee', includeText: true)($value, $source, null)];
    }

    public static function nonempty(array $value): ?array
    {
        return $value ?: null;
    }
}
