<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Models\Person\Person;
use App\Models\Declaration;
use App\Models\LegalEntity;
use App\Models\Employee\Employee;
use App\Models\Relations\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Concerns\MocksBasicDictionaries;
use Mockery;
use Illuminate\Support\Str;
use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;

class CarePlanIndexTest extends TestCase
{
    use DatabaseTransactions;
    use MocksBasicDictionaries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockBasicDictionaries();

        config(['cipher.api.domain' => 'https://cipher.invalid']);
        \Illuminate\Support\Facades\Cache::put('knedp_certificate_authority', [], 60);
    }

    protected function migrateDatabases()
    {
        $this->artisan('migrate:fresh', [
            '--path' => [
                database_path('migrations'),
                database_path('migrations/install'),
                database_path('migrations/update/0_1'),
            ],
            '--realpath' => true,
        ]);
    }

    public function test_care_plan_index_sync_flow(): void
    {
        // 1. Setup initial data
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $legalEntity);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Doctor',
            'last_name' => 'Who',
            'tax_id' => '1234567890',
            'birth_date' => '1970-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'doctor@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. Who',
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

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'John',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $division = \App\Models\Division::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Main Division',
            'type' => 'CLINIC',
            'status' => 'ACTIVE',
            'email' => 'division@example.com',
            'mountain_group' => false,
            'legal_entity_id' => $legalEntity->id,
        ]);

        $declarationRequest = \App\Models\DeclarationRequest::create([
            'uuid' => (string) Str::uuid(),
            'status' => \App\Enums\Declaration\RequestStatus::SIGNED,
            'declaration_number' => '123',
            'employee_id' => $employee->id,
            'person_id' => $person->id,
            'legal_entity_id' => $legalEntity->id,
            'division_id' => $division->id,
        ]);

        // Create an active declaration linking person and employee
        Declaration::create([
            'uuid' => (string) Str::uuid(),
            'declaration_number' => '123',
            'declaration_request_id' => $declarationRequest->id,
            'employee_id' => $employee->id,
            'person_id' => $person->id,
            'legal_entity_id' => $legalEntity->id,
            'division_id' => $division->id,
            'is_active' => true,
            'status' => \App\Enums\Declaration\Status::ACTIVE,
            'sync_status' => \App\Enums\JobStatus::COMPLETED,
            'end_date' => now()->addYears(1)->format('Y-m-d'),
            'inserted_at' => now(),
            'signed_at' => now(),
            'start_date' => now()->format('Y-m-d'),
        ]);

        if (config('permission.teams')) {
            setPermissionsTeamId($legalEntity->id);
        }

        $this->actingAs($user);

        // 2. Mock EHealth APIs
        $mockCarePlanApi = Mockery::mock(\App\Classes\eHealth\Api\CarePlan::class)->makePartial();
        $mockDeclarationApi = Mockery::mock(\App\Classes\eHealth\Api\Declaration::class);
        $mockActivityApi = Mockery::mock(\App\Classes\eHealth\Api\CarePlanActivity::class)->makePartial();

        $this->instance(\App\Classes\eHealth\Api\CarePlan::class, $mockCarePlanApi);
        $this->instance(\App\Classes\eHealth\Api\Declaration::class, $mockDeclarationApi);
        $this->instance(\App\Classes\eHealth\Api\CarePlanActivity::class, $mockActivityApi);

        // Mock declarations sync for patient
        $decSyncResponse = Mockery::mock(EHealthResponse::class);
        $decSyncResponse->shouldReceive('validate')->andReturn([]);
        $mockDeclarationApi->shouldReceive('getMany')->with(['person_id' => $person->uuid], Mockery::any())->andReturn($decSyncResponse);

        // Mock care plans sync for patient
        $carePlanUuid = (string) Str::uuid();
        $resource = json_decode(file_get_contents(base_path('tests/Fixtures/CarePlan/plan.json')), true, flags: JSON_THROW_ON_ERROR);
        $resource['id'] = $carePlanUuid;
        $resource['title'] = 'Synced Care Plan';
        $resource['author']['identifier']['value'] = $employee->uuid;
        $carePlanData = [$resource];

        $cpSyncResponse = Mockery::mock(EHealthResponse::class);
        $cpSyncResponse->shouldReceive('getData')->andReturn($carePlanData);
        $cpSyncResponse->shouldReceive('getPaging')->andReturn(['total_pages' => 1]);
        $detailsResponse = Mockery::mock(EHealthResponse::class);
        $detailsResponse->shouldReceive('validate')->andReturn($resource);
        $mockCarePlanApi->shouldReceive('getDetails')->with($person->uuid, $carePlanUuid)->andReturn($detailsResponse);
        $mockCarePlanApi->shouldReceive('getBySearchParams')->with($person->uuid, ['page' => 1])->andReturn($cpSyncResponse);

        // Mock CarePlanActivity summary sync (called inside syncActivities)
        $activitySummaryResponse = Mockery::mock(EHealthResponse::class);
        $activitySummaryResponse->shouldReceive('getData')->andReturn([]);
        $activitySummaryResponse->shouldReceive('getPaging')->andReturn([]);
        $activitySummaryResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockActivityApi->shouldReceive('getSummary')->andReturn($activitySummaryResponse);

        // 3. Test CarePlanIndex Livewire component
        Livewire::test(\App\Livewire\CarePlan\CarePlanIndex::class)
            ->call('sync')
            ->assertHasNoErrors();

        // 4. Verify Care Plan is synchronized to the database
        $this->assertDatabaseHas('care_plans', [
            'uuid' => $carePlanUuid,
            'title' => 'Synced Care Plan',
            'person_id' => $person->id,
        ]);
    }

    public function test_new_care_plan_button_links_to_create_form(): void
    {
        ['legalEntity' => $legalEntity, 'user' => $user] = $this->createCarePlanIndexAuthContext();

        $this->actingAs($user);

        Livewire::test(\App\Livewire\CarePlan\CarePlanIndex::class)
            ->assertSeeHtml(route('care-plans.create', $legalEntity));
    }

    public function test_care_plan_create_page_loads_without_person(): void
    {
        ['legalEntity' => $legalEntity, 'user' => $user] = $this->createCarePlanIndexAuthContext();

        $this->actingAs($user);

        Livewire::test(\App\Livewire\CarePlan\CarePlanCreate::class, [
            'legalEntity' => $legalEntity,
        ])
            ->assertSet('personId', 0)
            ->assertSet('allowsPatientChange', true)
            ->assertSet('patientFullName', __('care-plan.new_care_plan'))
            ->assertOk();
    }

    public function test_care_plan_create_patient_search_loads_encounters(): void
    {
        ['legalEntity' => $legalEntity, 'user' => $user] = $this->createCarePlanIndexAuthContext();

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'birth_date' => '2001-02-23',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $person->names()->create([
            'first_name' => 'Якийсь',
            'last_name' => 'Пацієнт',
            'language' => 'uk'
        ]);

        $identifierId = \App\Models\MedicalEvents\Sql\Identifier::create(['value' => (string) Str::uuid()])->id;
        $codingId = \App\Models\MedicalEvents\Sql\Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id;
        $ccId = \App\Models\MedicalEvents\Sql\CodeableConcept::create()->id;

        $encounter = \App\Models\MedicalEvents\Sql\Encounter::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'status' => 'finished',
            'episode_id' => $identifierId,
            'class_id' => $codingId,
            'type_id' => $ccId,
            'ehealth_inserted_at' => null,
        ]);

        $syncedEncounter = $encounter->replicate();
        $syncedEncounter->uuid = (string) Str::uuid();
        $syncedEncounter->setAttribute('ehealth_inserted_at', now());
        $syncedEncounter->save();

        $draftEncounter = $encounter->replicate();
        $draftEncounter->uuid = (string) Str::uuid();
        $draftEncounter->status = 'draft';
        $draftEncounter->save();

        $this->actingAs($user);

        Livewire::test(\App\Livewire\CarePlan\CarePlanCreate::class, [
            'legalEntity' => $legalEntity,
        ])
            ->set('patientSearch.firstName', 'Пацієнт')
            ->set('patientSearch.lastName', 'Якийсь')
            ->set('patientSearch.birthDate', '23.02.2001')
            ->call('searchForPatient')
            ->assertCount('patientSearchResults', 1)
            ->call('selectPatient', $person->id)
            ->assertSet('personId', $person->id)
            ->assertCount('availableEncounters', 2)
            ->assertSet(
                'availableEncounters',
                static fn (array $availableEncounters): bool =>
                in_array($encounter->uuid, array_column($availableEncounters, 'uuid'), true)
                && in_array($syncedEncounter->uuid, array_column($availableEncounters, 'uuid'), true)
                && !in_array($draftEncounter->uuid, array_column($availableEncounters, 'uuid'), true)
            );
    }

    /**
     * @return array{legalEntity: LegalEntity, user: User}
     */
    private function createCarePlanIndexAuthContext(): array
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
        $this->instance('legalEntity', $legalEntity);

        $party = Party::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Doctor',
            'last_name' => 'Who',
            'tax_id' => '1234567890',
            'birth_date' => '1970-01-01',
            'gender' => 'MALE',
        ]);

        $user = User::create([
            'uuid' => (string) Str::uuid(),
            'email' => 'doctor-index@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. Who',
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

        return [
            'legalEntity' => $legalEntity,
            'user' => $user,
        ];
    }
}
