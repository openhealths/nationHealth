<?php

declare(strict_types=1);

namespace App\Auth\EHealth\Services;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Models\LegalEntity;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class TokenStorage
{
    protected string $tokenKey;

    protected string $expiresAtKey = 'auth_token_expires_at';

    protected string $refreshTokenKey = 'refresh_token';

    public function __construct()
    {
        $this->tokenKey = config('ehealth.api.oauth.bearer_token');
    }

    /**
     * Save all data concerns to the Bearer token
     *
     * @param  array  $tokenData
     * @return void
     */
    public function store(array $tokenData): void
    {
        Session::put($this->tokenKey, $tokenData['value']);
        Session::put($this->expiresAtKey, $tokenData['expires_at']);
        Session::put($this->refreshTokenKey, $tokenData['details']['refresh_token']);
        Session::save();
    }

    public function hasBearerToken(): bool
    {
        return Session::has($this->tokenKey);
    }

    public function getBearerToken(): ?string
    {
        return Session::get($this->tokenKey);
    }

    public function getRefreshToken(): ?string
    {
        $token = Session::get($this->refreshTokenKey);

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function getExpiresAt(): Carbon
    {
        $ts = Session::get($this->expiresAtKey);

        return Carbon::createFromTimestamp($ts);
    }

    /**
     * Delete all the data concerns to the Bearer token
     *
     * @return void
     */
    public function clear(): void
    {
        Session::forget([
            $this->tokenKey,
            $this->expiresAtKey,
            $this->refreshTokenKey
        ]);

        Session::save();
    }

    public function refreshBearerToken(): bool
    {
        $refreshToken = $this->getRefreshToken();
        if ($refreshToken === null) {
            return false;
        }

        $legalEntityUuid = Session::get('ehealth_legal_entity_uuid');

        $entity = LegalEntity::whereUuid($legalEntityUuid)
            ->select(['client_id', 'client_secret'])
            ->first();

        if (!$entity) {
            $this->clear();

            return false;
        }

        try {
            $response = EHealth::auth()->extendTokenLifetime(
                $entity->clientId,
                $entity->clientSecret,
                $refreshToken
            );
            $this->store($response->validate());

            return true;
        } catch (EHealthException|EHealthConnectionException $exception) {
            Log::channel('e_health_errors')->error("Error while extend token lifetime {$exception->getMessage()}", [
                'exception' => $exception
            ]);

            return false;
        }
    }
}
