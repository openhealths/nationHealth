<?php

declare(strict_types=1);

namespace Tests\Feature\MedicalEvents;

use App\Models\CarePlan;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\User;
use App\Policies\CarePlanPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MedicalEventAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_care_plan_policy_denies_a_plan_from_another_legal_entity(): void
    {
        [$user, $homeEntity] = $this->doctorInEntity();
        $this->instance('legalEntity', $homeEntity);

        $foreignEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $homeEntity->legalEntityTypeId,
            'is_active' => true,
        ]);

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Foreign',
            'last_name' => 'Patient',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => Employee::query()->where('legal_entity_id', $homeEntity->id)->value('id'),
            'legal_entity_id' => $foreignEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Foreign plan',
            'status' => 'new',
        ]);

        $this->actingAs($user);

        $policy = new CarePlanPolicy();
        $this->assertTrue($policy->view($user, $carePlan)->denied());
        $this->assertTrue($policy->manage($user, $carePlan)->denied());
        $this->assertTrue(Gate::forUser($user)->denies('view', $carePlan));
        $this->assertTrue(Gate::forUser($user)->denies('manage', $carePlan));
    }

    public function test_show_http_route_404s_a_plan_from_another_legal_entity(): void
    {
        [$user, $homeEntity] = $this->doctorInEntity();
        $this->instance('legalEntity', $homeEntity);

        $foreignEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $homeEntity->legalEntityTypeId,
            'is_active' => true,
        ]);

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Foreign',
            'last_name' => 'Http',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => Employee::query()->where('legal_entity_id', $homeEntity->id)->value('id'),
            'legal_entity_id' => $foreignEntity->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Foreign plan',
            'status' => 'new',
        ]);

        $this->actingAs($user, 'ehealth');

        $this->get(route('care-plans.show', [
            'legalEntity' => $homeEntity,
            'carePlan' => $carePlan->id,
        ]))->assertNotFound();
    }

    /**
     * @return array{0: User, 1: LegalEntity}
     */
    private function doctorInEntity(): array
    {
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Auth',
            'last_name' => 'Doctor',
            'tax_id' => '3344556677',
            'birth_date' => '1975-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'authz_'.Str::random(6).'@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr Authz',
            'employee_type' => \App\Enums\User\Role::DOCTOR->value,
            'status' => \App\Enums\Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);
        $user->employees()->attach($employee->id);

        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        $this->grantMedicalEventAbilities($user);

        return [$user, $legalEntity];
    }
}
