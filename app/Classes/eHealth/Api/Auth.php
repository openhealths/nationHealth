<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use App\Exceptions\EHealth\EHealthConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Auth extends EHealthRequest
{
    public function login(string $email, string $password): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateAccessToken(...));

        return $this->post('auth/login', [
            'token' => [
                'grant_type' => 'password',
                'email' => $email,
                'password' => $password,
                'client_id' => config('ehealth.api.mis_id'),
                'scope' => 'app:authorize'
            ]
        ]);
    }

    public function authorize(string $accessToken, string $scopes, string $legalEntityId): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateAuthorize(...));
        $this->withToken($accessToken);

        return $this->post('oauth/apps/authorize', [
            'app' => [
                'client_id' => $legalEntityId,
                'redirect_uri' => config('ehealth.api.redirect_uri'),
                'scope' => $scopes
            ]
        ]);
    }

    /**
     * This endpoint is used to terminate users authenticated session based on a valid access token.
     * Refresh token from authenticated session will also be expired.
     *
     * @param  string  $accessToken
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * see: https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/oauth/logout
     */
    public function logout(string $accessToken): PromiseInterface|EHealthResponse
    {
        $this->withToken($accessToken);

        return $this->post('auth/logout');
    }

    /**
     * Currently access_token and refresh_token are configured to have same lifetime, so we don't expect you to refresh it.
     * In the future, you will be able to refresh access tokens to extend access_token lifetime.
     *
     * @param  string  $clientId
     * @param  string  $clientSecret
     * @param  string  $refreshToken
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * see: https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/oauth/use-refresh-token-for-access-token-extension
     *
     */
    public function extendTokenLifetime(string $clientId, string $clientSecret, string $refreshToken): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateExtendTokenLifetime(...));

        $data = [
            'token' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken
            ]
        ];

        return $this->post('oauth/tokens', $data);
    }

    protected function validateAccessToken(EHealthResponse $response): array
    {
        $validator = Validator::make($response->getData(), [
            'value' => ['required', 'string'],
            'user_id' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    protected function validateAuthorize(EHealthResponse $response): array
    {
        $validator = Validator::make($response->getData(), [
            'details' => ['required', 'array'],
            'details.app_id' => ['required', 'uuid'],
            'details.client_id' => ['required', 'uuid', Rule::exists('legal_entities', 'uuid')],
            'details.redirect_uri' => ['required', 'url'],
            'details.scope_request' => ['required', 'string'],
            'expires_at' => ['required', 'integer'],
            'id' => ['required', 'uuid'],
            'name' => ['required', 'string', Rule::in(['authorization_code'])],
            'user_id' => ['required', 'uuid'],
            'value' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    protected function validateExtendTokenLifetime(EHealthResponse $response): array
    {
        $validator = Validator::make($response->getData(), [
            'expires_at' => ['required', 'integer'],
            'value' => ['required', 'string'],
            'details.refresh_token' => ['required', 'string']
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }
}
