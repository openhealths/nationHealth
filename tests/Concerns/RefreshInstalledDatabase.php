<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Refresh the test database the way a fresh installation builds it: install migrations hold the full current schema,
 * update migrations are only for upgrading existing databases.
 *
 * The default migrator doesn't see the install and update subfolders, so RefreshDatabase alone would build an empty
 * database. The method lives in a trait rather than in the base TestCase, since the method of RefreshDatabase would
 * override one inherited from a parent class.
 */
trait RefreshInstalledDatabase
{
    use RefreshDatabase;

    /**
     * Build the schema from the install migrations only.
     *
     * @return array
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--path' => database_path('migrations/install'),
            '--realpath' => true
        ];
    }
}
