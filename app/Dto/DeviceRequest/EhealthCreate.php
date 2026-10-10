<?php

declare(strict_types=1);

namespace App\Dto\DeviceRequest;

use App\Mapping\Transforms\FhirReference;
use Symfony\Component\ObjectMapper\Attribute\Map;

final class EhealthCreate extends Ehealth
{
    #[Map(source: 'uuid')]
    public string $id;

    #[Map(if: false)]
    public string $status = 'active';

    #[Map(source: 'program_id', transform: new FhirReference('medical_program'))]
    public ?array $program = null;
}
