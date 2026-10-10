<?php

declare(strict_types=1);

namespace App\Dto\Condition;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Dto\FormCollection;
use App\Dto\Shared\ClinicalConcept;
use App\Dto\Shared\ClinicalReference;
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
    #[Map(source: '[primarySource]')]
    public mixed $primarySource;

    #[Map(if: false)]
    public readonly array $context;
    #[Map(source: '[codeCode]', transform: [self::class, 'codeValue'])]
    public array $code;

    #[Map(source: '[clinicalStatus]')]
    public mixed $clinicalStatus;

    #[Map(source: '[verificationStatus]')]
    public mixed $verificationStatus;

    #[Map(source: '[onsetDate]', transform: [self::class, 'onsetValue'])]
    public string $onsetDate;

    #[Map(source: '[primarySource]', transform: [[self::class, 'asserterRows'], new MapCollection(targetClass: ClinicalReference::class), [self::class, 'asserterResult']])]
    public ?array $asserter;

    #[Map(source: '[primarySource]', transform: [self::class, 'reportOriginValue'])]
    public ?array $reportOrigin;

    #[Map(source: '[severityCode?]', transform: [self::class, 'severityValue'])]
    public ?array $severity;

    #[Map(source: '[bodySites?]', transform: [[self::class, 'bodyRows'], new MapCollection(targetClass: ClinicalConcept::class), [self::class, 'nonempty']])]
    public ?array $bodySites;

    #[Map(source: '[stageCode?]', transform: [self::class, 'stageValue'])]
    public ?array $stage;

    #[Map(source: '[assertedDate?]', transform: [self::class, 'assertedValue'])]
    public ?string $assertedDate;

    #[Map(source: '[evidenceCodes?]', transform: [[self::class, 'evidenceRows'], new MapCollection(targetClass: EhealthEvidence::class), [self::class, 'nonempty']])]
    public ?array $evidences;

    public function __construct(string $id, string $encounter, #[Map(if: false)] private readonly string $employee)
    {
        $this->id = $id;
        $this->context = new FhirReference('encounter', includeText: true)($encounter, $this, null);
    }

    public static function codeValue(string $value, FormCollection $source): array
    {
        return new FhirCodeableConcept($source['codeSystem'], includeText: true)($value, $source, null);
    }

    public static function onsetValue(string $value, FormCollection $source): string
    {
        return convertToEHealthISO8601($value.' '.$source['onsetTime']);
    }

    public static function asserterRows(mixed $value, FormCollection $source, self $target): array
    {
        return $value ? [new Collection(['uuid' => $source['asserterEmployeeId'] ?? $target->employee, 'type' => 'employee', 'text' => $source['asserterText'] ?? ''])] : [];
    }

    public static function asserterResult(array $value, FormCollection $source): ?array
    {
        return $source['primarySource'] ? $value : null;
    }

    public static function reportOriginValue(mixed $value, FormCollection $source): ?array
    {
        return $value ? null : new FhirCodeableConcept('eHealth/report_origins', includeText: true)($source['reportOriginCode'], $source, null);
    }

    public static function severityValue(mixed $value, FormCollection $source): ?array
    {
        return empty($value) ? null : new FhirCodeableConcept('eHealth/condition_severities', includeText: true)($value, $source, null);
    }

    public static function bodyRows(?array $values): array
    {
        return collect($values ?? [])->filter(static fn (array $row): bool => !empty($row['code']))->map(static fn (array $row): Collection => new Collection(['code' => $row['code'], 'system' => 'eHealth/body_sites']))->values()->all();
    }

    public static function nonempty(array $value): ?array
    {
        return $value ?: null;
    }

    public static function stageValue(mixed $value, FormCollection $source): ?array
    {
        return empty($value) ? null : ['summary' => new FhirCodeableConcept('eHealth/condition_stages', includeText: true)($value, $source, null)];
    }

    public static function assertedValue(mixed $value, FormCollection $source): ?string
    {
        return !empty($value) && !empty($source['assertedTime']) ? convertToEHealthISO8601($value.' '.$source['assertedTime']) : null;
    }

    public static function evidenceRows(mixed $value, FormCollection $source): array
    {
        return !empty($value) || !empty($source['evidenceDetails']) ? [new Collection($source->all())] : [];
    }
}
