<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    protected const int CHUNK_SIZE = 1000;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('legal_entity_type_permissions', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_type_id')->constrained()->cascadeOnDelete();

            $table->primary(['permission_id', 'legal_entity_type_id']);

            $table->timestamps();
        });

        try {
            $this->setData();
        } catch (Exception $error) {
            $this->down();

            throw $error;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_entity_type_permissions');
    }

    /**
     * Set up default permissions for legal entity types
     *
     * @return void
     */
    protected function setData(): void
    {
        $typesData = config('ehealth.legal_entity_types', []);

        // Fetch type ids keyed by name
        $typeIds = DB::table('legal_entity_types')->pluck('id', 'name');

        // Map permission name => [permission_id => [...guards]] (include all guards)
        $permMap = DB::table('permissions')
            ->select('id', 'name')
            ->get()
            ->groupBy('name')
            ->map(fn ($dataToInsert) => $dataToInsert->pluck('id')->all());

        $dataToInsert = [];

        foreach ($typesData as $typeName => $permNames) {

            $typeId = (int) $typeIds[$typeName];

            foreach ($permNames as $name) {

                foreach ($permMap[$name] as $pid) {
                    $dataToInsert[] = [
                        'permission_id' => (int) $pid,
                        'legal_entity_type_id' => $typeId,
                    ];
                }
            }
        }

        // Insert in chunks using insertOrIgnore for idempotency
        foreach (array_chunk($dataToInsert, self::CHUNK_SIZE) as $chunk) {
            DB::table('legal_entity_type_permissions')->insertOrIgnore($chunk);
        }
    }
};
