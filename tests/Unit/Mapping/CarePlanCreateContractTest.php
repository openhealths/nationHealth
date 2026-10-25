<?php

declare(strict_types=1);

namespace Tests\Unit\Mapping;

use App\Classes\eHealth\Api\CarePlan as CarePlanApi;
use App\Classes\eHealth\Api\CarePlanActivity as ActivityApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CarePlanCreateContractTest extends TestCase
{
    #[DataProvider('contracts')]
    public function testApiCreatePreservesIndependentSigningOrderAndAllowlist(array $input): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $api = $input['target'] === 'plan' ? app(CarePlanApi::class) : app(ActivityApi::class);
        $source = $input['input'];
        $actual = $api->buildCreateContent($source);
        $json = json_encode($actual, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $this->assertSame(json_decode($input['json'], true, flags: JSON_THROW_ON_ERROR), $actual);
        $this->assertSame($input['json'], $json);
        $this->assertStringNotContainsString('serverOnly', $json);
        $this->assertStringNotContainsString('synthetic-server-field', $json);
        $this->assertSame($input['input'], $source);
        $this->assertSame([], $queries);
        Http::assertNothingSent();
    }

    public static function contracts(): iterable
    {
        $file = dirname(__DIR__, 2).'/Fixtures/Mapping/care-plan-api-baseline.json';
        foreach (json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) as $name => $input) {
            yield $name => [$input];
        }
    }
}
