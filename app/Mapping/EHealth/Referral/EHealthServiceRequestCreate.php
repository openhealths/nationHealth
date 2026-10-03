<?php

declare(strict_types=1);

namespace App\Mapping\EHealth\Referral;

use App\Mapping\Transforms\FhirReference;
use Symfony\Component\ObjectMapper\Attribute\Map;

final class EHealthServiceRequestCreate extends EHealthServiceRequestBody
{
    #[Map(source: 'uuid')]
    public string $id;

    #[Map(source: 'programId', transform: new FhirReference('medical_program'))]
    public ?array $program = null;
}
