<?php

declare(strict_types=1);

use App\Enums\DeviceDispense\Status;
use App\Enums\JobStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('legal_entities', static function (Blueprint $table) {
            if (!Schema::hasColumn('legal_entities', 'device_dispense_sync_status')) {
                $table->enum('device_dispense_sync_status', JobStatus::values())
                    ->nullable()
                    ->after('device_association_sync_status');
            }
        });

        Schema::table('device_dispenses', static function (Blueprint $table) {
            $table->foreignId('performer_id')->nullable()->change();
            $table->foreignId('location_id')->nullable()->change();
            $table->timestamp('when_handed_over')->nullable()->change();
            $table->foreignId('encounter_id')->nullable()->change();
        });

        DB::statement('ALTER TABLE device_dispenses DROP CONSTRAINT IF EXISTS device_dispenses_status_check');

        $statuses = implode("','", Status::values());

        DB::statement(
            "ALTER TABLE device_dispenses
            ADD CONSTRAINT device_dispenses_status_check
            CHECK (status IN ('$statuses'))"
        );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('legal_entities', static function (Blueprint $table) {
            if (Schema::hasColumn('legal_entities', 'device_dispense_sync_status')) {
                $table->dropColumn('device_dispense_sync_status');
            }
        });
    }
};