<?php

declare(strict_types=1);

use App\Jobs\ObservationConfigurationSync;
use App\Repositories\Repository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('observation_configs', static function (Blueprint $table) {
            if (!Schema::hasColumn('observation_configs', 'settings')) {
                $table->json('settings')
                    ->nullable()
                    ->after('value_range')
                    ->comment('full settings payload from eHealth');
            }
        });

        Repository::observationConfig()->flush();

        ObservationConfigurationSync::dispatch();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('observation_configs', static function (Blueprint $table) {
            if (Schema::hasColumn('observation_configs', 'settings')) {
                $table->dropColumn('settings');
            }
        });
    }
};
