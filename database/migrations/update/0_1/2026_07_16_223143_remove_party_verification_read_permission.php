<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Previously removed party_verification permissions other than details/write.
 * eHealth confirmed party_verification:read is a valid scope — destructive body removed.
 * Restoration is handled by 2026_07_22_103000_restore_party_verification_read_permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No-op: keep migration history for environments that already ran the old body.
    }

    public function down(): void
    {
        //
    }
};
