<?php

declare(strict_types=1);

namespace App\Dto\CarePlanActivity;

use Symfony\Component\ObjectMapper\Attribute\Map;

final class OutcomeReference
{
    #[Map(source: 'uuid', transform: [self::class, 'mapIdentifier'])]
    public array $identifier;

    public static function mapIdentifier(string $uuid): array
    {
        return ['value' => $uuid];
    }
}
