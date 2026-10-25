<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Classes\eHealth\Api\Responses\Collections\ServiceRequestSearch;
use App\Dto\ServiceRequest\Model as ServiceRequestModelData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Tests\TestCase;

class ServiceRequestExternalResponseTest extends TestCase
{
    public static function contracts(): iterable
    {
        $directory = dirname(__DIR__, 2).'/Fixtures/Mapping';
        $inputs = require $directory.'/service-request-external-inputs.php';
        $baseline = json_decode(file_get_contents($directory.'/service-request-external-baseline.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($inputs as $name => $input) {
            yield $name => [$input, $baseline['cases'][$name]];
        }
    }

    #[DataProvider('contracts')]
    public function test_full_import_matches_the_independently_captured_legacy_contract(array $input, array $expected): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $result = app(ObjectMapperInterface::class)->map(new ServiceRequestSearch($input), ServiceRequestModelData::class);

        $this->assertSame($expected, $result->toExternalRecord());
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public function test_source_class_selects_import_rules_without_changing_partial_sync(): void
    {
        $source = ['id' => 'remote-id', 'uuid' => 'local-id', 'request_number' => 'snake', 'requisition' => 'camel',
            'occurrencePeriod' => ['start' => '2026-10-01T14:15:00+03:00'],
            'basedOn' => [['identifier' => ['value' => 'activity-id']]], 'supportingInfo' => [[]]];
        $mapper = app(ObjectMapperInterface::class);
        $import = $mapper->map(new ServiceRequestSearch($source), ServiceRequestModelData::class)->toExternalRecord();
        $sync = $mapper->map((object) $source, ServiceRequestModelData::class)->toSyncPatch();

        $this->assertSame('local-id', $import['uuid']);
        $this->assertSame('remote-id', $sync['uuid']);
        $this->assertSame('camel', $import['request_number']);
        $this->assertSame('snake', $sync['request_number']);
        $this->assertSame('2026-10-01T14:15:00+03:00', $import['started_at']);
        $this->assertSame('2026-10-01', $sync['started_at']);
        $this->assertSame('activity-id', $import['based_on_uuid']);
        $this->assertArrayNotHasKey('based_on_uuid', $sync);
        $this->assertSame([['uuid' => null, 'type' => null]], $import['supporting_info']);
        $this->assertArrayNotHasKey('supporting_info', $sync);
    }
}
