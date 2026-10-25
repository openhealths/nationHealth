<?php

declare(strict_types=1);

namespace App\Dto\DeviceRequest;

use App\Mapping\Transforms\FhirCodeableConcept;
use App\Livewire\DeviceRequest\DeviceRequestForm;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** The standalone /api/device_requests contract differs from the patient signed-create contract. */
#[Map(source: DeviceRequestForm::class)]
final class EhealthDraft
{
    #[Map(source: 'patientId')]
    public string $person_id;

    #[Map(source: 'medicalProgram')]
    public string $program;

    #[Map(source: 'deviceType', transform: new FhirCodeableConcept('eHealth/SNOMED'))]
    public array $code;

    #[Map(source: 'quantity', transform: 'intval')]
    public int $quantity;

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
