<?php

declare(strict_types=1);

namespace App\Dto\Encounter;

use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class FormParticipant
{
    #[Map(source: '[identifier?][value?]')]
    public mixed $uuid;

    #[Map(source: '[displayValue?]', transform: [self::class, 'nameValue'])]
    public mixed $name;

    public static function nameValue(mixed $value, Collection $source): mixed
    {
        return $source->has('displayValue') ? $value : ($source->has('display_value') ? $source['display_value'] : '');
    }
}
