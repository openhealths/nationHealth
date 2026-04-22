<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', static function (Blueprint $table) {
            if (!Schema::hasColumn('encounters', 'cancellation_reason')) {
                $table->string('cancellation_reason')->nullable()->after('status');
            }
            if (!Schema::hasColumn('encounters', 'explanatory_letter')) {
                $table->string('explanatory_letter')->nullable()->after('cancellation_reason');
            }
            if (!Schema::hasColumn('encounters', 'incoming_referral_id')) {
                $table->foreignId('incoming_referral_id')->nullable()->after('division_id')->constrained('identifiers');
            }
            if (!Schema::hasColumn('encounters', 'origin_episode_id')) {
                $table->foreignId('origin_episode_id')->nullable()->after('incoming_referral_id')->constrained('identifiers');
            }
            if (!Schema::hasColumn('encounters', 'prescriptions')) {
                $table->text('prescriptions')->nullable()->after('origin_episode_id');
            }
            if (!Schema::hasColumn('encounters', 'ehealth_inserted_at')) {
                $table->timestamp('ehealth_inserted_at')->nullable()->after('prescriptions');
            }
            if (!Schema::hasColumn('encounters', 'ehealth_updated_at')) {
                $table->timestamp('ehealth_updated_at')->nullable()->after('ehealth_inserted_at');
            }
        });

        if (!Schema::hasTable('encounter_action_references')) {
            Schema::create('encounter_action_references', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
                $table->foreignId('identifier_id')->constrained('identifiers')->cascadeOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('encounter_participants')) {
            Schema::create('encounter_participants', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
                $table->foreignId('identifier_id')->constrained('identifiers')->cascadeOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('encounter_supporting_info')) {
            Schema::create('encounter_supporting_info', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
                $table->foreignId('identifier_id')->constrained('identifiers')->cascadeOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('encounter_hospitalizations')) {
            Schema::create('encounter_hospitalizations', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('encounter_id')->constrained('encounters')->cascadeOnDelete();
                $table->string('pre_admission_identifier')->nullable();
                $table->foreignId('admit_source_id')->nullable()->constrained('codings');
                $table->foreignId('re_admission_id')->nullable()->constrained('codings');
                $table->foreignId('destination_id')->nullable()->constrained('identifiers');
                $table->foreignId('discharge_disposition_id')->nullable()->constrained('codings');
                $table->foreignId('discharge_department_id')->nullable()->constrained('codings');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('encounter_hospitalizations');
        Schema::dropIfExists('encounter_supporting_info');
        Schema::dropIfExists('encounter_participants');
        Schema::dropIfExists('encounter_action_references');

        Schema::table('encounters', static function (Blueprint $table) {
            if (Schema::hasColumn('encounters', 'incoming_referral_id')) {
                $table->dropForeign(['incoming_referral_id']);
            }
            if (Schema::hasColumn('encounters', 'origin_episode_id')) {
                $table->dropForeign(['origin_episode_id']);
            }

            $columns = array_filter(
                ['cancellation_reason', 'explanatory_letter', 'incoming_referral_id', 'origin_episode_id', 'prescriptions', 'ehealth_inserted_at', 'ehealth_updated_at'],
                static fn (string $column) => Schema::hasColumn('encounters', $column)
            );

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
