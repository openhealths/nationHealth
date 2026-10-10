<?php

declare(strict_types=1);

namespace App\Dto\Procedure;

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
    public mixed $status = 'completed';

    #[Map(source: '[category?][coding?][0?][code?]', if: new SourceHasPath('category.coding.0.code'))]
    public mixed $categoryCode = '';

    #[Map(source: '[code?][identifier?][value?]', if: new SourceHasPath('code.identifier.value'))]
    public mixed $codeValue = '';

    #[Map(source: '[encounter?][identifier?][value?]', if: new SourceHasPath('encounter.identifier.value'))]
    public mixed $encounterId = '';

    #[Map(source: '[primarySource?]')]
    public mixed $primarySource;

    #[Map(source: '[performer?]', transform: [self::class, 'performerValue'])]
    public mixed $performerEmployeeId;

    #[Map(source: '[reportOrigin?][coding?][0?][code?]', if: new SourceHasPath('reportOrigin.coding.0.code'))]
    public mixed $reportOriginCode = '';

    #[Map(source: '[reportOrigin?][text?]', if: new SourceHasPath('reportOrigin.text'))]
    public mixed $reportOriginText = '';

    #[Map(source: '[division?][identifier?][value?]', if: new SourceHasPath('division.identifier.value'))]
    public mixed $divisionId = '';

    #[Map(source: '[outcome?][coding?][0?][code?]', if: new SourceHasPath('outcome.coding.0.code'))]
    public mixed $outcomeCode = '';

    #[Map(source: '[note?]', if: new SourceHasPath('note'))]
    public mixed $note = '';

    #[Map(source: '[basedOn?]', transform: [self::class, 'basedOnValue'])]
    public mixed $basedOnIdentifier;

    #[Map(source: '[paperReferral?]', transform: [[self::class, 'sourceObject'], new MapObject(\App\Dto\PaperReferral\Form::class)])]
    public \App\Dto\PaperReferral\Form $paperReferralData;

    #[Map(source: '[performedDateTime?]', transform: [self::class, 'performedTypeValue'])]
    public string $performedType;

    #[Map(source: '[performedDateTime?]', transform: [self::class, 'dateValue'])]
    public string $performedDate;

    #[Map(source: '[performedDateTime?]', transform: [self::class, 'timeValue'])]
    public string $performedTime;

    #[Map(source: '[performedPeriodStartDate?]', transform: 'convertToAppDateFormat')]
    public string $performedPeriodStartDate;

    #[Map(source: '[performedPeriodStartTime?]', transform: [self::class, 'timeValue'])]
    public string $performedPeriodStartTime;

    #[Map(source: '[performedPeriodEndDate?]', transform: 'convertToAppDateFormat')]
    public string $performedPeriodEndDate;

    #[Map(source: '[performedPeriodEndTime?]', transform: [self::class, 'timeValue'])]
    public string $performedPeriodEndTime;

    #[Map(source: '[reasonReferences?]', transform: [[self::class, 'resolvedRows'], new MapCollection(targetClass: FormReason::class)])]
    public array $reasonReferences;

    #[Map(source: '[usedCodes?]', transform: [[self::class, 'rows'], new MapCollection(targetClass: \App\Dto\Shared\FormConceptCode::class)])]
    public array $usedCodes;

    #[Map(source: '[usedReferences?]', transform: [[self::class, 'usedRows'], new MapCollection(targetClass: FormUsedReference::class)])]
    public array $usedReferences;

    #[Map(source: '[focalDevices?]', transform: [[self::class, 'rows'], new MapCollection(targetClass: FormFocalDevice::class)])]
    public array $focalDevice;

    #[Map(source: '[complicationDetails?]', transform: [[self::class, 'resolvedRows'], new MapCollection(targetClass: FormReason::class)])]
    public array $complicationDetails;

    public function __construct(#[Map(if: false)] private readonly array $detailsMap = [])
    {
    }

    public static function sourceObject(mixed $value, Collection $source): Collection
    {
        return $source;
    }

    public static function performerValue(?array $value): mixed
    {
        return data_get($value, '0.identifier.value', data_get($value, 'identifier.value', ''));
    }

    public static function basedOnValue(?array $value): mixed
    {
        return data_get($value, '0.identifier.value', data_get($value, 'identifier.value', ''));
    }

    public static function performedTypeValue(mixed $value, Collection $source): string
    {
        return !empty($value) ? 'date_time' : (!empty($source['performedPeriodStartDate']) ? 'period' : '');
    }

    public static function dateValue(mixed $value): string
    {
        return $value ? convertToAppDateFormat($value) : '';
    }

    public static function timeValue(mixed $value): string
    {
        return $value ? CarbonImmutable::parse($value)->format('H:i') : '';
    }

    public static function rows(?array $value): array
    {
        return array_map(static fn (array $row): Collection => new Collection($row), $value ?? []);
    }

    public static function usedRows(?array $value): array
    {
        return self::rows(array_values(array_filter($value ?? [], static fn (array $row): bool => !empty($row['identifier']['value']))));
    }

    public static function resolvedRows(?array $value, Collection $source, self $target): array
    {
        return array_map(static fn (array $row): Collection => new Collection([...$row, 'resolvedDetails' => $target->detailsMap[$row['identifier']['value'] ?? null] ?? []]), $value ?? []);
    }

    public function toArray(): array
    {
        $result = [];
        foreach (get_object_vars($this) as $key => $value) {
            if ($key === 'detailsMap') {
                continue;
            }
            if ($key === 'paperReferralData') {
                $result = [...$result, ...$value->toArray()];
                continue;
            }
            if (in_array($key, ['reasonReferences', 'usedCodes', 'usedReferences', 'focalDevice', 'complicationDetails'], true)) {
                $value = array_map(static function (object $row) use ($key): array {
                    $data = get_object_vars($row);
                    if ($key === 'complicationDetails') {
                        unset($data['type']);
                    }

                    return $data;
                }, $value);
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
