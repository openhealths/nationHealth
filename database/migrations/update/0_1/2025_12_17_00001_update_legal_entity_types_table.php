<?php

declare(strict_types=1);

use App\Models\LegalEntity;
use App\Core\ExtendedMigration;

/**
 * --- EXAMPLE MIGRATION ---
 *
 * This migration removes specific (unused) legal entity types
 */
return new class extends ExtendedMigration
{
    // Data to be removed
    protected const array PROCEEDED_TYPES = [
        ['name' => LegalEntity::TYPE_MIS],
        ['name' => LegalEntity::TYPE_NHS],
        ['name' => LegalEntity::TYPE_MSP_PHARMACY],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $backupData = DB::table('legal_entity_types')
            ->whereIn('name', array_column(self::PROCEEDED_TYPES, 'name'))
            ->get()
            ->toArray();

        // Backup existing data in migration_backups table
        $this->backup($backupData);

        $this->doUpdate();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        logger('Restoring legal_entity_types from backup');

        // Retrieve backup data from migration_backups table
        $backupData = $this->restore();

        if ($backupData === null) {
            logger('No backup data found for legal_entity_type_permissions migration. Skipping restore.');

            return;
        }

        DB::table('legal_entity_types')->insertOrIgnore($backupData);
    }

    /**
     * Set the updated data for the legal_entity_types table.
     *
     * @return void
     */
    protected function doUpdate(): void
    {
        DB::table('legal_entity_types')
            ->whereIn('name', array_column(self::PROCEEDED_TYPES, 'name'))
            ->delete();
    }
};
