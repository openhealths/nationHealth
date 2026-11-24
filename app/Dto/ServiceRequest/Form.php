<?php

declare(strict_types=1);

namespace App\Dto\ServiceRequest;

use App\Livewire\ServiceRequestRequest\Forms\ServiceRequestRequestForm;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\IsNotNull;

#[Map(source: ServiceRequestRequest::class, target: ServiceRequestRequestForm::class)]
class Form
{
    public ?string $note = null;

    public ?string $patientInstruction = null;

    #[Map(source: '[priority?][text?]', if: new IsNotNull())]
    public string $priority = 'routine';
}
