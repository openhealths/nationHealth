<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('approvals', static function (Blueprint $table) {
            if (!Schema::hasColumn('approvals', 'created_by_id')) {
                $table->foreignId('created_by_id')->nullable()->constrained('identifiers');
            }

            if (!Schema::hasColumn('approvals', 'authorize_with')) {
                $table->uuid('authorize_with')->nullable();
            }

            if (!Schema::hasColumn('approvals', 'authentication_method_id')) {
                $table->foreignId('authentication_method_id')->nullable()->constrained('authentication_methods');
            }

            if (!Schema::hasColumn('approvals', 'access_level')) {
                $table->string('access_level')->default('read');
            }

            if (!Schema::hasColumn('approvals', 'is_verified')) {
                $table->boolean('is_verified')->default(false);
            }

            if (!Schema::hasColumn('approvals', 'expires_at')) {
                $table->timestamp('expires_at')->nullable();
            }
        });

        if (!Schema::hasTable('approval_granted_resources')) {
            Schema::create('approval_granted_resources', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('approval_id')->constrained('approvals')->cascadeOnDelete();
                $table->foreignId('granted_to_id')->nullable()->constrained('identifiers');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('approval_granted_resource_types')) {
            Schema::create('approval_granted_resource_types', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('approval_id')->constrained('approvals')->cascadeOnDelete();
                $table->foreignId('codeable_concept_id')->constrained('codeable_concepts')->cascadeOnDelete();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_granted_resource_types');
        Schema::dropIfExists('approval_granted_resources');

        Schema::table('approvals', static function (Blueprint $table) {
            if (Schema::hasColumn('approvals', 'created_by_id')) {
                $table->dropForeign(['created_by_id']);
                $table->dropColumn('created_by_id');
            }

            if (Schema::hasColumn('approvals', 'authorize_with')) {
                $table->dropColumn('authorize_with');
            }

            if (Schema::hasColumn('approvals', 'authentication_method_id')) {
                $table->dropForeign(['authentication_method_id']);
                $table->dropColumn('authentication_method_id');
            }

            if (Schema::hasColumn('approvals', 'access_level')) {
                $table->dropColumn('access_level');
            }

            if (Schema::hasColumn('approvals', 'is_verified')) {
                $table->dropColumn('is_verified');
            }

            if (Schema::hasColumn('approvals', 'expires_at')) {
                $table->dropColumn('expires_at');
            }
        });
    }
};
