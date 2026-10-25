<?php

declare(strict_types=1);

namespace App\Dto\Composition;

use App\Dto\FormCollection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: FormCollection::class)]
final class Event
{
    #[Map(if: false)]
    public array $code = ['coding' => [['system' => 'COMPOSITION_EVENTS', 'code' => 'COMPOSITION_VALIDITY_PERIOD']]];

    #[Map(source: '[period]')]
    public array $period;

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
