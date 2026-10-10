<?php

declare(strict_types=1);

namespace App\Dto\Procedure;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class FormFocalDevice
{
    #[Map(source: '[manipulated?][identifier?][value?]', if: new SourceHasPath('manipulated.identifier.value'))]
    public mixed $manipulatedId = '';

    #[Map(source: '[action?][coding?][0?][code?]', if: new SourceHasPath('action.coding.0.code'))]
    public mixed $actionCode = '';

}
