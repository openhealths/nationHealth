<?php

declare(strict_types=1);

namespace Tests\Feature\HealthcareService;

use App\Enums\HealthcareService\Status;
use App\Enums\User\Role;
use App\Models\Division;
use App\Models\LegalEntity;
use App\Models\User;

/**
 * Setup the healthcare service tests share: a user signed in to the legal entity with a role, and a healthcare
 * service in the shape eHealth returns it. Building the response needs the WithFaker trait in the test class.
 */
trait PreparesHealthcareServiceTests
{
    /**
     * User working in the legal entity with the role, in the context the application sets for the legal entity's
     * pages, logged in with the role: the user holds the role's scopes as the eHealth login gives them. Fails when the
     * role is not assigned, so a denied case can't pass only because the role is missing.
     *
     * @param  LegalEntity  $legalEntity
     * @param  Role  $role
     * @return User
     */
    protected function userWithRole(LegalEntity $legalEntity, Role $role): User
    {
        $this->app->instance('legalEntity', $legalEntity);
        setPermissionsTeamId($legalEntity->id);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->syncPermissions(config('ehealth.roles.' . $role->value));

        $this->assertTrue($user->hasRole($role->value), "Role $role->value is not assigned");

        return $user;
    }

    /**
     * Active family doctor service of the division in outpatient conditions, in the shape eHealth returns a healthcare
     * service, unless overridden.
     *
     * @param  LegalEntity  $legalEntity
     * @param  Division  $division
     * @param  array  $overrides
     * @return array
     */
    protected function ehealthHealthcareService(LegalEntity $legalEntity, Division $division, array $overrides = []): array
    {
        return array_replace([
            'id' => $this->faker->uuid(),
            'division_id' => $division->uuid,
            'legal_entity_id' => $legalEntity->uuid,
            'category' => ['coding' => [['system' => 'HEALTHCARE_SERVICE_CATEGORIES', 'code' => 'MSP']]],
            'speciality_type' => 'FAMILY_DOCTOR',
            'providing_condition' => 'OUTPATIENT',
            'status' => Status::ACTIVE->value,
            'is_active' => true,
            'available_time' => [],
            'not_available' => [],
            'inserted_at' => '2026-10-30T10:00:00Z',
            'inserted_by' => $this->faker->uuid(),
            'updated_at' => '2026-10-30T10:00:00Z',
            'updated_by' => $this->faker->uuid(),
        ], $overrides);
    }
}
