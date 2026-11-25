<?php

declare(strict_types=1);

namespace App\Dto\HealthcareService;

use App\Classes\eHealth\Api\Responses\Collections\HealthcareServiceDetails;
use App\Dto\CodeableConcept\Model as CodeableConceptModel;
use App\Dto\Transform\Ehealth\Availability;
use App\Enums\HealthcareService\Status;
use App\Livewire\Division\Forms\HealthcareServiceForm;
use App\Models\HealthcareService;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\Condition\TargetClass;

/**
 * Healthcare service form or validated eHealth response mapped to model attributes, which are then mapped to the healthcare service model.
 * HealthcareServiceDetails is used for mapping eHealth response data,
 * HealthcareServiceForm is used for mapping form data of a draft.
 * Properties without a mapping for the form keep their defaults, so non-nullable ones are mapped only from the response.
 * Every property stored on the healthcare service has a #[Map] for the model, since Eloquent attributes are not declared properties
 * and the mapper copies a property without #[Map] only to a declared property of the same name.
 * Category and type have none, since they are stored as codeable concepts, and neither has the division uuid,
 * since the model links the division by its id.
 */
#[Map(source: HealthcareServiceDetails::class)]
#[Map(source: HealthcareServiceForm::class)]
#[Map(target: HealthcareService::class)]
class Model
{
    #[Map(source: '[id]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(if: new TargetClass(HealthcareService::class))]
    public ?string $uuid = null;

    #[Map(source: '[division_id]', if: new SourceClass(HealthcareServiceDetails::class))]
    public ?string $divisionUuid = null;

    #[Map(source: '[speciality_type]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(source: 'specialityType', if: new SourceClass(HealthcareServiceForm::class))]
    #[Map(if: new TargetClass(HealthcareService::class))]
    public ?string $specialityType = null;

    #[Map(source: '[providing_condition]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(source: 'providingCondition', if: new SourceClass(HealthcareServiceForm::class))]
    #[Map(if: new TargetClass(HealthcareService::class))]
    public ?string $providingCondition = null;

    #[Map(source: '[license_id]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(source: 'licenseId', if: new SourceClass(HealthcareServiceForm::class))]
    #[Map(if: new TargetClass(HealthcareService::class))]
    public ?string $licenseId = null;

    #[Map(source: '[comment]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(source: 'comment', if: new SourceClass(HealthcareServiceForm::class))]
    #[Map(if: new TargetClass(HealthcareService::class))]
    public ?string $comment = null;

    #[Map(source: '[status]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(if: new TargetClass(HealthcareService::class))]
    public string $status = Status::DRAFT->value;

    #[Map(source: '[is_active]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(if: new TargetClass(HealthcareService::class))]
    public bool $isActive = false;

    #[Map(source: '[category]', if: new SourceClass(HealthcareServiceDetails::class), transform: [CodeableConceptModel::class, 'map'])]
    #[Map(source: 'categoryCode', if: new SourceClass(HealthcareServiceForm::class), transform: [self::class, 'category'])]
    public CodeableConceptModel $category;

    #[Map(source: '[type]', if: new SourceClass(HealthcareServiceDetails::class), transform: [CodeableConceptModel::class, 'map'])]
    #[Map(source: 'typeCode', if: new SourceClass(HealthcareServiceForm::class), transform: [self::class, 'type'])]
    public ?CodeableConceptModel $type = null;

    #[Map(source: '[coverage_area]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(if: new TargetClass(HealthcareService::class))]
    public ?array $coverageArea = null;

    #[Map(source: '[available_time]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(source: 'availableTime', if: new SourceClass(HealthcareServiceForm::class), transform: [Availability::class, 'availableTime'])]
    #[Map(if: new TargetClass(HealthcareService::class))]
    public ?array $availableTime = null;

    #[Map(source: '[not_available]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(source: 'notAvailable', if: new SourceClass(HealthcareServiceForm::class), transform: [Availability::class, 'notAvailable'])]
    #[Map(if: new TargetClass(HealthcareService::class))]
    public ?array $notAvailable = null;

    #[Map(source: '[licensed_healthcare_service]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(if: new TargetClass(HealthcareService::class))]
    public ?array $licensedHealthcareService = null;

    #[Map(source: '[inserted_at]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(if: new TargetClass(HealthcareService::class))]
    public ?string $ehealthInsertedAt = null;

    #[Map(source: '[inserted_by]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(if: new TargetClass(HealthcareService::class))]
    public ?string $ehealthInsertedBy = null;

    #[Map(source: '[updated_at]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(if: new TargetClass(HealthcareService::class))]
    public ?string $ehealthUpdatedAt = null;

    #[Map(source: '[updated_by]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(if: new TargetClass(HealthcareService::class))]
    public ?string $ehealthUpdatedBy = null;

    /**
     * Category code from the form as a codeable concept of the healthcare service categories dictionary.
     *
     * @param  string  $categoryCode
     * @return CodeableConceptModel|null
     */
    public static function category(string $categoryCode): ?CodeableConceptModel
    {
        return CodeableConceptModel::map(['coding' => [['system' => 'HEALTHCARE_SERVICE_CATEGORIES', 'code' => $categoryCode]]]);
    }

    /**
     * Type code from the form as a codeable concept of the types dictionary of the chosen category, null when no type is chosen.
     * The category is read from the form, since a transform receives the source along with the mapped value.
     *
     * @param  string|null  $typeCode
     * @param  HealthcareServiceForm  $form
     * @return CodeableConceptModel|null
     */
    public static function type(?string $typeCode, HealthcareServiceForm $form): ?CodeableConceptModel
    {
        return CodeableConceptModel::map([
            'coding' => [['system' => "HEALTHCARE_SERVICE_{$form->categoryCode}_TYPES", 'code' => $typeCode]]
        ]);
    }
}
