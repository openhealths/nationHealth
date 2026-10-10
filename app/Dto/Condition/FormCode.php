<?php

declare(strict_types=1);

namespace App\Dto\Condition;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class FormCode
{
    #[Map(source: '[coding?][0?][code?]', if: new SourceHasPath('coding.0.code'))]
    public mixed $code = '';

    #[Map(source: '[coding?][0?][system?]')]
    public mixed $system;

}
