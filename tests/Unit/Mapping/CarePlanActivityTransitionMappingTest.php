<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Dto\CarePlanActivity\EhealthCancel;
use App\Dto\CarePlanActivity\EhealthComplete;
use App\Livewire\CarePlan\CarePlanShow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class CarePlanActivityTransitionMappingTest extends TestCase
{
    public function test_cancel_changes_only_status_reason_in_the_remote_document(): void
    {
        $snapshot = [
            'id' => 'activity',
            'unknownClinicalField' => ['zero' => 0, 'false' => false, 'emptyList' => []],
            'author' => ['identifier' => ['value' => 'employee']],
            'detail' => ['status' => 'scheduled', 'do_not_perform' => false, 'quantity' => ['value' => 1.0]],
            'statusHistory' => [['status' => 'scheduled']],
        ];
        $component = new CarePlanShow();
        $component->statusReason = 'typo';
        $this->preventMappingIo($queries);
        $payload = app(ObjectMapperInterface::class)->map($component, new EhealthCancel($snapshot))->toArray();
        $expected = $snapshot;
        $expected['detail']['status_reason'] = [
            'coding' => [['system' => 'eHealth/care_plan_activity_cancel_reasons', 'code' => 'typo']],
        ];
        $this->assertSame($expected, $payload);
        $this->assertSame(json_encode($expected, JSON_PRESERVE_ZERO_FRACTION), json_encode($payload, JSON_PRESERVE_ZERO_FRACTION));
        $this->assertSame($snapshot['detail']['status'], $payload['detail']['status']);
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    #[DataProvider('completeInputs')]
    public function test_complete_preserves_the_existing_unsigned_patch_contract(string $code, array $references): void
    {
        $component = new CarePlanShow();
        $component->statusReason = 'completed';
        $component->outcomeCode = $code;
        $component->outcomeReferences = $references;
        $this->preventMappingIo($queries);
        $payload = app(ObjectMapperInterface::class)->map($component, EhealthComplete::class)->toArray();
        // The previous CarePlanManager PATCH builder: detail, optional outcome, optional references.
        $expected = ['detail' => ['status_reason' => ['coding' => [[
            'system' => 'eHealth/care_plan_activity_complete_reasons', 'code' => 'completed',
        ]]]]];
        if ($code) {
            $expected['outcome_codeable_concept'] = [['coding' => [[
                'system' => 'eHealth/care_plan_activity_outcomes', 'code' => $code,
            ]]]];
        }
        if ($references !== []) {
            $expected['outcome_reference'] = array_map(static fn (string $uuid): array => ['identifier' => ['value' => $uuid]], $references);
        }
        $this->assertSame($expected, $payload);
        $this->assertSame(json_encode($expected), json_encode($payload));
        $this->assertArrayNotHasKey('signed_data', $payload);
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public static function completeInputs(): iterable
    {
        yield 'reason only' => ['', []];
        yield 'outcome and references' => ['improved', ['one', 'two']];
        yield 'legacy false-like outcome' => ['0', []];
        yield 'sparse reference keys' => ['', [2 => 'two', 5 => 'five']];
    }

    private function preventMappingIo(?array &$queries): void
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        Http::preventStrayRequests();
        Http::fake();
    }
}
