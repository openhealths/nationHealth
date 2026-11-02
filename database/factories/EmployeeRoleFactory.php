<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmployeeRole\Status;
use App\Models\Employee\Employee;
use App\Models\EmployeeRole;
use App\Models\HealthcareService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeRole>
 */
class EmployeeRoleFactory extends Factory
{
    /**
     * Define the model's default state: an active role of an employee in a healthcare service of the employee's
     * division.
     *
     * @return array
     */
    public function definition(): array
    {
        $startDate = CarbonImmutable::now()->subYear();

        return [
            'uuid' => $this->faker->uuid(),
            'employee_id' => Employee::factory(),
            'healthcare_service_id' => static fn (array $attributes): int => HealthcareService::factory()
                ->create(['division_id' => Employee::whereKey($attributes['employee_id'])->value('division_id')])
                ->id,
            'start_date' => $startDate,
            'status' => Status::ACTIVE,
            'is_active' => true,
            'ehealth_inserted_at' => $startDate,
            'ehealth_inserted_by' => $this->faker->uuid(),
            'ehealth_updated_at' => $startDate,
            'ehealth_updated_by' => $this->faker->uuid()
        ];
    }
}
