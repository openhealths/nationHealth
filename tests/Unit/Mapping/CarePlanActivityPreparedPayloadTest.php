<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Enums\CarePlanStatus;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\Period;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class CarePlanActivityPreparedPayloadTest extends TestCase
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

        $payload = app(\Tests\Support\CarePlanActivityPayload::class)->formatCarePlanActivityRequest($activity);

        $this->assertIsInt($payload['detail']['quantity']['value']);
        $this->assertSame(1, $payload['detail']['quantity']['value']);
    }

    public function test_device_payload_prefers_device_definition_uuid_over_classification(): void
    {
        $carePlan = new CarePlan([
            'period_start' => now()->subDay(),
            'period_end' => now()->addMonth(),
        ]);
        $carePlan->setRawAttributes(array_merge($carePlan->getAttributes(), [
            'period_start' => now()->subDay()->format('Y-m-d'),
            'period_end' => now()->addMonth()->format('Y-m-d'),
        ]));

        $deviceUuid = '0b70715d-0e6e-4a89-889f-815cf429cb87';
        $activity = new CarePlanActivity([
            'kind' => 'device_request',
            'status' => CarePlanStatus::DRAFT->value,
            'quantity' => 1,
            'quantity_system' => 'device_unit',
            'quantity_code' => 'piece',
            'product_reference' => $deviceUuid,
            'product_codeable_concept' => '18_09_03',
            'program' => 'af8ba0d3-1520-4a01-8156-22065e96fd9a',
            'scheduled_period_start' => now()->format('Y-m-d'),
            'scheduled_period_end' => now()->addWeek()->format('Y-m-d'),
        ]);
        $activity->setRelation('carePlan', $carePlan);

        $payload = app(\Tests\Support\CarePlanActivityPayload::class)->formatCarePlanActivityRequest($activity);

        $this->assertSame(
            $deviceUuid,
            $payload['detail']['product_reference']['identifier']['value'] ?? null
        );
        $this->assertArrayNotHasKey('product_codeable_concept', $payload['detail']);
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

        $payload = app(\Tests\Support\CarePlanActivityPayload::class)->formatCarePlanActivityRequest($activity);
        $planStart = Carbon::parse('2026-06-25T16:33:00Z')->utc();
        $activityStart = Carbon::parse($payload['detail']['scheduled_period']['start'])->utc();

        $this->assertTrue($activityStart->gte($planStart));
        $this->assertSame('2026-06-25T16:33:00Z', $payload['detail']['scheduled_period']['start']);

        Carbon::setTestNow();
    }

    public function test_payload_author_comes_from_the_activity_not_the_current_session(): void
    {
        $authorUuid = '6f2f8d1a-3a4e-4c39-9d1d-4a0f1b6ad0aa';

        $author = new \App\Models\Employee\Employee();
        $author->setRawAttributes(['id' => 42, 'uuid' => $authorUuid]);

        $activity = new CarePlanActivity([
            'kind' => 'service_request',
            'status' => CarePlanStatus::DRAFT->value,
            'uuid' => (string) Str::uuid(),
            'quantity' => 1,
            'quantity_system' => 'SERVICE_UNIT',
            'quantity_code' => 'PIECE',
            'product_reference' => '4fbe6a29-5ffb-4bde-be83-7c968ee12e25',
        ]);
        $activity->setRelation('carePlan', new CarePlan());
        $activity->setRelation('author', $author);

        $payload = app(\Tests\Support\CarePlanActivityPayload::class)->formatCarePlanActivityRequest($activity);

        $this->assertSame($authorUuid, $payload['author'][0]['identifier']['value']);
    }

    public function test_payload_omits_the_author_when_the_activity_has_none(): void
    {
        $activity = new CarePlanActivity([
            'kind' => 'service_request',
            'status' => CarePlanStatus::DRAFT->value,
            'uuid' => (string) Str::uuid(),
            'quantity' => 1,
            'quantity_system' => 'SERVICE_UNIT',
            'quantity_code' => 'PIECE',
            'product_reference' => '4fbe6a29-5ffb-4bde-be83-7c968ee12e25',
        ]);
        $activity->setRelation('carePlan', new CarePlan());
        $activity->setRelation('author', null);

        $payload = app(\Tests\Support\CarePlanActivityPayload::class)->formatCarePlanActivityRequest($activity);

        // eHealth rejects an activity without an author uuid, which beats silently
        // attributing it to whoever is signed in.
        $this->assertArrayNotHasKey('value', $payload['author'][0]['identifier']);
    }

    public function test_build_activity_cancel_sign_payload_adds_status_reason_to_full_snapshot(): void
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
                'quantity' => ['value' => 1.0, 'code' => 'PIECE', 'system' => 'MEDICATION_UNIT', 'unit' => 'шт'],
                'program' => ['identifier' => ['value' => '1318eabc-1a1a-42f6-8450-61e11c19eede']],
            ],
        ];

        $signed = app(\Symfony\Component\ObjectMapper\ObjectMapperInterface::class)->map(
            tap(new \App\Livewire\CarePlan\CarePlanShow(), static function ($component) use ($statusReason): void {
                $component->statusReason = $statusReason['coding'][0]['code'];
            }),
            new \App\Dto\CarePlanActivity\EhealthCancel($base),
        )->toArray();

        $this->assertSame($statusReason, $signed['detail']['status_reason']);

        // API-007-006-0005 compares the signed content with the activity as eHealth renders it,
        // so everything except detail.status_reason has to survive byte for byte. Dropping a
        // field here — quantity.unit for instance — earns a 422 "Signed content doesn't match
        // with previously created activity".
        $withoutStatusReason = $signed;
        unset($withoutStatusReason['detail']['status_reason']);

        $this->assertSame($base, $withoutStatusReason);
        $this->assertSame('шт', $signed['detail']['quantity']['unit']);
    }

    public function test_build_activity_cancel_sign_payload_leaves_the_ehealth_author_shape_alone(): void
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
            'detail' => [
                'kind' => 'medication_request',
                'status' => 'scheduled',
                'do_not_perform' => false,
            ],
        ];

        $signed = app(\Symfony\Component\ObjectMapper\ObjectMapperInterface::class)->map(
            tap(new \App\Livewire\CarePlan\CarePlanShow(), static function ($component) use ($statusReason): void {
                $component->statusReason = $statusReason['coding'][0]['code'];
            }),
            new \App\Dto\CarePlanActivity\EhealthCancel($base),
        )->toArray();

        // Get Care Plan Activity by ID returns author as an object, and cancel has to sign back
        // exactly what it was given. Wrapping it as a list is a create/complete concern and
        // would make the comparison fail here.
        $this->assertFalse(array_is_list($signed['author']));
        $this->assertSame('1766ae9e-828d-4daa-bba8-48da3a13393a', $signed['author']['identifier']['value']);
        $this->assertSame($statusReason, $signed['detail']['status_reason']);
    }
}
