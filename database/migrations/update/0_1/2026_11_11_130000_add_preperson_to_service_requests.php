<?php

declare(strict_types=1);

use RuntimeException;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_request_requests', static function (Blueprint $table): void {
            $table->foreignId('person_id')->nullable()->change();
            $table->foreignId('preperson_id')->nullable()->constrained('prepersons');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Unidentified patient referrals require a forward repair to preserve clinical records.');
    }
};
