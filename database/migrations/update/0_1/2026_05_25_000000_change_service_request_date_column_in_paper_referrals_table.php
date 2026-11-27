<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('paper_referrals', static function (Blueprint $table): void {
                $table->timestamp('service_request_date', 0)->nullable()->change();
            });

            return;
        }
        DB::statement('
            ALTER TABLE paper_referrals
            ALTER COLUMN service_request_date
            TYPE timestamp(0) WITHOUT TIME ZONE
            USING service_request_date::timestamp(0) WITHOUT TIME ZONE
        ');

        DB::statement('ALTER TABLE paper_referrals ALTER COLUMN service_request_date DROP NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('paper_referrals', static function (Blueprint $table): void {
                $table->string('service_request_date')->nullable()->change();
            });

            return;
        }
        DB::statement('
            ALTER TABLE paper_referrals
            ALTER COLUMN service_request_date
            TYPE varchar(255)
            USING service_request_date::varchar
        ');
    }
};
