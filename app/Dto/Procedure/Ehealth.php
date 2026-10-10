<?php

declare(strict_types=1);

namespace App\Dto\Procedure;

use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Dto\FormCollection;
use App\Dto\Shared\ClinicalReference;
use App\Dto\Shared\ClinicalConcept;
use App\Enums\Person\ProcedureStatus;
use App\Livewire\Procedure\Forms\ProcedureForm;
use App\Mapping\Transforms\FhirCodeableConcept;
use App\Mapping\Transforms\FhirReference;
use App\Mapping\Transforms\MapObject;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: FormCollection::class, if: new SourceClass(FormCollection::class))]
#[Map(source: ProcedureForm::class, if: new SourceClass(ProcedureForm::class))]
final class Ehealth
{
    use PreservesEhealthDocumentValues;
    #[Map(if: false)]
    public readonly string $id;

    #[Map(source: '[status]', if: new SourceClass(FormCollection::class), transform: [self::class, 'statusValue'])]
    #[Map(source: 'procedure[status]', if: new SourceClass(ProcedureForm::class), transform: [self::class, 'statusValue'])]
    public string $status;

    #[Map(source: '[codeValue]', if: new SourceClass(FormCollection::class), transform: new FhirReference('service', includeText: true))]
    #[Map(source: 'procedure[codeValue]', if: new SourceClass(ProcedureForm::class), transform: new FhirReference('service', includeText: true))]
    public array $code;

    #[Map(if: false)]
    public readonly array $recordedBy;

    #[Map(source: '[primarySource]', if: new SourceClass(FormCollection::class))]
    #[Map(source: 'procedure[primarySource]', if: new SourceClass(ProcedureForm::class))]
    public mixed $primarySource;

    #[Map(if: false)]
    public readonly array $managingOrganization;

    #[Map(source: '[categoryCode]', if: new SourceClass(FormCollection::class), transform: new FhirCodeableConcept('eHealth/procedure_categories', includeText: true))]
    #[Map(source: 'procedure[categoryCode]', if: new SourceClass(ProcedureForm::class), transform: new FhirCodeableConcept('eHealth/procedure_categories', includeText: true))]
    public array $category;

    #[Map(source: '[performedDate?]', if: new SourceClass(FormCollection::class), transform: [self::class, 'performedDateTimeValue'])]
    #[Map(source: 'procedure[performedDate?]', if: new SourceClass(ProcedureForm::class), transform: [self::class, 'performedDateTimeValue'])]
    public ?string $performedDateTime;

    #[Map(source: '[performedType?]', if: new SourceClass(FormCollection::class), transform: [self::class, 'performedPeriodValue'])]
    #[Map(source: 'procedure[performedType?]', if: new SourceClass(ProcedureForm::class), transform: [self::class, 'performedPeriodValue'])]
    public ?array $performedPeriod;

    #[Map(source: '[basedOnIdentifier?]', if: new SourceClass(FormCollection::class), transform: [self::class, 'basedOnValue'])]
    #[Map(source: 'procedure[basedOnIdentifier?]', if: new SourceClass(ProcedureForm::class), transform: [self::class, 'basedOnValue'])]
    public ?array $basedOn;

    #[Map(source: '[paperReferralRequesterLegalEntityEdrpou?]', if: new SourceClass(FormCollection::class), transform: [[self::class, 'paperSource'], new MapObject(\App\Dto\PaperReferral\Ehealth::class)])]
    #[Map(source: 'procedure[paperReferralRequesterLegalEntityEdrpou?]', if: new SourceClass(ProcedureForm::class), transform: [[self::class, 'paperSource'], new MapObject(\App\Dto\PaperReferral\Ehealth::class)])]
    public ?\App\Dto\PaperReferral\Ehealth $paperReferral;

    #[Map(source: '[divisionId?]', if: new SourceClass(FormCollection::class), transform: [self::class, 'divisionValue'])]
    #[Map(source: 'procedure[divisionId?]', if: new SourceClass(ProcedureForm::class), transform: [self::class, 'divisionValue'])]
    public ?array $division;

    #[Map(source: '[reasonReferences?]', if: new SourceClass(FormCollection::class), transform: [[self::class, 'reasonReferencesRows'], new MapCollection(targetClass: ClinicalReference::class), [self::class, 'reasonReferencesResult']])]
    #[Map(source: 'procedure[reasonReferences?]', if: new SourceClass(ProcedureForm::class), transform: [[self::class, 'reasonReferencesRows'], new MapCollection(targetClass: ClinicalReference::class), [self::class, 'reasonReferencesResult']])]
    public ?array $reasonReferences;

    #[Map(source: '[outcomeCode?]', if: new SourceClass(FormCollection::class), transform: [self::class, 'outcomeValue'])]
    #[Map(source: 'procedure[outcomeCode?]', if: new SourceClass(ProcedureForm::class), transform: [self::class, 'outcomeValue'])]
    public ?array $outcome;

    #[Map(source: '[note?]', if: new SourceClass(FormCollection::class), transform: [self::class, 'optionalValue'])]
    #[Map(source: 'procedure[note?]', if: new SourceClass(ProcedureForm::class), transform: [self::class, 'optionalValue'])]
    public mixed $note;

    #[Map(source: '[usedCodes?]', if: new SourceClass(FormCollection::class), transform: [[self::class, 'usedCodesRows'], new MapCollection(targetClass: ClinicalConcept::class), [self::class, 'usedCodesResult']])]
    #[Map(source: 'procedure[usedCodes?]', if: new SourceClass(ProcedureForm::class), transform: [[self::class, 'usedCodesRows'], new MapCollection(targetClass: ClinicalConcept::class), [self::class, 'usedCodesResult']])]
    public ?array $usedCodes;

    #[Map(source: '[usedReferences?]', if: new SourceClass(FormCollection::class), transform: [[self::class, 'usedReferencesRows'], new MapCollection(targetClass: ClinicalReference::class), [self::class, 'usedReferencesResult']])]
    #[Map(source: 'procedure[usedReferences?]', if: new SourceClass(ProcedureForm::class), transform: [[self::class, 'usedReferencesRows'], new MapCollection(targetClass: ClinicalReference::class), [self::class, 'usedReferencesResult']])]
    public ?array $usedReferences;

    #[Map(source: '[focalDevice?]', if: new SourceClass(FormCollection::class), transform: [[self::class, 'focalDeviceRows'], new MapCollection(targetClass: EhealthFocalDevice::class), [self::class, 'focalDeviceResult']])]
    #[Map(source: 'procedure[focalDevice?]', if: new SourceClass(ProcedureForm::class), transform: [[self::class, 'focalDeviceRows'], new MapCollection(targetClass: EhealthFocalDevice::class), [self::class, 'focalDeviceResult']])]
    public ?array $focalDevice;

    #[Map(source: '[primarySource]', if: new SourceClass(FormCollection::class), transform: [[self::class, 'performerRows'], new MapCollection(targetClass: ClinicalReference::class), [self::class, 'performerResult']])]
    #[Map(source: 'procedure[primarySource]', if: new SourceClass(ProcedureForm::class), transform: [[self::class, 'performerRows'], new MapCollection(targetClass: ClinicalReference::class), [self::class, 'performerResult']])]
    public ?array $performer;

    #[Map(source: '[primarySource]', if: new SourceClass(FormCollection::class), transform: [self::class, 'reportOriginValue'])]
    #[Map(source: 'procedure[primarySource]', if: new SourceClass(ProcedureForm::class), transform: [self::class, 'reportOriginValue'])]
    public ?array $reportOrigin;

    #[Map(if: false)]
    public readonly ?array $encounter;

    #[Map(source: '[complicationDetails?]', if: new SourceClass(FormCollection::class), transform: [[self::class, 'complicationDetailsRows'], new MapCollection(targetClass: ClinicalReference::class), [self::class, 'complicationDetailsResult']])]
    #[Map(source: 'procedure[complicationDetails?]', if: new SourceClass(ProcedureForm::class), transform: [[self::class, 'complicationDetailsRows'], new MapCollection(targetClass: ClinicalReference::class), [self::class, 'complicationDetailsResult']])]
    public ?array $complicationDetails;

    public function __construct(string $id, string $legalEntity, string $employee, private readonly ?string $encounterUuid = null)
    {
        $this->id = $id;
        $this->recordedBy = new FhirReference('employee', includeText: true)($employee, $this, null);
        $this->managingOrganization = new FhirReference('legal_entity', includeText: true)($legalEntity, $this, null);
        $this->encounter = empty($encounterUuid) ? null : new FhirReference('encounter', includeText: true)($encounterUuid, $this, null);
    }
    public static function row(FormCollection|ProcedureForm $source): array
    {
        return $source instanceof ProcedureForm ? $source->procedure : $source->all();
    }
    public static function statusValue(string $value): string
    {
        return ProcedureStatus::from($value)->value;
    }
    public static function optionalValue(mixed $value): mixed
    {
        return empty($value) ? null : $value;
    }
    public static function performedDateTimeValue(mixed $value, object $source): ?string
    {
        $row = self::row($source);

        return $row['status'] === ProcedureStatus::COMPLETED->value && ($row['performedType'] ?? null) === 'date_time' ? convertToEHealthISO8601($value.' '.$row['performedTime']) : null;
    }
    public static function performedPeriodValue(mixed $value, object $source): ?array
    {
        $row = self::row($source);

        return $row['status'] === ProcedureStatus::COMPLETED->value && $value === 'period' ? ['start' => convertToEHealthISO8601($row['performedPeriodStartDate'].' '.$row['performedPeriodStartTime']), 'end' => convertToEHealthISO8601($row['performedPeriodEndDate'].' '.$row['performedPeriodEndTime'])] : null;
    }
    public static function paperSource(mixed $value, object $source): ?FormCollection
    {
        return empty($value) ? null : new FormCollection(self::row($source));
    }
    public static function referenceRows(?array $values, string $type, string $key = 'id'): array
    {
        return array_map(static fn (string $uuid): Collection => new Collection(['uuid' => $uuid, 'type' => $type]), collect($values ?? [])->pluck($key)->filter()->unique()->values()->all());
    }
    public static function reasonReferencesRows(?array $values): array
    {
        return collect($values ?? [])->filter(static fn (array $row): bool => !empty($row['id']) && !empty($row['type']))->map(static fn (array $row): Collection => new Collection(['uuid' => $row['id'], 'type' => $row['type']]))->values()->all();
    }
    public static function usedCodesRows(?array $values): array
    {
        return array_map(static fn (string $code): Collection => new Collection(['code' => $code, 'system' => 'eHealth/assistive_products']), collect($values ?? [])->pluck('code')->filter()->unique()->values()->all());
    }
    public static function usedReferencesRows(?array $values): array
    {
        return self::referenceRows($values, 'equipment');
    }
    public static function complicationDetailsRows(?array $values, object $source, self $target): array
    {
        return empty($target->encounterUuid) ? [] : self::referenceRows($values, 'condition');
    }
    public static function focalDeviceRows(?array $values, object $source, self $target): array
    {
        return empty($target->encounterUuid) || self::row($source)['status'] !== ProcedureStatus::COMPLETED->value ? [] : array_map(static fn (array $row): Collection => new Collection($row), array_values($values ?? []));
    }
    public static function performerRows(mixed $value, object $source): array
    {
        return $value ? [new Collection(['uuid' => self::row($source)['performerEmployeeId'], 'type' => 'employee'])] : [];
    }
    public static function performerResult(array $values, object $source): ?array
    {
        return self::row($source)['primarySource'] ? $values : null;
    }
    public static function reportOriginValue(mixed $value, object $source): ?array
    {
        if ($value) {
            return null;
        }
        $row = self::row($source);
        $concept = new FhirCodeableConcept('eHealth/report_origins', includeText: true)($row['reportOriginCode'], $source, null);
        $concept['text'] = $row['reportOriginText'] ?? '';

        return $concept;
    }
    public static function basedOnValue(mixed $value, object $source): ?array
    {
        return empty($value) ? null : new FhirReference('service_request', includeText: true)($value, $source, null);
    }
    public static function divisionValue(mixed $value, object $source): ?array
    {
        return empty($value) ? null : new FhirReference('division', includeText: true)($value, $source, null);
    }
    public static function outcomeValue(mixed $value, object $source): ?array
    {
        return empty($value) ? null : new FhirCodeableConcept('eHealth/procedure_outcomes', includeText: true)($value, $source, null);
    }
    public static function reasonReferencesResult(array $values, object $source): ?array
    {
        return empty(self::row($source)['reasonReferences']) ? null : $values;
    }
    public static function usedCodesResult(array $values, object $source): ?array
    {
        return empty(self::row($source)['usedCodes']) ? null : $values;
    }
    public static function usedReferencesResult(array $values, object $source): ?array
    {
        return empty(self::row($source)['usedReferences']) ? null : $values;
    }
    public static function focalDeviceResult(array $values, object $source, self $target): ?array
    {
        return !empty($target->encounterUuid) && self::row($source)['status'] === ProcedureStatus::COMPLETED->value && !empty(self::row($source)['focalDevice']) ? $values : null;
    }
    public static function complicationDetailsResult(array $values, object $source, self $target): ?array
    {
        return !empty($target->encounterUuid) && !empty(self::row($source)['complicationDetails']) ? $values : null;
    }
}
