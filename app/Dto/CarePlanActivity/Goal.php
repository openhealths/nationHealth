<?php

declare(strict_types=1);

namespace App\Dto\CarePlanActivity;

use Symfony\Component\ObjectMapper\Attribute\Map;

final class Goal
{
    #[Map(source: 'code', transform: [self::class, 'mapCoding'])]
    public array $coding;

    public static function mapCoding(string $code): array
    {
        return [['system' => 'eHealth/care_plan_activity_goals', 'code' => $code]];
    }
}
