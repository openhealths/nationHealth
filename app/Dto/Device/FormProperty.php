<?php

declare(strict_types=1);

namespace App\Dto\Device;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** Repository stores property variants beneath the value relation. Missing variants stay null. */
#[Map(source: Collection::class)]
final class FormProperty
{
    #[Map(source: '[code?][coding?][0?][code?]', if: new SourceHasPath('code.coding.0.code'))]
    public mixed $code = '';

    #[Map(source: '[value?][valueCodeableConcept?][coding?][0?][system?]')]
    public mixed $valueCodeableConceptSystem;

    #[Map(source: '[value?][valueCodeableConcept?][coding?][0?][code?]')]
    public mixed $valueCodeableConceptCode;

    #[Map(source: '[value?][valueQuantity?][value?]')]
    public mixed $valueQuantityValue;

    #[Map(source: '[value?][valueQuantity?][comparator?]')]
    public mixed $valueQuantityComparator;

    #[Map(source: '[value?][valueQuantity?][unit?]')]
    public mixed $valueQuantityUnit;

    #[Map(source: '[value?][valueQuantity?][system?]')]
    public mixed $valueQuantitySystem;

    #[Map(source: '[value?][valueQuantity?][code?]')]
    public mixed $valueQuantityCode;

    #[Map(source: '[value?][valueRange?][low?][value?]')]
    public mixed $valueRangeLowValue;

    #[Map(source: '[value?][valueRange?][low?][unit?]')]
    public mixed $valueRangeLowUnit;

    #[Map(source: '[value?][valueRange?][low?][system?]')]
    public mixed $valueRangeLowSystem;

    #[Map(source: '[value?][valueRange?][low?][code?]')]
    public mixed $valueRangeLowCode;

    #[Map(source: '[value?][valueRange?][high?][value?]')]
    public mixed $valueRangeHighValue;

    #[Map(source: '[value?][valueRange?][high?][unit?]')]
    public mixed $valueRangeHighUnit;

    #[Map(source: '[value?][valueRange?][high?][system?]')]
    public mixed $valueRangeHighSystem;

    #[Map(source: '[value?][valueRange?][high?][code?]')]
    public mixed $valueRangeHighCode;

    #[Map(source: '[value?][valueBoolean?]')]
    public mixed $valueBoolean;

    #[Map(source: '[value?][valueInteger?]')]
    public mixed $valueInteger;

    #[Map(source: '[value?][valueString?]')]
    public mixed $valueString;
}
