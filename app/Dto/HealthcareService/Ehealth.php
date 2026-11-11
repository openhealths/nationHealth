<?php

declare(strict_types=1);

namespace App\Dto\HealthcareService;

use App\Dto\CodeableConcept\Ehealth as CodeableConceptEhealth;
use App\Dto\EhealthMapping;
use App\Dto\Transform\Ehealth\Availability;
use App\Livewire\Division\Forms\HealthcareServiceForm;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Healthcare service payload in the format expected by the eHealth API.
 * Properties without #[Map] are taken from the form property with the same name.
 */
#[Map(source: HealthcareServiceForm::class)]
class Ehealth
{
    use EhealthMapping;

    public ?string $divisionId = null;

    #[Map(source: 'category', transform: [CodeableConceptEhealth::class, 'map'])]
    public CodeableConceptEhealth $category;

    public ?string $specialityType = null;

    public ?string $providingCondition = null;

    #[Map(source: 'type', transform: [CodeableConceptEhealth::class, 'map'])]
    public ?CodeableConceptEhealth $type = null;

    public ?string $licenseId = null;

    public ?string $comment = null;

    #[Map(source: 'availableTime', transform: [Availability::class, 'availableTime'])]
    public ?array $availableTime = null;

    #[Map(source: 'notAvailable', transform: [Availability::class, 'notAvailable'])]
    public ?array $notAvailable = null;
}
