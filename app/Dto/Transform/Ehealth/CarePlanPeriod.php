<?php

declare(strict_types=1);

namespace App\Dto\Transform\Ehealth;

use Carbon\CarbonImmutable;
use Symfony\Component\ObjectMapper\TransformCallableInterface;

/** Convert the local editor date and time fields to the Care Plan API period. */
class CarePlanPeriod implements TransformCallableInterface
{
    public function __invoke(mixed $value, object $source, ?object $target): array
    {
        $period = [];
        foreach (['start', 'end'] as $boundary) {
            $field = 'period' . ucfirst($boundary);
            $date = data_get($source, $field);
            if ($date === null || $date === '') {
                continue;
            }
            $time = data_get($source, $field . 'Time');
            $parsed = CarbonImmutable::createFromFormat('!' . config('app.date_format'), $date, config('app.timezone'))
                ->setTimeFromTimeString($time ?: ($boundary === 'end' ? '23:59:59' : '00:00:00'));
            $period[$boundary] = $parsed->utc()->format('Y-m-d\TH:i:s\Z');
        }

        return $period;
    }
}
