<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Division;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\User;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The employee attributed to an eHealth request must be derivable from the arguments alone,
 * so the same resolution holds in HTTP, queued jobs and tests.
 */
class ResolvesEmployeeContextTest extends TestCase
{
    use DatabaseTransactions;

    private LegalEntity $legalEntity;

    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();

        $this->legalEntity = $this->createLegalEntity();

        $this->person = Person::create([
            'uuid' => (string) Str::uuid(),
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);
    }

    public function test_prefers_the_encounter_performer_over_the_acting_employee(): void
    {
        $performer = $this->createEmployee('Performer');
        $acting = $this->createEmployee('Acting');
        $encounter = $this->createEncounter($performer->uuid);
        $carePlan = $this->createCarePlan($performer, $encounter);

        $context = app(ReferralRequestLifecycleService::class)
            ->resolveEmployeeContext($carePlan, null, $acting->id);

        $this->assertSame($performer->id, $context['employee_id']);
        $this->assertSame($performer->uuid, $context['employee_uuid']);
        $this->assertSame($this->legalEntity->uuid, $context['legal_entity_uuid']);
    }

    public function test_falls_back_to_the_activity_author_then_to_the_acting_employee(): void
    {
        $author = $this->createEmployee('Author');
        $acting = $this->createEmployee('Acting');
        $encounter = $this->createEncounter(null);
        $carePlan = $this->createCarePlan($author, $encounter);

        $activity = CarePlanActivity::create([
            'uuid' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->id,
            'author_id' => $author->id,
            'status' => 'scheduled',
            'kind' => 'service_request',
        ]);

        $service = app(ReferralRequestLifecycleService::class);

        $this->assertSame(
            $author->id,
            $service->resolveEmployeeContext($carePlan, $activity, $acting->id)['employee_id']
        );

        $this->assertSame(
            $acting->id,
            $service->resolveEmployeeContext($carePlan, null, $acting->id)['employee_id']
        );
    }

    public function test_resolves_nothing_when_the_caller_names_no_acting_employee(): void
    {
        $doctor = $this->createEmployee('Logged in doctor');
        $encounter = $this->createEncounter(null);
        $carePlan = $this->createCarePlan($doctor, $encounter);

        // Being logged in must not decide who a request is attributed to.
        $this->actingAs(User::findOrFail($doctor->user_id));

        $context = app(ReferralRequestLifecycleService::class)->resolveEmployeeContext($carePlan);

        $this->assertNull($context['employee_id']);
        $this->assertNull($context['employee_uuid']);
        $this->assertNull($context['legal_entity_uuid']);
    }

    public function test_encounter_context_uses_the_acting_employee_without_a_session(): void
    {
        $acting = $this->createEmployee('Acting');
        $encounter = $this->createEncounter(null);

        $service = app(ReferralRequestLifecycleService::class);

        $this->assertSame(
            $acting->id,
            $service->resolveEncounterEmployeeContext($encounter, $acting->id)['employee_id']
        );

        $this->assertNull($service->resolveEncounterEmployeeContext($encounter)['employee_id']);
    }

    public function test_translates_the_encounter_division_identifier_to_a_local_division(): void
    {
        $divisionUuid = (string) Str::uuid();

        $division = Division::create([
            'uuid' => $divisionUuid,
            'legal_entity_id' => $this->legalEntity->id,
            'name' => 'Division',
            'type' => 'CLINIC',
            'status' => 'ACTIVE',
            'email' => 'division-'.Str::random(8).'@example.com',
        ]);

        $encounter = $this->createEncounter(null);
        $encounter->update([
            'division_id' => Identifier::create(['value' => $divisionUuid])->id,
        ]);

        $context = app(ReferralRequestLifecycleService::class)
            ->resolveEncounterEmployeeContext($encounter->fresh(['division']));

        // encounters.division_id is an identifier id; request tables expect a divisions id.
        $this->assertSame($division->id, $context['division_id']);
    }

    public function test_leaves_the_division_unset_when_the_encounter_division_is_unknown_locally(): void
    {
        $encounter = $this->createEncounter(null);
        $encounter->update([
            'division_id' => Identifier::create(['value' => (string) Str::uuid()])->id,
        ]);

        $context = app(ReferralRequestLifecycleService::class)
            ->resolveEncounterEmployeeContext($encounter->fresh(['division']));

        $this->assertNull($context['division_id']);
    }

    private function createLegalEntity(): LegalEntity
    {
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        return LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
    }

    private function createEmployee(string $name): Employee
    {
        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => $name,
            'last_name' => 'Test',
            'tax_id' => (string) random_int(1000000000, 9999999999),
            'birth_date' => '1980-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'employee-'.Str::random(8).'@example.com',
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        return Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => $name,
            'employee_type' => \App\Enums\User\Role::DOCTOR->value,
            'status' => \App\Enums\Status::APPROVED->value,
            'legal_entity_id' => $this->legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'user_id' => $user->id,
            'party_id' => $party->id,
        ]);
    }

    private function createEncounter(?string $performerUuid): Encounter
    {
        $encounter = Encounter::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'status' => 'finished',
            'episode_id' => Identifier::create(['value' => (string) Str::uuid()])->id,
            'class_id' => Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id,
            'type_id' => CodeableConcept::create()->id,
            'ehealth_inserted_at' => now(),
        ]);

        if ($performerUuid !== null) {
            $encounter->update([
                'performer_id' => Identifier::create(['value' => $performerUuid])->id,
            ]);
        }

        return $encounter->fresh(['performer']);
    }

    private function createCarePlan(Employee $author, Encounter $encounter): CarePlan
    {
        return CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $this->person->id,
            'author_id' => $author->id,
            'legal_entity_id' => $this->legalEntity->id,
            'encounter_id' => $encounter->id,
            'period_start' => now()->format('Y-m-d'),
            'title' => 'Context plan',
            'status' => 'active',
        ]);
    }
}
