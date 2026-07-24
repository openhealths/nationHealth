<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Enums\CarePlanStatus;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\Period;
use App\Repositories\CarePlanActivityRepository;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class CarePlanActivityRepositoryTest extends TestCase
{
    public function test_device_request_quantity_is_integer_in_payload(): void
    {
        $carePlan = new CarePlan([
            'period_start' => now()->subDay(),
            'period_end' => now()->addMonth(),
        ]);
        $carePlan->setRawAttributes(array_merge($carePlan->getAttributes(), [
            'period_start' => now()->subDay()->format('Y-m-d'),
            'period_end' => now()->addMonth()->format('Y-m-d'),
        ]));

        $activity = new CarePlanActivity([
            'kind' => 'device_request',
            'status' => CarePlanStatus::DRAFT->value,
            'quantity' => 1,
            'quantity_system' => 'device_unit',
            'quantity_code' => 'piece',
            'product_reference' => '0cf026bd-82f0-46eb-becb-669a0552368d',
            'program' => '0cefbce3-0000-0000-0000-000000000001',
            'scheduled_period_start' => now()->format('Y-m-d'),
            'scheduled_period_end' => now()->addWeek()->format('Y-m-d'),
        ]);
        $activity->setRelation('carePlan', $carePlan);

        $payload = app(CarePlanActivityRepository::class)->formatCarePlanActivityRequest($activity);

        $this->assertIsInt($payload['detail']['quantity']['value']);
        $this->assertSame(1, $payload['detail']['quantity']['value']);
    }

    public function test_build_device_prequalify_payload_includes_occurrence_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-29 10:00:00', 'Europe/Kyiv'));

        $carePlan = new CarePlan();
        $carePlan->setRawAttributes([
            'uuid' => (string) Str::uuid(),
        ]);

        $activity = new CarePlanActivity([
            'kind' => 'device_request',
            'status' => CarePlanStatus::DRAFT->value,
            'uuid' => (string) Str::uuid(),
            'quantity' => 100,
            'quantity_system' => 'device_unit',
            'quantity_code' => 'piece',
            'product_reference' => '0fa1e6cd-7066-4881-92a5-6d747a1128f7',
            'program' => '85953838-1834-4ed6-8bf4-3f83057380ec',
            'scheduled_period_start' => '2026-06-27',
            'scheduled_period_end' => '2026-07-06',
        ]);
        $activity->setRelation('carePlan', $carePlan);

        $uuids = [
            'person_uuid' => (string) Str::uuid(),
            'encounter_uuid' => (string) Str::uuid(),
            'employee_uuid' => (string) Str::uuid(),
            'legal_entity_uuid' => (string) Str::uuid(),
        ];

        $payload = app(CarePlanActivityRepository::class)->buildDevicePrequalifyPayload($activity, $carePlan, $uuids);

        $this->assertArrayHasKey('occurrence_period', $payload['device_request']);
        $this->assertArrayHasKey('start', $payload['device_request']['occurrence_period']);
        $this->assertArrayHasKey('end', $payload['device_request']['occurrence_period']);
        $this->assertArrayNotHasKey('code', $payload['device_request']);
        $this->assertArrayHasKey('identifier', $payload['device_request']['code_reference']);
        $this->assertSame('0fa1e6cd-7066-4881-92a5-6d747a1128f7', $payload['device_request']['code_reference']['identifier']['value']);
    }

    public function test_draft_activity_start_is_clipped_to_ehealth_care_plan_period_start(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 17:13:11', 'Europe/Kyiv'));

        $carePlan = new CarePlan();
        $carePlan->setRawAttributes([
            'period_start' => '2026-06-25',
            'period_end' => '2026-07-25',
        ]);

        $effectivePeriod = new Period();
        $effectivePeriod->setRawAttributes([
            'start' => '2026-06-25 16:33:00',
            'end' => null,
        ]);
        $carePlan->setRelation('effectivePeriod', $effectivePeriod);

        $activity = new CarePlanActivity([
            'kind' => 'service_request',
            'status' => CarePlanStatus::DRAFT->value,
            'uuid' => (string) Str::uuid(),
            'quantity' => 1,
            'quantity_system' => 'SERVICE_UNIT',
            'quantity_code' => 'PIECE',
            'product_reference' => '4fbe6a29-5ffb-4bde-be83-7c968ee12e25',
            'scheduled_period_start' => '2026-06-25',
            'scheduled_period_end' => '2026-07-02',
            'created_at' => Carbon::parse('2026-06-25 10:00:00'),
        ]);
        $activity->setRelation('carePlan', $carePlan);

        $payload = app(CarePlanActivityRepository::class)->formatCarePlanActivityRequest($activity);
        $planStart = Carbon::parse('2026-06-25T16:33:00Z')->utc();
        $activityStart = Carbon::parse($payload['detail']['scheduled_period']['start'])->utc();

        $this->assertTrue($activityStart->gte($planStart));
        $this->assertSame('2026-06-25T16:33:00Z', $payload['detail']['scheduled_period']['start']);

        Carbon::setTestNow();
    }

    public function test_normalize_ehealth_activity_for_signing_strips_read_only_fields(): void
    {
        $raw = [
            'id' => 'f5ad4f67-7066-4d0d-bcff-c17a11a723e4',
            'author' => [
                'display_value' => 'Андрій Дмитрович Копилець',
                'identifier' => [
                    'type' => [
                        'coding' => [
                            ['code' => 'employee', 'system' => 'eHealth/resources'],
                        ],
                        'text' => null,
                    ],
                    'value' => '1766ae9e-828d-4daa-bba8-48da3a13393a',
                ],
            ],
            'care_plan' => [
                'display_value' => null,
                'identifier' => [
                    'type' => [
                        'coding' => [
                            ['code' => 'care_plan', 'system' => 'eHealth/resources'],
                        ],
                        'text' => null,
                    ],
                    'value' => '63e74515-13cd-43c5-9e8e-9742854ad949',
                ],
            ],
            'detail' => [
                'kind' => 'medication_request',
                'status' => 'scheduled',
                'do_not_perform' => false,
                'goal' => [],
                'reason_code' => [],
                'reason_reference' => [],
                'description' => null,
                'product_reference' => [
                    'display_value' => null,
                    'identifier' => [
                        'type' => [
                            'coding' => [
                                ['code' => 'medication', 'system' => 'eHealth/resources'],
                            ],
                            'text' => null,
                        ],
                        'value' => '02b5e4de-22ec-429d-81f2-8faf44bd8c92',
                    ],
                ],
                'quantity' => [
                    'code' => 'PIECE',
                    'system' => 'MEDICATION_UNIT',
                    'unit' => 'шт',
                    'value' => 1.0,
                ],
                'scheduled_period' => [
                    'start' => '2026-06-25T18:14:37Z',
                    'end' => '2026-07-02T20:59:59Z',
                ],
            ],
            'remaining_quantity' => ['value' => 1.0],
            'inserted_at' => '2026-06-25T18:14:41.040000Z',
        ];

        $normalized = app(CarePlanActivityRepository::class)->normalizeEHealthActivityForSigning($raw);

        $this->assertArrayNotHasKey('display_value', $normalized['author']);
        $this->assertArrayNotHasKey('remaining_quantity', $normalized);
        $this->assertArrayNotHasKey('inserted_at', $normalized);
        $this->assertArrayNotHasKey('goal', $normalized['detail']);
        $this->assertArrayNotHasKey('reason_code', $normalized['detail']);
        $this->assertArrayNotHasKey('description', $normalized['detail']);
        $this->assertArrayNotHasKey('text', $normalized['author']['identifier']['type']);
    }

    public function test_build_activity_cancel_sign_payload_preserves_creation_snapshot(): void
    {
        $statusReason = [
            'coding' => [
                [
                    'system' => 'eHealth/care_plan_activity_cancel_reasons',
                    'code' => 'typo',
                ],
            ],
        ];

        $base = [
            'id' => 'f5ad4f67-7066-4d0d-bcff-c17a11a723e4',
            'author' => [
                'identifier' => [
                    'type' => [
                        'coding' => [
                            ['system' => 'eHealth/resources', 'code' => 'employee'],
                        ],
                    ],
                    'value' => '1766ae9e-828d-4daa-bba8-48da3a13393a',
                ],
            ],
            'care_plan' => [
                'identifier' => [
                    'type' => [
                        'coding' => [
                            ['system' => 'eHealth/resources', 'code' => 'care_plan'],
                        ],
                    ],
                    'value' => '63e74515-13cd-43c5-9e8e-9742854ad949',
                ],
            ],
            'detail' => [
                'kind' => 'medication_request',
                'status' => 'scheduled',
                'do_not_perform' => false,
                'quantity' => ['value' => 1.0, 'code' => 'PIECE', 'system' => 'MEDICATION_UNIT'],
                'program' => ['identifier' => ['value' => '1318eabc-1a1a-42f6-8450-61e11c19eede']],
            ],
        ];

        $signed = app(CarePlanActivityRepository::class)->buildActivityCancelSignPayload($base);

        $this->assertSame('f5ad4f67-7066-4d0d-bcff-c17a11a723e4', $signed['id']);
        $this->assertSame('medication_request', $signed['detail']['kind']);
        $this->assertSame('scheduled', $signed['detail']['status']);
        $this->assertFalse($signed['detail']['do_not_perform']);
        $this->assertSame(1.0, $signed['detail']['quantity']['value']);
        $this->assertArrayNotHasKey('status_reason', $signed['detail']);

        $patchDetail = app(CarePlanActivityRepository::class)->buildActivityCancelPatchDetail($base, $statusReason);
        $this->assertSame($statusReason, $patchDetail['status_reason']);
        $this->assertFalse($patchDetail['do_not_perform']);
    }
}
