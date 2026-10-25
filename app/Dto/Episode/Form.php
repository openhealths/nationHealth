<?php

declare(strict_types=1);

namespace App\Dto\Episode;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class Form
{
    #[Map(source: '[uuid?]')]
    public mixed $id;

    #[Map(source: '[name?]')]
    public mixed $name;

    #[Map(source: '[type?][code?]', if: new SourceHasPath('type.code'))]
    public mixed $typeCode = '';

    #[Map(source: '[careManager?][identifier?][value?]', if: new SourceHasPath('careManager.identifier.value'))]
    public mixed $careManagerId = '';

    #[Map(source: '[period?][start?]', transform: [self::class, 'dateValue'])]
    public string $startDate;

    #[Map(source: '[period?][start?]', transform: [self::class, 'timeValue'])]
    public string $startTime;

    public static function dateValue(mixed $value): string
    {
        return Str::before((string) $value, ' ');
    }

    public static function timeValue(mixed $value): string
    {
        return Str::after((string) $value, ' ');
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
