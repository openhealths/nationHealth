<?php

declare(strict_types=1);

namespace App\Dto\DiagnosticReport;

use App\Enums\Person\DiagnosticReportStatus;
use App\Mapping\Conditions\SourceHasPath;
use App\Mapping\Transforms\MapObject;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: Collection::class)]
final class Form
{
    #[Map(source: '[uuid?]')]
    public mixed $uuid;

    #[Map(source: '[status?]', if: new SourceHasPath('status'))]
    public mixed $status = DiagnosticReportStatus::FINAL->value;

    #[Map(source: '[category?][0?][coding?][0?][code?]')]
    public mixed $categoryCode;

    #[Map(source: '[code?][identifier?][value?]', if: new SourceHasPath('code.identifier.value'))]
    public mixed $codeValue = '';

    #[Map(source: '[primarySource?]')]
    public mixed $primarySource;

    #[Map(source: '[reportOrigin?][coding?][0?][code?]', if: new SourceHasPath('reportOrigin.coding.0.code'))]
    public mixed $reportOriginCode = '';

    #[Map(source: '[reportOrigin?][text?]', if: new SourceHasPath('reportOrigin.text'))]
    public mixed $reportOriginText = '';

    #[Map(source: '[paperReferral?]', transform: [[self::class, 'sourceObject'], new MapObject(\App\Dto\PaperReferral\Form::class)])]
    public \App\Dto\PaperReferral\Form $paperReferralData;

    #[Map(source: '[conclusionCode?][coding?][0?][code?]', if: new SourceHasPath('conclusionCode.coding.0.code'))]
    public mixed $conclusionCode = '';

    #[Map(source: '[conclusion?]', if: new SourceHasPath('conclusion'))]
    public mixed $conclusion = '';

    #[Map(source: '[division?][identifier?][value?]', if: new SourceHasPath('division.identifier.value'))]
    public mixed $divisionId = '';

    #[Map(source: '[performer?]', transform: [[self::class, 'performerRows'], new MapCollection(targetClass: FormReference::class), [self::class, 'ids']])]
    public array $performerEmployeeIds;

    #[Map(source: '[basedOn?][identifier?][value?]', if: new SourceHasPath('basedOn.identifier.value'))]
    public mixed $basedOnIdentifier = '';

    #[Map(source: '[usedReferences?]', transform: [[self::class, 'referenceRows'], new MapCollection(targetClass: FormReference::class)])]
    public array $usedReferences;

    #[Map(source: '[specimens?]', transform: [[self::class, 'referenceRows'], new MapCollection(targetClass: FormReference::class), [self::class, 'ids']])]
    public array $specimenIds;

    #[Map(source: '[resultsInterpreter?][reference?][identifier?][value?]', if: new SourceHasPath('resultsInterpreter.reference.identifier.value'))]
    public mixed $resultsInterpreterEmployeeId = '';

    #[Map(source: '[issuedDate?]')]
    public mixed $issuedDate;

    #[Map(source: '[issuedTime?]')]
    public mixed $issuedTime;

    #[Map(source: '[effectiveDateTime?]', transform: [self::class, 'effectiveTypeValue'])]
    public string $effectiveType;

    #[Map(source: '[effectiveDate?]', transform: [self::class, 'effectiveDateValue'])]
    public mixed $effectiveDate;

    #[Map(source: '[effectiveTime?]', transform: [self::class, 'effectiveTimeValue'])]
    public mixed $effectiveTime;

    #[Map(source: '[effectivePeriodStartDate?]', if: new SourceHasPath('effectivePeriodStartDate'))]
    public mixed $effectivePeriodStartDate = '';

    #[Map(source: '[effectivePeriodStartTime?]', if: new SourceHasPath('effectivePeriodStartTime'))]
    public mixed $effectivePeriodStartTime = '';

    #[Map(source: '[effectivePeriodEndDate?]', if: new SourceHasPath('effectivePeriodEndDate'))]
    public mixed $effectivePeriodEndDate = '';

    #[Map(source: '[effectivePeriodEndTime?]', if: new SourceHasPath('effectivePeriodEndTime'))]
    public mixed $effectivePeriodEndTime = '';

    public static function sourceObject(mixed $value, Collection $source): Collection
    {
        return $source;
    }

    public static function performerRows(?array $value, Collection $source): array
    {
        $interpreter = data_get($source->all(), 'resultsInterpreter.reference.identifier.value', '');

        return collect($value ?? [])->pluck('reference.identifier.value')->filter()->reject(static fn (string $id): bool => $id === $interpreter)->unique()->map(static fn (string $id): Collection => new Collection(['identifier' => ['value' => $id]]))->values()->all();
    }

    public static function referenceRows(?array $value): array
    {
        return collect($value ?? [])->filter(static fn (array $row): bool => !empty(data_get($row, 'identifier.value')))->map(static fn (array $row): Collection => new Collection($row))->values()->all();
    }

    public static function ids(array $value): array
    {
        return array_map(static fn (object $row): mixed => $row->id, $value);
    }

    public static function effectiveTypeValue(mixed $value, Collection $source): string
    {
        return !empty($value) ? 'date_time' : (!empty($source['effectivePeriodStartDate']) ? 'period' : '');
    }

    public static function effectiveDateValue(mixed $value, Collection $source): mixed
    {
        return $source->has('effectiveDate') ? $value : (!empty($source['effectiveDateTime']) ? convertToAppDateFormat($source['effectiveDateTime']) : '');
    }

    public static function effectiveTimeValue(mixed $value, Collection $source): mixed
    {
        return $source->has('effectiveTime') ? $value : (!empty($source['effectiveDateTime']) ? CarbonImmutable::parse($source['effectiveDateTime'])->format('H:i') : '');
    }

    public function toArray(): array
    {
        $data = [];
        foreach (get_object_vars($this) as $key => $value) {
            if ($key === 'paperReferralData') {
                $data = [...$data, ...$value->toArray()];
                continue;
            }
            if ($key === 'usedReferences') {
                $value = array_map(get_object_vars(...), $value);
            }
            $data[$key] = $value;
        }

        return $data;
    }
}
