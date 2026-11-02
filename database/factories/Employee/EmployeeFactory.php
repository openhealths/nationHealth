<?php

declare(strict_types=1);

namespace Database\Factories\Employee;

use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Division;
use App\Models\Employee\Employee;
use App\Models\Relations\Party;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state: an approved doctor working in a division of its legal entity.
     *
     * @return array
     */
    public function definition(): array
    {
        return [
            'uuid' => $this->faker->uuid(),
            'division_id' => Division::factory(),
            'legal_entity_id' => static fn (array $attributes): int => Division::whereKey($attributes['division_id'])->value('legal_entity_id'),
            'party_id' => Party::factory(),
            'employee_type' => Role::DOCTOR->value,
            'position' => 'P1',
            'start_date' => CarbonImmutable::now()->subYear()->toDateString(),
            'status' => Status::APPROVED,
            'is_active' => true
        ];
    }
}
