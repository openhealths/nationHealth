<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep the immunization dose in the shared quantities table and move the stored doses there.
     *
     * @return void
     */
    public function up(): void
    {
        if (!Schema::hasColumn('immunizations', 'dose_quantity_id')) {
            Schema::table('immunizations', static function (Blueprint $table): void {
                $table->foreignId('dose_quantity_id')->nullable()->constrained('quantities');
            });
        }

        if (Schema::hasTable('immunization_dose_quantities')) {
            DB::table('immunization_dose_quantities')
                ->orderBy('id')
                ->each(static function (object $doseQuantity): void {
                    $quantityId = DB::table('quantities')->insertGetId([
                        'value' => $doseQuantity->value,
                        'comparator' => $doseQuantity->comparator,
                        'unit' => $doseQuantity->unit,
                        'system' => $doseQuantity->system,
                        'code' => $doseQuantity->code,
                        'created_at' => $doseQuantity->created_at,
                        'updated_at' => $doseQuantity->updated_at
                    ]);

                    DB::table('immunizations')
                        ->where('id', $doseQuantity->immunization_id)
                        ->update(['dose_quantity_id' => $quantityId]);
                });

            Schema::drop('immunization_dose_quantities');
        }
    }

    /**
     * Move the doses back to their own table. Its value column stays decimal, as the fractional doses stored meanwhile
     * do not fit the former integer one.
     *
     * @return void
     */
    public function down(): void
    {
        if (!Schema::hasTable('immunization_dose_quantities')) {
            Schema::create('immunization_dose_quantities', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('immunization_id')->constrained('immunizations')->cascadeOnDelete();
                $table->decimal('value', 24, 10);
                $table->string('comparator')->nullable();
                $table->enum('unit', ['MG', 'MKG', 'ML'])->nullable();
                $table->string('system')->nullable();
                $table->string('code')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasColumn('immunizations', 'dose_quantity_id')) {
            DB::table('immunizations')
                ->join('quantities', 'quantities.id', '=', 'immunizations.dose_quantity_id')
                ->select([
                    'immunizations.id as immunization_id',
                    'quantities.value',
                    'quantities.comparator',
                    'quantities.unit',
                    'quantities.system',
                    'quantities.code',
                    'quantities.created_at',
                    'quantities.updated_at'
                ])
                ->orderBy('immunizations.id')
                ->each(static function (object $doseQuantity): void {
                    DB::table('immunization_dose_quantities')->insert((array) $doseQuantity);
                });

            Schema::table('immunizations', static function (Blueprint $table): void {
                $table->dropConstrainedForeignId('dose_quantity_id');
            });
        }
    }
};
