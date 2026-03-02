<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    protected const int CHUNK_SIZE = 10000;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('icd_10', static function (Blueprint $table) {
            $table->id();
            $table->string('code')->index();
            $table->string('description', 500)->index();
            $table->boolean('is_active');
            $table->jsonb('child_values');
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
        Schema::dropIfExists('icd_10');
    }

    /**
     * Populate the ICD-10 (InternationalClassification of Diseases, 10th Revision) table with initial data.
     *
     * @return void
     */
    protected function setData(): void
    {
        $dictionary = dictionary()->getLargeDictionary('eHealth/ICD10_AM/condition_codes')['eHealth/ICD10_AM/condition_codes'];

        $data = [];

        foreach ($dictionary as $key => $value) {
            $data[] = [
                'code' => $key,
                'description' => $value['description'],
                'is_active' => $value['is_active'],
                'child_values' => json_encode($value['child_values'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now()
            ];
        }

        // Clear an old table before inserting new data
        DB::table('icd_10')->truncate();

        // Insert data by chunks
        $chunks = array_chunk($data, self::CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            DB::table('icd_10')->insert($chunk);
        }
    }
};
