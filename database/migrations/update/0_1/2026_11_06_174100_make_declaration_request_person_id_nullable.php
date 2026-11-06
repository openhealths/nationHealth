<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow storing a request whose patient is not in the MIS yet, the details sync creates and links the patient.
     *
     * @return void
     */
    public function up(): void
    {
        $personColumn = collect(Schema::getColumns('declaration_requests'))->firstWhere('name', 'person_id');

        if ($personColumn !== null && !$personColumn['nullable']) {
            Schema::table('declaration_requests', static function (Blueprint $table): void {
                $table->foreignId('person_id')->nullable()->change();
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
        $personColumn = collect(Schema::getColumns('declaration_requests'))->firstWhere('name', 'person_id');

        if ($personColumn !== null && $personColumn['nullable']) {
            Schema::table('declaration_requests', static function (Blueprint $table): void {
                $table->foreignId('person_id')->nullable(false)->change();
            });
        }
    }
};
