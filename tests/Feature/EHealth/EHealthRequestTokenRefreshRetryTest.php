<?php

declare(strict_types=1);

namespace Tests\Feature\EHealth;

use App\Auth\EHealth\Services\TokenStorage;
use App\Classes\eHealth\Api\Employee;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EHealthRequestTokenRefreshRetryTest extends TestCase
{
    #[Test]
    public function retries_once_after_invalid_access_token_when_refresh_succeeds(): void
    {
        Session::start();
        Session::put(config('ehealth.api.oauth.bearer_token'), 'dead-token');

        $tokenStorage = Mockery::mock(TokenStorage::class);
        $tokenStorage->shouldReceive('refreshBearerToken')->once()->andReturn(true);
        $tokenStorage->shouldReceive('getBearerToken')->andReturn('fresh-token');
        $this->app->instance(TokenStorage::class, $tokenStorage);

        Http::fake([
            '*/api/employees/*/actions/deactivate' => Http::sequence()
                ->push([
                    'error' => ['type' => 'access_denied', 'message' => 'Invalid access token'],
                    'meta' => ['code' => 401],
                ], 401)
                ->push([
                    'data' => [
                        'id' => 'ac5b2c8e-35fa-489d-b5ef-733005dca33d',
                        'status' => 'STOPPED',
                        'is_active' => false,
                    ],
                    'meta' => ['code' => 200],
                ], 200),
        ]);

        $api = $this->makeEmployeeApi();

        $response = $api->deactivate('ac5b2c8e-35fa-489d-b5ef-733005dca33d', '2026-08-06', 'STOPPED');

        $this->assertInstanceOf(EHealthResponse::class, $response);
        $this->assertTrue($response->successful());
        Http::assertSentCount(2);
    }

    #[Test]
    public function does_not_retry_when_refresh_fails(): void
    {
        Session::start();
        Session::put(config('ehealth.api.oauth.bearer_token'), 'dead-token');

        $tokenStorage = Mockery::mock(TokenStorage::class);
        $tokenStorage->shouldReceive('refreshBearerToken')->once()->andReturn(false);
        $this->app->instance(TokenStorage::class, $tokenStorage);

        Http::fake([
            '*/api/employees/*/actions/deactivate' => Http::response([
                'error' => ['type' => 'access_denied', 'message' => 'Invalid access token'],
                'meta' => ['code' => 401],
            ], 401),
        ]);

        $api = $this->makeEmployeeApi();

        try {
            $api->deactivate('ac5b2c8e-35fa-489d-b5ef-733005dca33d', '2026-08-06', 'STOPPED');
            $this->fail('Expected EHealthResponseException was not thrown.');
        } catch (EHealthResponseException $exception) {
            $this->assertSame(401, $exception->getCode());
            Http::assertSentCount(1);
        }
    }

    private function makeEmployeeApi(): Employee
    {
        $factory = Http::getFacadeRoot();
        $api = new Employee($factory);

        $stubs = (function () {
            return $this->stubCallbacks;
        })->call($factory);
        $api->stub($stubs);

        return $api;
    }
}
