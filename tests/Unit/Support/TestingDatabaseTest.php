<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestingDatabase;

class TestingDatabaseTest extends TestCase
{
    #[DataProvider('isolatedDatabases')]
    public function test_accepts_only_the_database_for_the_current_test_process(string $database, string|false $token, bool $allowBase): void
    {
        TestingDatabase::assertIsolated($database, $token, $allowBase);
        $this->addToAssertionCount(1);
    }

    public static function isolatedDatabases(): array
    {
        return [
            ['testing', false, false],
            ['testing_test_1', '1', false],
            ['testing_test_2', '2', false],
            ['testing', '1', true],
        ];
    }

    #[DataProvider('unsafeDatabases')]
    public function test_rejects_production_and_other_process_databases(string $database, string|false $token): void
    {
        $this->expectException(RuntimeException::class);
        TestingDatabase::assertIsolated($database, $token);
    }

    public static function unsafeDatabases(): array
    {
        return [
            ['mis_dev', false], ['production', '1'], ['testing_test_2', '1'],
            ['testing_test_1', false], ['testing_backup', false], ['testing', '1'],
        ];
    }
}
