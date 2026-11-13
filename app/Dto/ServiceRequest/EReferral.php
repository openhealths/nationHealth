<?php

declare(strict_types=1);

namespace App\Dto\ServiceRequest;

use App\Livewire\Referral\Forms\EReferralForm;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** Editable electronic referral fields for a local draft; this is not an eHealth API payload. */
#[Map(source: EReferralForm::class)]
class EReferral
{
    public ?string $note = null;

    public ?string $patientInstruction = null;

    public string $priority = 'routine';

    /**
     * Preserve the server-owned FHIR graph while changing only the fields this form owns.
     * Reference.identifier.type.coding[], CodeableConcept.coding[], Quantity metadata,
     * basedOn/supportingInfo/reasonReference collections and occurrence choice stay intact.
     * This is the local camelCase resource; the existing API client owns the wire contract.
     *
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    public function toResource(array $resource): array
    {
        return array_replace($resource, [
            'note' => $this->note,
            'patientInstruction' => $this->patientInstruction,
            'priority' => $this->priority,
        ]);
    }
}
