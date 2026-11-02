<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Status;
use App\Models\LegalEntity;
use App\Models\LegalEntityType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalEntity>
 */
class LegalEntityFactory extends Factory
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
            'legal_entity_type_id' => static fn (): int => LegalEntityType::whereName(LegalEntity::TYPE_PRIMARY_CARE)->value('id'),
            'edr' => ['name' => $this->faker->company()],
            'edrpou' => $this->faker->numerify('########'),
            'email' => $this->faker->safeEmail(),
            'status' => Status::ACTIVE->value,
            'is_active' => true
        ];
    }

    /**
     * Legal entity of the given type.
     *
     * @param  string  $typeName
     * @return static
     */
    public function ofType(string $typeName): static
    {
        return $this->state([
            'legal_entity_type_id' => static fn (): int => LegalEntityType::whereName($typeName)->value('id')
        ]);
    }
}
