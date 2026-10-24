<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents;

use App\Core\Arr;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ServiceRequestFhirRoundTripTest extends TestCase
{
    private function resource(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../../Fixtures/ServiceRequest/resource.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_full_resource_survives_the_existing_form_projection(): void
    {
        $mapper = new ServiceRequestMapper();
        $resource = $this->resource();
        $actual = $mapper->toFhir($mapper->fromFhir(Arr::toSnakeCase($resource)));
        $this->assertEquals($resource, $actual);
        $this->assertSame($resource['occurrencePeriod'], $actual['occurrencePeriod']);
        $this->assertSame($resource['basedOn'], $actual['basedOn']);
        $this->assertSame($resource['code'], $actual['code']);
    }

    public function test_clearing_an_activity_keeps_the_care_plan_basis(): void
    {
        $mapper = new ServiceRequestMapper();
        $form = $mapper->fromFhir($this->resource());
        $form['based_on_uuid'] = null;
        $actual = $mapper->toFhir($form);
        $this->assertSame([$this->resource()['basedOn'][0]], $actual['basedOn']);
    }

    public function test_unchanged_care_plan_context_preserves_reference_order(): void
    {
        $resource = $this->resource();
        $resource['basedOn'] = array_reverse($resource['basedOn']);
        $mapper = new ServiceRequestMapper();
        $actual = $mapper->toFhir($mapper->fromFhir($resource), ['carePlanUuid' => $resource['basedOn'][1]['identifier']['value']]);
        $this->assertSame($resource['basedOn'], $actual['basedOn']);
    }

    public function test_create_and_prequalify_use_one_resource_with_distinct_envelopes(): void
    {
        $mapper = new ServiceRequestMapper();
        $data = $mapper->fromFhir($this->resource());
        $create = $mapper->toCreateSignedContent($data, []);
        $prequalify = $mapper->toPrequalifyPayload($data, []);
        $this->assertSame(1.23456789, $create['quantity']['value']);
        $this->assertSame('service_group', $create['code']['identifier']['type']['coding'][0]['code']);
        $this->assertSame('episode_of_care', $create['context']['identifier']['type']['coding'][0]['code']);
        $this->assertArrayNotHasKey('quantityInteger', $create);
        $this->assertArrayNotHasKey('remaining_quantity', $create);
        $this->assertArrayNotHasKey('remote_extension', $create);
        $this->assertArrayNotHasKey('program', $prequalify['service_request']);
        $this->assertArrayNotHasKey('id', $prequalify['service_request']);
        $this->assertSame($create['program'], $prequalify['programs'][0]);
        unset($create['id'], $create['program']);
        $this->assertSame($create, $prequalify['service_request']);
    }

    public function test_occurrence_is_not_shifted_or_extended_and_mapping_is_repeatable(): void
    {
        $mapper = new ServiceRequestMapper();
        $data = $mapper->fromFhir($this->resource());
        $data['started_at'] = '2025-01-01T12:13:14.123456+02:00';
        $data['ended_at'] = '2025-01-01T13:14:15.654321+02:00';
        $first = $mapper->toCreateSignedContent($data, []);
        $this->assertSame('2025-01-01T10:13:14.123456Z', $first['occurrence_period']['start']);
        $this->assertSame('2025-01-01T11:14:15.654321Z', $first['occurrence_period']['end']);
        $this->assertSame($first, $mapper->toCreateSignedContent($data, []));
        $this->assertArrayNotHasKey('authored_on', $first);
    }

    public function test_explicit_empty_collections_clear_only_that_collection(): void
    {
        $mapper = new ServiceRequestMapper();
        $data = $mapper->fromFhir($this->resource());
        $data['supporting_info'] = [];
        $actual = $mapper->toFhir($data);
        $this->assertSame([], $actual['supportingInfo']);
        $this->assertCount(2, $actual['reasonReference']);
        $this->assertCount(2, $actual['basedOn']);
        $this->assertSame(0, $actual['remainingQuantity']['value']);
        $this->assertFalse($actual['remoteExtension']['flag']);
    }

    public function test_unknown_reference_type_is_not_guessed(): void
    {
        $mapper = new ServiceRequestMapper();
        $data = $mapper->fromFhir($this->resource());
        $data['reason_reference'] = [['uuid' => '10050000-0000-4000-8000-000000000999']];
        $this->expectException(InvalidArgumentException::class);
        $mapper->toFhir($data);
    }

    public function test_mapping_never_allocates_a_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ServiceRequestMapper())->toFhir(['service_id' => 'service-test']);
    }

    public function test_occurrence_variants_are_mutually_exclusive(): void
    {
        $mapper = new ServiceRequestMapper();
        $data = $mapper->fromFhir($this->resource());
        $data['occurrence_date_time'] = '2026-11-01T10:20:30Z';
        $this->expectException(InvalidArgumentException::class);
        $mapper->toFhir($data);
    }
}
