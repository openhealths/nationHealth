<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\License\Type;
use App\Models\LegalEntity;
use App\Models\License;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<License>
 */
class LicenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition(): array
    {
        $issuedDate = CarbonImmutable::now()->subYear()->toDateString();

        return [
            'uuid' => $this->faker->uuid(),
            'legal_entity_id' => LegalEntity::factory(),
            'type' => Type::MSP,
            'is_active' => true,
            'is_primary' => false,
            'issued_by' => $this->faker->company(),
            'issued_date' => $issuedDate,
            'active_from_date' => $issuedDate,
            'order_no' => $this->faker->numerify('######'),
            'license_number' => $this->faker->numerify('########'),
            'expiry_date' => CarbonImmutable::now()->addYear()->toDateString(),
            'what_licensed' => $this->faker->sentence()
        ];
    }
}
