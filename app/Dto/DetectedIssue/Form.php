<?php

declare(strict_types=1);

namespace App\Dto\DetectedIssue;

use App\Enums\DetectedIssue\Status;
use App\Mapping\Conditions\SourceHasPath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** Already-loaded repository document to the editable encounter row. */
#[Map(source: Collection::class)]
final class Form
{
    #[Map(source: '[uuid?]', transform: [self::class, 'uuid'])]
    public mixed $uuid;

    #[Map(source: '[subject?][identifier?][value?]', if: new SourceHasPath('subject.identifier.value'))]
    public mixed $subjectId = '';

    #[Map(source: '[status?]', if: new SourceHasPath('status'))]
    public mixed $status = Status::PRELIMINARY->value;

    #[Map(source: '[identifiedDateTime?]', transform: [self::class, 'date'])]
    public string $identifiedDate;

    #[Map(source: '[identifiedDateTime?]', transform: [self::class, 'time'])]
    public string $identifiedTime;

    #[Map(source: '[code?][coding?][0?][code?]', if: new SourceHasPath('code.coding.0.code'))]
    public mixed $code = '';

    #[Map(source: '[detail?]', if: new SourceHasPath('detail'))]
    public mixed $detail = '';

    #[Map(source: '[implicated?][identifier?][value?]', if: new SourceHasPath('implicated.identifier.value'))]
    public mixed $implicatedId = '';

    #[Map(source: '[basedOn?][identifier?][value?]', if: new SourceHasPath('basedOn.identifier.value'))]
    public mixed $basedOnId = '';

    #[Map(source: '[primarySource?]', if: new SourceHasPath('primarySource'))]
    public mixed $primarySource = true;

    #[Map(source: '[author?]', transform: [self::class, 'authorEmployee'])]
    public mixed $authorEmployeeId;

    #[Map(source: '[reportOrigin?][coding?][0?][code?]', if: new SourceHasPath('reportOrigin.coding.0.code'))]
    public mixed $reportOriginCode = '';

    public static function uuid(mixed $uuid, Collection $source): mixed
    {
        return array_key_exists('uuid', $source->all()) ? $uuid : ($source['id'] ?? null);
    }

    public static function date(mixed $value): string
    {
        return $value ? convertToAppDateFormat($value) : '';
    }

    public static function authorEmployee(mixed $value): mixed
    {
        // Secondary-source records may carry author as an empty JSON object rather than an array.
        return data_get($value, 'identifier.value', '');
    }

    public static function time(mixed $value): string
    {
        return $value ? CarbonImmutable::parse($value)->format('H:i') : '';
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
