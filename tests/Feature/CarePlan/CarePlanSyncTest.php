<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Person\Person;
use App\Repositories\CarePlanRepository;
use Tests\TestCase;

class CarePlanSyncTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('DB_CONNECTION=pgsql');
        putenv('DB_HOST=127.0.0.1');
        putenv('DB_DATABASE=mis_dev');
        putenv('DB_USERNAME=sail');
        putenv('DB_PASSWORD=password');

        parent::setUp();

        \Illuminate\Support\Facades\DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\DB::rollBack();
        parent::tearDown();
    }

    public function test_care_plan_and_activities_can_be_synced_and_mapped_to_structured_sql(): void
    {
        // 1. Setup - Create a person, legal entity, and employee
        $person = Person::create([
            'uuid' => '540d6f4c-1d7c-4a3d-a51b-5e04f981e8d6',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'birth_date' => '1990-01-01',
            'gender' => 'MALE',
            'patient_signed' => true,
            'process_disclosure_data_consent' => true,
        ]);

        $legalEntity = \App\Models\LegalEntity::create([
            'uuid' => 'd5a6c9d0-8f6b-4a3d-a2e6-c1b8df7902d4',
            'name' => 'Test Clinic',
            'status' => 'ACTIVE',
            'edrpou' => '12345678',
            'public_name' => 'Test Clinic',
            'mis_client_secret' => 'secret',
            'email' => 'clinic@example.com',
            'legal_entity_type_id' => 1,
        ]);

        $party = \App\Models\Relations\Party::create([
            'first_name' => 'Doctor',
            'last_name' => 'Who',
            'birth_date' => '1980-01-01',
            'gender' => 'MALE',
            'verification_status' => 'VERIFIED',
        ]);

        $employee = \App\Models\Employee\Employee::create([
            'uuid' => 'd5a6c9d0-8f6b-4a3d-a2e6-c1b8df7902d3',
            'legal_entity_id' => $legalEntity->id,
            'legal_entity_uuid' => $legalEntity->uuid,
            'party_id' => $party->id,
            'employee_type' => 'DOCTOR',
            'position' => 'DOCTOR',
            'status' => 'APPROVED',
            'start_date' => '2020-01-01',
            'is_active' => true,
        ]);

        // 2. Mock EHealth Response
        $carePlanUuid = 'c0280b2c-686b-4e63-a262-429d4791ea82';
        $activityUuid = 'f892d9d1-8d9e-4a6c-9c7d-8e9a0b1c2d3e';

        $carePlanData = [
            'data' => [
                [
                    'id' => $carePlanUuid,
                    'status' => 'active',
                    'title' => 'Test Care Plan',
                    'category' => [
                        'coding' => [
                            ['system' => 'http://e-health.gov.ua/systems/care-plan-category', 'code' => '736382003']
                        ],
                        'text' => 'Treatment plan'
                    ],
                    'period' => [
                        'start' => '2026-04-14',
                        'end' => '2026-05-14'
                    ],
                    'encounter' => [
                        'identifier' => [
                            'value' => 'd06efb40-9a3d-4c3e-b8d4-510bf36ad0b6'
                        ]
                    ],
                    'careManager' => [
                        'identifier' => [
                            'value' => 'd5a6c9d0-8f6b-4a3d-a2e6-c1b8df7902d3'
                        ]
                    ],
                    'supportingInfo' => [
                        [
                            'identifier' => [
                                'value' => 'e9d8c7a6-b5c4-4a3d-92ef-c1b8a92ef0d5'
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

        $mockCarePlanResponse = \Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $mockCarePlanResponse->shouldReceive('getData')->andReturn($carePlanData);

        $mockActivityResponse = \Mockery::mock(\App\Classes\eHealth\EHealthResponse::class);
        $mockActivityResponse->shouldReceive('getData')->andReturn($activityData);

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
        $this->assertNotNull($carePlan->categoryId);
        $this->assertEquals('736382003', $carePlan->categoryConcept->coding->first()->code);

        $this->assertDatabaseHas('care_plan_activities', [
            'uuid' => $activityUuid,
            'status' => 'scheduled',
        ]);

        $activity = CarePlanActivity::where('uuid', $activityUuid)->first();
        $this->assertNotNull($activity->kindId);
        $this->assertEquals('ServiceRequest', $activity->kindConcept->coding->first()->code);
        $this->assertEquals('J00', $activity->reasonConcept->coding->first()->code);
    }
}
