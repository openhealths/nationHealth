<?php

declare(strict_types=1);

namespace App\Dto\Condition;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class FormEvidence
{
    #[Map(source: '[identifier?][value?]')]
    public mixed $id;

    #[Map(source: '[resolvedDetails?][ehealthInsertedAt?]', if: new SourceHasPath('resolvedDetails.ehealthInsertedAt'))]
    public mixed $ehealthInsertedAt = '';

    #[Map(source: '[resolvedDetails?][codeCode?]')]
    public mixed $codeCode;

    #[Map(source: '[resolvedDetails?][type?]')]
    public mixed $type;

}
