<?php

declare(strict_types=1);

namespace App\Dto\CarePlan;

use Symfony\Component\ObjectMapper\Attribute\Map;

final class DisplayReference
{
    #[Map(source: 'name')]
    public string $display;
}
