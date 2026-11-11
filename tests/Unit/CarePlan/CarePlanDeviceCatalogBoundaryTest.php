<?php

declare(strict_types=1);

namespace Tests\Unit\CarePlan;

use App\Classes\eHealth\Api\DeviceDefinition as DeviceDefinitionApi;
use App\Livewire\CarePlan\Concerns\ManagesCarePlanReferrals;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\NativeClinicalFixtures;
use Tests\TestCase;

class CarePlanDeviceCatalogBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ehealth.api.domain' => 'https://ehealth.test']);
        Http::preventStrayRequests();
    }

    public function test_detail_uses_the_full_validated_native_definition(): void
    {
        $definition = NativeClinicalFixtures::definition();
        $this->fakeDetail($definition);

        $this->assertSame($definition, $this->lookup());
        Http::assertSentCount(1);
    }

    public function test_malformed_detail_cannot_supply_packaging_or_trigger_fallback(): void
    {
        $this->fakeDetail(NativeClinicalFixtures::definition(['packaging' => ['packaging_count' => null]]));

        $this->assertNull($this->lookup());
        Http::assertSentCount(1);
    }

    public function test_another_definition_cannot_supply_packaging_or_trigger_fallback(): void
    {
        $this->fakeDetail(NativeClinicalFixtures::definition(['id' => 'a17dc4b1-c0e2-43dd-a5ec-fd3ee2a9e1d8']));

        $this->assertNull($this->lookup());
        Http::assertSentCount(1);
    }

    public function test_catalogue_fallback_reads_the_matching_definition_after_page_ten(): void
    {
        $api = $this->fallbackApi();
        for ($page = 1; $page <= 11; $page++) {
            $definition = NativeClinicalFixtures::definition([
                'id' => $page === 11 ? NativeClinicalFixtures::UUID : 'a17dc4b1-c0e2-43dd-a5ec-fd3ee2a9e1d8',
            ]);
            $paging = array_replace(NativeClinicalFixtures::paging($page, 11, 11), ['page_size' => 1]);
            $api->shouldReceive('getMany')->once()->with(['page_size' => 100, 'page' => $page])
                ->andReturn(NativeClinicalFixtures::definitionResponse([$definition], $paging));
        }

        $this->assertSame(NativeClinicalFixtures::definition(), $this->lookup());
        Http::assertNothingSent();
    }

    public function test_malformed_catalogue_cannot_supply_packaging(): void
    {
        $api = $this->fallbackApi();
        $api->shouldReceive('getMany')->once()->andReturn(NativeClinicalFixtures::definitionResponse([
            NativeClinicalFixtures::definition(['packaging' => ['packaging_count' => null]]),
        ], NativeClinicalFixtures::paging()));

        $this->expectException(ValidationException::class);
        $this->lookup();
    }

    public function test_an_unexpected_catalogue_page_is_rejected_before_selection(): void
    {
        $api = $this->fallbackApi();
        $api->shouldReceive('getMany')->once()->andReturn(NativeClinicalFixtures::definitionResponse([
            NativeClinicalFixtures::definition(),
        ], NativeClinicalFixtures::paging(2, 2, 2)));

        $this->expectException(ValidationException::class);
        $this->lookup();
    }

    public function test_changing_page_totals_are_rejected_before_selection(): void
    {
        $api = $this->fallbackApi();
        $api->shouldReceive('getMany')->once()->with(['page_size' => 100, 'page' => 1])
            ->andReturn(NativeClinicalFixtures::definitionResponse([
                NativeClinicalFixtures::definition(['id' => 'a17dc4b1-c0e2-43dd-a5ec-fd3ee2a9e1d8']),
            ], NativeClinicalFixtures::paging(1, 2, 2)));
        $api->shouldReceive('getMany')->once()->with(['page_size' => 100, 'page' => 2])
            ->andReturn(NativeClinicalFixtures::definitionResponse([
                NativeClinicalFixtures::definition(),
            ], NativeClinicalFixtures::paging(2, 3, 3)));

        $this->expectException(ValidationException::class);
        $this->lookup();
    }

    private function fakeDetail(array $definition): void
    {
        $factory = Http::getFacadeRoot();
        Http::fake(['ehealth.test/*' => Http::response(['data' => $definition])]);
        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api = new DeviceDefinitionApi($factory);
        $api->stub($stubs)->preventStrayRequests();
        $this->instance(DeviceDefinitionApi::class, $api);
    }

    private function fallbackApi(): DeviceDefinitionApi
    {
        $api = Mockery::mock(DeviceDefinitionApi::class)->makePartial();
        $api->shouldReceive('getById')->once()->with(NativeClinicalFixtures::UUID)
            ->andThrow(new RuntimeException('Detail unavailable'));
        $this->instance(DeviceDefinitionApi::class, $api);

        return $api;
    }

    private function lookup(): ?array
    {
        $component = new class
        {
            use ManagesCarePlanReferrals;
        };
        $method = new ReflectionMethod($component, 'findDeviceDefinitionByReference');

        return $method->invoke($component, NativeClinicalFixtures::UUID, null);
    }
}
