<?php

declare(strict_types=1);

namespace App\Dto\Shared;

use App\Mapping\Transforms\FhirIdentifier;
use Symfony\Component\ObjectMapper\Attribute\Map;

final class EhealthReference
{
    #[Map(source: 'uuid', transform: FhirIdentifier::class)]
    public array $identifier;
}
