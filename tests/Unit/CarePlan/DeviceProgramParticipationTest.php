<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Classes\eHealth\Api\Contract as ContractApi;
use App\Classes\eHealth\Api\DeviceDefinition;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Contract\ContractStatus;
use App\Enums\JobStatus;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Contracts\Contract;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use Tests\Support\CarePlanActivityRequirements;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class DeviceProgramParticipationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_verified_and_legacy_active_contracts_both_contribute_programs(): void
    {
        $legalEntity = $this->createLegalEntity();
        $expected = [];
        foreach (ContractStatus::cases() as $status) {
            $program = (string) Str::uuid();
            Contract::create([
                'uuid' => (string) Str::uuid(), 'legal_entity_id' => $legalEntity->id,
                'contractor_legal_entity_id' => $legalEntity->uuid,
                'contractor_owner_id' => (string) Str::uuid(),
                'status' => $status->value, 'contract_number' => 'TEST-'.$status->value,
                'medical_programs' => [$program],
            ]);
            if (in_array($status, [ContractStatus::VERIFIED, ContractStatus::ACTIVE], true)) {
                $expected[] = $program;
            }
        }

        $this->assertEqualsCanonicalizing($expected, app(CarePlanActivityRequirements::class)
            ->resolveParticipatingProgramIds($legalEntity, false));
    }

    public function test_contract_sync_validates_and_persists_all_pages(): void
    {
        $legalEntity = $this->createLegalEntity();
        $sessionEntity = $this->createLegalEntity();
        $this->instance('legalEntity', $sessionEntity);
        $programs = [(string) Str::uuid(), (string) Str::uuid()];
        $api = Mockery::mock(ContractApi::class)->makePartial();
        foreach ($programs as $index => $program) {
            $api->shouldReceive('getMany')->once()->with([
                'contractor_legal_entity_id' => $legalEntity->uuid, 'page' => $index + 1,
            ])->andReturn($this->contractResponse($api, [[
                'id' => (string) Str::uuid(), 'status' => 'VERIFIED',
                'contract_number' => 'REMOTE-'.$index, 'medical_programs' => [$program],
                'contractor_owner_id' => (string) Str::uuid(),
            ]], $index + 1, 2));
        }
        $this->instance(ContractApi::class, $api);

        $this->assertEqualsCanonicalizing($programs, app(CarePlanActivityRequirements::class)
            ->resolveParticipatingProgramIds($legalEntity));
        $this->assertSame(2, Contract::where('legal_entity_id', $legalEntity->id)->count());
        $this->assertSame(0, Contract::where('legal_entity_id', $sessionEntity->id)->count());
        $this->assertSame([$legalEntity->uuid], Contract::where('legal_entity_id', $legalEntity->id)
            ->distinct()->pluck('contractor_legal_entity_id')->all());
        $this->assertSame(JobStatus::COMPLETED, $legalEntity->fresh()->getEntityStatus(LegalEntity::ENTITY_CONTRACT));
    }

    public function test_partial_local_contract_cache_is_not_an_authoritative_program_list(): void
    {
        $legalEntity = $this->createLegalEntity();
        $legalEntity->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_CONTRACT);
        Contract::create([
            'uuid' => (string) Str::uuid(), 'legal_entity_id' => $legalEntity->id,
            'contractor_legal_entity_id' => $legalEntity->uuid,
            'contractor_owner_id' => (string) Str::uuid(),
            'status' => 'VERIFIED', 'contract_number' => 'PARTIAL',
            'medical_programs' => [(string) Str::uuid()],
        ]);
        $api = Mockery::mock(ContractApi::class)->makePartial();
        $api->shouldReceive('getMany')->once()->andThrow(new RuntimeException('Unavailable'));
        $this->instance(ContractApi::class, $api);

        $this->assertSame([], app(CarePlanActivityRequirements::class)->resolveParticipatingProgramIds($legalEntity));
        $this->assertSame(JobStatus::PROCESSING, $legalEntity->fresh()->getEntityStatus(LegalEntity::ENTITY_CONTRACT));
        $this->assertSame(1, Contract::where('legal_entity_id', $legalEntity->id)->count());
    }

    public function test_invalid_last_contract_page_does_not_persist_a_partial_list(): void
    {
        $legalEntity = $this->createLegalEntity();
        $this->instance('legalEntity', $legalEntity);
        $api = Mockery::mock(ContractApi::class)->makePartial();
        $api->shouldReceive('getMany')->once()->with([
            'contractor_legal_entity_id' => $legalEntity->uuid, 'page' => 1,
        ])->andReturn($this->contractResponse($api, [[
            'id' => (string) Str::uuid(), 'status' => 'VERIFIED', 'contract_number' => 'VALID',
            'medical_programs' => [(string) Str::uuid()],
        ]], 1, 2));
        $api->shouldReceive('getMany')->once()->with([
            'contractor_legal_entity_id' => $legalEntity->uuid, 'page' => 2,
        ])->andReturn($this->contractResponse($api, [['id' => 'not-a-uuid']], 2, 2));
        $this->instance(ContractApi::class, $api);

        $this->assertSame([], app(CarePlanActivityRequirements::class)->resolveParticipatingProgramIds($legalEntity));
        $this->assertSame(0, Contract::where('legal_entity_id', $legalEntity->id)->count());
    }

    public function test_incomplete_contract_paging_is_unknown_and_does_not_populate_the_cache(): void
    {
        $legalEntity = $this->createLegalEntity();
        $api = Mockery::mock(ContractApi::class)->makePartial();
        $api->shouldReceive('getMany')->once()->andReturn($this->contractResponse($api, [[
            'id' => (string) Str::uuid(), 'status' => 'VERIFIED', 'contract_number' => 'PARTIAL',
        ]], 1, null));
        $this->instance(ContractApi::class, $api);

        $this->assertSame([], app(CarePlanActivityRequirements::class)->resolveParticipatingProgramIds($legalEntity));
        $this->assertSame(0, Contract::where('legal_entity_id', $legalEntity->id)->count());
    }

    protected function contractResponse(ContractApi $api, array $data, int $page, ?int $pages): EHealthResponse
    {
        return new EHealthResponse(new Response(200, [], json_encode([
            'data' => $data, 'paging' => ['page_number' => $page, 'total_pages' => $pages],
        ])), fn (EHealthResponse $response): array => (new ReflectionMethod(ContractApi::class, 'validateMany'))->invoke($api, $response));
    }

    public function test_catalog_lookup_continues_to_the_second_page(): void
    {
        $api = Mockery::mock(DeviceDefinition::class)->makePartial();
        $api->shouldReceive('getMany')->once()->with([
            'medical_program_id' => 'program', 'page_size' => 300, 'page' => 1,
        ])->andReturn(new EHealthResponse(new Response(200, [], json_encode([
            'data' => [['id' => 'other-device']],
            'paging' => ['page_number' => 1, 'total_pages' => 2],
        ]))));
        $api->shouldReceive('getMany')->once()->with([
            'medical_program_id' => 'program', 'page_size' => 300, 'page' => 2,
        ])->andReturn(new EHealthResponse(new Response(200, [], json_encode([
            'data' => [['id' => 'target-device', 'is_active' => true]],
            'paging' => ['page_number' => 2, 'total_pages' => 2],
        ]))));
        $this->instance(DeviceDefinition::class, $api);

        $this->assertTrue(app(DeviceDefinition::class)
            ->isDeviceInProgramCatalog('program', 'target-device'));
    }

    public function test_incomplete_catalog_pagination_is_a_lookup_warning_not_a_missing_device(): void
    {
        $api = Mockery::mock(DeviceDefinition::class)->makePartial();
        $api->shouldReceive('getMany')->once()->andReturn(new EHealthResponse(new Response(200, [], json_encode([
            'data' => [['id' => 'other-device']],
            'paging' => ['page_number' => 1],
        ]))));
        $this->instance(DeviceDefinition::class, $api);
        $dictionary = Mockery::mock(\App\Services\Dictionary\DictionaryManager::class);
        $dictionary->shouldReceive('medicalPrograms')->andReturn(collect());
        $this->instance(\App\Services\Dictionary\DictionaryManager::class, $dictionary);
        $guard = Mockery::mock(CarePlanActivityRequirements::class)->makePartial();
        $guard->shouldReceive('resolveParticipatingProgramIds')->once()->andReturn(['program']);
        $activity = new CarePlanActivity(['program' => 'program', 'product_reference' => 'target-device']);

        $assessment = $guard->assess(new CarePlan(), $activity, new LegalEntity());

        $this->assertSame([], $assessment->blockingIssues);
        $this->assertSame([__('care-plan.device_catalog_lookup_failed', [
            'device_id' => 'target-device', 'program_id' => 'program',
        ])], $assessment->warnings);
    }

    protected function migrateDatabases(): void
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

    public function test_blocks_sign_when_program_missing_from_active_contracts(): void
    {
        $legalEntity = $this->createLegalEntity();
        $programId = (string) Str::uuid();
        $otherProgramId = (string) Str::uuid();

        Contract::query()->create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $legalEntity->id,
            'contractor_legal_entity_id' => $legalEntity->uuid,
            'contractor_owner_id' => (string) Str::uuid(),
            'status' => ContractStatus::ACTIVE->value,
            'contract_number' => 'TEST-001',
            'medical_programs' => [$otherProgramId],
        ]);

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Test',
            'last_name' => 'Patient',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $employee = \App\Models\Employee\Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Test Doctor',
            'employee_type' => 'DOCTOR',
            'status' => 'APPROVED',
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
        ]);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => $employee->id,
            'legal_entity_id' => $legalEntity->id,
            'status' => 'active',
            'title' => 'Test plan',
            'period_start' => now()->format('Y-m-d'),
            'period_end' => now()->addMonth()->format('Y-m-d'),
        ]);

        $activity = CarePlanActivity::create([
            'care_plan_id' => $carePlan->id,
            'author_id' => $employee->id,
            'kind' => 'device_request',
            'status' => 'draft',
            'program' => $programId,
            'product_reference' => (string) Str::uuid(),
        ]);

        $guard = app(CarePlanActivityRequirements::class);
        $participating = $guard->resolveParticipatingProgramIds($legalEntity, false);

        $this->assertSame([$otherProgramId], $participating);

        $assessment = $guard->assess($carePlan, $activity, $legalEntity);
        $this->assertNotNull($assessment->blockingMessage());
    }

    public function test_extracts_program_ids_from_contract_medical_program_objects(): void
    {
        $legalEntity = $this->createLegalEntity();
        $programId = (string) Str::uuid();

        Contract::query()->create([
            'uuid' => (string) Str::uuid(),
            'legal_entity_id' => $legalEntity->id,
            'contractor_legal_entity_id' => $legalEntity->uuid,
            'contractor_owner_id' => (string) Str::uuid(),
            'status' => ContractStatus::ACTIVE->value,
            'contract_number' => 'TEST-002',
            'medical_programs' => [
                ['id' => $programId],
            ],
        ]);

        $participating = app(CarePlanActivityRequirements::class)
            ->resolveParticipatingProgramIds($legalEntity, false);

        $this->assertSame([$programId], $participating);
    }

    public function test_assess_allows_device_activity_without_medical_program(): void
    {
        $legalEntity = $this->createLegalEntity();

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'first_name' => 'Test',
            'last_name' => 'Patient',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $employee = \App\Models\Employee\Employee::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Test Doctor',
            'employee_type' => 'DOCTOR',
            'status' => 'APPROVED',
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
        ]);

        $carePlan = CarePlan::create([
            'uuid' => (string) Str::uuid(),
            'person_id' => $person->id,
            'author_id' => $employee->id,
            'legal_entity_id' => $legalEntity->id,
            'status' => 'active',
            'title' => 'No program plan',
            'period_start' => now()->format('Y-m-d'),
            'period_end' => now()->addMonth()->format('Y-m-d'),
        ]);

        $activity = CarePlanActivity::create([
            'care_plan_id' => $carePlan->id,
            'author_id' => $employee->id,
            'kind' => 'device_request',
            'status' => 'draft',
            'program' => null,
            'product_reference' => (string) Str::uuid(),
        ]);

        $assessment = app(CarePlanActivityRequirements::class)
            ->assess($carePlan, $activity, $legalEntity);

        $this->assertNull($assessment->blockingMessage());
    }

    public function test_device_allows_care_plan_activity_respects_program_devices_flag(): void
    {
        $guard = app(DeviceDefinition::class);

        $allowed = [
            'is_active' => true,
            'program_devices' => [[
                'care_plan_activity_allowed' => true,
                'start_date' => '2024-07-01',
                'end_date' => null,
                'max_daily_count' => 5,
            ]],
        ];
        $blocked = [
            'is_active' => true,
            'program_devices' => [[
                'care_plan_activity_allowed' => false,
                'start_date' => '2024-07-01',
                'end_date' => null,
                'max_daily_count' => 5,
            ]],
        ];
        $withoutRows = [
            'is_active' => true,
        ];

        $this->assertTrue($guard->deviceAllowsCarePlanActivity($allowed));
        $this->assertFalse($guard->deviceAllowsCarePlanActivity($blocked));
        $this->assertTrue($guard->deviceAllowsCarePlanActivity($withoutRows));
        $this->assertSame(5, $guard->resolveProgramDevice($allowed)['max_daily_count'] ?? null);
    }

    private function createLegalEntity(): LegalEntity
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
        $legalEntity->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_CONTRACT);

        return $legalEntity;
    }
}
