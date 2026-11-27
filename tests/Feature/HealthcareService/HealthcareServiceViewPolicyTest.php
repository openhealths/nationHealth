<?php

declare(strict_types=1);

namespace Tests\Feature\HealthcareService;

use App\Enums\User\Role;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshInstalledDatabase;
use Tests\TestCase;

/**
 * Who may view the healthcare services of the legal entity, and who may synchronize them.
 *
 * Viewing is given only to the owner, the administrator and the pharmacy owner, although more roles hold the read
 * scope. Synchronizing stores the services, so it also needs the write scope. The expected roles are written out here
 * instead of being read from the configuration, so an accidental change of the role scopes fails this test.
 */
class HealthcareServiceViewPolicyTest extends TestCase
{
    use PreparesHealthcareServiceTests;
    use RefreshInstalledDatabase;

    /**
     * Roles allowed to view healthcare services, each in a legal entity type that holds it.
     *
     * @return array
     */
    public static function rolesAllowedToView(): array
    {
        return [
            'owner' => [LegalEntity::TYPE_PRIMARY_CARE, Role::OWNER],
            'admin' => [LegalEntity::TYPE_PRIMARY_CARE, Role::ADMIN],
            'pharmacy owner' => [LegalEntity::TYPE_PHARMACY, Role::PHARMACY_OWNER],
        ];
    }

    /**
     * Roles that hold the read scope but are not allowed to view healthcare services, each in a legal entity type
     * that holds it.
     *
     * @return array
     */
    public static function rolesDeniedToView(): array
    {
        return [
            'doctor' => [LegalEntity::TYPE_PRIMARY_CARE, Role::DOCTOR],
            'specialist' => [LegalEntity::TYPE_OUTPATIENT, Role::SPECIALIST],
            'assistant' => [LegalEntity::TYPE_PRIMARY_CARE, Role::ASSISTANT],
            'receptionist' => [LegalEntity::TYPE_PRIMARY_CARE, Role::RECEPTIONIST],
            'reorganization owner' => [LegalEntity::TYPE_PRIMARY_CARE, Role::REORGANIZATION_OWNER],
            'pharmacist' => [LegalEntity::TYPE_PHARMACY, Role::PHARMACIST],
        ];
    }

    /**
     * The role may view the healthcare services.
     *
     * @param  string  $legalEntityType
     * @param  Role  $role
     * @return void
     */
    #[DataProvider('rolesAllowedToView')]
    public function test_role_can_view_services(string $legalEntityType, Role $role): void
    {
        $user = $this->userWithRole(LegalEntity::factory()->ofType($legalEntityType)->create(), $role);

        $this->assertTrue($user->can('viewAny', HealthcareService::class));
    }

    /**
     * The role holds the read scope, but may not view the healthcare services.
     *
     * @param  string  $legalEntityType
     * @param  Role  $role
     * @return void
     */
    #[DataProvider('rolesDeniedToView')]
    public function test_role_with_read_scope_cannot_view_services(string $legalEntityType, Role $role): void
    {
        $user = $this->userWithRole(LegalEntity::factory()->ofType($legalEntityType)->create(), $role);
        $this->assertTrue($user->can('healthcare_service:read'), "Role $role->value doesn't hold the read scope");

        $this->assertFalse($user->can('viewAny', HealthcareService::class));
    }

    /**
     * A role with the read scope only can't synchronize, since synchronizing writes the services to the database.
     *
     * @return void
     */
    public function test_role_without_write_scope_cannot_sync_services(): void
    {
        $user = $this->userWithRole(LegalEntity::factory()->create(), Role::DOCTOR);
        $this->assertTrue($user->can('healthcare_service:read'));
        $this->assertFalse($user->can('healthcare_service:write'));

        $this->assertFalse($user->can('sync', HealthcareService::class));
    }
}
