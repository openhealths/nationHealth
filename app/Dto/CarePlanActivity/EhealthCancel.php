<?php

declare(strict_types=1);

namespace App\Dto\CarePlanActivity;

use App\Livewire\CarePlan\CarePlanComponent;
use App\Mapping\Transforms\FhirCodeableConcept;
use Symfony\Component\ObjectMapper\Attribute\Map;

/** Cancel signs the complete remote snapshot, changing only detail.status_reason. */
#[Map(source: CarePlanComponent::class)]
final class EhealthCancel
{
    #[Map(source: 'statusReason', transform: new FhirCodeableConcept('eHealth/care_plan_activity_cancel_reasons'))]
    public array $statusReason;

    public function __construct(#[Map(if: false)] private readonly array $snapshot)
    {
    }

    public function toArray(): array
    {
        $payload = $this->snapshot;
        if (!is_array($payload['detail'] ?? null)) {
            $payload['detail'] = [];
        }
        $payload['detail']['status_reason'] = $this->statusReason;

        return $payload;
    }
}
