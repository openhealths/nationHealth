<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

/**
 * Composition (МВН / МВТН) status.
 *
 * Codes are upper-case, matching the live COMPOSITION_STATUS dictionary. Note that the
 * SwaggerHub search filter spells the error state `ENTERED-IN-ERROR` with hyphens while
 * every other source uses underscores; {@see fromEHealth()} accepts both.
 *
 * @see https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18024529953
 */
enum CompositionStatus: string
{
    use EnumUtils;

    /** Чернетка — created but not yet signed. */
    case PRELIMINARY = 'PRELIMINARY';

    /** Підписаний — signed with the author's electronic signature. */
    case FINAL = 'FINAL';

    /** Виправлений — superseded by a later conclusion in the same chain. */
    case AMENDED = 'AMENDED';

    /** Введений помилково. */
    case ENTERED_IN_ERROR = 'ENTERED_IN_ERROR';

    public function label(): string
    {
        return match ($this) {
            self::PRELIMINARY => __('compositions.status.preliminary'),
            self::FINAL => __('compositions.status.final'),
            self::AMENDED => __('compositions.status.amended'),
            self::ENTERED_IN_ERROR => __('compositions.status.entered_in_error'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FINAL => 'badge-green',
            self::AMENDED => 'badge-yellow',
            self::PRELIMINARY => 'badge-dark',
            self::ENTERED_IN_ERROR => 'badge-red',
        };
    }

    /**
     * Resolve a status received from eHealth, tolerating the hyphenated spelling.
     */
    public static function fromEHealth(?string $status): ?self
    {
        if ($status === null || $status === '') {
            return null;
        }

        return self::tryFrom(str_replace('-', '_', strtoupper($status)));
    }

    /**
     * Only an unsigned conclusion may still be signed (TV 3.8.1.7, 3.8.2.9).
     */
    public function isSignable(): bool
    {
        return $this === self::PRELIMINARY;
    }

    /**
     * Only a signed conclusion may be marked as entered in error (TV 3.8.1.10.1, 3.8.2.15.1).
     */
    public function isCancellable(): bool
    {
        return $this === self::FINAL;
    }
}
