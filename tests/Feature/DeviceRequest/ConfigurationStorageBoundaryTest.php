<?php

declare(strict_types=1);

namespace Tests\Feature\DeviceRequest;

use App\Classes\eHealth\Api\Configuration;
use App\Jobs\ConfigurationMetadataSync;
use App\Jobs\ObservationConfigurationSync;
use App\Models\ConfigurationMetadata;
use App\Models\ObservationConfig;
use App\Repositories\Repository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\NativeClinicalFixtures as Native;
use Tests\TestCase;

class ConfigurationStorageBoundaryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_invalid_metadata_does_not_write_or_dispatch_a_configuration_refresh(): void
    {
        Queue::fake();
        $api = Mockery::mock(Configuration::class);
        $api->shouldReceive('getMetadata')->once()->andReturn(Native::response('GET /api/configurations/metadata', [
            ['resource' => 'observation_configurations', 'updated_at' => null],
        ]));
        $this->instance(Configuration::class, $api);

        (new ConfigurationMetadataSync())->handle();

        $this->assertSame(0, ConfigurationMetadata::count());
        Queue::assertNothingPushed();
    }

    public function test_invalid_second_page_cannot_partially_replace_observation_rules(): void
    {
        $existing = ObservationConfig::create(['code' => 'old', 'system' => 'test', 'is_active' => true, 'category' => [], 'settings' => ['old' => true]]);
        $api = Mockery::mock(Configuration::class);
        $api->shouldReceive('getObservations')->once()->with(['page' => 1])->andReturn(Native::response('GET /api/observation_configurations', [Native::configuration()], Native::paging(1, 2, 2)));
        $api->shouldReceive('getObservations')->once()->with(['page' => 2])->andReturn(Native::response('GET /api/observation_configurations', [Native::configuration(['settings' => 'invalid json'])], Native::paging(2, 2, 2)));
        $this->instance(Configuration::class, $api);

        (new ObservationConfigurationSync())->handle(Repository::observationConfig());

        $this->assertSame(1, ObservationConfig::count());
        $this->assertSame(['old' => true], $existing->fresh()->settings);
    }

    public function test_valid_native_settings_are_decoded_only_after_complete_validation(): void
    {
        $api = Mockery::mock(Configuration::class);
        $api->shouldReceive('getObservations')->once()->with(['page' => 1])->andReturn(Native::response('GET /api/observation_configurations', [Native::configuration(['settings' => ['RESULT_TYPE' => ['check' => 'quantity']]])], Native::paging()));
        $this->instance(Configuration::class, $api);

        (new ObservationConfigurationSync())->handle(Repository::observationConfig());

        $this->assertDatabaseHas('observation_configs', ['code' => 'test', 'value_type' => 'valueQuantity']);
        $this->assertSame(['RESULT_TYPE' => ['check' => 'quantity']], ObservationConfig::firstOrFail()->settings);
    }
}
