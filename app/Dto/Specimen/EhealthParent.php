<?php

declare(strict_types=1);

namespace App\Dto\Specimen;

use App\Mapping\Transforms\FhirIdentifier;
use Illuminate\Support\Collection;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: Collection::class)]
final class EhealthParent
{
    #[Map(source: '[uuid]', transform: new FhirIdentifier('specimen', includeText: true))]
    public array $identifier;
}
