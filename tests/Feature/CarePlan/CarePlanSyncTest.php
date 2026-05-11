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

class CarePlanSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_care_plan_and_activities_can_be_synced_and_mapped_to_structured_sql(): void
    {
        // 1. Setup - Create a person
        $person = new Person();
        $person->uuid = '540d6f4c-1d7c-4a3d-a51b-5e04f981e8d6';
        $person->save();

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
                            'value' => 'encounter-uuid'
                        ]
                    ],
                    'careManager' => [
                        'identifier' => [
                            'value' => 'employee-uuid'
                        ]
                    ],
                    'supportingInfo' => [
                        [
                            'identifier' => [
                                'value' => 'episode-uuid'
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

        $mockCarePlanResponse = new class($carePlanData) {
            public function __construct(private array $data) {}
            public function getData() { return $this->data; }
        };

        $mockActivityResponse = new class($activityData) {
            public function __construct(private array $data) {}
            public function getData() { return $this->data; }
        };

        $mockCarePlanApi = $this->mock(\App\Classes\eHealth\Api\CarePlan::class);
        $mockCarePlanApi->shouldReceive('getSummary')->andReturn($mockCarePlanResponse);

        $mockActivityApi = $this->mock(\App\Classes\eHealth\Api\CarePlanActivity::class);
        $mockActivityApi->shouldReceive('getSummary')->andReturn($mockActivityResponse);

        // 3. Run Sync
        $repo = app(CarePlanRepository::class);
        $repo->syncCarePlans($person);

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
