<?php

declare(strict_types=1);

namespace App\Dto\Condition;

use App\Mapping\Conditions\SourceHasPath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Transform\MapCollection;

#[Map(source: Collection::class)]
final class Form
{
    #[Map(source: '[uuid?]')]
    public mixed $uuid;

    #[Map(source: '[primarySource?]')]
    public mixed $primarySource;

    #[Map(source: '[code?][coding?][0?][system?]')]
    public mixed $codeSystem;

    #[Map(source: '[code?][coding?][0?][code?]')]
    public mixed $codeCode;

    #[Map(source: '[clinicalStatus?]')]
    public mixed $clinicalStatus;

    #[Map(source: '[verificationStatus?]')]
    public mixed $verificationStatus;

    #[Map(source: '[onsetDate?]', transform: 'convertToAppDateFormat')]
    public string $onsetDate;

    #[Map(source: '[onsetDate?]', transform: [self::class, 'onsetTimeValue'])]
    public string $onsetTime;

    #[Map(source: '[assertedDate?]', transform: [self::class, 'assertedDateValue'])]
    public ?string $assertedDate;

    #[Map(source: '[assertedDate?]', transform: [self::class, 'assertedTimeValue'])]
    public ?string $assertedTime;

    #[Map(source: '[severity?][coding?][0?][code?]', if: new SourceHasPath('severity.coding.0.code'))]
    public mixed $severityCode = '';

    #[Map(source: '[bodySites?]', transform: [[self::class, 'rows'], new MapCollection(targetClass: \App\Dto\Shared\FormConceptCode::class)])]
    public array $bodySites;

    #[Map(source: '[stage?][summary?][coding?][0?][code?]', if: new SourceHasPath('stage.summary.coding.0.code'))]
    public mixed $stageCode = '';

    #[Map(source: '[asserter?]', transform: [self::class, 'asserterValue'])]
    public mixed $asserterEmployeeId;

    #[Map(source: '[asserter?]', transform: [self::class, 'asserterTextValue'])]
    public mixed $asserterText;

    #[Map(source: '[reportOrigin?][coding?][0?][code?]', if: new SourceHasPath('reportOrigin.coding.0.code'))]
    public mixed $reportOriginCode = '';

    #[Map(source: '[evidences?][0?][codes?]', transform: [[self::class, 'rows'], new MapCollection(targetClass: FormCode::class)])]
    public array $evidenceCodes;

    #[Map(source: '[evidences?][0?][details?]', transform: [[self::class, 'resolvedRows'], new MapCollection(targetClass: FormEvidence::class)])]
    public array $evidenceDetails;

    public function __construct(#[Map(if: false)] private readonly array $detailsMap = [], #[Map(if: false)] private readonly string $fallbackTime = '')
    {
    }

    public static function onsetTimeValue(mixed $value, Collection $source, self $target): string
    {
        return $value ? CarbonImmutable::parse($value)->format('H:i') : $target->fallbackTime;
    }

    public static function assertedDateValue(mixed $value): ?string
    {
        return $value ? convertToAppDateFormat($value) : null;
    }

    public static function assertedTimeValue(mixed $value): ?string
    {
        return $value ? CarbonImmutable::parse($value)->format('H:i') : null;
    }

    public static function asserterValue(?array $value): mixed
    {
        return data_get($value, '0.identifier.value', data_get($value, 'identifier.value', ''));
    }

    public static function asserterTextValue(?array $value): mixed
    {
        return data_get($value, '0.identifier.type.text', data_get($value, 'identifier.type.text', ''));
    }

    public static function resolvedRows(?array $value, Collection $source, self $target): array
    {
        return array_map(static fn (array $row): Collection => new Collection([...$row, 'resolvedDetails' => $target->detailsMap[$row['identifier']['value'] ?? null] ?? []]), $value ?? []);
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

        return $data;
    }
}
