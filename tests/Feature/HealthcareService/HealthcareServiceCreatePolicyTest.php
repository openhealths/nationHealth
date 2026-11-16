<?php

declare(strict_types=1);

namespace Tests\Feature\HealthcareService;

use App\Enums\User\Role;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who may create healthcare services: the role of the user, the type and the status of the legal entity.
 *
 * The expected roles are written out here on purpose instead of being read from the configuration, so an accidental
 * change of the role scopes or of the roles allowed for a legal entity type fails this test.
 */
class HealthcareServiceCreatePolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Roles with the healthcare service write scope in the legal entity types allowed to create services.
     *
     * @return array
     */
    public static function rolesAllowedToCreate(): array
    {
        return [
            'primary care owner' => [LegalEntity::TYPE_PRIMARY_CARE, Role::OWNER],
            'primary care admin' => [LegalEntity::TYPE_PRIMARY_CARE, Role::ADMIN],
            'outpatient owner' => [LegalEntity::TYPE_OUTPATIENT, Role::OWNER],
            'outpatient admin' => [LegalEntity::TYPE_OUTPATIENT, Role::ADMIN],
            'emergency owner' => [LegalEntity::TYPE_EMERGENCY, Role::OWNER],
            'emergency admin' => [LegalEntity::TYPE_EMERGENCY, Role::ADMIN],
            'pharmacy pharmacy owner' => [LegalEntity::TYPE_PHARMACY, Role::PHARMACY_OWNER],
            'pharmacy owner' => [LegalEntity::TYPE_PHARMACY, Role::OWNER]
        ];
    }

    /**
     * Roles without the write scope, and roles of the legal entity types not allowed to create services.
     * Only roles that can be held in the legal entity type are listed.
     *
     * @return array
     */
    public static function rolesDeniedToCreate(): array
    {
        return [
            'primary care reorganization owner' => [LegalEntity::TYPE_PRIMARY_CARE, Role::REORGANIZATION_OWNER],
            'primary care hr' => [LegalEntity::TYPE_PRIMARY_CARE, Role::HR],
            'primary care doctor' => [LegalEntity::TYPE_PRIMARY_CARE, Role::DOCTOR],
            'primary care assistant' => [LegalEntity::TYPE_PRIMARY_CARE, Role::ASSISTANT],
            'primary care receptionist' => [LegalEntity::TYPE_PRIMARY_CARE, Role::RECEPTIONIST],
            'primary care med admin' => [LegalEntity::TYPE_PRIMARY_CARE, Role::MED_ADMIN],
            'primary care laborant' => [LegalEntity::TYPE_PRIMARY_CARE, Role::LABORANT],
            'outpatient reorganization owner' => [LegalEntity::TYPE_OUTPATIENT, Role::REORGANIZATION_OWNER],
            'outpatient hr' => [LegalEntity::TYPE_OUTPATIENT, Role::HR],
            'outpatient assistant' => [LegalEntity::TYPE_OUTPATIENT, Role::ASSISTANT],
            'outpatient specialist' => [LegalEntity::TYPE_OUTPATIENT, Role::SPECIALIST],
            'outpatient receptionist' => [LegalEntity::TYPE_OUTPATIENT, Role::RECEPTIONIST],
            'outpatient med admin' => [LegalEntity::TYPE_OUTPATIENT, Role::MED_ADMIN],
            'outpatient laborant' => [LegalEntity::TYPE_OUTPATIENT, Role::LABORANT],
            'outpatient med coordinator' => [LegalEntity::TYPE_OUTPATIENT, Role::MED_COORDINATOR],
            'emergency reorganization owner' => [LegalEntity::TYPE_EMERGENCY, Role::REORGANIZATION_OWNER],
            'emergency hr' => [LegalEntity::TYPE_EMERGENCY, Role::HR],
            'emergency specialist' => [LegalEntity::TYPE_EMERGENCY, Role::SPECIALIST],
            'emergency assistant' => [LegalEntity::TYPE_EMERGENCY, Role::ASSISTANT],
            'pharmacy pharmacist' => [LegalEntity::TYPE_PHARMACY, Role::PHARMACIST],
            'pharmacy hr' => [LegalEntity::TYPE_PHARMACY, Role::HR],
            'msp owner' => [LegalEntity::TYPE_MSP, Role::OWNER],
            'msp admin' => [LegalEntity::TYPE_MSP, Role::ADMIN],
            'msp hr' => [LegalEntity::TYPE_MSP, Role::HR],
            'msp limited owner' => [LegalEntity::TYPE_MSP_LIMITED, Role::OWNER],
            'msp limited admin' => [LegalEntity::TYPE_MSP_LIMITED, Role::ADMIN]
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

    /**
     * Build the schema the way a fresh installation does: install migrations hold the full current schema, update
     * migrations are only for upgrading existing databases.
     *
     * @return array
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--path' => database_path('migrations/install'),
            '--realpath' => true
        ];
    }

    /**
     * User working in the legal entity with the role, in the context the application sets for the legal entity's
     * pages. Fails when the role is not assigned, so a denied case can't pass only because the role is missing.
     *
     * @param  LegalEntity  $legalEntity
     * @param  Role  $role
     * @return User
     */
    private function userWithRole(LegalEntity $legalEntity, Role $role): User
    {
        $this->app->instance('legalEntity', $legalEntity);
        setPermissionsTeamId($legalEntity->id);

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->assertTrue($user->hasRole($role->value), "Role $role->value is not assigned");

        return $user;
    }
}
