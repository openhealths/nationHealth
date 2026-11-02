<?php

declare(strict_types=1);

namespace Database\Factories\MedicalEvents\Sql;

use App\Models\MedicalEvents\Sql\CodeableConcept;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CodeableConcept>
 */
class CodeableConceptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition(): array
    {
        return [
            'text' => null
        ];
    }
}
