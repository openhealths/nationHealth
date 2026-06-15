<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        if (!Schema::hasTable('observation_configs')) {
            Schema::create('observation_configs', static function (Blueprint $table): void {
                $table->id();
                $table->string('code')->comment('observation code, e.g. LOINC 82810-3 or a custom key');
                $table->string('system')->comment('coding system, e.g. eHealth/LOINC/observation_codes');
                $table->boolean('is_active')->default(true);
                $table->json('category')->comment('category codes from settings.CATEGORY');
                $table->string('value_type')->nullable()->comment('FHIR value type, e.g. valueQuantity');
                $table->string('binding')->nullable()->comment('answer list id for valueCodeableConcept');
                $table->string('unit')->nullable()->comment('UCUM unit for valueQuantity');
                $table->string('value_range')->nullable()->comment('numeric range from settings.RESULT_BOUNDARIES, e.g. 0-100');
                $table->string('ehealth_updated_at')->nullable()->comment('raw updated_at from eHealth, used as the incremental sync watermark');
                $table->timestamps();

                $table->unique(['code', 'system']);
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
        Schema::dropIfExists('observation_configs');
    }
};
