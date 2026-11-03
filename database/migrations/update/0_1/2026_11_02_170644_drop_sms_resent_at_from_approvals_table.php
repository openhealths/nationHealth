<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('approvals', 'sms_resent_at')) {
            Schema::table('approvals', static function (Blueprint $table): void {
                $table->dropColumn('sms_resent_at');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('approvals', 'sms_resent_at')) {
            Schema::table('approvals', static function (Blueprint $table): void {
                $table->timestamp('sms_resent_at')->nullable();
            });
        }
    }
};
