<?php

declare(strict_types=1);

namespace App\Dto\ServiceRequest;

use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(source: ServiceRequestRequest::class)]
class Form
{
    #[Map(source: '[note?]')]
    public ?string $note = null;

    #[Map(source: '[patientInstruction?]')]
    public ?string $patientInstruction = null;

    #[Map(source: '[priority?][text?]')]
    public ?string $priority = null;
}
