<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\UsesUtcDatabaseTimeZone;
use Tests\TestCase;

class DatabaseTimeZoneTest extends TestCase
{
    use UsesUtcDatabaseTimeZone;

    #[TestWith(['pgsql', "SET LOCAL TIME ZONE 'UTC'"])]
    #[TestWith(['mysql', "SET SESSION time_zone = '+00:00'"])]
    #[TestWith(['mariadb', "SET SESSION time_zone = '+00:00'"])]
    public function test_utc_setup_changes_only_the_test_transaction_or_session(string $driver, string $statement): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn($driver);
        DB::shouldReceive('getConnections')->andReturn([]);
        DB::shouldReceive('connection')->once()->andReturn($connection);
        DB::shouldReceive('statement')->once()->with($statement)->andReturn(true);

        $this->useUtcDatabaseTimeZone();
    }
}
