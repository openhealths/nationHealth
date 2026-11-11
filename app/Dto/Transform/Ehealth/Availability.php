<?php

declare(strict_types=1);

namespace App\Dto\Transform\Ehealth;

/**
 * Healthcare service availability transforms for #[Map], referenced as [Availability::class, 'method'].
 * They convert the form values into the format of the eHealth API, which is also the format they are stored in.
 */
class Availability
{
    /**
     * Builds each weekly schedule item from the form inputs, renaming the camelCase keys to snake_case.
     *
     * @param  array|null  $availableTime
     * @return array|null
     */
    public static function availableTime(?array $availableTime): ?array
    {
        if ($availableTime === null) {
            return null;
        }

        return collect($availableTime)
            ->map(static fn (array $item): array => [
                'days_of_week' => $item['daysOfWeek'],
                'all_day' => $item['allDay'],
                'available_start_time' => $item['availableStartTime'] ?? null,
                'available_end_time' => $item['availableEndTime'] ?? null
            ])
            ->values()
            ->all();
    }

    /**
     * Builds each period from the validated form inputs only, converting date and time into ISO 8601 start and end.
     * Keys added by the frontend (e.g. frontendId) are left out.
     *
     * @param  array|null  $notAvailable
     * @return array
     */
    public static function notAvailable(?array $notAvailable): array
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
}
