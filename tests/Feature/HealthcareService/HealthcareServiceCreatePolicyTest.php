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
 * Who may create healthcare services: the role of the user, the type and the status of the legal entity.
 *
 * The expected roles are written out here on purpose instead of being read from the configuration, so an accidental
 * change of the allowed roles, of the legal entity types allowed to create services, or a write scope lost by an
 * allowed role fails this test. Each case checks a different rule, so a role is listed once and a legal entity type
 * is checked with the owner.
 */
class HealthcareServiceCreatePolicyTest extends TestCase
{
    use PreparesHealthcareServiceTests;
    use RefreshInstalledDatabase;

    /**
     * The owner in each legal entity type allowed to create services, and each other allowed role once, in a legal
     * entity type that holds it.
     *
     * @return array
     */
    public static function rolesAllowedToCreate(): array
    {
        return [
            'primary care owner' => [LegalEntity::TYPE_PRIMARY_CARE, Role::OWNER],
            'outpatient owner' => [LegalEntity::TYPE_OUTPATIENT, Role::OWNER],
            'emergency owner' => [LegalEntity::TYPE_EMERGENCY, Role::OWNER],
            'pharmacy owner' => [LegalEntity::TYPE_PHARMACY, Role::OWNER],
            'admin' => [LegalEntity::TYPE_PRIMARY_CARE, Role::ADMIN],
            'pharmacy owner role' => [LegalEntity::TYPE_PHARMACY, Role::PHARMACY_OWNER],
        ];
    }

    /**
     * Each role not allowed to create services once, in a legal entity type that holds it, and the owner in each
     * legal entity type not allowed to create them.
     *
     * @return array
     */
    public static function rolesDeniedToCreate(): array
    {
        return [
            'reorganization owner' => [LegalEntity::TYPE_PRIMARY_CARE, Role::REORGANIZATION_OWNER],
            'hr' => [LegalEntity::TYPE_PRIMARY_CARE, Role::HR],
            'doctor' => [LegalEntity::TYPE_PRIMARY_CARE, Role::DOCTOR],
            'assistant' => [LegalEntity::TYPE_PRIMARY_CARE, Role::ASSISTANT],
            'receptionist' => [LegalEntity::TYPE_PRIMARY_CARE, Role::RECEPTIONIST],
            'med admin' => [LegalEntity::TYPE_PRIMARY_CARE, Role::MED_ADMIN],
            'laborant' => [LegalEntity::TYPE_PRIMARY_CARE, Role::LABORANT],
            'specialist' => [LegalEntity::TYPE_OUTPATIENT, Role::SPECIALIST],
            'med coordinator' => [LegalEntity::TYPE_OUTPATIENT, Role::MED_COORDINATOR],
            'pharmacist' => [LegalEntity::TYPE_PHARMACY, Role::PHARMACIST],
            'msp owner' => [LegalEntity::TYPE_MSP, Role::OWNER],
            'msp limited owner' => [LegalEntity::TYPE_MSP_LIMITED, Role::OWNER],
        ];
    }

    /**
     * The role may create healthcare services in a legal entity of this type.
     *
     * @param  string  $legalEntityType
     * @param  Role  $role
     * @return void
     */
    #[DataProvider('rolesAllowedToCreate')]
    public function test_role_can_create_service(string $legalEntityType, Role $role): void
    {
        $user = $this->userWithRole(LegalEntity::factory()->ofType($legalEntityType)->create(), $role);

        $this->assertTrue($user->can('create', HealthcareService::class));
    }

    /**
     * The role may not create healthcare services in a legal entity of this type.
     *
     * @param  string  $legalEntityType
     * @param  Role  $role
     * @return void
     */
    #[DataProvider('rolesDeniedToCreate')]
    public function test_role_cannot_create_service(string $legalEntityType, Role $role): void
    {
        $user = $this->userWithRole(LegalEntity::factory()->ofType($legalEntityType)->create(), $role);

        $this->assertFalse($user->can('create', HealthcareService::class));
    }

    /**
     * A suspended legal entity still creates healthcare services.
     *
     * @return void
     */
    public function test_owner_of_suspended_legal_entity_can_create_service(): void
    {
        $user = $this->userWithRole(LegalEntity::factory()->create(['status' => 'SUSPENDED']), Role::OWNER);

        $this->assertTrue($user->can('create', HealthcareService::class));
    }

    /**
     * A closed legal entity creates no healthcare services, even for a role that holds the scope.
     *
     * @return void
     */
    public function test_owner_of_closed_legal_entity_cannot_create_service(): void
    {
        $user = $this->userWithRole(LegalEntity::factory()->create(['status' => 'CLOSED']), Role::OWNER);

        $this->assertFalse($user->can('create', HealthcareService::class));
    }
}
