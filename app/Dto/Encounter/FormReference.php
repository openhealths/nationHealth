<?php

declare(strict_types=1);

namespace App\Dto\Encounter;

use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class FormReference
{
    #[Map(source: '[identifier?][value?]')]
    public mixed $uuid;

}
