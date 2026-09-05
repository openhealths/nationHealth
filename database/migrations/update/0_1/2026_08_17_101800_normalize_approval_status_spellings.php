<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('approvals')) {
            return;
        }

        DB::table('approvals')->whereRaw("UPPER(status) = 'NEW'")->update(['status' => 'pending']);
        DB::table('approvals')->whereRaw("UPPER(status) = 'APPROVED'")->update(['status' => 'active']);
    }

    public function down(): void
    {
        if (!Schema::hasTable('approvals')) {
            return;
        }

        DB::table('approvals')->where('status', 'pending')->update(['status' => 'NEW']);
        DB::table('approvals')->where('status', 'active')->update(['status' => 'APPROVED']);
    }
};
