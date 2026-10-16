<?php

declare(strict_types=1);

namespace Tests\Unit\eHealth\Api;

use App\Classes\eHealth\Api\Configuration;
use App\Classes\eHealth\Api\Dictionary;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConfigurationQueryTest extends TestCase
{
    public static function activeFilters(): array
    {
        $cases = [];
        foreach ([
            [Configuration::class, 'getDictionaries', '/api/dictionaries_configurations'],
            [Configuration::class, 'getDevices', '/api/devices_configurations'],
            [Configuration::class, 'getDeviceParameters', '/api/device_parameters_configurations'],
            [Dictionary::class, 'getMany', '/api/v2/dictionaries'],
        ] as [$apiClass, $method, $path]) {
            foreach (['active boolean' => [true, 'true'], 'inactive boolean' => [false, 'false'], 'active literal' => ['true', 'true'], 'inactive literal' => ['false', 'false'], 'omitted' => [null, null]] as $name => [$input, $expected]) {
                $cases[$method.' '.$name] = [$apiClass, $method, $path, $input, $expected];
            }
        }

        return $cases;
    }

    #[DataProvider('activeFilters')]
    public function test_active_filter_is_encoded_as_api_boolean_literal(string $apiClass, string $method, string $path, bool|string|null $input, ?string $expected): void
    {
        config(['ehealth.api.domain' => 'https://ehealth.example']);
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        $factory = Http::getFacadeRoot();
        $api = new $apiClass($factory);
        $api->stub((function () {
            return $this->stubCallbacks;
        })->call($factory));
        $api->preventStrayRequests();
        $query = ['page' => 2, 'page_size' => 10];
        if ($input !== null) {
            $query['is_active'] = $input;
        }
        $api->{$method}($query);
        Http::assertSentCount(1);
        Http::assertSent(static function (Request $request) use ($path, $expected): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $sentQuery);

            return $request->method() === 'GET' && parse_url($request->url(), PHP_URL_PATH) === $path
                && ($sentQuery['is_active'] ?? null) === $expected
                && $sentQuery['page'] === '2' && $sentQuery['page_size'] === '10';
        });
    }
}
