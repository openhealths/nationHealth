<?php

declare(strict_types=1);

namespace App\Dto\Observation;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class FormComponent
{
    #[Map(source: '[code?][coding?][0?][code?]', if: new SourceHasPath('code.coding.0.code'))]
    public mixed $codeCode = '';

    #[Map(source: '[code?][coding?][0?][system?]', if: new SourceHasPath('code.coding.0.system'))]
    public mixed $codeSystem = 'eHealth/ICF/qualifiers';

    #[Map(source: '[value?][valueCodeableConcept?][coding?][0?][code?]')]
    public mixed $valueCode;

    #[Map(source: '[value?][valueCodeableConcept?][coding?][0?][system?]')]
    public mixed $valueSystem;

    #[Map(source: '[interpretation?][coding?][0?][code?]', if: new SourceHasPath('interpretation.coding.0.code'))]
    public mixed $interpretationCode = '';

}
