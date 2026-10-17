<?php

declare(strict_types=1);

namespace App\Dto\Division;

use App\Dto\FormCollection;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;

#[Map(source: FormCollection::class)]
class Coordinate
{
    #[Map(source: '[value]', transform: [self::class, 'normalizeInput'])]
    public mixed $value = null;

    public static function fromInput(mixed $value): self
    {
        return new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())
            ->map(new FormCollection(['value' => $value]), self::class);
    }

    public static function normalizeInput(mixed $value): mixed
    {
        $normalized = Location::normalizeCoordinate($value);

        return \is_float($normalized)
            ? (float) number_format($normalized, 6, '.', '')
            : $value;
    }
}
