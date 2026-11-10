<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the tables Passport keeps its OAuth clients, codes and tokens in.
     *
     * @return void
     */
    public function up(): void
    {
        if (!Schema::hasTable('oauth_auth_codes')) {
            Schema::create('oauth_auth_codes', static function (Blueprint $table): void {
                $table->char('id', 80)->primary();
                $table->foreignId('user_id')->index();
                $table->foreignUuid('client_id');
                $table->text('scopes')->nullable();
                $table->boolean('revoked');
                $table->dateTime('expires_at')->nullable();
            });
        }

        if (!Schema::hasTable('oauth_access_tokens')) {
            Schema::create('oauth_access_tokens', static function (Blueprint $table): void {
                $table->char('id', 80)->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->foreignUuid('client_id');
                $table->string('name')->nullable();
                $table->text('scopes')->nullable();
                $table->boolean('revoked');
                $table->timestamps();
                $table->dateTime('expires_at')->nullable();
            });
        }

        if (!Schema::hasTable('oauth_refresh_tokens')) {
            Schema::create('oauth_refresh_tokens', static function (Blueprint $table): void {
                $table->char('id', 80)->primary();
                $table->char('access_token_id', 80)->index();
                $table->boolean('revoked');
                $table->dateTime('expires_at')->nullable();
            });
        }

        if (!Schema::hasTable('oauth_clients')) {
            Schema::create('oauth_clients', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->nullableMorphs('owner');
                $table->string('name');
                $table->string('secret')->nullable();
                $table->string('provider')->nullable();
                $table->text('redirect_uris');
                $table->text('grant_types');
                $table->boolean('revoked');
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_clients');
        Schema::dropIfExists('oauth_refresh_tokens');
        Schema::dropIfExists('oauth_access_tokens');
        Schema::dropIfExists('oauth_auth_codes');
    }
};
