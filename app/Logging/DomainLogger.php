<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Support\Facades\Log;

/**
 * Domain logging gated by LOG_DOMAIN_VERBOSE.
 *
 * info/debug are suppressed on production-like envs when verbose is off.
 * warning/error/critical always pass through so incidents stay visible.
 */
final class DomainLogger
{
    public static function isVerbose(): bool
    {
        return (bool) config('logging.domain_verbose', false);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function debug(string $message, array $context = []): void
    {
        if (!self::isVerbose()) {
            return;
        }

        Log::debug($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function info(string $message, array $context = []): void
    {
        if (!self::isVerbose()) {
            return;
        }

        Log::info($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function notice(string $message, array $context = []): void
    {
        if (!self::isVerbose()) {
            return;
        }

        Log::notice($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function warning(string $message, array $context = []): void
    {
        Log::warning($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function error(string $message, array $context = []): void
    {
        Log::error($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function critical(string $message, array $context = []): void
    {
        Log::critical($message, $context);
    }
}
