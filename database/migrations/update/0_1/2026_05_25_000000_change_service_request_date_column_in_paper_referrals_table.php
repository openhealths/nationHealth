<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paper_referrals', static function (Blueprint $table) {
            $table->dateTime('service_request_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('paper_referrals', static function (Blueprint $table) {
            $table->string('service_request_date')->nullable()->change();
        });
    }
};
