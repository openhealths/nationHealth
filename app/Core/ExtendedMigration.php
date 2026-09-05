<?php

declare(strict_types=1);

namespace App\Core;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

class ExtendedMigration extends Migration
{
    /**
     * Creates a backup of the provided data during migration.
     *
     * This method is responsible for storing backup data that can be used
     * to restore the system state if the migration needs to be rolled back.
     *
     * @param  mixed  $backupData
     * @return void
     */
    protected function backup(mixed $backupData): void
    {
        $file = new \ReflectionClass(static::class)->getFileName();
        $migrationName = $file ? pathinfo($file, PATHINFO_FILENAME) : static::class;

        $json = json_encode($backupData, JSON_THROW_ON_ERROR);

        // Compress the JSON data to  reduce storage size
        $compressed = gzcompress($json);

        $wrapped = base64_encode($compressed);

        DB::table('migration_backups')->updateOrInsert(
            ['migration' => $migrationName],
            [
                'data' => $wrapped,
                'created_at' => now(),
                'updated_at' => now()
            ]
        );
    }

    /**
     * Restore the previous state or data.
     *
     * This method is responsible for restoring data or reverting changes
     * that were made during the migration process. It is typically called
     * when rolling back a migration or undoing specific operations.
     *
     * @return mixed The restored data or state, return type depends on implementation
     */
    protected function restore(): mixed
    {
        $file = (new \ReflectionClass(static::class))->getFileName();
        $migrationName = $file
            ? pathinfo($file, PATHINFO_FILENAME)
            : static::class;

        $encoded = DB::table('migration_backups')
            ->where('migration', $migrationName)
            ->value('data');

        if (!$encoded) {
            return null;
        }

        $unwrapped = base64_decode($encoded, true);

        if ($unwrapped === false) {
            throw new \RuntimeException("Invalid base64 backup for migration {$migrationName}");
        }

        $json = gzuncompress($unwrapped);

        if ($json === false) {
            throw new \RuntimeException("Invalid compressed backup for migration {$migrationName}");
        }

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
