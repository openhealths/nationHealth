<?php

declare(strict_types=1);

namespace App\Dto\HealthcareService;

use App\Dto\EhealthMapping;
use App\Livewire\Division\Forms\HealthcareServiceForm;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Healthcare service payload in the format expected by the eHealth API.
 * Properties without #[Map] are taken from the form property with the same name.
 */
#[Map(source: HealthcareServiceForm::class)]
class Ehealth
{
    use EhealthMapping;

    public ?string $divisionId = null;

    public array $category = [];

    public ?string $specialityType = null;

    public ?string $providingCondition = null;

    #[Map(source: 'type', transform: [Model::class, 'typeOrNull'])]
    public ?array $type = null;

    public ?string $licenseId = null;

    public ?string $comment = null;

    public ?array $availableTime = null;

    #[Map(source: 'notAvailable', transform: [Model::class, 'formNotAvailable'])]
    public ?array $notAvailable = null;

    /**
     * Maps the form into the eHealth payload DTO.
     * The strict property accessor is kept: the form declares every field, so a wrong source name fails loudly.
     *
     * @param  HealthcareServiceForm  $form
     * @return self
     */
    public static function fromForm(HealthcareServiceForm $form): self
    {
        return new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessor())->map($form, self::class);
    }
}
