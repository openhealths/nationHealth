<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Install extends MigrationsCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'install {--clear : Clear the cache before installation} {--wipe : Wipe the database before installation} {--key : Generate application key}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Setup system for first use';

    /**
     * Set the migrations path (and supply stuff aka seeders) for the application
     *
     * This method configures the path where database migration files (and seeders) are located
     * or should be executed from during the installation process.
     *
     * @return void
     */
    protected function setMigrationsPath(): void
    {
        $this->migrations_path = config('ehealth.migrations.install.path', database_path('migrations/install'));
    }

    /**
     * Execute actions before running database migrations
     *
     * This method is called during the installation process before any database
     * migrations are executed. It can be used to perform preparatory tasks such as
     * checking database connectivity, creating initial configuration, or setting up
     * required database structures
     *
     * @return void
     */
    protected function beforeMigrations(): void
    {
        // Prepare options
        $this->options['wipe'] = $this->option('wipe');
        $this->options['key'] = $this->option('key');

        $this->newLine();
        $this->components->info('Start installation...');
        $this->newLine();

        // Completely wipe the database before installation
        if ($this->options['wipe']) {
            $this->call('db:wipe');

            $this->call('migrate:install');
        }

        // Generate application key
        if ($this->options['key']) {
            $this->info('Generating new application key...');

            $this->call('key:generate', ['--force' => true]);
        }
    }

    /**
     * Performs post-migration setup tasks
     *
     * This method is called after database migrations have been succesfully! executed.
     * It can be used to seed data, configure application settings, or perform
     * any other necessary initialization tasks that depend on the database schema.
     *
     * @return void
     */
    protected function afterMigrations(): void
    {
        $this->newLine();

        $this->fixAllPostgresSequences();

        $this->components->info('Installation completed successfully.');
    }

    /**
     * Fixes PostgreSQL sequences for all relevant tables.
     *
     * @return void
     */
    protected function fixAllPostgresSequences(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $tables = [
            'users',
            'legal_entities',
            'parties',
            'employees',
            'employee_requests',
            'revisions'
        ];

        foreach ($tables as $tableName) {
            $this->fixPostgresSequence($tableName);
        }
    }

    /**
     * Resets the sequence for a given table to the current maximum ID.
     * This version is more robust and handles empty tables correctly.
     *
     * @param  string  $tableName
     * @return void
     */
    protected function fixPostgresSequence(string $tableName): void
    {
        try {
            DB::statement(
                "SELECT setval(pg_get_serial_sequence('{$tableName}', 'id'), COALESCE((SELECT MAX(id) FROM \"{$tableName}\"), 0));"
            );
        } catch (Exception $e) {
            Log::warning("Could not reset sequence for table '{$tableName}': " . $e->getMessage());
        }
    }
}
