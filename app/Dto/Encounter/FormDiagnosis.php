<?php

declare(strict_types=1);

namespace App\Dto\Encounter;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class FormDiagnosis
{
    #[Map(source: '[role?][coding?][0?][code?]')]
    public mixed $roleCode;

    #[Map(source: '[rank?]', if: new SourceHasPath('rank'))]
    public mixed $rank = '';

}
