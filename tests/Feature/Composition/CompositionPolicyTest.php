<?php

declare(strict_types=1);

namespace Tests\Feature\Composition;

use App\Enums\Composition\CompositionStatus;
use App\Enums\Composition\CompositionType;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Composition;
use App\Models\Relations\Party;
use App\Models\User;
use App\Policies\CompositionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Covers the two independent gates on every conclusion action: the eHealth scope, and
 * the type of legal entity the user is acting in (TV 3.8.1.1, 3.8.2.1).
 *
 * A birth conclusion is outpatient-only while a disability conclusion is allowed in
 * primary care too, so both entity types are exercised rather than assumed equivalent.
 */
class CompositionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', [
            '--path' => [
                database_path('migrations'),
                database_path('migrations/install'),
            ],
            '--realpath' => true,
        ]);
    }

    public function test_outpatient_specialist_with_scopes_may_create_both_conclusion_types(): void
    {
        ['user' => $user] = $this->actingInEntity(
            LegalEntity::TYPE_OUTPATIENT,
            ['composition:create'],
            Role::SPECIALIST
        );

        $policy = new CompositionPolicy();

        $this->assertTrue($policy->createNewborn($user)->allowed());
        $this->assertTrue($policy->createTempDisability($user)->allowed());
    }

    public function test_primary_care_doctor_may_create_a_disability_conclusion_but_not_a_birth_one(): void
    {
        ['user' => $user] = $this->actingInEntity(
            LegalEntity::TYPE_PRIMARY_CARE,
            ['composition:create'],
            Role::DOCTOR
        );

        $policy = new CompositionPolicy();

        $this->assertTrue($policy->createTempDisability($user)->allowed());
        $this->assertTrue(
            $policy->createNewborn($user)->denied(),
            'A birth conclusion must not be issuable from a primary care entity.'
        );
    }

    /**
     * TV 3.8.1.1 / 3.8.2.1 name role and entity type as a pair. Each of these users
     * satisfies one half of a permitted combination and none of them satisfies a whole
     * one, so every attempt has to be refused.
     */
    public function test_role_and_entity_type_are_required_as_a_pair(): void
    {
        $policy = new CompositionPolicy();

        ['user' => $outpatientDoctor] = $this->actingInEntity(
            LegalEntity::TYPE_OUTPATIENT,
            ['composition:create'],
            Role::DOCTOR
        );

        $this->assertTrue(
            $policy->createNewborn($outpatientDoctor)->denied(),
            'An outpatient DOCTOR is not a permitted author of a birth conclusion.'
        );
        $this->assertTrue(
            $policy->createTempDisability($outpatientDoctor)->denied(),
            'Outpatient disability conclusions are reserved for a SPECIALIST.'
        );

        ['user' => $primaryCareSpecialist] = $this->actingInEntity(
            LegalEntity::TYPE_PRIMARY_CARE,
            ['composition:create'],
            Role::SPECIALIST
        );

        $this->assertTrue(
            $policy->createTempDisability($primaryCareSpecialist)->denied(),
            'Primary care disability conclusions are reserved for a DOCTOR.'
        );
        $this->assertTrue($policy->createNewborn($primaryCareSpecialist)->denied());
    }

    public function test_a_user_without_a_clinical_employee_record_cannot_create_anything(): void
    {
        ['user' => $user, 'employee' => $employee] = $this->actingInEntity(
            LegalEntity::TYPE_OUTPATIENT,
            ['composition:create'],
            Role::SPECIALIST
        );

        // The scope and the entity type still line up; only the employee is gone.
        $employee->update(['is_active' => false]);

        $policy = new CompositionPolicy();

        $this->assertTrue($policy->createNewborn($user)->denied());
        $this->assertTrue($policy->createTempDisability($user)->denied());
    }

    public function test_pharmacy_may_not_create_or_list_conclusions_at_all(): void
    {
        ['user' => $user] = $this->actingInEntity(LegalEntity::TYPE_PHARMACY, [
            'composition:create',
            'composition:search',
        ], Role::SPECIALIST);

        $policy = new CompositionPolicy();

        $this->assertTrue($policy->viewAny($user)->denied());
        $this->assertTrue($policy->createNewborn($user)->denied());
        $this->assertTrue($policy->createTempDisability($user)->denied());
    }

    public function test_holding_the_right_entity_type_is_not_enough_without_the_scope(): void
    {
        ['user' => $user] = $this->actingInEntity(LegalEntity::TYPE_OUTPATIENT, [], Role::SPECIALIST);

        $policy = new CompositionPolicy();

        $this->assertTrue($policy->viewAny($user)->denied());
        $this->assertTrue($policy->createNewborn($user)->denied());
        $this->assertTrue($policy->createTempDisability($user)->denied());
    }

    public function test_only_the_author_may_sign_an_unsigned_conclusion(): void
    {
        ['user' => $user, 'employee' => $employee] = $this->actingInEntity(
            LegalEntity::TYPE_OUTPATIENT,
            ['composition:sign']
        );

        $policy = new CompositionPolicy();

        $own = $this->composition(CompositionStatus::PRELIMINARY, $employee->uuid);
        $someoneElses = $this->composition(CompositionStatus::PRELIMINARY, (string) Str::uuid());

        $this->assertTrue($policy->sign($user, $own)->allowed());
        $this->assertTrue($policy->sign($user, $someoneElses)->denied());
    }

    public function test_an_already_signed_conclusion_cannot_be_signed_again(): void
    {
        ['user' => $user, 'employee' => $employee] = $this->actingInEntity(
            LegalEntity::TYPE_OUTPATIENT,
            ['composition:sign']
        );

        $composition = $this->composition(CompositionStatus::FINAL, $employee->uuid);

        $this->assertTrue((new CompositionPolicy())->sign($user, $composition)->denied());
    }

    public function test_cancellation_requires_a_signed_conclusion_owned_by_the_caller(): void
    {
        ['user' => $user, 'employee' => $employee] = $this->actingInEntity(
            LegalEntity::TYPE_OUTPATIENT,
            ['composition:cancel']
        );

        $policy = new CompositionPolicy();

        $signed = $this->composition(CompositionStatus::FINAL, $employee->uuid);
        $draft = $this->composition(CompositionStatus::PRELIMINARY, $employee->uuid);
        $foreign = $this->composition(CompositionStatus::FINAL, (string) Str::uuid());

        $this->assertTrue($policy->cancel($user, $signed)->allowed());
        $this->assertTrue($policy->cancel($user, $draft)->denied());
        $this->assertTrue($policy->cancel($user, $foreign)->denied());
    }

    public function test_erln_resend_is_limited_to_failed_disability_conclusions(): void
    {
        ['user' => $user, 'employee' => $employee] = $this->actingInEntity(
            LegalEntity::TYPE_PRIMARY_CARE,
            ['composition:write']
        );

        $policy = new CompositionPolicy();

        $failed = $this->composition(CompositionStatus::FINAL, $employee->uuid, [
            'erln_status' => 'ERROR',
        ]);
        $succeeded = $this->composition(CompositionStatus::FINAL, $employee->uuid, [
            'erln_status' => 'DONE',
        ]);
        $birth = $this->composition(CompositionStatus::FINAL, $employee->uuid, [
            'type' => CompositionType::NEWBORN->value,
            'erln_status' => 'ERROR',
        ]);

        $this->assertTrue($policy->resendErln($user, $failed)->allowed());
        $this->assertTrue($policy->resendErln($user, $succeeded)->denied());
        $this->assertTrue(
            $policy->resendErln($user, $birth)->denied(),
            'A birth conclusion is never registered in the ERLN registry.'
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function composition(
        CompositionStatus $status,
        string $authorUuid,
        array $overrides = []
    ): Composition {
        $type = CompositionType::from($overrides['type'] ?? CompositionType::TEMP_DISABILITY->value);
        unset($overrides['type'], $overrides['author_uuid']);

        $typeConcept = \App\Models\MedicalEvents\Sql\CodeableConcept::create(['text' => null]);
        $typeConcept->coding()->create([
            'system' => 'COMPOSITION_TYPES',
            'code' => $type->value,
        ]);

        $author = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => $authorUuid]);

        return Composition::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'status' => $status->value,
            'type_id' => $typeConcept->id,
            'author_id' => $author->id,
        ], $overrides));
    }

    public function test_birth_conclusion_author_is_the_specialist_with_an_allowed_position(): void
    {
        ['user' => $user, 'legalEntity' => $legalEntity, 'employee' => $endocrinologist] = $this->actingInEntity(
            LegalEntity::TYPE_OUTPATIENT,
            ['composition:create'],
            Role::SPECIALIST,
            'P56'
        );

        $pediatrician = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Ольга Лікарівна',
            'employee_type' => Role::SPECIALIST->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'P8',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $user->partyId,
        ]);
        $user->employees()->attach($pediatrician->id);
        $user->unsetRelation('party');

        $author = $user->getCompositionAuthorEmployee(CompositionType::NEWBORN);

        $this->assertSame($pediatrician->uuid, $author?->uuid);
        $this->assertNotSame($endocrinologist->uuid, $author?->uuid);
        $this->assertTrue((new CompositionPolicy())->createNewborn($user)->allowed());
    }

    public function test_birth_conclusion_is_denied_when_the_only_specialist_position_is_forbidden(): void
    {
        ['user' => $user] = $this->actingInEntity(
            LegalEntity::TYPE_OUTPATIENT,
            ['composition:create'],
            Role::SPECIALIST,
            'P56'
        );

        $response = (new CompositionPolicy())->createNewborn($user);

        $this->assertTrue($response->denied());
        $this->assertSame(
            __('errors.ehealth.messages.illegal_author_position'),
            $response->message()
        );
    }

    public function test_single_role_pediatrician_is_not_overwritten_by_a_sibling_endocrinologist(): void
    {
        ['user' => $pediatricianUser, 'legalEntity' => $legalEntity] = $this->actingInEntity(
            LegalEntity::TYPE_OUTPATIENT,
            ['composition:create'],
            Role::SPECIALIST,
            'P8'
        );

        Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Ольга Лікарівна',
            'employee_type' => Role::SPECIALIST->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'P56',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => null,
            'party_id' => $pediatricianUser->partyId,
        ]);

        $pediatricianUser->unsetRelation('party');

        $author = $pediatricianUser->getCompositionAuthorEmployee(CompositionType::NEWBORN);

        $this->assertSame('P8', $author?->position);
        $this->assertTrue((new CompositionPolicy())->createNewborn($pediatricianUser)->allowed());
    }

    public function test_disability_conclusion_still_accepts_a_non_obstetric_specialist(): void
    {
        ['user' => $user] = $this->actingInEntity(
            LegalEntity::TYPE_OUTPATIENT,
            ['composition:create'],
            Role::SPECIALIST,
            'P56'
        );

        $this->assertTrue((new CompositionPolicy())->createTempDisability($user)->allowed());
    }

    /**
     * Put the user inside a legal entity of the given type, holding the given scopes.
     *
     * @param  list<string>  $scopes
     * @return array{legalEntity: LegalEntity, user: User, employee: Employee}
     */
    private function actingInEntity(
        string $type,
        array $scopes,
        Role $role = Role::DOCTOR,
        string $position = 'P10',
    ): array {
        $typeId = DB::table('legal_entity_types')->where('name', $type)->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => $type]);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Ольга',
            'last_name' => 'Лікарівна',
            'tax_id' => '1234567890',
            'birth_date' => '1985-05-05',
            'gender' => 'FEMALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            // The cross-matrix cases build several users inside one test.
            'email' => 'doctor+' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Ольга Лікарівна',
            'employee_type' => $role->value,
            'status' => Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => $role === Role::SPECIALIST && $position === 'P10' ? 'P8' : $position,
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);

        $user->employees()->attach($employee->id);

        $this->instance('legalEntity', $legalEntity);

        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        if ($scopes !== []) {
            $user->givePermissionToParent(
                ...array_map(
                    static fn (string $scope) => Permission::findOrCreate($scope, 'web'),
                    $scopes
                )
            );
        }

        return compact('legalEntity', 'user', 'employee');
    }
}
