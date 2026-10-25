<?php

declare(strict_types=1);

namespace App\Dto\Procedure;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class FormReason
{
    #[Map(source: '[identifier?][value?]')]
    public mixed $id;

    #[Map(source: '[identifier?][type?][coding?][0?][code?]')]
    public mixed $type;

    #[Map(source: '[resolvedDetails?][ehealthInsertedAt?]')]
    public mixed $ehealthInsertedAt;

    #[Map(source: '[resolvedDetails?][codeCode?]', if: new SourceHasPath('resolvedDetails.codeCode'))]
    public mixed $codeCode = '';

    #[Map(source: '[resolvedDetails?][codeSystem?]')]
    public mixed $codeSystem;

}
