<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\CarePlan\Model;
use App\Dto\CarePlan\Form;
use App\Dto\FormCollection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CarePlanDataTest extends TestCase
{
    public function testUnchangedFormPreservesFullResourceAndTimestampPrecision(): void
    {
        $resource = json_decode(file_get_contents(__DIR__.'/../../Fixtures/CarePlan/plan.json'), true, flags: JSON_THROW_ON_ERROR);
        $context = ['resource' => $resource, 'timezone' => 'Europe/Kyiv', 'dateFormat' => 'd.m.Y'];

        $form = $this->toForm($resource, $context);

        $this->assertSame('19.10.2026', $form['periodStart']);
        $this->assertSame('13:20:30', $form['periodStartTime']);
        $this->assertEquals($resource, $this->toResource($form, $context));
    }

    public function testChangingEpisodesKeepsOtherSupportingInformation(): void
    {
        $resource = json_decode(file_get_contents(__DIR__.'/../../Fixtures/CarePlan/plan.json'), true, flags: JSON_THROW_ON_ERROR);
        $result = $this->toResource(['episodes' => []], ['resource' => $resource]);

        $this->assertCount(1, $result['supportingInfo']);
        $this->assertSame('procedure', $result['supportingInfo'][0]['identifier']['type']['coding'][0]['code']);
    }

    public function testClearingOptionalPeriodEndIsExplicitAndStartIsNotRecomputed(): void
    {
        $resource = ['id' => 'plan-id', 'period' => ['start' => '2026-10-19T10:20:30.123Z', 'end' => '2026-11-01T23:59:59Z']];
        $result = $this->toResource(['periodEnd' => ''], ['resource' => $resource]);

        $this->assertSame($resource['period']['start'], $result['period']['start']);
        $this->assertNull($result['period']['end']);
    }

    public function testDateOnlyFormUsesExplicitTimezoneIncludingDst(): void
    {
        $result = $this->toResource(['periodStart' => '2026-10-25', 'periodStartTime' => '04:30:00'], ['id' => 'plan-id', 'timezone' => 'Europe/Kyiv']);

        $this->assertSame('2026-10-25T02:30:00Z', $result['period']['start']);
    }

    public function testMapperDoesNotAllocateANewIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->toResource(['title' => 'Draft']);
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
