<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HealthcareService\Status;
use App\Models\Division;
use App\Models\HealthcareService;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthcareService>
 */
class HealthcareServiceFactory extends Factory
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
            'division_id' => Division::factory(),
            'legal_entity_id' => static fn (array $attributes): int => Division::whereKey($attributes['division_id'])->value('legal_entity_id'),
            'category_id' => CodeableConcept::factory()->hasCoding(['system' => 'HEALTHCARE_SERVICE_CATEGORIES', 'code' => 'MSP']),
            'speciality_type' => 'FAMILY_DOCTOR',
            'providing_condition' => 'OUTPATIENT',
            'status' => Status::ACTIVE,
            'is_active' => true,
            'ehealth_inserted_at' => $this->faker->dateTimeBetween('-2 years', '-1 year'),
            'ehealth_inserted_by' => $this->faker->uuid(),
            'ehealth_updated_at' => $this->faker->dateTimeBetween('-1 year'),
            'ehealth_updated_by' => $this->faker->uuid()
        ];
    }
}
