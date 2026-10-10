<?php

declare(strict_types=1);

namespace App\Dto\Encounter;

use App\Core\Arr;
use App\Dto\Concerns\PreservesEhealthDocumentValues;
use App\Dto\FormCollection;
use App\Dto\Shared\ClinicalConcept;
use App\Dto\Shared\ClinicalReference;
use App\Enums\Person\EncounterStatus;
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
    #[Map(source: '[periodDate]', transform: [self::class, 'periodValue'])]
    public array $period;

    #[Map(if: false)]
    public readonly array $visit;
    #[Map(if: false)]
    public readonly array $episode;
    #[Map(source: '[classCode]', transform: [self::class, 'classValue'])]
    public array $class;

    #[Map(source: '[typeCode]', transform: new FhirCodeableConcept('eHealth/encounter_types', includeText: true))]
    public array $type;

    #[Map(source: '[performerId]', transform: new FhirReference('employee', includeText: true))]
    public array $performer;

    #[Map(source: '[referralType]', transform: [self::class, 'incomingValue'])]
    public ?array $incomingReferral;

    #[Map(source: '[referralType]', transform: [self::class, 'paperValue'])]
    public ?array $paperReferral;

    #[Map(source: '[priorityCode?]', transform: [self::class, 'priorityValue'])]
    public ?array $priority;

    #[Map(source: '[reasons?]', transform: [[self::class, 'reasonRows'], new MapCollection(targetClass: ClinicalConcept::class), [self::class, 'nonempty']])]
    public ?array $reasons;

    #[Map(source: '[diagnoses]', transform: [[self::class, 'diagnosisRows'], new MapCollection(targetClass: EhealthDiagnosis::class)])]
    public array $diagnoses;

    #[Map(source: '[actions?]', transform: [[self::class, 'actionRows'], new MapCollection(targetClass: ClinicalConcept::class), [self::class, 'nonempty']])]
    public ?array $actions;

    #[Map(source: '[actionReferences?]', transform: [[self::class, 'actionReferenceRows'], new MapCollection(targetClass: ClinicalReference::class), [self::class, 'nonempty']])]
    public ?array $actionReferences;

    #[Map(source: '[divisionId?]', transform: [self::class, 'divisionValue'])]
    public ?array $division;

    #[Map(source: '[prescriptions?]', transform: [self::class, 'prescriptionsValue'])]
    public mixed $prescriptions;

    #[Map(source: '[supportingInfo?]', transform: [[self::class, 'supportingRows'], new MapCollection(targetClass: ClinicalReference::class), [self::class, 'supportingResult']])]
    public ?array $supportingInfo;

    #[Map(source: '[hospitalization?]', transform: [[self::class, 'hospitalizationSource'], new MapObject(EhealthHospitalization::class)])]
    public ?EhealthHospitalization $hospitalization;

    #[Map(source: '[participant?]', transform: [[self::class, 'participantRows'], new MapCollection(targetClass: ClinicalReference::class), [self::class, 'nonempty']])]
    public ?array $participant;

    public function __construct(string $id, string $visit, string $episode, #[Map(if: false)] private readonly ?string $employee, #[Map(if: false)] private readonly array $conditions = [])
    {
        $this->id = $id;
        $this->status = EncounterStatus::FINISHED->value;
        $this->visit = new FhirReference('visit', includeText: true)($visit, $this, null);
        $this->episode = new FhirReference('episode', includeText: true)($episode, $this, null);
    }

    public static function periodValue(string $value, FormCollection $source): array
    {
        return ['start' => convertToEHealthISO8601($value.' '.$source['periodStart']), 'end' => convertToEHealthISO8601($value.' '.$source['periodEnd'])];
    }

    public static function classValue(string $value): array
    {
        return ['system' => 'eHealth/encounter_classes', 'code' => $value];
    }

    public static function incomingValue(mixed $value, FormCollection $source): ?array
    {
        if ($value !== 'electronic') {
            return null;
        }
        $ref = new FhirReference('service_request', includeText: true)($source['referralNumber'], $source, null);
        if (!empty($source['referralDisplayValue'])) {
            $ref['display_value'] = $source['referralDisplayValue'];
        }

        return $ref;
    }

    public static function paperValue(mixed $value, FormCollection $source): ?array
    {
        if ($value !== 'paper') {
            return null;
        }
        $paper = $source['paperReferral'];
        $paper['serviceRequestDate'] = convertToYmd($paper['serviceRequestDate']);

        return Arr::toSnakeCase($paper);
    }

    public static function priorityValue(mixed $value, FormCollection $source): ?array
    {
        return empty($value) ? null : new FhirCodeableConcept('eHealth/encounter_priority', includeText: true)($value, $source, null);
    }

    public static function reasonRows(?array $value): array
    {
        return array_map(static fn (array $row): Collection => new Collection(['code' => $row['code'], 'system' => 'eHealth/ICPC2/reasons', 'text' => $row['text'] ?? '']), $value ?? []);
    }

    public static function actionRows(?array $value): array
    {
        return array_map(static fn (array $row): Collection => new Collection(['code' => $row['code'], 'system' => 'eHealth/ICPC2/actions', 'text' => $row['text'] ?? '']), $value ?? []);
    }

    public static function diagnosisRows(array $value, FormCollection $source, self $target): array
    {
        $rows = [];
        foreach ($value as $index => $diagnosis) {
            $id = $target->conditions[$index]['id'] ?? null;
            if ($id !== null) {
                $rows[] = new Collection([...$diagnosis, 'conditionId' => $id]);
            }
        }

        return $rows;
    }

    public static function actionReferenceRows(?array $value): array
    {
        return collect($value ?? [])->pluck('uuid')->filter()->unique()->map(static fn (string $uuid): Collection => new Collection(['uuid' => $uuid, 'type' => 'service']))->values()->all();
    }

    public static function divisionValue(mixed $value, FormCollection $source): ?array
    {
        return empty($value) ? null : new FhirReference('division', includeText: true)($value, $source, null);
    }

    public static function prescriptionsValue(mixed $value): mixed
    {
        return empty($value) ? null : (is_array($value) ? Arr::toSnakeCase($value) : $value);
    }

    public static function supportingRows(?array $value): array
    {
        return collect($value ?? [])->filter(static fn (array $row): bool => !empty($row['uuid']) && !empty($row['type']))->unique(static fn (array $row): string => $row['type'].':'.$row['uuid'])->map(static fn (array $row): Collection => new Collection(['uuid' => $row['uuid'], 'type' => $row['type'], 'text' => $row['typeLabel'] ?? '']))->values()->all();
    }

    public static function supportingResult(array $value, FormCollection $source): ?array
    {
        return empty($source['supportingInfo']) ? null : $value;
    }

    public static function hospitalizationSource(?array $value): ?Collection
    {
        return array_filter($value ?? []) === [] ? null : new Collection($value);
    }

    public static function participantRows(?array $value, FormCollection $source, self $target): array
    {
        $asserters = collect($target->conditions)->flatMap(static function (array $row): array {
            $asserter = $row['asserter'] ?? null;
            if (!$asserter) {
                return [];
            }

            return array_is_list($asserter) ? collect($asserter)->pluck('identifier.value')->all() : [data_get($asserter, 'identifier.value')];
        })->filter();

        return collect($value ?? [])->pluck('uuid')->push($target->employee)->concat($asserters)->filter()->unique()->map(static fn (string $uuid): Collection => new Collection(['uuid' => $uuid, 'type' => 'employee']))->values()->all();
    }

    public static function nonempty(array $value): ?array
    {
        return $value ?: null;
    }
}
