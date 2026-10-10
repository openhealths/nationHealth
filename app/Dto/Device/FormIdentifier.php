<?php

declare(strict_types=1);

namespace App\Dto\Device;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class FormIdentifier
{
    #[Map(source: '[identifier?][type?][coding?][0?][code?]', if: new SourceHasPath('identifier.type.coding.0.code'))]
    public mixed $code = '';

    #[Map(source: '[identifier?][type?][text?]', if: new SourceHasPath('identifier.type.text'))]
    public mixed $text = '';

    #[Map(source: '[identifier?][value?]', if: new SourceHasPath('identifier.value'))]
    public mixed $value = '';
}
