<?php

declare(strict_types=1);

namespace App\Livewire\Composition;

use App\Classes\eHealth\EHealth;
use App\Enums\Composition\CompositionCategory;
use App\Enums\Composition\CompositionPregnancyPeriodMode;
use App\Enums\Composition\CompositionType;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\MedicalEvents\CompositionGuardException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Validity periods a pregnancy conclusion may be issued for (TV 3.8.2.5.4).
 *
 * The allowed lengths are not a MIS decision: eHealth publishes them as composition
 * configurations and rejects anything else. This is therefore the only place that
 * answers "how long may this conclusion last", and it fails closed — an unreachable
 * or empty configuration blocks the conclusion instead of silently falling back to a
 * free-text date, which would let the doctor build a payload eHealth is guaranteed
 * to refuse (and, worse, look like MIS approved it).
 */
class PregnancyPeriods
{
    /**
     * Lengths, in days, the given mode allows.
     *
     * @return list<int> Sorted ascending, never empty.
     * @throws CompositionGuardException when the configuration cannot be established.
     */
    public function allowedDays(CompositionPregnancyPeriodMode $mode): array
    {
        $name = $mode->configurationName();

        try {
            $configurations = EHealth::configuration()->getCompositions([
                'type' => CompositionType::TEMP_DISABILITY->value,
                'category' => CompositionCategory::PREGNANCY->value,
                'is_active' => true,
            ])->getData();
        } catch (EHealthConnectionException | EHealthException $exception) {
            Log::error('Failed to load pregnancy period configuration', [
                'configuration' => $name,
                'error' => $exception->getMessage(),
            ]);

            throw new CompositionGuardException(
                __('compositions.errors.pregnancy_periods_unavailable')
            );
        }

        // Exact name match only. Substring matching cannot distinguish the two
        // configurations reliably, and a wrong match silently widens the allowed set.
        $days = collect($configurations)
            ->filter(static fn (mixed $row): bool => is_array($row) && data_get($row, 'name') === $name)
            ->pluck('value')
            ->flatten()
            ->filter(static fn (mixed $value): bool => is_numeric($value) && (int) $value > 0)
            ->map(static fn (mixed $value): int => (int) $value)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($days === []) {
            Log::error('Pregnancy period configuration is missing or empty', ['configuration' => $name]);

            throw new CompositionGuardException(
                __('compositions.errors.pregnancy_periods_unavailable')
            );
        }

        return $days;
    }

    /**
     * End dates offered for a period starting on the given day, keyed by length in days.
     *
     * The start day itself counts towards the length, hence `addDays($count - 1)`.
     *
     * @param  string  $start  Any parsable date.
     * @return array<int, string> Formatted with the application date format.
     * @throws CompositionGuardException
     */
    public function allowedEndDates(string $start, CompositionPregnancyPeriodMode $mode): array
    {
        $from = CarbonImmutable::parse($start);

        $options = [];

        foreach ($this->allowedDays($mode) as $days) {
            $options[$days] = $from->addDays($days - 1)->format(config('app.date_format'));
        }

        return $options;
    }

    /**
     * Reject a period whose end is not one of the configured options.
     *
     * @param  string  $start  Any parsable date.
     * @param  string  $end  Any parsable date.
     * @throws CompositionGuardException
     */
    public function assertPeriodAllowed(string $start, string $end, CompositionPregnancyPeriodMode $mode): void
    {
        $allowed = $this->allowedEndDates($start, $mode);
        $candidate = CarbonImmutable::parse($end)->format(config('app.date_format'));

        if (!in_array($candidate, $allowed, true)) {
            throw new CompositionGuardException(
                __('compositions.errors.pregnancy_period_not_allowed', [
                    'periods' => implode(', ', $allowed),
                ])
            );
        }
    }
}
