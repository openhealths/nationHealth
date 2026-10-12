<?php

declare(strict_types=1);

namespace Tests\Feature\EHealth;

use App\Classes\eHealth\Api\Auth;
use App\Classes\eHealth\Exceptions\ApiException;
use App\Classes\eHealth\Request as LegacyRequest;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EHealthCredentialLoggingTest extends TestCase
{
    private const string EMAIL = 'credential-log-test@example.invalid';

    private const string PASSWORD = 'test-password-not-for-real-login';

    private const string TOKEN = 'test-private-bearer-token';

    private const string CLIENT_SECRET = 'test-private-client-secret';

    /** @var list<array{message: string, context: array}> */
    private array $records = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['ehealth.api.domain' => 'https://ehealth.example.invalid/']);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('debug', 'error', 'warning', 'info')
            ->andReturnUsing(function (string $message, array $context = []): void {
                $this->records[] = compact('message', 'context');
            });
    }

    #[DataProvider('loginStatusProvider')]
    public function test_login_logs_only_metadata_and_sends_original_credentials(int $status): void
    {
        $payload = [
            'data' => ['value' => self::TOKEN, 'user_id' => 'test-user'],
            'error' => ['message' => self::EMAIL . ' ' . self::PASSWORD, 'password' => self::PASSWORD],
        ];
        Http::fake(['*' => Http::response($payload, $status)]);

        try {
            $response = $this->makeApi()->login(self::EMAIL, self::PASSWORD);
            $this->assertSame(200, $status);
            $this->assertSame(self::TOKEN, $response->json('data.value'));
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            $this->assertNotSame(200, $status);
            $this->assertSame($payload, $exception->getDetails());
            $exception->report();
            $exception->handle('Login API failed', 'Safe user-facing message');
            $this->assertSame('Safe user-facing message', Session::get('error'));
        }

        Http::assertSent(
            fn (Request $request): bool =>
            $request['token']['email'] === self::EMAIL
            && $request['token']['password'] === self::PASSWORD
        );
        $this->assertSame(['method' => 'POST', 'url' => 'auth/login'], $this->records[0]['context']);
        if ($status !== 200) {
            $this->assertSame(['status' => $status, 'url' => 'auth/login'], $this->records[1]['context']);
        }
        $this->assertNoCredentialsLogged();
    }

    public static function loginStatusProvider(): array
    {
        return ['success' => [200], 'validation' => [422], 'gateway failure' => [521]];
    }

    public function test_refresh_token_and_client_secret_are_sent_but_never_logged(): void
    {
        Http::fake(['*' => Http::response(['data' => ['value' => self::TOKEN]], 200)]);

        $this->makeApi()->extendTokenLifetime('client-id', self::CLIENT_SECRET, self::TOKEN);

        Http::assertSent(
            fn (Request $request): bool =>
            $request['token']['client_secret'] === self::CLIENT_SECRET
            && $request['token']['refresh_token'] === self::TOKEN
        );
        $this->assertNoCredentialsLogged();
    }

    #[DataProvider('requestOptionsProvider')]
    public function test_request_bodies_headers_and_url_secrets_are_not_logged(array $options): void
    {
        Http::fake(['*' => Http::response('Echo: ' . self::PASSWORD . ' ' . self::TOKEN, 521)]);
        $url = 'https://user:' . self::PASSWORD . '@ehealth.example.invalid/auth/login?email=' . self::EMAIL;

        try {
            $this->makeApi()->send('POST', $url, $options);
            $this->fail('The failed response must still throw an exception.');
        } catch (EHealthResponseException $exception) {
            $this->assertSame(521, $exception->response->status());
            $this->assertStringContainsString(self::PASSWORD, $exception->response->body());
        }

        Http::assertSentCount(1);
        $this->assertSame(['method' => 'POST', 'url' => '/auth/login'], $this->records[0]['context']);
        $this->assertSame(['status' => 521, 'url' => '/auth/login'], $this->records[1]['context']);
        $this->assertNoCredentialsLogged();
    }

    public static function requestOptionsProvider(): array
    {
        return [
            'JSON' => [['json' => ['token' => ['email' => self::EMAIL, 'password' => self::PASSWORD]]]],
            'form' => [['form_params' => ['email' => self::EMAIL, 'password' => self::PASSWORD]]],
            'short raw body' => [['body' => 'password=' . self::PASSWORD]],
            'long raw body' => [['body' => self::PASSWORD . str_repeat('x', 600)]],
            'headers' => [['headers' => ['Authorization' => 'Bearer ' . self::TOKEN, 'Cookie' => self::PASSWORD, 'API-key' => self::CLIENT_SECRET]]],
            'multipart' => [['multipart' => [['name' => 'password', 'contents' => self::PASSWORD]]]],
        ];
    }

    public function test_connection_exception_reporting_does_not_log_raw_message_or_previous_exception(): void
    {
        Http::fake(['*' => Http::failedConnection(self::PASSWORD . ' ' . self::EMAIL . ' ' . self::TOKEN)]);

        try {
            $this->makeApi()->login(self::EMAIL, self::PASSWORD);
            $this->fail('Connection errors must still throw.');
        } catch (EHealthConnectionException $exception) {
            $this->assertStringContainsString(self::PASSWORD, $exception->getMessage());
            $exception->report();
            $exception->handle('Login connection failed', 'Connection unavailable');
            $this->assertSame('Connection unavailable', Session::get('error'));
        }

        $this->assertNoCredentialsLogged();
    }

    public function test_legacy_request_and_exception_do_not_log_credentials_or_echoed_response(): void
    {
        config(['ehealth.api.api_key' => 'test-api-key']);
        $payload = ['error' => ['message' => self::PASSWORD . ' ' . self::EMAIL]];
        Http::fake(['*' => Http::response($payload, 500)]);

        try {
            (new LegacyRequest('post', 'auth/login?token=' . self::TOKEN, [
                'email' => self::EMAIL,
                'password' => self::PASSWORD,
            ], false))->sendRequest();
            $this->fail('The legacy client must still throw on failure.');
        } catch (ApiException $exception) {
            $exception->report();
        }

        Http::assertSent(fn (Request $request): bool => $request['password'] === self::PASSWORD);
        $this->assertSame(['url' => '/auth/login', 'status' => 500], $this->records[0]['context']);
        $this->assertNoCredentialsLogged();
    }

    private function assertNoCredentialsLogged(): void
    {
        $this->assertNotEmpty($this->records);
        $serialized = json_encode($this->records, JSON_THROW_ON_ERROR);

        foreach ([self::EMAIL, self::PASSWORD, self::TOKEN, self::CLIENT_SECRET] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    private function makeApi(): Auth
    {
        $factory = Http::getFacadeRoot();
        $api = new Auth($factory);
        $api->stub((function () {
            return $this->stubCallbacks;
        })->call($factory));

        return $api;
    }
}
