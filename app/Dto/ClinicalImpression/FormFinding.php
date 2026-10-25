<?php

declare(strict_types=1);

namespace App\Dto\ClinicalImpression;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class FormFinding
{
    #[Map(source: '[itemReference?][identifier?][value?]')]
    public mixed $id;

    #[Map(source: '[itemReference?][identifier?][type?][coding?][0?][code?]')]
    public mixed $type;

    #[Map(source: '[resolvedDetails?][ehealthInsertedAt?]')]
    public mixed $ehealthInsertedAt;

    #[Map(source: '[resolvedDetails?][codeCode?]')]
    public mixed $codeCode;

    #[Map(source: '[resolvedDetails?][codeSystem?]')]
    public mixed $codeSystem;

    #[Map(source: '[basis?]', if: new SourceHasPath('basis'))]
    public mixed $basis = '';

}
