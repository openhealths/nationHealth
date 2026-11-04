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
        $whatLicensedColumn = collect(Schema::getColumns('licenses'))->firstWhere('name', 'what_licensed');

        if (!$whatLicensedColumn['nullable']) {
            Schema::table('licenses', static function (Blueprint $table): void {
                $table->string('what_licensed')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        $whatLicensedColumn = collect(Schema::getColumns('licenses'))->firstWhere('name', 'what_licensed');

        if ($whatLicensedColumn['nullable']) {
            DB::table('licenses')->whereNull('what_licensed')->update(['what_licensed' => '']);

            Schema::table('licenses', static function (Blueprint $table): void {
                $table->string('what_licensed')->nullable(false)->change();
            });
        }
    }
};
