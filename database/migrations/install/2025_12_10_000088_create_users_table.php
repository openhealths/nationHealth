<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->nullable()->constrained('parties')->onDelete('set null');
            $table->uuid()->nullable()->unique();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
            $table->rememberToken();
            $table->string('two_factor_code')->nullable();
            $table->timestamp('two_factor_code_expires_at')->nullable();
            $table->foreignId('current_team_id')->nullable();
            $table->string('profile_photo_path', 2048)->nullable();
            $table->jsonb('settings')->nullable();
            $table->jsonb('priv_settings')->nullable();
            $table->boolean('is_blocked')->nullable();
            $table->string('block_reason')->nullable();
            $table->foreignId('person_id')->nullable()->constrained('persons')->onDelete('set null');
            $table->timestamp('inserted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
