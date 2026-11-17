<?php

declare(strict_types=1);

namespace Tests\Unit\eHealth;

use App\Classes\eHealth\Api\EmployeeApi;
use App\Classes\eHealth\Api\LegalEntitiesApi;
use App\Classes\eHealth\Errors\ErrorHandler;
use App\Classes\eHealth\Exceptions\ApiException;
use App\Classes\eHealth\Request as LegacyRequest;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyRequestErrorHandlingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ehealth.api.domain' => 'https://ehealth.example']);
        Http::preventStrayRequests();
    }

    #[DataProvider('legacyApiCalls')]
    public function test_shared_api_calls_preserve_the_api_exception_contract(
        string $client,
        string $action,
        array $arguments,
        string $method,
        string $path
    ): void {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid access token']], 403)]);

        $this->assertApiFailure(
            static fn () => $client::$action(...$arguments),
            403,
            'Недійсний токен доступу'
        );

        Http::assertSentCount(1);
        Http::assertSent(static fn (HttpRequest $request): bool => $request->method() === $method
            && parse_url($request->url(), PHP_URL_HOST) === 'ehealth.example'
            && parse_url($request->url(), PHP_URL_PATH) === $path);
    }

    public static function legacyApiCalls(): array
    {
        return [
            'legal entity search' => [LegalEntitiesApi::class, '_get', [], 'GET', '/api/v2/legal_entities'],
            'legal entity details' => [LegalEntitiesApi::class, '_getById', ['entity-id'], 'GET', '/api/v2/legal_entities/entity-id'],
            'legal entity verification' => [LegalEntitiesApi::class, '_verify', ['entity-id'], 'PATCH', '/api/legal_entities/entity-id/actions/nhs_verify'],
            'legal entity creation or update' => [LegalEntitiesApi::class, '_createOrUpdate', [[]], 'PUT', '/api/v2/legal_entities'],
            'login user details' => [EmployeeApi::class, 'getUserDetails', [], 'GET', '/oauth/user'],
        ];
    }

    public function test_token_exchange_rejections_keep_their_status_and_translation(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid scope']], 400)]);
        $request = new LegacyRequest('POST', '/oauth/tokens', ['token' => ['code' => 'synthetic-code']], false);

        $this->assertApiFailure($request->sendRequest(...), 400, 'Недійсний доступ');

        Http::assertSentCount(1);
        Http::assertSent(static fn (HttpRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://ehealth.example/oauth/tokens'
            && !$request->hasHeader('Authorization'));
    }

    public function test_unauthorized_user_lookup_clears_the_ehealth_token_context(): void
    {
        $keys = [
            config('ehealth.api.oauth.bearer_token'),
            config('ehealth.api.oauth.token_scopes'),
            'auth_token_expires_at',
            'refresh_token',
        ];
        foreach ($keys as $key) {
            session()->put($key, 'synthetic-value');
        }
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid access token']], 401)]);

        $this->assertApiFailure(EmployeeApi::getUserDetails(...), 401, 'Недійсний токен доступу');

        foreach ($keys as $key) {
            $this->assertFalse(session()->has($key));
        }
    }

    #[DataProvider('unstructuredResponses')]
    public function test_unstructured_failures_still_throw_an_api_exception(string $body): void
    {
        Http::fake(['*' => Http::response($body, 502)]);

        $this->assertApiFailure(EmployeeApi::getUserDetails(...), 502, 'Помилка в процесі обробки запиту');
    }

    public static function unstructuredResponses(): array
    {
        return [
            'HTML gateway response' => ['<html>Bad Gateway</html>'],
            'empty body' => [''],
            'JSON null' => ['null'],
            'JSON string' => ['"Service unavailable"'],
            'JSON number' => ['502'],
            'JSON boolean' => ['false'],
        ];
    }

    #[DataProvider('malformedErrorPayloads')]
    public function test_malformed_details_do_not_mask_the_api_failure(array $payload, string $expected): void
    {
        Http::fake(['*' => Http::response($payload, 422)]);

        $this->assertApiFailure(EmployeeApi::getUserDetails(...), 422, $expected);
    }

    public static function malformedErrorPayloads(): array
    {
        return [
            'non-string top-level message' => [
                ['error' => ['message' => ['unexpected']]],
                'Помилка в процесі обробки запиту',
            ],
            'blank top-level message' => [
                ['error' => ['message' => ' ']],
                'Помилка в процесі обробки запиту',
            ],
            'non-string field' => [
                ['error' => ['invalid' => [[
                    'entry' => ['unexpected'], 'rules' => [['description' => 'has invalid format']],
                ]]]],
                'Невідоме поле: Некоректний формат значення.',
            ],
            'non-string rule description' => [
                ['error' => ['invalid' => [[
                    'entry' => '$.drfo', 'rules' => [['description' => ['unexpected']]],
                ]]]],
                'Номер РНОКПП: Опис помилки відсутній',
            ],
            'non-array rules' => [
                ['error' => ['message' => 'Invalid signature', 'invalid' => [[
                    'entry' => '$.drfo', 'rules' => 'unexpected',
                ]]]],
                'Некоректний КЕП',
            ],
            'blank rule description with a request message' => [
                ['error' => ['message' => 'Invalid signature', 'invalid' => [[
                    'entry' => '$.drfo', 'rules' => [['description' => ' ']],
                ]]]],
                'Некоректний КЕП',
            ],
            'non-array invalid entry with a valid sibling' => [
                ['error' => ['invalid' => ['unexpected', [
                    'entry' => '$.drfo', 'rules' => [['description' => 'has invalid format']],
                ]]]],
                'Номер РНОКПП: Некоректний формат значення.',
            ],
            'non-array rule with a valid sibling' => [
                ['error' => ['invalid' => [[
                    'entry' => '$.drfo', 'rules' => ['unexpected', ['description' => 'has invalid format']],
                ]]]],
                'Номер РНОКПП: Некоректний формат значення.',
            ],
        ];
    }

    public function test_license_activity_rejections_keep_the_form_field_label(): void
    {
        $result = new ErrorHandler()->handleError(['error' => ['invalid' => [[
            'entry' => '$.license.what_licensed',
            'rules' => [['description' => 'string does not match pattern']],
        ]]]]);

        $this->assertSame(
            ['Напрям діяльності, що ліцензовано: Рядок не відповідає шаблону'],
            $result
        );
    }

    protected function assertApiFailure(callable $request, int $status, string $message): void
    {
        try {
            $request();
            $this->fail('An unsuccessful response must throw an API exception.');
        } catch (ApiException $exception) {
            $this->assertSame($status, $exception->getCode());
            $this->assertSame($message, $exception->getMessage());
        }
    }
}
