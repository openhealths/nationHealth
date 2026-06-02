<?php

namespace Tests\Feature;

use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\CodeableConcept;
use App\Models\MedicalEvents\Sql\Identifier;
use App\Models\Person\Person;
use App\Repositories\CarePlanRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;

class CarePlanSyncTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_care_plan_and_activities_can_be_synced_and_mapped_to_structured_sql(): void
    {
        // 1. Setup - Create a person & LegalEntity
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id') 
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = \App\Models\LegalEntity::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);
        $this->instance('legalEntity', $legalEntity);

        $person = new Person();
        $person->uuid = '540d6f4c-1d7c-4a3d-a51b-5e04f981e8d6';
        $person->first_name = 'John';
        $person->last_name = 'Doe';
        $person->gender = 'MALE';
        $person->birth_date = '1990-01-01';
        $person->patient_signed = true;
        $person->process_disclosure_data_consent = true;
        $person->save();

        // 2. Mock EHealth Response
        $carePlanUuid = 'c0280b2c-686b-4e63-a262-429d4791ea82';
        $activityUuid = 'f892d9d1-8d9e-4a6c-9c7d-8e9a0b1c2d3e';
        $encounterUuid = 'a47c210d-2a1c-439f-be38-5182046ac201';
        $employeeUuid = '268c12a0-4f9e-4c7b-a256-6a18d61ef3aa';
        $episodeUuid = '9e02c5df-1a48-4cb9-9132-841cd2f0ac0b';

        $party = \App\Models\Relations\Party::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'first_name' => 'Gregory',
            'last_name' => 'Doctor',
            'tax_id' => '1234567890',
            'birth_date' => '1970-01-01',
            'gender' => 'MALE',
        ]);

        $employee = \App\Models\Employee\Employee::create([
            'uuid' => $employeeUuid,
            'full_name' => 'Dr. House',
            'employee_type' => \App\Enums\User\Role::DOCTOR->value,
            'status' => \App\Enums\Status::APPROVED->value,
            'legal_entity_id' => $legalEntity->id,
            'is_active' => true,
            'position' => 'Doctor',
            'start_date' => now()->format('Y-m-d'),
            'party_id' => $party->id,
        ]);

        $carePlanData = [
            'data' => [
                [
                    'id' => $carePlanUuid,
                    'status' => 'active',
                    'title' => 'Test Care Plan',
                    'author' => [
                        'identifier' => [
                            'value' => $employeeUuid
                        ]
                    ],
                    'category' => [
                        [
                            'coding' => [
                                ['system' => 'http://e-health.gov.ua/systems/care-plan-category', 'code' => '736382003']
                            ],
                            'text' => 'Treatment plan'
                        ]
                    ],
                    'period' => [
                        'start' => '2026-04-14',
                        'end' => '2026-05-14'
                    ],
                    'encounter' => [
                        'identifier' => [
                            'value' => $encounterUuid
                        ]
                    ],
                    'careManager' => [
                        'identifier' => [
                            'value' => $employeeUuid
                        ]
                    ],
                    'supportingInfo' => [
                        [
                            'identifier' => [
                                'value' => $episodeUuid
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $activityData = [
            'data' => [
                [
                    'id' => $activityUuid,
                    'status' => 'scheduled',
                    'detail' => [
                        'kind' => [
                            'coding' => [['system' => 'http://hl7.org/fhir/care-plan-activity-kind', 'code' => 'ServiceRequest']],
                            'text' => 'Service Request'
                        ],
                        'quantity' => ['value' => 5],
                        'scheduledPeriod' => ['start' => '2026-04-15', 'end' => '2026-04-20'],
                        'reasonCode' => [
                            ['coding' => [['system' => 'http://e-health.gov.ua/systems/condition-code', 'code' => 'J00']]]
                        ]
                    ]
                ]
            ]
        ];

        $mockCarePlanResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $mockCarePlanResponse->shouldReceive('getData')->andReturn($carePlanData);
        $mockCarePlanResponse->shouldReceive('getStatusCode')->andReturn(200);

        $mockActivityResponse = Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $mockActivityResponse->shouldReceive('getData')->andReturn($activityData);
        $mockActivityResponse->shouldReceive('getStatusCode')->andReturn(200);

        $mockCarePlanApi = $this->mock(\App\Classes\eHealth\Api\CarePlan::class);
        $mockCarePlanApi->shouldReceive('getSummary')->andReturn($mockCarePlanResponse);

        $mockActivityApi = $this->mock(\App\Classes\eHealth\Api\CarePlanActivity::class);
        $mockActivityApi->shouldReceive('getSummary')->andReturn($mockActivityResponse);

        // 3. Run Sync
        $repo = app(CarePlanRepository::class);
        $repo->syncCarePlans($carePlanData, $person->id);

        // 4. Verification
        $this->assertDatabaseHas('care_plans', [
            'uuid' => $carePlanUuid,
            'status' => 'active',
            'title' => 'Test Care Plan',
        ]);

        $carePlan = CarePlan::where('uuid', $carePlanUuid)->first();
        $this->assertNotNull($carePlan->category_id);
        $this->assertEquals('736382003', $carePlan->categoryConcept->coding->first()->code);

        $this->assertDatabaseHas('care_plan_activities', [
            'uuid' => $activityUuid,
            'status' => 'scheduled',
        ]);

        $activity = CarePlanActivity::where('uuid', $activityUuid)->first();
        $this->assertNotNull($activity->kind_id);
        $this->assertEquals('ServiceRequest', $activity->kindConcept->coding->first()->code);
        $this->assertEquals('J00', $activity->reasonConcept->coding->first()->code);
    }
}
