<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use RuntimeException;

trait UsesUtcDatabaseTimeZone
{
    protected function useUtcDatabaseTimeZone(): void
    {
        // DatabaseTransactions disconnects the session during teardown; server defaults are unchanged.
        DB::statement(match (DB::connection()->getDriverName()) {
            'pgsql' => "SET LOCAL TIME ZONE 'UTC'",
            'mysql', 'mariadb' => "SET SESSION time_zone = '+00:00'",
            default => throw new RuntimeException('UTC test sessions require PostgreSQL, MySQL or MariaDB.'),
        });
    }
}
