<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents\Mappers;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\CarePlanActivityResourceHarness;

class CarePlanActivityMapperTest extends TestCase
{
    public function test_complete_activity_round_trip_keeps_lists_zero_and_false(): void
    {
        $resource = json_decode(file_get_contents(__DIR__.'/../../../../Fixtures/CarePlan/activity.json'), true, flags: JSON_THROW_ON_ERROR);
        $mapper = new CarePlanActivityResourceHarness();

        $result = $mapper->toFhir($mapper->fromFhir($resource), ['resource' => $resource]);

        $this->assertEquals($resource, $result);
        $this->assertSame(0, $result['detail']['remainingQuantity']['value']);
        $this->assertFalse($result['detail']['doNotPerform']);
        $this->assertCount(2, $result['author']);
        $this->assertCount(2, $result['detail']['goal']);
    }

    public function test_fractional_form_quantity_does_not_lose_existing_unit_metadata(): void
    {
        $resource = ['id' => 'activity-id', 'detail' => ['kind' => 'medication_request', 'quantity' => ['value' => 1, 'system' => 'MEDICATION_UNIT', 'code' => 'ML', 'unit' => 'ml']]];
        $result = (new CarePlanActivityResourceHarness())->toFhir(['quantity' => '1.2345'], ['resource' => $resource]);

        $this->assertSame(1.2345, $result['detail']['quantity']['value']);
        $this->assertSame('ML', $result['detail']['quantity']['code']);
        $this->assertSame('ml', $result['detail']['quantity']['unit']);
    }

    public function test_service_group_reference_type_is_preserved(): void
    {
        $result = (new CarePlanActivityResourceHarness())->toFhir(['uuid' => 'activity-id', 'kind' => 'service_request', 'productReference' => 'service_group/service-id']);

        $this->assertSame('service_group', $result['detail']['productReference']['identifier']['type']['coding'][0]['code']);
    }

    public function test_device_activity_accepts_a_classification_concept_without_a_reference(): void
    {
        $result = (new CarePlanActivityResourceHarness())->toFhir(['uuid' => 'activity-id', 'kind' => 'device_request', 'productCodeableConcept' => 'CLASS', 'quantity' => 2, 'doNotPerform' => false]);

        $this->assertArrayNotHasKey('productReference', $result['detail']);
        $this->assertSame('device_definition_classification_type', $result['detail']['productCodeableConcept']['coding'][0]['system']);
        $this->assertFalse($result['detail']['doNotPerform']);
    }

    public function test_both_product_variants_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CarePlanActivityResourceHarness())->toFhir(['uuid' => 'activity-id', 'kind' => 'device_request', 'productReference' => 'device-id', 'productCodeableConcept' => 'CLASS']);
    }

    public function test_multiple_scheduled_variants_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CarePlanActivityResourceHarness())->toFhir(['uuid' => 'activity-id', 'kind' => 'service_request', 'scheduledPeriod' => ['start' => '2026-10-19T10:00:00Z'], 'scheduledString' => 'As needed']);
    }

    public function test_existing_untyped_reason_reference_keeps_its_type_and_display(): void
    {
        $reference = ['identifier' => ['value' => 'observation-id', 'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => 'observation']]]], 'displayValue' => 'Existing observation'];
        $resource = ['id' => 'activity-id', 'detail' => ['kind' => 'service_request', 'reasonReference' => [$reference]]];

        $result = (new CarePlanActivityResourceHarness())->toFhir(['reasonReference' => ['observation-id']], ['resource' => $resource]);

        $this->assertSame([$reference], $result['detail']['reasonReference']);
    }

    public function test_untyped_reason_reference_with_ambiguous_existing_types_is_rejected(): void
    {
        $references = array_map(static fn (string $type): array => [
            'identifier' => ['value' => 'shared-id', 'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $type]]]],
        ], ['condition', 'observation']);
        $resource = ['id' => 'activity-id', 'detail' => ['kind' => 'service_request', 'reasonReference' => $references]];

        $this->expectException(InvalidArgumentException::class);
        (new CarePlanActivityResourceHarness())->toFhir(['reasonReference' => ['shared-id']], ['resource' => $resource]);
    }

    public function test_explicit_reason_reference_type_selects_the_matching_existing_resource(): void
    {
        $references = array_map(static fn (string $type): array => [
            'identifier' => ['value' => 'shared-id', 'type' => ['coding' => [['system' => 'eHealth/resources', 'code' => $type]]]],
        ], ['condition', 'observation']);
        $resource = ['id' => 'activity-id', 'detail' => ['kind' => 'service_request', 'reasonReference' => $references]];

        $result = (new CarePlanActivityResourceHarness())->toFhir(['reasonReference' => ['Observation/shared-id']], ['resource' => $resource]);

        $this->assertSame([$references[1]], $result['detail']['reasonReference']);
    }

    public function test_untyped_reason_reference_is_not_silently_assumed_to_be_a_condition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CarePlanActivityResourceHarness())->toFhir(['uuid' => 'activity-id', 'kind' => 'service_request', 'reasonReference' => ['untyped-id']]);
    }
}
