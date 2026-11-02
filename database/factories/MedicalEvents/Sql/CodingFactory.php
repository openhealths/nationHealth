<?php

declare(strict_types=1);

namespace Database\Factories\MedicalEvents\Sql;

use App\Models\MedicalEvents\Sql\Coding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coding>
 */
class CodingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition(): array
    {
        return [
            'system' => $this->faker->word(),
            'code' => $this->faker->word()
        ];
    }
}
