<?php

declare(strict_types=1);

namespace App\Dto\Transform;

use Carbon\CarbonImmutable;

/**
 * Date and time the user enters in the application timezone.
 * eHealth and the database keep the moment in UTC, so this is the one place the form values are converted, both ways.
 */

class LocalDateTime
{
    /**
     * Date and time entered in the application timezone as an ISO 8601 date-time in UTC.
     *
     * @param  string  $date
     * @param  string  $time
     * @return string
     */
    public static function toUtc(string $date, string $time): string
    {
        return CarbonImmutable::parse("$date $time", config('app.timezone'))->utc()->toIso8601ZuluString();
    }

    /**
     * Moment stored in UTC as the date and time the form shows in the application timezone.
     *
     * @param  string  $utc
     * @return array{date: string, time: string}
     */
    public static function toLocal(string $utc): array
    {
        $moment = CarbonImmutable::parse($utc)->setTimezone(config('app.timezone'));

        return [
            'date' => $moment->format(config('app.date_format')),
            'time' => $moment->format('H:i')
        ];
    }
}
