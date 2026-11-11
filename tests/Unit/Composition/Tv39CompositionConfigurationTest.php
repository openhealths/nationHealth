<?php

declare(strict_types=1);

namespace Tests\Unit\Composition;

use App\Enums\Composition\CompositionConfiguration;
use App\Exceptions\MedicalEvents\CompositionGuardException;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Composition\UsesTv39CompositionConfiguration;
use Tests\TestCase;

class Tv39CompositionConfigurationTest extends TestCase
{
    use UsesTv39CompositionConfiguration;

    #[DataProvider('invalidSettings')]
    public function test_invalid_configuration_is_refused(mixed $settings): void
    {
        config(['compositions.emal' => $settings]);
        $this->expectException(CompositionGuardException::class);
        CompositionConfiguration::CANCEL_DAYS->days();
    }

    public static function invalidSettings(): array
    {
        return [[null], ['{'], ['[]'], [[]], [true], [['EMAL_FILTER_CANCEL_TIMEOUT_DISABILITY' => 7]]];
    }

    #[DataProvider('invalidDays')]
    public function test_day_limits_require_nonnegative_integers(mixed $days): void
    {
        $settings = config('compositions.emal');
        $settings[CompositionConfiguration::CANCEL_DAYS->value] = $days;
        config(['compositions.emal' => $settings]);
        $this->expectException(CompositionGuardException::class);
        CompositionConfiguration::CANCEL_DAYS->days();
    }

    public static function invalidDays(): array
    {
        return [[-1], [1.5], ['7'], [true], [null], [[]]];
    }

    public function test_json_mirror_preserves_integer_day_units(): void
    {
        config(['compositions.emal' => json_encode(config('compositions.emal'))]);
        $this->assertSame(7, CompositionConfiguration::CANCEL_DAYS->days());
        $this->assertSame(14, CompositionConfiguration::PREGNANCY_APPENDED->days());
    }

    public function test_missing_category_never_uses_another_category_value(): void
    {
        $this->expectException(CompositionGuardException::class);
        CompositionConfiguration::ENCOUNTER_AGE->days('UNKNOWN');
    }

    public function test_last_configured_calendar_day_is_allowed_and_next_day_is_refused(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11T20:59:59Z'));
        CompositionConfiguration::assertCancellationAllowed('2026-11-04T00:00:01Z');
        $this->assertTrue(true);
        $this->expectException(CompositionGuardException::class);
        CompositionConfiguration::assertCancellationAllowed('2026-11-03T00:00:00Z');
    }

    public function test_replacement_reason_uses_its_own_timeout(): void
    {
        $settings = config('compositions.emal');
        $settings[CompositionConfiguration::REPLACE_ENABLED->value] = true;
        config(['compositions.emal' => $settings]);
        $this->travelTo(CarbonImmutable::parse('2026-11-11T12:00:00Z'));
        CompositionConfiguration::assertCancellationAllowed('2026-10-01T09:00:00Z', 'INCORRECT_PATIENT');
        $this->assertTrue(true);
        $this->expectException(CompositionGuardException::class);
        CompositionConfiguration::assertCancellationAllowed('2026-10-01T09:00:00Z', 'INCORRECT_OTHER');
    }

    public function test_disabled_timeout_does_not_apply_a_day_limit(): void
    {
        $settings = config('compositions.emal');
        $settings[CompositionConfiguration::CANCEL_ENABLED->value] = false;
        config(['compositions.emal' => $settings]);
        CompositionConfiguration::assertCancellationAllowed('2020-01-01');
        $this->assertTrue(true);
    }

    public function test_invalid_date_is_a_translated_guard_failure(): void
    {
        $this->expectException(CompositionGuardException::class);
        $this->expectExceptionMessage(__('compositions.errors.configuration_unavailable'));
        CompositionConfiguration::assertCancellationAllowed('not-a-date');
    }

    public function test_boolean_switch_cannot_be_a_string(): void
    {
        $settings = config('compositions.emal');
        $settings[CompositionConfiguration::CANCEL_ENABLED->value] = 'false';
        config(['compositions.emal' => $settings]);
        $this->expectException(CompositionGuardException::class);
        CompositionConfiguration::CANCEL_ENABLED->enabled();
    }
}
