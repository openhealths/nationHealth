<?php

declare(strict_types=1);

namespace App\Dto\ServiceRequest;

use App\Livewire\ServiceRequestRequest\Forms\ServiceRequestRequestForm;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;

#[Map(source: ServiceRequestRequestForm::class, target: ServiceRequestRequest::class)]
class Model
{
    #[Map(target: 'note')]
    public ?string $note = null;

    #[Map(target: 'patientInstruction')]
    public ?string $patientInstruction = null;

    // The repository associates the priority relation using the code from the form.
    #[Map(if: new SourceClass(ServiceRequestRequestForm::class))]
    public string $priority = 'routine';
}
