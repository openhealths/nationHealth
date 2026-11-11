<?php

declare(strict_types=1);

namespace App\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

readonly class AfterOrEqualDateTime implements ValidationRule
{
    /**
     * @param  string  $endDate  The date portion of the end datetime (d.m.Y).
     * @param  string  $startDate  The date portion of the start datetime (d.m.Y).
     * @param  string  $startTime  The time portion of the start datetime (H:i).
     * @param  string  $startAttributeKey  Translation key under validation.attributes for the error message.
     */
    public function __construct(
        private string $endDate,
        private string $startDate,
        private string $startTime,
        private string $startAttributeKey = 'effective_period_start'
    ) {
    }

    /**
     * Run the validation rule.
     *
     * @param  string  $attribute
     * @param  mixed  $value  The end time (H:i).
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($this->endDate) || empty($this->startDate) || empty($this->startTime) || empty($value)) {
            return;
        }

        $end = CarbonImmutable::createFromFormat(config('app.date_format') . ' H:i', $this->endDate . ' ' . $value);
        $start = CarbonImmutable::createFromFormat(config('app.date_format') . ' H:i', $this->startDate . ' ' . $this->startTime);

        if ($end->lessThan($start)) {
            // The date part of the compared moments may differ while the field holds only the time, so both are named
            $fail(__('validation.after_or_equal_date_time', [
                'date' => __('validation.attributes.' . $this->startAttributeKey),
                'value' => $this->endDate . ' ' . $value,
                'start' => $this->startDate . ' ' . $this->startTime
            ]));
        }
    }
}
