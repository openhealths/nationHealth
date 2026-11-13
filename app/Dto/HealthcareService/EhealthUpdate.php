<?php

declare(strict_types=1);

namespace App\Dto\HealthcareService;

use App\Dto\EhealthMapping;
use App\Livewire\Division\Forms\HealthcareServiceForm;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Healthcare service update payload in the format expected by the eHealth API: only the mutable fields.
 * The update is a PATCH, where an empty value clears the field, so all three fields are always sent.
 * Properties without #[Map] are taken from the form property with the same name.
 */
#[Map(source: HealthcareServiceForm::class)]
class EhealthUpdate
{
    use EhealthMapping {
        toArray as protected withoutEmptyValues;
    }

    public ?string $comment = null;

    public ?array $availableTime = null;

    #[Map(source: 'notAvailable', transform: [Model::class, 'formNotAvailable'])]
    public ?array $notAvailable = null;

    /**
     * Empty values inside the fields are dropped as in the create payload, then the emptied fields are sent explicitly.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->withoutEmptyValues() + ['comment' => null, 'available_time' => [], 'not_available' => []];
    }
}
