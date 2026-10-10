<?php

declare(strict_types=1);

namespace App\Dto\HealthcareService;

use App\Classes\eHealth\Api\Responses\HealthcareServiceResponse;
use App\Contracts\Dto\Model as ModelContract;
use App\Core\Arr;
use App\Enums\HealthcareService\Status;
use App\Livewire\Division\Forms\HealthcareServiceForm;
use App\Models\HealthcareService;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Condition\SourceClass;
use Symfony\Component\ObjectMapper\ObjectMapper;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Healthcare service form or validated eHealth response mapped to model attributes.
 * HealthcareServiceResponse is used for mapping eHealth response data,
 * HealthcareServiceForm is used for mapping form data of a draft.
 * Properties without #[Map] are taken from the source property with the same name;
 * the lenient property accessor reads null for those missing in the source,
 * so non-nullable ones that the form lacks are mapped only from the response.
 */
#[Map(source: HealthcareServiceResponse::class)]
#[Map(source: HealthcareServiceForm::class)]
class Model implements ModelContract
{
    public ?string $uuid = null;

    public ?string $specialityType = null;

    public ?string $providingCondition = null;

    public ?string $licenseId = null;

    public ?string $comment = null;

    #[Map(source: 'status', if: new SourceClass(HealthcareServiceResponse::class))]
    public string $status = Status::DRAFT->value;

    #[Map(source: 'isActive', if: new SourceClass(HealthcareServiceResponse::class))]
    public bool $isActive = false;

    public array $category = [];

    #[Map(source: 'type', transform: [self::class, 'typeOrNull'])]
    public ?array $type = null;

    public ?array $coverageArea = null;

    #[Map(source: 'availableTime', transform: [self::class, 'snakeCaseAvailableTime'])]
    public ?array $availableTime = null;

    #[Map(source: 'notAvailable', if: new SourceClass(HealthcareServiceResponse::class))]
    #[Map(source: 'notAvailable', if: new SourceClass(HealthcareServiceForm::class), transform: [self::class, 'formNotAvailable'])]
    public ?array $notAvailable = null;

    public ?array $licensedHealthcareService = null;

    public ?string $ehealthInsertedAt = null;

    public ?string $ehealthInsertedBy = null;

    public ?string $ehealthUpdatedAt = null;

    public ?string $ehealthUpdatedBy = null;

    /**
     * Maps the validated eHealth response or the form into this DTO.
     * The lenient property accessor reads null for optional fields the response may omit.
     *
     * @param  HealthcareServiceResponse|HealthcareServiceForm  $source
     * @return self
     */
    public static function fromSource(HealthcareServiceResponse|HealthcareServiceForm $source): self
    {
        return new ObjectMapper(propertyAccessor: PropertyAccess::createPropertyAccessorBuilder()
            ->disableExceptionOnInvalidPropertyPath()
            ->getPropertyAccessor())
            ->map($source, self::class);
    }

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
     * Healthcare service attributes without category and type, which are stored as codeable concepts.
     *
     * @param  HealthcareService|null  $healthcareService
     * @return HealthcareService
     */
    public function toModel(?HealthcareService $healthcareService = null): HealthcareService
    {
        $healthcareService ??= new HealthcareService();

        foreach (Arr::except(get_object_vars($this), ['category', 'type']) as $key => $value) {
            $healthcareService->setAttribute($key, $value);
        }

        return $healthcareService;
    }
}
