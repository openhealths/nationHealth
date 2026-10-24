<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MedicalEvents\Mappers;

use App\Services\MedicalEvents\Mappers\CarePlanMapper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CarePlanMapperTest extends TestCase
{
    public function test_unchanged_form_preserves_full_resource_and_timestamp_precision(): void
    {
        $resource = json_decode(file_get_contents(__DIR__.'/../../../../Fixtures/CarePlan/plan.json'), true, flags: JSON_THROW_ON_ERROR);
        $mapper = new CarePlanMapper();
        $context = ['resource' => $resource, 'timezone' => 'Europe/Kyiv', 'dateFormat' => 'd.m.Y'];

        $form = $mapper->fromFhir($resource, $context);

        $this->assertSame('19.10.2026', $form['periodStart']);
        $this->assertSame('13:20:30', $form['periodStartTime']);
        $this->assertEquals($resource, $mapper->toFhir($form, $context));
    }

    public function test_changing_episodes_keeps_other_supporting_information(): void
    {
        $resource = json_decode(file_get_contents(__DIR__.'/../../../../Fixtures/CarePlan/plan.json'), true, flags: JSON_THROW_ON_ERROR);
        $result = (new CarePlanMapper())->toFhir(['episodes' => []], ['resource' => $resource]);

        $this->assertCount(1, $result['supportingInfo']);
        $this->assertSame('procedure', $result['supportingInfo'][0]['identifier']['type']['coding'][0]['code']);
    }

    public function test_clearing_optional_period_end_is_explicit_and_start_is_not_recomputed(): void
    {
        $resource = ['id' => 'plan-id', 'period' => ['start' => '2026-10-19T10:20:30.123Z', 'end' => '2026-11-01T23:59:59Z']];
        $result = (new CarePlanMapper())->toFhir(['periodEnd' => ''], ['resource' => $resource]);

        $this->assertSame($resource['period']['start'], $result['period']['start']);
        $this->assertNull($result['period']['end']);
    }

    public function test_date_only_form_uses_explicit_timezone_including_dst(): void
    {
        $result = (new CarePlanMapper())->toFhir(['periodStart' => '2026-10-25', 'periodStartTime' => '04:30:00'], ['id' => 'plan-id', 'timezone' => 'Europe/Kyiv']);

        $this->assertSame('2026-10-25T02:30:00Z', $result['period']['start']);
    }

    public function test_mapper_does_not_allocate_a_new_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CarePlanMapper())->toFhir(['title' => 'Draft']);
    }
}
