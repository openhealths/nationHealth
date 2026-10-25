<?php

declare(strict_types=1);

namespace App\Dto\Encounter;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class FormSupportingInfo
{
    #[Map(source: '[identifier?][value?]')]
    public mixed $uuid;

    #[Map(source: '[identifier?][type?][coding?][0?][code?]')]
    public mixed $type;

    #[Map(source: '[resolvedDetails?][ehealthInsertedAt?]')]
    public mixed $date;

    #[Map(source: '[resolvedDetails?][codeCode?]')]
    public mixed $code;

    #[Map(source: '[name?]', if: new SourceHasPath('name'))]
    public mixed $name = '';

    #[Map(source: '[typeLabel?]', if: new SourceHasPath('typeLabel'))]
    public mixed $typeLabel = '';

}
