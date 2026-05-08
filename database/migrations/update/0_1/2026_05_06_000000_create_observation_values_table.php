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
        if (!Schema::hasTable('sampled_data')) {
            Schema::create('sampled_data', static function (Blueprint $table) {
                $table->id();
                $table->integer('origin')->nullable();
                $table->integer('period')->nullable();
                $table->integer('factor')->nullable();
                $table->integer('lower_limit')->nullable();
                $table->integer('upper_limit')->nullable();
                $table->integer('dimensions')->nullable();
                $table->string('data');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('ratios')) {
            Schema::create('ratios', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('denominator_id')->nullable()->constrained('quantities');
                $table->foreignId('numerator_id')->nullable()->constrained('quantities');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('ranges')) {
            Schema::create('ranges', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('low_id')->nullable()->constrained('quantities');
                $table->foreignId('high_id')->nullable()->constrained('quantities');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('values')) {
            Schema::create('values', static function (Blueprint $table) {
                $table->id();
                $table->foreignId('observation_id')->nullable()->constrained('observations')->cascadeOnDelete();
                $table->foreignId('observation_component_id')->nullable()->constrained('observation_components')->cascadeOnDelete();
                $table->foreignId('value_codeable_concept_id')->nullable()->constrained('codeable_concepts');
                $table->foreignId('value_quantity_id')->nullable()->constrained('quantities');
                $table->foreignId('value_ratio_id')->nullable()->constrained('ratios');
                $table->foreignId('value_range_id')->nullable()->constrained('ranges');
                $table->foreignId('value_sampled_data_id')->nullable()->constrained('sampled_data')->cascadeOnDelete();
                $table->string('value_string')->nullable();
                $table->boolean('value_boolean')->nullable();
                $table->timestamp('value_date_time')->nullable();
                $table->time('value_time')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('observations', static function (Blueprint $table) {
            if (Schema::hasColumn('observations', 'value_codeable_concept_id')) {
                $table->dropForeign(['value_codeable_concept_id']);
                $table->dropColumn('value_codeable_concept_id');
            }

            if (Schema::hasColumn('observations', 'value_string')) {
                $table->dropColumn('value_string');
            }

            if (Schema::hasColumn('observations', 'value_boolean')) {
                $table->dropColumn('value_boolean');
            }

            if (Schema::hasColumn('observations', 'value_date_time')) {
                $table->dropColumn('value_date_time');
            }
        });

        Schema::table('observation_components', static function (Blueprint $table) {
            if (Schema::hasColumn('observation_components', 'value_codeable_concept_id')) {
                $table->dropForeign(['value_codeable_concept_id']);
                $table->dropColumn('value_codeable_concept_id');
            }

            if (Schema::hasColumn('observation_components', 'codeable_concept_id')) {
                $table->dropForeign(['codeable_concept_id']);
                $table->dropColumn('codeable_concept_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('observations', static function (Blueprint $table) {
            if (!Schema::hasColumn('observations', 'value_codeable_concept_id')) {
                $table->foreignId('value_codeable_concept_id')->nullable()->constrained('codeable_concepts');
            }

            if (!Schema::hasColumn('observations', 'value_string')) {
                $table->string('value_string')->nullable();
            }

            if (!Schema::hasColumn('observations', 'value_boolean')) {
                $table->boolean('value_boolean')->nullable();
            }

            if (!Schema::hasColumn('observations', 'value_date_time')) {
                $table->timestamp('value_date_time')->nullable();
            }
        });

        Schema::table('observation_components', static function (Blueprint $table) {
            if (!Schema::hasColumn('observation_components', 'value_codeable_concept_id')) {
                $table->foreignId('value_codeable_concept_id')->nullable()->constrained('codeable_concepts');
            }
        });

        Schema::dropIfExists('values');
        Schema::dropIfExists('ranges');
        Schema::dropIfExists('ratios');
        Schema::dropIfExists('sampled_data');
    }
};
