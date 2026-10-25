<?php

declare(strict_types=1);

namespace App\Dto\Specimen;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class FormParent
{
    #[Map(source: '[identifier?][value?]', if: new SourceHasPath('identifier.value'))]
    public string $value = '';
}
