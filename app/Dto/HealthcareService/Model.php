<?php

declare(strict_types=1);

namespace App\Dto\HealthcareService;

use App\Classes\eHealth\Api\Responses\Collections\HealthcareServiceDetails;
use App\Contracts\Dto\Model as ModelContract;
use App\Core\Arr;
use App\Enums\HealthcareService\Status;
use App\Livewire\Division\Forms\HealthcareServiceForm;
use App\Models\HealthcareService;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;

/**
 * Healthcare service form or validated eHealth response mapped to model attributes.
 * HealthcareServiceDetails is used for mapping eHealth response data,
 * HealthcareServiceForm is used for mapping form data of a draft.
 * Properties without a mapping for the form keep their defaults, so non-nullable ones are mapped only from the response.
 */
#[Map(source: HealthcareServiceDetails::class)]
#[Map(source: HealthcareServiceForm::class)]
class Model implements ModelContract
{
    #[Map(source: '[uuid]', if: new SourceClass(HealthcareServiceDetails::class))]
    public ?string $uuid = null;

    #[Map(source: '[division_id]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(source: 'divisionId', if: new SourceClass(HealthcareServiceForm::class))]
    public ?string $divisionUuid = null;

    #[Map(source: '[speciality_type]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(source: 'specialityType', if: new SourceClass(HealthcareServiceForm::class))]
    public ?string $specialityType = null;

    #[Map(source: '[providing_condition]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(source: 'providingCondition', if: new SourceClass(HealthcareServiceForm::class))]
    public ?string $providingCondition = null;

    #[Map(source: '[license_id]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(source: 'licenseId', if: new SourceClass(HealthcareServiceForm::class))]
    public ?string $licenseId = null;

    #[Map(source: '[comment]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(source: 'comment', if: new SourceClass(HealthcareServiceForm::class))]
    public ?string $comment = null;

    #[Map(source: '[status]', if: new SourceClass(HealthcareServiceDetails::class))]
    public string $status = Status::DRAFT->value;

    #[Map(source: '[is_active]', if: new SourceClass(HealthcareServiceDetails::class))]
    public bool $isActive = false;

    #[Map(source: '[category]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(source: 'category', if: new SourceClass(HealthcareServiceForm::class))]
    public array $category = [];

    #[Map(source: '[type]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(source: 'type', if: new SourceClass(HealthcareServiceForm::class), transform: [self::class, 'typeOrNull'])]
    public ?array $type = null;

    #[Map(source: '[coverage_area]', if: new SourceClass(HealthcareServiceDetails::class))]
    public ?array $coverageArea = null;

    #[Map(source: '[available_time]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(source: 'availableTime', if: new SourceClass(HealthcareServiceForm::class), transform: [self::class, 'snakeCaseAvailableTime'])]
    public ?array $availableTime = null;

    #[Map(source: '[not_available]', if: new SourceClass(HealthcareServiceDetails::class))]
    #[Map(source: 'notAvailable', if: new SourceClass(HealthcareServiceForm::class), transform: [self::class, 'formNotAvailable'])]
    public ?array $notAvailable = null;

    #[Map(source: '[licensed_healthcare_service]', if: new SourceClass(HealthcareServiceDetails::class))]
    public ?array $licensedHealthcareService = null;

    #[Map(source: '[ehealth_inserted_at]', if: new SourceClass(HealthcareServiceDetails::class))]
    public ?string $ehealthInsertedAt = null;

    #[Map(source: '[ehealth_inserted_by]', if: new SourceClass(HealthcareServiceDetails::class))]
    public ?string $ehealthInsertedBy = null;

    #[Map(source: '[ehealth_updated_at]', if: new SourceClass(HealthcareServiceDetails::class))]
    public ?string $ehealthUpdatedAt = null;

    #[Map(source: '[ehealth_updated_by]', if: new SourceClass(HealthcareServiceDetails::class))]
    public ?string $ehealthUpdatedBy = null;

    /**
     * The form keeps an empty coding structure when no type is selected.
     *
     * @param  array|null  $type
     * @return array|null
     */
    public static function typeOrNull(?array $type): ?array
    {
        return empty($type['coding'][0]['code']) ? null : $type;
    }

    /**
     * Available time is stored with snake_case keys, the same as in the eHealth response.
     *
     * @param  array|null  $availableTime
     * @return array|null
     */
    public static function snakeCaseAvailableTime(?array $availableTime): ?array
    {
        return $availableTime === null ? null : Arr::toSnakeCase($availableTime);
    }

    /**
     * Builds each period from the validated form inputs only, converting date and time into ISO 8601 start and end.
     * Keys added by the frontend (e.g. frontendId) are left out.
     *
     * @param  array|null  $notAvailable
     * @return array
     */
    public static function formNotAvailable(?array $notAvailable): array
    {
        return collect($notAvailable)
            ->map(static fn (array $item): array => [
                'description' => $item['description'],
                'during' => [
                    'start' => convertToEHealthISO8601("{$item['during']['startDate']} {$item['during']['startTime']}"),
                    'end' => convertToEHealthISO8601("{$item['during']['endDate']} {$item['during']['endTime']}")
                ]
            ])
            ->values()
            ->all();
    }

    /**
     * Healthcare service attributes without category and type, which are stored as codeable concepts,
     * and without the division uuid, since the model links the division by its id.
     *
     * @param  HealthcareService|null  $healthcareService
     * @return HealthcareService
     */
    public function toModel(?HealthcareService $healthcareService = null): HealthcareService
    {
        $healthcareService ??= new HealthcareService();

        foreach (Arr::except(get_object_vars($this), ['category', 'type', 'divisionUuid']) as $key => $value) {
            $healthcareService->setAttribute($key, $value);
        }

        return $healthcareService;
    }
}
