<?php

declare(strict_types=1);

namespace App\Dto\ClinicalImpression;

use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class FormProblem
{
    #[Map(source: '[identifier?][value?]')]
    public mixed $id;

    #[Map(source: '[resolvedDetails?][ehealthInsertedAt?]')]
    public mixed $ehealthInsertedAt;

    #[Map(source: '[resolvedDetails?][codeCode?]')]
    public mixed $codeCode;

    #[Map(source: '[resolvedDetails?][codeSystem?]')]
    public mixed $codeSystem;

}
