<?php

declare(strict_types=1);

namespace App\Dto\Encounter;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class FormConcept
{
    #[Map(source: '[coding?][0?][code?]')]
    public mixed $code;

    #[Map(source: '[text?]', if: new SourceHasPath('text'))]
    public mixed $text = '';

}
