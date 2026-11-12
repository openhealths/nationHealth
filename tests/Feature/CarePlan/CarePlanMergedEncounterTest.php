<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Enums\Person\MergedPersonStatus;
use App\Models\CarePlan;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\EncounterDiagnose;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\MergedPerson;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Composition\CompositionTestFixtures;
use Tests\Support\CarePlanCreateHarness;
use Tests\TestCase;

class CarePlanMergedEncounterTest extends TestCase
{
    use CompositionTestFixtures;
    use DatabaseTransactions {
        beginDatabaseTransaction as beginIsolatedTransaction;
    }

    public function beginDatabaseTransaction(): void
    {
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->beginIsolatedTransaction();
    }

    public function test_confirmed_merge_supports_selection_diagnosis_category_validation(): void
    {
        ['person' => $person, 'employee' => $employee] = $this->compositionFixture(scopes: ['encounter:read', 'condition:read']);
        $preperson = $this->compositionPreperson();
        $merge = $this->merge($person, $preperson);
        $this->grantPatientAccess($person, $employee);
        [$encounter, $condition] = $this->encounter($preperson);
        Http::preventStrayRequests();

        $repository = Repository::carePlan();
        $choices = (new CarePlanCreateHarness())->carePlanEncounterChoices($person->id);
        $this->assertSame([$encounter->uuid], array_column($choices, 'uuid'));
        $this->assertStringContainsString('Попередній епізод', $choices[0]['label']);
        $this->assertSame($encounter->id, $repository->findEncounter($encounter->uuid, $person->id)?->id);
        $this->assertSame($encounter->id, $repository->findEncounter($encounter->id, $person->id)?->id);
        $this->assertSame($condition->id, $repository->findCondition($condition->uuid, $person->id)?->id);
        $this->assertSame('OUTPATIENT', $repository->encounterTermsOfService($encounter->uuid, $person->id));
        $this->assertSame('E10.9', $repository->encounterData($encounter->uuid, $person->id)['addresses'][0]['coding'][0]['code']);
        $categories = $repository->categoryCodesForEncounter($encounter->uuid, $person->id);
        $this->assertNotEmpty($categories);
        $repository->assertCategoryForEncounter($categories[0], $encounter->uuid, $person->id);
        $this->assertSame($preperson->id, $encounter->fresh()->getAttribute('preperson_id'));
        $this->assertNull($encounter->fresh()->getAttribute('person_id'));
        $this->assertSame($preperson->id, $condition->fresh()->prepersonId);
        Http::assertNothingSent();

        $merge->update(['status' => MergedPersonStatus::DECLINED]);
        $this->assertSame([], (new CarePlanCreateHarness())->carePlanEncounterChoices($person->id));
        $this->assertNull($repository->findEncounter($encounter->uuid, $person->id));
        $this->assertNull($repository->findCondition($condition->uuid, $person->id));
    }

    public function test_merge_does_not_grant_patient_access_and_denial_has_no_side_effects(): void
    {
        ['person' => $person, 'employee' => $employee] = $this->compositionFixture(scopes: ['encounter:read', 'condition:read']);
        $preperson = $this->compositionPreperson();
        $this->merge($person, $preperson);
        [$encounter, $condition] = $this->encounter($preperson);
        Http::preventStrayRequests();
        $before = [$encounter->fresh()->getAttributes(), $condition->fresh()->getAttributes(), CarePlan::count(), Approval::count()];

        $this->assertSame([], (new CarePlanCreateHarness())->carePlanEncounterChoices($person->id));
        $this->assertNull(Repository::carePlan()->findEncounter($encounter->uuid, $person->id));
        $this->assertNull(Repository::carePlan()->findCondition($condition->uuid, $person->id));
        $this->assertSame([], Repository::carePlan()->encounterData($encounter->uuid, $person->id)['addresses']);
        $this->assertSame($before, [$encounter->fresh()->getAttributes(), $condition->fresh()->getAttributes(), CarePlan::count(), Approval::count()]);
        Http::assertNothingSent();

        $approval = $this->grantPatientAccess($person, $employee);
        $this->assertCount(1, (new CarePlanCreateHarness())->carePlanEncounterChoices($person->id));
        $approval->update(['expires_at' => now()->subMinute()]);
        $this->assertSame([], (new CarePlanCreateHarness())->carePlanEncounterChoices($person->id));
        $this->assertNull(Repository::carePlan()->findEncounter($encounter->uuid, $person->id));
    }

    public function test_other_patients_clinics_and_unfinished_merged_encounters_are_excluded(): void
    {
        ['person' => $person, 'employee' => $employee] = $this->compositionFixture(scopes: ['encounter:read', 'condition:read']);
        $preperson = $this->compositionPreperson();
        $this->merge($person, $preperson);
        $this->grantPatientAccess($person, $employee);
        [$eligible] = $this->encounter($preperson);
        [$unmerged] = $this->encounter($this->compositionPreperson());
        [$foreign] = $this->encounter($preperson, (string) Str::uuid());
        [$unfinished] = $this->encounter($preperson);
        $unfinished->update(['status' => 'draft']);
        [$closed] = $this->encounter($preperson);
        Episode::whereUuid($closed->episode->value)->update(['status' => 'closed']);
        [$own] = $this->encounter($person);

        $this->assertEqualsCanonicalizing([$eligible->uuid, $own->uuid], array_column((new CarePlanCreateHarness())->carePlanEncounterChoices($person->id), 'uuid'));
        foreach ([$unmerged, $foreign, $unfinished, $closed] as $rejected) {
            $this->assertNull(Repository::carePlan()->findEncounter($rejected->uuid, $person->id));
        }
    }

    public function test_confirmed_person_merge_uses_the_same_selection_and_scope_checks(): void
    {
        ['person' => $person, 'employee' => $employee] = $this->compositionFixture(scopes: ['encounter:read', 'condition:read']);
        $previous = Person::create(['uuid' => (string) Str::uuid(), 'gender' => 'MALE']);
        MergedPerson::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $person->id,
            'merged_uuid' => $previous->uuid, 'merged_person_id' => $previous->id,
            'status' => MergedPersonStatus::MERGED, 'ehealth_inserted_at' => now(), 'ehealth_updated_at' => now(),
        ]);
        $this->grantPatientAccess($person, $employee);
        [$encounter] = $this->encounter($previous);

        $this->assertSame([$encounter->uuid], array_column((new CarePlanCreateHarness())->carePlanEncounterChoices($person->id), 'uuid'));
        $this->assertSame($encounter->id, Repository::carePlan()->findEncounter($encounter->uuid, $person->id)?->id);
    }

    public function test_patient_approval_does_not_replace_encounter_and_condition_read_scopes(): void
    {
        ['person' => $person, 'employee' => $employee] = $this->compositionFixture(scopes: []);
        $preperson = $this->compositionPreperson();
        $this->merge($person, $preperson);
        $this->grantPatientAccess($person, $employee);
        [$encounter, $condition] = $this->encounter($preperson);
        Http::preventStrayRequests();

        $this->assertSame([], (new CarePlanCreateHarness())->carePlanEncounterChoices($person->id));
        $this->assertNull(Repository::carePlan()->findEncounter($encounter->uuid, $person->id));
        $this->assertNull(Repository::carePlan()->findCondition($condition->uuid, $person->id));
        Http::assertNothingSent();
    }

    public function test_a_primary_diagnosis_from_another_preperson_cannot_be_used_for_the_plan(): void
    {
        ['person' => $person, 'employee' => $employee] = $this->compositionFixture(scopes: ['encounter:read', 'condition:read']);
        $preperson = $this->compositionPreperson();
        $this->merge($person, $preperson);
        $this->grantPatientAccess($person, $employee);
        [$encounter, $condition] = $this->encounter($preperson);
        $condition->update(['preperson_id' => $this->compositionPreperson()->id]);

        $this->assertSame([], Repository::carePlan()->encounterData($encounter->uuid, $person->id)['addresses']);
        $this->assertSame([], Repository::carePlan()->categoryCodesForEncounter($encounter->uuid, $person->id));
    }

    private function merge(Person $person, Preperson $preperson): MergedPerson
    {
        return MergedPerson::create([
            'uuid' => (string) Str::uuid(), 'person_id' => $person->id,
            'merged_uuid' => $preperson->uuid, 'merged_preperson_id' => $preperson->id,
            'status' => MergedPersonStatus::MERGED, 'ehealth_inserted_at' => now(), 'ehealth_updated_at' => now(),
        ]);
    }

    private function grantPatientAccess(Person $person, Employee $employee): Approval
    {
        return Approval::create([
            'uuid' => (string) Str::uuid(), 'approvable_type' => Person::class, 'approvable_id' => $person->id,
            'granted_to_id' => Identifier::create(['value' => $employee->uuid])->id, 'granted_to_type' => 'employee',
            'status' => 'active', 'is_verified' => true, 'expires_at' => now()->addHour(), 'access_level' => 'read',
        ]);
    }

    /** @return array{Encounter, Condition} */
    private function encounter(Person|Preperson $patient, ?string $organizationUuid = null): array
    {
        $owner = $patient instanceof Preperson ? ['preperson_id' => $patient->id] : ['person_id' => $patient->id];
        $episode = Episode::create([
            ...$owner, 'uuid' => (string) Str::uuid(), 'status' => 'active', 'name' => 'Попередній епізод',
            'managing_organization_id' => Identifier::create(['value' => $organizationUuid ?? legalEntity()->uuid])->id,
        ]);
        $class = MedicalEventsRepository::coding()->store(['system' => 'eHealth/encounter_classes', 'code' => 'AMB']);
        $type = MedicalEventsRepository::codeableConcept()->store(['coding' => [['system' => 'eHealth/encounter_types', 'code' => 'TEST']]]);
        $encounter = Encounter::create([
            ...$owner, 'uuid' => (string) Str::uuid(), 'status' => 'finished',
            'episode_id' => Identifier::create(['value' => $episode->uuid])->id,
            'class_id' => $class->id, 'type_id' => $type->id, 'ehealth_inserted_at' => now(),
        ]);
        $code = MedicalEventsRepository::codeableConcept()->store(['coding' => [['system' => 'eHealth/ICD10_AM/condition_codes', 'code' => 'E10.9']]]);
        $condition = Condition::create([
            ...$owner, 'uuid' => (string) Str::uuid(), 'primary_source' => true,
            'clinical_status' => 'active', 'verification_status' => 'confirmed', 'code_id' => $code->id,
            'context_id' => Identifier::create(['value' => $encounter->uuid])->id, 'onset_date' => now(),
        ]);
        $role = MedicalEventsRepository::codeableConcept()->store(['coding' => [['system' => 'eHealth/diagnosis_roles', 'code' => 'primary']]]);
        EncounterDiagnose::create([
            'encounter_id' => $encounter->id, 'condition_id' => Identifier::create(['value' => $condition->uuid])->id,
            'role_id' => $role->id, 'rank' => 1,
        ]);

        return [$encounter, $condition];
    }
}
