<?php

declare(strict_types=1);

namespace App\Classes\eHealth;

use App\Classes\eHealth\Errors\BaseErrorTranslator;
use App\Contracts\ApiErrorTranslator;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthRateLimitedException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Traits\EHealthApiFormatter;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\RequestException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use InvalidArgumentException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HigherOrderTapProxy;
use Illuminate\Support\Str;

abstract class EHealthRequest extends PendingRequest
{
    use EHealthApiFormatter;

    public const string QUERY_PARAM_PAGE_SIZE = 'page_size';

    /**
     * The HTTP request timeout in seconds.
     * This is specifically needed to load dictionaries
     * TODO load dictionaries during first-run or similar installation process
     */
    public const int TIMEOUT = 100;

    protected ?Closure $validator = null;

    protected ?Closure $mapper = null;

    protected EHealthRateLimiter $rateLimiter;

    public function __construct(
        ?Factory $factory = null,
        $middleware = [],
        ?EHealthRateLimiter $rateLimiter = null,
    ) {
        parent::__construct($factory, $middleware);

        $this->rateLimiter = $rateLimiter ?? app(EHealthRateLimiter::class);

        $this->withHeaders([
            'X-Custom-PSK' => config('ehealth.api.token'),
            'API-key' => config('ehealth.api.api_key'),
        ]);

        $token = session()->get(
            config('ehealth.api.oauth.bearer_token')
        );

        if ($token) {
            $this->withToken($token);
        }

        $this->baseUrl(
            config('ehealth.api.domain')
        );
    }

    /**
     * Sends an HTTP request to the eHealth API and handles the response.
     *
     * This method overrides the parent send method to provide custom error handling:
     * - Returns the response if it is not an EHealthResponse instance.
     * - Returns the response if it is successful.
     * - Throws EHealthValidationException if the response status is 422 (validation error).
     * - Throws EHealthResponseException for all other unsuccessful responses.
     *
     * @param  string  $method  The HTTP method (e.g., 'GET', 'POST', etc.).
     * @param  string  $url  The endpoint URL.
     * @param  array  $options  Additional request options (query, body, headers, etc.).
     * @return EHealthResponse|Response
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException|EHealthRateLimitedException
     */
    public function send(string $method, string $url, array $options = []): EHealthResponse|Response
    {
        Log::debug("eHealth Request: {$method} {$url}", [
            'options' => $this->sanitizeOptionsForLog($options),
        ]);

        try {
            $response = parent::send($method, $url, $options);
        } catch (ConnectionException $exception) {
            throw new EHealthConnectionException($exception->getMessage(), $exception->getCode(), $exception);
        } catch (RequestException $exception) {
            // Laravel's retry() may throw before send() returns; keep the same eHealth error handling.
            $response = $exception->response;
        }

        if (!is_a($response, EHealthResponse::class)) {
            return $response;
        }

        if ($response->successful() || $response->redirect()) {
            return $response;
        }

        if ($response->status() === 422) {
            Log::error('eHealth validation failed 422', [
                'url' => $url,
                'request' => $this->sanitizeOptionsForLog($options),
                'response' => $response->json()
            ]);
            throw new EHealthValidationException($response->json(), $this->errorTranslator());
        }

        Log::error('eHealth request failed', [
            'status' => $response->status(),
            'url' => $url,
            'body' => $response->body()
        ]);

        throw new EHealthResponseException($response, $this->errorTranslator());
    }

    /**
     * Apply limits after Laravel resolves the URL and completes beforeSending header changes.
     * This handler runs for every HTTP attempt, including retries and redirected requests.
     */
    public function buildBeforeSendingHandler(): Closure
    {
        $beforeSending = parent::buildBeforeSendingHandler();

        return function (callable $handler) use ($beforeSending): callable {
            return $beforeSending(function (RequestInterface $request, array $options) use ($handler) {
                $key = $this->reserveRequest($request);

                return $handler($request, $options)->then(function (ResponseInterface $response) use ($key): ResponseInterface {
                    if ($key !== null && $response->getStatusCode() === 429) {
                        $seconds = (new EHealthResponseException(new Response($response)))->retryAfter();
                        try {
                            $this->rateLimiter->cooldown($key, $seconds);
                        } catch (LockTimeoutException) {
                            // The current caller must still receive HTTP 429 and wait the full Retry-After.
                            Log::warning('eHealth Retry-After could not be shared because its cache lock is busy.');
                        }
                    }

                    return $response;
                });
            });
        };
    }

    /**
     * Count the final HTTP request against its configured group and token.
     * Return the shared key for a later 429, or null when no group/token applies.
     */
    protected function reserveRequest(RequestInterface $request): ?string
    {
        $group = $this->rateLimitGroup($request);
        $authorization = $request->getHeaderLine('Authorization');
        if ($group === null || $authorization === '') {
            return null;
        }

        if (count($request->getHeader('Authorization')) !== 1) {
            throw new InvalidArgumentException('A rate-limited eHealth request must have exactly one Authorization header value.');
        }

        $key = $this->rateLimitKey($request, $group, $authorization);
        $this->rateLimiter->reserve(
            $key,
            (int) config('ehealth.rate_limit.'.$group.'.max_requests'),
            (int) config('ehealth.rate_limit.'.$group.'.window_seconds'),
        );

        return $key;
    }

    /**
     * Select the group whose configured method and exact path pattern match this request.
     * For example, GET /api/employees/123 uses employee; /api/employees/123/roles does not.
     * Subclasses may override this selection for a resource with different quota rules.
     */
    protected function rateLimitGroup(RequestInterface $request): ?string
    {
        $path = rtrim($request->getUri()->getPath(), '/');
        foreach (config('ehealth.rate_limit', []) as $group => $policy) {
            if (!is_array($policy) || !in_array($request->getMethod(), $policy['methods'] ?? [], true)) {
                continue;
            }
            foreach ($policy['paths'] ?? [] as $pattern) {
                if (preg_match($pattern, $path) === 1) {
                    return $group;
                }
            }
        }

        return null;
    }

    /** Group by the actual API host and token, never by a local user ID or the request client's initial token. */
    protected function rateLimitKey(RequestInterface $request, string $group, string $authorization): string
    {
        // Authentication scheme casing/spacing does not create a new quota for the same bearer token.
        $identity = preg_match('/^Bearer[ \t]+([^ \t,]+)[ \t]*$/i', trim($authorization), $matches) === 1
            ? $matches[1]
            : $authorization;

        return 'ehealth-requests:'.$request->getUri()->getHost().':'.$group.':'.hash('sha256', $identity);
    }

    /**
     * Remove sensitive and too-large values from HTTP client logs.
     */
    protected function sanitizeOptionsForLog(array $options): array
    {
        if (isset($options['json']) && is_array($options['json'])) {
            $json = $options['json'];

            foreach (['signed_content', 'signed_medication_request_request', 'signed_medication_reject', 'signed_device_request_request', 'signed_data', 'signed_legal_entity_request'] as $signedKey) {
                if (isset($json[$signedKey]) && is_string($json[$signedKey])) {
                    $json[$signedKey] = '[base64_signed_content_redacted length=' . strlen($json[$signedKey]) . ']';
                }
            }

            // Composition sign/cancel carry the PKCS#7 blob in `data`.
            if (isset($json['data']) && is_string($json['data']) && strlen($json['data']) > 512) {
                $json['data'] = '[base64_signed_content_redacted length=' . strlen($json['data']) . ']';
            }

            $options['json'] = $json;
        }

        if (isset($options['body']) && is_string($options['body']) && strlen($options['body']) > 512) {
            $options['body'] = '[body_redacted length=' . strlen($options['body']) . ' preview=' . Str::limit($options['body'], 120) . ']';
        }

        return $options;
    }

    /**
     * Set a Callable validator for the response, which accepts an EHealthResponse instance as an argument.
     * See EHealthResponse::validate().
     *
     * @param  callable  $validator
     */
    protected function setValidator(callable $validator): void
    {
        $this->validator = $validator;
    }

    /**
     * Set a Callable mapper for the response, which accepts an EHealthResponse instance as an argument.
     * See EHealthResponse::map().
     *
     * @param  callable  $mapper
     * @return void
     */
    protected function setMapper(callable $mapper): void
    {
        $this->mapper = $mapper;
    }

    /**
     * Set the default page size for the request.
     * It's the maximum number of items that can be returned per page.
     */
    protected function setDefaultPageSize(): void
    {
        $this->withQueryParameters([
            self::QUERY_PARAM_PAGE_SIZE => config('ehealth.api.page_size'),
        ]);
    }

    /**
     * Get the translator of the errors this resource returns. A resource with its own errors overrides it.
     *
     * @return ApiErrorTranslator
     */
    protected function errorTranslator(): ApiErrorTranslator
    {
        return new BaseErrorTranslator();
    }

    /**
     * Overrides the HTTP Client Request method to get a custom response.
     */
    protected function newResponse($response): HigherOrderTapProxy|EHealthResponse
    {
        return tap(new EHealthResponse($response, $this->validator, $this->mapper), function (EHealthResponse $laravelResponse) {

            if ($this->truncateExceptionsAt === null) {
                return;
            }

            $this->truncateExceptionsAt === false
                ? $laravelResponse->dontTruncateExceptions()
                : $laravelResponse->truncateExceptionsAt($this->truncateExceptionsAt);
        });
    }

    public function asMis(): self
    {
        $misToken = config('ehealth.api.mis_token');
        $misApiKey = config('ehealth.api.mis_api_key');

        if (empty($misApiKey)) {
            throw new \RuntimeException('CRITICAL: MIS API Key is missing!');
        }

        if (empty($misToken)) {
            throw new \RuntimeException('CRITICAL: MIS Token is missing! Check if it is expired.');
        }

        // 1. We forcibly replace the Bearer Token with the MIS token
        $this->withToken($misToken);

        // 2. Update the API-key and add a User-Agent to bypass WAF (Cloudflare)
        $this->withHeaders([
            'API-key' => $misApiKey,
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'application/json',
        ]);

        return $this;
    }
}
