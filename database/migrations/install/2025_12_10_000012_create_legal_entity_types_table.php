<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('legal_entity_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('localized_name')->nullable();
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
        Schema::dropIfExists('legal_entity_types');
    }

    /**
     * Set the initial data for the legal_entity_types table.
     *
     * @return void
     */
    protected function setData(): void
    {
        $now = now();

        $availableTypes = [];

        foreach (config('ehealth.legal_entity_localized_names') as $typeName => $localizedName) {
            $availableTypes[] = [
                'name' => $typeName,
                'localized_name' => $localizedName,
            ];
        }

        $configuredTypes = array_keys(config('ehealth.legal_entity_types', []));

        $configuredTypes = array_filter($availableTypes, function ($typeData) use ($configuredTypes) {
            return in_array($typeData['name'], $configuredTypes, true);
        });

        foreach ($configuredTypes as $typeRecord) {
            $typeRecord['created_at'] = $now;
            $typeRecord['updated_at'] = $now;
        }

        DB::table('legal_entity_types')->insert($configuredTypes);
    }
};
