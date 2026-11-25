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

    #[Map(source: 'categoryCode', transform: [self::class, 'category'])]
    public CodeableConceptEhealth $category;

    public ?string $specialityType = null;

    public ?string $providingCondition = null;

    #[Map(source: 'typeCode', transform: [self::class, 'type'])]
    public ?CodeableConceptEhealth $type = null;

    public ?string $licenseId = null;

    public ?string $comment = null;

    #[Map(source: 'availableTime', transform: [Availability::class, 'availableTime'])]
    public ?array $availableTime = null;

    #[Map(source: 'notAvailable', transform: [Availability::class, 'notAvailable'])]
    public ?array $notAvailable = null;

    /**
     * Category code from the form as a codeable concept of the healthcare service categories dictionary.
     *
     * @param  string  $categoryCode
     * @return CodeableConceptEhealth|null
     */
    public static function category(string $categoryCode): ?CodeableConceptEhealth
    {
        return CodeableConceptEhealth::map(['coding' => [['system' => 'HEALTHCARE_SERVICE_CATEGORIES', 'code' => $categoryCode]]]);
    }

    /**
     * Type code from the form as a codeable concept of the types dictionary of the chosen category, null when no type is chosen.
     * The category is read from the form, since a transform receives the source along with the mapped value.
     *
     * @param  string|null  $typeCode
     * @param  HealthcareServiceForm  $form
     * @return CodeableConceptEhealth|null
     */
    public static function type(?string $typeCode, HealthcareServiceForm $form): ?CodeableConceptEhealth
    {
        return CodeableConceptEhealth::map([
            'coding' => [['system' => "HEALTHCARE_SERVICE_{$form->categoryCode}_TYPES", 'code' => $typeCode]]
        ]);
    }
}
