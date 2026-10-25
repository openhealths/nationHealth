<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\CarePlanActivity\Model;
use App\Dto\CarePlanActivity\Form;
use App\Dto\FormCollection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CarePlanActivityDataTest extends TestCase
{
    public function testCompleteActivityRoundTripKeepsListsZeroAndFalse(): void
    {
        $resource = json_decode(file_get_contents(__DIR__.'/../../Fixtures/CarePlan/activity.json'), true, flags: JSON_THROW_ON_ERROR);

        $result = $this->toResource($this->toForm($resource), ['resource' => $resource]);

        $this->assertEquals($resource, $result);
        $this->assertSame(0, $result['detail']['remainingQuantity']['value']);
        $this->assertFalse($result['detail']['doNotPerform']);
        $this->assertCount(2, $result['author']);
        $this->assertCount(2, $result['detail']['goal']);
    }

    public function testFractionalFormQuantityDoesNotLoseExistingUnitMetadata(): void
    {
        $resource = ['id' => 'activity-id', 'detail' => ['kind' => 'medication_request', 'quantity' => ['value' => 1, 'system' => 'MEDICATION_UNIT', 'code' => 'ML', 'unit' => 'ml']]];
        $result = $this->toResource(['quantity' => '1.2345'], ['resource' => $resource]);

        $this->assertSame(1.2345, $result['detail']['quantity']['value']);
        $this->assertSame('ML', $result['detail']['quantity']['code']);
        $this->assertSame('ml', $result['detail']['quantity']['unit']);
    }

    public function testServiceGroupReferenceTypeIsPreserved(): void
    {
        $result = $this->toResource(['uuid' => 'activity-id', 'kind' => 'service_request', 'productReference' => 'service_group/service-id']);

        $this->assertSame('service_group', $result['detail']['productReference']['identifier']['type']['coding'][0]['code']);
    }

    public function testDeviceActivityAcceptsAClassificationConceptWithoutAReference(): void
    {
        $result = $this->toResource(['uuid' => 'activity-id', 'kind' => 'device_request', 'productCodeableConcept' => 'CLASS', 'quantity' => 2, 'doNotPerform' => false]);

        $this->assertArrayNotHasKey('productReference', $result['detail']);
        $this->assertSame('device_definition_classification_type', $result['detail']['productCodeableConcept']['coding'][0]['system']);
        $this->assertFalse($result['detail']['doNotPerform']);
    }

    public function testBothProductVariantsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->toResource(['uuid' => 'activity-id', 'kind' => 'device_request', 'productReference' => 'device-id', 'productCodeableConcept' => 'CLASS']);
    }

    public function testMultipleScheduledVariantsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->toResource(['uuid' => 'activity-id', 'kind' => 'service_request', 'scheduledPeriod' => ['start' => '2026-10-19T10:00:00Z'], 'scheduledString' => 'As needed']);
    }

    public function testUntypedReasonReferenceIsNotSilentlyAssumedToBeACondition(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->toResource(['uuid' => 'activity-id', 'kind' => 'service_request', 'reasonReference' => ['untyped-id']]);
    }

    private function toResource(array $fields, array $context = []): array
    {
        return Model::fromSource(new FormCollection($fields), $context)->toArray();
    }

    private function toForm(array $resource, array $context = []): array
    {
        return Form::fromSource(new FormCollection($resource), $context)->toArray();
    }
}
