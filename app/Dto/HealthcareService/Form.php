<?php

declare(strict_types=1);

namespace App\Dto\HealthcareService;

use App\Core\Arr;
use App\Dto\Transform\LocalDateTime;
use App\Models\HealthcareService;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use Symfony\Component\ObjectMapper\Attribute\Map;

/**
 * Properties without #[Map] are taken from the model attribute with the same name.
 * The division is not mapped: the form holds its uuid, while the model holds its id.
 */
#[Map(source: HealthcareService::class)]
class Form
{
    #[Map(source: '[category]', transform: [self::class, 'code'])]
    public string $categoryCode;

    public ?string $specialityType = null;

    public ?string $providingCondition = null;

    #[Map(source: '[type]', transform: [self::class, 'code'])]
    public ?string $typeCode = null;

    public ?string $licenseId = null;

    public ?string $comment = null;

    #[Map(source: '[availableTime]', transform: [self::class, 'camelCaseAvailableTime'])]
    public ?array $availableTime = null;

    #[Map(source: '[notAvailable]', transform: [self::class, 'notAvailable'])]
    public ?array $notAvailable = null;

    /**
     * Code the stored codeable concept holds, null when the service has none.
     *
     * @param  CodeableConcept|null  $codeableConcept
     * @return string|null
     */
    public static function code(?CodeableConcept $codeableConcept): ?string
    {
        return $codeableConcept?->coding->first()?->code;
    }

    /**
     * Available time is stored with snake_case keys, while the form fields use camelCase.
     *
     * @param  array|null  $availableTime
     * @return array|null
     */
    public static function camelCaseAvailableTime(?array $availableTime): ?array
    {
        return $availableTime === null ? null : Arr::toCamelCase($availableTime);
    }

    /**
     * Periods the service is not available, stored in UTC, as the dates and times the form shows in the application timezone.
     *
     * @param  array|null  $notAvailable
     * @return array|null
     */
    public static function notAvailable(?array $notAvailable): ?array
    {
        if ($notAvailable === null) {
            return null;
        }

        return array_map(static function (array $item): array {
            $start = LocalDateTime::toLocal($item['during']['start']);
            $end = LocalDateTime::toLocal($item['during']['end']);

            return [
                ...$item,
                'during' => [
                    'startDate' => $start['date'],
                    'startTime' => $start['time'],
                    'endDate' => $end['date'],
                    'endTime' => $end['time']
                ]
            ];
        }, $notAvailable);
    }
}
