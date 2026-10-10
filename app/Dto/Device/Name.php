<?php

declare(strict_types=1);

namespace App\Dto\Device;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** Names have the same shape in the validated form, API document and stored relation. */
#[Map(source: Collection::class)]
final class Name
{
    #[Map(source: '[type?]', if: new SourceHasPath('type'))]
    public mixed $type = '';

    #[Map(source: '[value?]', if: new SourceHasPath('value'))]
    public mixed $value = '';
}
