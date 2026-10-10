<?php

declare(strict_types=1);

namespace App\Dto\ClinicalImpression;

use App\Enums\ClinicalImpression\Status;
use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: Collection::class)]
final class Form
{
    #[Map(source: '[uuid?]')]
    public mixed $uuid;

    #[Map(source: '[status?]', if: new SourceHasPath('status'))]
    public mixed $status = Status::COMPLETED->value;

    #[Map(source: '[code?][coding?][0?][code?]')]
    public mixed $codeCode;

    #[Map(source: '[assessor?][identifier?][value?]', if: new SourceHasPath('assessor.identifier.value'))]
    public mixed $assessorEmployeeId = '';

    #[Map(source: '[description?]', if: new SourceHasPath('description'))]
    public mixed $description = '';

    #[Map(source: '[effectivePeriodStartDate?]', if: new SourceHasPath('effectivePeriodStartDate'))]
    public mixed $effectivePeriodStartDate = '';

    #[Map(source: '[effectivePeriodStartTime?]', if: new SourceHasPath('effectivePeriodStartTime'))]
    public mixed $effectivePeriodStartTime = '';

    #[Map(source: '[effectivePeriodEndDate?]', if: new SourceHasPath('effectivePeriodEndDate'))]
    public mixed $effectivePeriodEndDate = '';

    #[Map(source: '[effectivePeriodEndTime?]', if: new SourceHasPath('effectivePeriodEndTime'))]
    public mixed $effectivePeriodEndTime = '';

    #[Map(source: '[note?]', if: new SourceHasPath('note'))]
    public mixed $note = '';

    #[Map(source: '[summary?]', if: new SourceHasPath('summary'))]
    public mixed $summary = '';

    #[Map(source: '[previous?]', transform: [[self::class, 'previousRows'], new MapCollection(targetClass: FormPrevious::class)])]
    public array $previous;

    #[Map(source: '[problems?]', transform: [[self::class, 'resolvedRows'], new MapCollection(targetClass: FormProblem::class)])]
    public array $problems;

    #[Map(source: '[findings?]', transform: [[self::class, 'findingRows'], new MapCollection(targetClass: FormFinding::class)])]
    public array $findings;

    #[Map(source: '[supportingInfo?]', transform: [[self::class, 'supportingRows'], new MapCollection(targetClass: FormSupportingInfo::class)])]
    public array $supportingInfo;

    public function __construct(#[Map(if: false)] private readonly array $detailsMap = [])
    {
    }

    public static function previousRows(?array $value, Collection $source, self $target): array
    {
        return data_get($value, 'identifier.value') ? self::resolvedRows([$value], $source, $target) : [];
    }

    public static function resolvedRows(?array $value, Collection $source, self $target): array
    {
        return array_map(static fn (array $row): Collection => new Collection([...$row, 'resolvedDetails' => $target->detailsMap[data_get($row, 'identifier.value')] ?? []]), $value ?? []);
    }

    public static function findingRows(?array $value, Collection $source, self $target): array
    {
        return array_map(static fn (array $row): Collection => new Collection([...$row, 'resolvedDetails' => $target->detailsMap[data_get($row, 'itemReference.identifier.value')] ?? []]), $value ?? []);
    }

    public static function supportingRows(?array $value, Collection $source, self $target): array
    {
        return self::resolvedRows(array_values($value ?? []), $source, $target);
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

        return $data;
    }
}
