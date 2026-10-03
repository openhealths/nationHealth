<?php

declare(strict_types=1);

namespace App\Mapping\EHealth\Shared;

use App\Mapping\Transforms\FhirIdentifier;
use Symfony\Component\ObjectMapper\Attribute\Map;

final class EHealthReference
{
    #[Map(source: 'uuid', transform: FhirIdentifier::class)]
    public array $identifier;
}
