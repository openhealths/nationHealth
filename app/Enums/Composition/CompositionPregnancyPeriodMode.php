<?php

declare(strict_types=1);

namespace App\Enums\Composition;

use Carbon\CarbonImmutable;
use App\Exceptions\MedicalEvents\CompositionGuardException;

/**
 * Which set of pregnancy validity periods applies to a conclusion (TV 3.8.2.5.4).
 *
 * eHealth publishes the two sets under distinct composition configuration names, so the
 * mode has to be decided before the configuration is read — guessing by substring match
 * on the configuration name is how a continuation ends up validated against the rules
 * for a brand new conclusion.
 */
enum CompositionPregnancyPeriodMode: string
{
    /** A pregnancy conclusion that starts a new case. */
    case NEW = 'NEW';

    /** A continuation of an existing case (relatesTo.code = appends). */
    case APPENDED = 'APPENDED';

    /** A clarification that supersedes another conclusion (relatesTo.code = replaces). */
    case REPLACEMENT = 'REPLACEMENT';

    /**
     * Composition configuration name holding the allowed period lengths, in days.
     *
     * A replacement is issued instead of the conclusion it supersedes rather than after
     * it, so it is bound by the same periods as a new one.
     */
    public function configurationName(): string
    {
        return match ($this) {
            self::APPENDED => 'EMAL_VALIDATION_PREGNANCY_APPENDED_COMPOSITION_ALLOWED_PERIOD',
            self::NEW, self::REPLACEMENT => 'EMAL_VALIDATION_PREGNANCY_NEW_COMPOSITION_ALLOWED_PERIODS',
        };
    }

    /**
     * Decide the mode from the relation the conclusion carries.
     */
    public static function fromRelation(?string $relatesToCode, ?string $relatesToTargetUuid): self
    {
        if (blank($relatesToTargetUuid)) {
            return self::NEW;
        }

        return $relatesToCode === 'appends' ? self::APPENDED : self::REPLACEMENT;
    }

    public function allowedDays(array $configurations): array
    {
        $name = $this->configurationName();

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
            throw new CompositionGuardException(
                __('compositions.errors.pregnancy_periods_unavailable')
            );
        }

        return $days;
    }

    public function allowedEndDates(string $start, array $configurations): array
    {
        $from = CarbonImmutable::parse($start);

        $options = [];

        foreach ($this->allowedDays($configurations) as $days) {
            $options[$days] = $from->addDays($days - 1)->format(config('app.date_format'));
        }

        return $options;
    }

    public function assertPeriodAllowed(string $start, string $end, array $configurations): void
    {
        $allowed = $this->allowedEndDates($start, $configurations);
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
