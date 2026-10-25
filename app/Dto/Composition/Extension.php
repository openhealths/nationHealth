<?php

declare(strict_types=1);

namespace App\Dto\Composition;

use App\Dto\FormCollection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: FormCollection::class)]
final class Extension
{
    #[Map(source: '[valueCode]')]
    public string $valueCode;

    #[Map(source: '[valueUuid?]')]
    public ?string $valueUuid = null;

    #[Map(source: '[valueDate?]')]
    public ?string $valueDate = null;

    #[Map(source: '[valueString?]')]
    public ?string $valueString = null;

    #[Map(source: '[valueBoolean?]')]
    public ?bool $valueBoolean = null;

    public function toArray(): array
    {
        return array_filter(get_object_vars($this), static fn (mixed $value): bool => $value !== null);
    }
}
