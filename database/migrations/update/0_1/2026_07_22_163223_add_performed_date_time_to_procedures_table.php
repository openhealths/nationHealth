<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procedures', static function (Blueprint $table): void {
                if (!Schema::hasColumn('procedures', 'performed_date_time')) {
                    $table
                        ->timestamp('performed_date_time')
                        ->nullable()
                        ->after('code_id');
                }
            }
        );
    }

    public function down(): void
    {
        Schema::table('procedures', static function (Blueprint $table): void {
                if (Schema::hasColumn('procedures', 'performed_date_time')) {
                    $table->dropColumn('performed_date_time');
                }
            }
        );
    }
};