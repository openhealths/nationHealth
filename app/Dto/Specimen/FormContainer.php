<?php

declare(strict_types=1);

namespace App\Dto\Specimen;

use App\Mapping\Conditions\SourceHasPath;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class FormContainer
{
    #[Map(source: '[identifier?]', if: new SourceHasPath('identifier'))]
    public mixed $identifier = '';

    #[Map(source: '[description?]', if: new SourceHasPath('description'))]
    public mixed $description = '';

    #[Map(source: '[type?][coding?][0?][code?]', if: new SourceHasPath('type.coding.0.code'))]
    public mixed $typeCode = '';

    #[Map(source: '[capacity?][value?]', if: new SourceHasPath('capacity.value'))]
    public mixed $capacityValue = '';

    #[Map(source: '[capacity?][code?]', if: new SourceHasPath('capacity.code'))]
    public mixed $capacityCode = '';

    #[Map(source: '[specimenQuantity?][value?]', if: new SourceHasPath('specimenQuantity.value'))]
    public mixed $specimenQuantityValue = '';

    #[Map(source: '[specimenQuantity?][code?]', if: new SourceHasPath('specimenQuantity.code'))]
    public mixed $specimenQuantityCode = '';

    #[Map(source: '[additiveCodeableConcept?][coding?][0?][code?]', if: new SourceHasPath('additiveCodeableConcept.coding.0.code'))]
    public mixed $additiveCode = '';
}
