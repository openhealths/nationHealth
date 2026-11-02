<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Division\Type;
use App\Enums\Status;
use App\Models\Division;
use App\Models\LegalEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Division>
 */
class DivisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'legal_entity_id' => LegalEntity::factory(),
            'name' => $this->faker->company(),
            'type' => Type::TYPE_CLINIC->value,
            'email' => $this->faker->safeEmail(),
            'mountain_group' => false,
            'status' => Status::ACTIVE,
            'is_active' => true
        ];
    }
}
