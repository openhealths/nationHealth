<?php

declare(strict_types=1);

namespace App\Dto\Procedure;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class FormUsedReference
{
    #[Map(source: '[identifier?][value?]', if: new SourceHasPath('identifier.value'))]
    public mixed $id = '';

}
