<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents\Mappers;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\CarePlanResourceHarness;

class CarePlanMapperTest extends TestCase
{
    public function test_unchanged_form_preserves_full_resource_and_timestamp_precision(): void
    {
        $resource = json_decode(file_get_contents(__DIR__.'/../../../../Fixtures/CarePlan/plan.json'), true, flags: JSON_THROW_ON_ERROR);
        $mapper = new CarePlanResourceHarness();
        $context = ['resource' => $resource, 'timezone' => 'Europe/Kyiv', 'dateFormat' => 'd.m.Y'];

        $form = $mapper->fromFhir($resource, $context);

        $this->assertSame('19.10.2026', $form['periodStart']);
        $this->assertSame('13:20:30', $form['periodStartTime']);
        $this->assertEquals($resource, $mapper->toFhir($form, $context));
    }

    public function test_changing_episodes_keeps_other_supporting_information(): void
    {
        $resource = json_decode(file_get_contents(__DIR__.'/../../../../Fixtures/CarePlan/plan.json'), true, flags: JSON_THROW_ON_ERROR);
        $result = (new CarePlanResourceHarness())->toFhir(['episodes' => []], ['resource' => $resource]);

        $this->assertCount(1, $result['supportingInfo']);
        $this->assertSame('procedure', $result['supportingInfo'][0]['identifier']['type']['coding'][0]['code']);
    }

    public function test_clearing_optional_period_end_is_explicit_and_start_is_not_recomputed(): void
    {
        $resource = ['id' => 'plan-id', 'period' => ['start' => '2026-10-19T10:20:30.123Z', 'end' => '2026-11-01T23:59:59Z']];
        $result = (new CarePlanResourceHarness())->toFhir(['periodEnd' => ''], ['resource' => $resource]);

        $this->assertSame($resource['period']['start'], $result['period']['start']);
        $this->assertNull($result['period']['end']);
    }

    public function test_date_only_form_uses_explicit_timezone_including_dst(): void
    {
        $result = (new CarePlanResourceHarness())->toFhir(['periodStart' => '2026-10-25', 'periodStartTime' => '04:30:00'], ['id' => 'plan-id', 'timezone' => 'Europe/Kyiv']);

        $this->assertSame('2026-10-25T02:30:00Z', $result['period']['start']);
    }

    public function test_mapper_does_not_allocate_a_new_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CarePlanResourceHarness())->toFhir(['title' => 'Draft']);
    }

    public function test_optional_manager_references_can_be_cleared_explicitly(): void
    {
        $resource = json_decode(file_get_contents(__DIR__.'/../../../../Fixtures/CarePlan/plan.json'), true, flags: JSON_THROW_ON_ERROR);
        $resource['careManager'] = $resource['author'];
        $resource['managingOrganization'] = $resource['author'];
        $result = (new CarePlanResourceHarness())->toFhir(['careManager' => '', 'managingOrganization' => null], ['resource' => $resource]);
        $this->assertArrayNotHasKey('careManager', $result);
        $this->assertArrayNotHasKey('managingOrganization', $result);
        $this->assertSame($resource['author'], $result['author']);
    }
    public function test_editing_other_fields_keeps_author_and_encounter_despite_current_context(): void
    {
        $mapper = new CarePlanResourceHarness();
        $resource = json_decode(file_get_contents(__DIR__.'/../../../../Fixtures/CarePlan/plan.json'), true, flags: JSON_THROW_ON_ERROR);
        $context = ['resource' => $resource, 'employeeUuid' => 'current-employee', 'encounterUuid' => 'current-encounter'];
        $edited = $mapper->toFhir(['title' => 'Updated'], $context);
        $this->assertSame($resource['author'], $edited['author']);
        $this->assertSame($resource['encounter'], $edited['encounter']);
        $changed = $mapper->toFhir(['author' => 'explicit-author', 'encounter' => 'explicit-encounter'], $context);
        $this->assertSame('explicit-author', $changed['author']['identifier']['value']);
        $this->assertSame('explicit-encounter', $changed['encounter']['identifier']['value']);
        $created = $mapper->toFhir([], ['id' => 'new-plan', 'employeeUuid' => 'current-employee', 'encounterUuid' => 'current-encounter']);
        $this->assertSame('current-employee', $created['author']['identifier']['value']);
        $this->assertSame('current-encounter', $created['encounter']['identifier']['value']);
    }

    public function test_iso_time_edits_on_the_same_date_keep_the_new_instant_and_fractional_seconds(): void
    {
        $mapper = new CarePlanResourceHarness();
        $resource = ['id' => 'plan-id', 'period' => ['start' => '2026-10-26T08:00:00.123456Z', 'end' => '2026-10-27T08:00:00Z']];
        $edited = $mapper->toFhir(['periodStart' => '2026-10-26T10:00:00.654321Z', 'periodEnd' => '2026-10-27T10:00:00+01:00'], ['resource' => $resource]);
        $this->assertSame('2026-10-26T10:00:00.654321Z', $edited['period']['start']);
        $this->assertSame('2026-10-27T09:00:00Z', $edited['period']['end']);
        $unchanged = $mapper->toFhir(['periodStart' => '2026-10-26T09:00:00.123456+01:00'], ['resource' => $resource]);
        $this->assertSame($resource['period']['start'], $unchanged['period']['start']);
    }

}
