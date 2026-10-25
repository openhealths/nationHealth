<?php

declare(strict_types=1);

namespace App\Dto\Encounter;

use App\Mapping\Conditions\SourceHasPath;
use App\Mapping\Transforms\MapObject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: Collection::class)]
final class Form
{
    #[Map(source: '[class?][code?]')]
    public mixed $classCode;

    #[Map(source: '[type?][coding?][0?][code?]')]
    public mixed $typeCode;

    #[Map(source: '[performer?][identifier?][value?]', if: new SourceHasPath('performer.identifier.value'))]
    public mixed $performerId = '';

    #[Map(source: '[division?][identifier?][value?]', if: new SourceHasPath('division.identifier.value'))]
    public mixed $divisionId = '';

    #[Map(source: '[priority?][coding?][0?][code?]', if: new SourceHasPath('priority.coding.0.code'))]
    public mixed $priorityCode = '';

    #[Map(source: '[period?][start?]', transform: 'convertToAppDateFormat')]
    public string $periodDate;

    #[Map(source: '[period?][start?]', transform: [self::class, 'timeValue'])]
    public string $periodStart;

    #[Map(source: '[period?][end?]', transform: [self::class, 'timeValue'])]
    public string $periodEnd;

    #[Map(source: '[actions?]', transform: [[self::class, 'rows'], new MapCollection(targetClass: FormConcept::class)])]
    public array $actions;

    #[Map(source: '[reasons?]', transform: [[self::class, 'rows'], new MapCollection(targetClass: FormConcept::class)])]
    public array $reasons;

    #[Map(source: '[diagnoses?]', transform: [[self::class, 'rows'], new MapCollection(targetClass: FormDiagnosis::class)])]
    public array $diagnoses;

    #[Map(source: '[incoming_referral?]', transform: [self::class, 'referralTypeValue'])]
    public string $referralType;

    #[Map(source: '[incoming_referral?][displayValue?]', if: new SourceHasPath('incoming_referral.displayValue'))]
    public mixed $referralNumber = '';

    #[Map(source: '[paper_referral?]', transform: [self::class, 'paperValue'])]
    public array $paperReferral;

    #[Map(source: '[prescriptions?]', if: new SourceHasPath('prescriptions'))]
    public mixed $prescriptions = '';

    #[Map(source: '[hospitalization?]', transform: [[self::class, 'objectSource'], new MapObject(FormHospitalization::class)])]
    public FormHospitalization $hospitalization;

    #[Map(source: '[action_references?]', transform: [[self::class, 'referenceRows'], new MapCollection(targetClass: FormReference::class)])]
    public array $actionReferences;

    #[Map(source: '[participants?]', transform: [[self::class, 'referenceRows'], new MapCollection(targetClass: FormParticipant::class)])]
    public array $participant;

    #[Map(source: '[supporting_info?]', transform: [[self::class, 'supportingRows'], new MapCollection(targetClass: FormSupportingInfo::class)])]
    public array $supportingInfo;

    public function __construct(#[Map(if: false)] private readonly array $detailsMap = [], #[Map(if: false)] private readonly string $fallbackTime = '')
    {
    }

    public static function timeValue(mixed $value, Collection $source, self $target): string
    {
        return $value ? CarbonImmutable::parse($value)->format('H:i') : $target->fallbackTime;
    }

    public static function referralTypeValue(mixed $value, Collection $source): string
    {
        return !empty($value) ? 'electronic' : (!empty($source['paper_referral']) ? 'paper' : '');
    }

    public static function paperValue(?array $value): array
    {
        return [...($value ?? []), 'serviceRequestDate' => convertToAppDateFormat(data_get($value, 'serviceRequestDate'))];
    }

    public static function objectSource(?array $value): Collection
    {
        return new Collection($value ?? []);
    }

    public static function referenceRows(?array $value): array
    {
        return self::rows(array_values(array_filter($value ?? [], static fn (array $row): bool => !empty(data_get($row, 'identifier.value')))));
    }

    public static function supportingRows(?array $value, Collection $source, self $target): array
    {
        return array_map(static fn (array $row): Collection => new Collection([...$row, 'resolvedDetails' => $target->detailsMap[data_get($row, 'identifier.value')] ?? []]), array_values($value ?? []));
    }

    public static function rows(?array $value): array
    {
        return array_map(static fn (array $row): Collection => new Collection($row), $value ?? []);
    }

    public function toArray(): array
    {
        $data = get_object_vars($this);
        unset($data['detailsMap'], $data['fallbackTime']);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = array_map(static fn (mixed $row): mixed => is_object($row) ? (method_exists($row, 'toArray') ? $row->toArray() : get_object_vars($row)) : $row, $value);
            }
        }

        $data['hospitalization'] = $this->hospitalization->toArray();

        return $data;
    }
}
