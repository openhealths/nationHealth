<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class TestingDatabase
{
    public static function assertIsolated(string $database, string|false $processToken, bool $allowBase = false): void
    {
        $allowed = [$processToken !== false && $processToken !== '' ? 'testing_test_'.$processToken : 'testing'];
        if ($allowBase) {
            $allowed[] = 'testing';
        }
        if (!in_array($database, $allowed, true)) {
            throw new RuntimeException("Refusing to run tests against database [{$database}]. Expected an isolated testing database for this process.");
        }
    }
}
