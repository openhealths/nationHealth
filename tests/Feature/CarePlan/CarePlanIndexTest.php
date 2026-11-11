<?php

declare(strict_types=1);

namespace Tests\Feature\CarePlan;

use App\Classes\eHealth\Api\CarePlan;
use App\Classes\eHealth\Api\CarePlanActivity;
use App\Classes\eHealth\Api\Declaration as DeclarationApi;
use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Declaration\RequestStatus;
use App\Enums\Declaration\Status;
use App\Enums\JobStatus;
use App\Enums\Status as EnumsStatus;
use App\Enums\User\Role;
use App\Livewire\CarePlan\CarePlanCreate;
use App\Livewire\CarePlan\CarePlanIndex;
use App\Models\Declaration;
use App\Models\DeclarationRequest;
use App\Models\Division;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;

use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Models\Relations\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\Concerns\CreatesFhirCarePlans;
use Tests\Concerns\MocksBasicDictionaries;
use Tests\TestCase;

class CarePlanIndexTest extends TestCase
{
    use CreatesFhirCarePlans;
    use DatabaseTransactions;
    use MocksBasicDictionaries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockBasicDictionaries();

        config(['cipher.api.domain' => 'https://cipher.invalid']);
        Cache::put('knedp_certificate_authority', [], 60);
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
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

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
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. Who',
            'employee_type' => Role::DOCTOR->value,
            'status' => EnumsStatus::APPROVED->value,
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

        $division = Division::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Main Division',
            'type' => 'CLINIC',
            'status' => 'ACTIVE',
            'email' => 'division@example.com',
            'mountain_group' => false,
            'legal_entity_id' => $legalEntity->id,
        ]);

        $declarationRequest = DeclarationRequest::create([
            'uuid' => (string) Str::uuid(),
            'status' => RequestStatus::SIGNED,
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
            'status' => Status::ACTIVE,
            'sync_status' => JobStatus::COMPLETED,
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
        $this->grantMedicalEventAbilities($user);
        session()->put(config('ehealth.api.oauth.bearer_token'), 'synthetic-token');
        Queue::fake();
        $mockCarePlanApi = Mockery::mock(CarePlan::class)->makePartial();
        $mockDeclarationApi = Mockery::mock(DeclarationApi::class);
        $mockActivityApi = Mockery::mock(CarePlanActivity::class)->makePartial();

        $this->instance(CarePlan::class, $mockCarePlanApi);
        $mockCarePlanApi->shouldReceive('withToken')->andReturnSelf();
        $this->instance(DeclarationApi::class, $mockDeclarationApi);
        $this->instance(CarePlanActivity::class, $mockActivityApi);

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
        $cpSyncResponse->shouldReceive('validate')->andReturn($carePlanData);
        $cpSyncResponse->shouldReceive('getPaging')->andReturn(['total_pages' => 1]);
        $cpSyncResponse->shouldReceive('isNotLast')->andReturn(false);
        $detailsResponse = Mockery::mock(EHealthResponse::class);
        $detailsResponse->shouldReceive('validate')->andReturn($resource);
        $mockCarePlanApi->shouldReceive('getDetails')->with($person->uuid, $carePlanUuid)->andReturn($detailsResponse);
        $mockCarePlanApi->shouldReceive('getBySearchParams')->with($person->uuid, ['page' => 1])->andReturn($cpSyncResponse);

        // Mock CarePlanActivity summary sync (called inside syncActivities)
        $activitySummaryResponse = Mockery::mock(EHealthResponse::class);
        $activitySummaryResponse->shouldReceive('validate')->andReturn([]);
        $activitySummaryResponse->shouldReceive('getPaging')->andReturn([]);
        $activitySummaryResponse->shouldReceive('getStatusCode')->andReturn(200);
        $mockActivityApi->shouldReceive('getSummary')->andReturn($activitySummaryResponse);

        // 3. Test CarePlanIndex Livewire component
        Livewire::test(CarePlanIndex::class)
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

        Livewire::test(CarePlanIndex::class)
            ->assertSeeHtml(route('care-plans.create', $legalEntity));
    }

    public function test_care_plan_create_page_loads_without_person(): void
    {
        ['legalEntity' => $legalEntity, 'user' => $user] = $this->createCarePlanIndexAuthContext();

        $this->actingAs($user);

        Livewire::test(CarePlanCreate::class, [
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
        $employee = $user->employees()->firstOrFail();
        $this->grantCarePlanEmployeeRole($employee, 'OUTPATIENT');

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

        $episodeUuid = (string) Str::uuid();
        $owner = Identifier::create(['value' => $legalEntity->uuid]);
        Episode::create([
            'uuid' => $episodeUuid,
            'person_id' => $person->id,
            'status' => 'active',
            'name' => 'Synthetic patient search episode',
            'managing_organization_id' => $owner->id,
        ]);
        $identifierId = Identifier::create(['value' => $episodeUuid])->id;
        $codingId = Coding::create(['code' => 'AMB', 'system' => 'eHealth/encounter_classes'])->id;
        $ccId = CodeableConcept::create()->id;

        $encounter = Encounter::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'status' => 'finished',
            'episode_id' => $identifierId,
            'class_id' => $codingId,
            'type_id' => $ccId,
            'performer_id' => Identifier::create(['value' => $employee->uuid])->id,
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

        Livewire::test(CarePlanCreate::class, [
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
        $typeId = DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

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
            'password' => Hash::make('password'),
            'party_id' => $party->id,
        ]);

        $employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Dr. Who',
            'employee_type' => Role::DOCTOR->value,
            'status' => EnumsStatus::APPROVED->value,
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
