<?php

declare(strict_types=1);

namespace App\Dto\MedicationRequest;

use App\Mapping\Transforms\FhirIdentifier;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** The medication request contract retains the empty identifier type text. */
final class EhealthReference
{
    #[Map(source: 'uuid', transform: new FhirIdentifier(includeText: true))]
    public array $identifier;
}
