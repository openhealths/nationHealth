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
        Schema::create('quantities', static function (Blueprint $table) {
            $table->id();
            $table->decimal('value')->comment('Numerical value (with implicit precision)');
            $table->enum('comparator', ['<', '<=', '>=', '>', '='])->nullable()->comment('ad - how to understand the value');
            $table->string('unit')->nullable()->comment('Unit from eHealth/ucum/units');
            $table->string('system')->nullable()->comment('dictionary - eHealth/ucum/units');
            $table->string('code')->nullable()->comment('Code from eHealth/ucum/units');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quantities');
    }
};
