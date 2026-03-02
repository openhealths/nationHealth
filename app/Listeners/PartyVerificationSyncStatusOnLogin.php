<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Classes\eHealth\EHealth;
use App\Events\EHealthUserLogin;
use App\Jobs\PartyVerificationSync;
use App\Notifications\SyncNotification;
use App\Traits\ProcessesPartyVerificationResponses;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class PartyVerificationSyncStatusOnLogin
{
    use ProcessesPartyVerificationResponses;

    public const string SCOPE_REQUIRED = 'party_verification:read';
    // Cache key prefix: party_verification_last_run:{legal_entity_id}
    private const string CACHE_KEY_PREFIX = 'party_verification_last_run:';
    private const int CACHE_TTL_SECONDS = 86400; // 24 hours

    /**
     * Handle the event using the hybrid sync pattern.
     *
     * @throws JsonException
     */
    public function handle(EHealthUserLogin $event): void
    {
        $user = $event->user;
        $legalEntity = $event->legalEntity;

        // 1. CHECK: Frequency (Once per 24h per Legal Entity)
        // We check cache first to avoid unnecessary decryption operations if sync already ran.
        $cacheKey = self::CACHE_KEY_PREFIX . $legalEntity->id;

        if (Cache::has($cacheKey)) {
            Log::info('Party verification sync skipped: Already ran today.', ['legal_entity_id' => $legalEntity->id]);

            return;
        }

        // 2. DECRYPT TOKEN
        try {
            $token = Crypt::decryptString($event->token);
        } catch (DecryptException $e) {
            // Fallback if token is somehow raw
            $token = $event->token;
        } catch (Throwable $e) {
            Log::error('Party verification listener: Token decryption failed.', ['error' => $e->getMessage()]);

            return;
        }

        // 3. CHECK: Scope (party_verification:read)
        // We manually validate the JWT scope to ensure the user has permission BEFORE making a request.
        if (!$this->tokenHasScope($token, self::SCOPE_REQUIRED)) {
            Log::info('Party verification sync skipped: User missing required scope.', [
                'user_id' => $user->id,
                'required_scope' => self::SCOPE_REQUIRED
            ]);

            return;
        }

        // 4. EXECUTE SYNC
        try {
            Log::info('Starting party verification sync.', ['user_id' => $user->id]);

            $response = EHealth::party()->withToken($token)->getMany();

            $this->processPartyVerificationResponse($response, $legalEntity);

            // If sync was successful, lock this action for 24 hours
            Cache::put($cacheKey, true, self::CACHE_TTL_SECONDS);

            if ($response->isNotLast()) {
                Bus::batch([new PartyVerificationSync($legalEntity, null, false, 2)])
                    ->name('Party Verification Status Sync')
                    ->withOption('legal_entity_id', $legalEntity->id)
                    ->withOption('token', Crypt::encryptString($token))
                    ->withOption('user', $user)
                    ->then(function (Batch $batch) use ($user) {
                        $user->notify(new SyncNotification('party_verification', 'completed'));
                    })
                    ->catch(function (Batch $batch, Throwable $e) use ($user) {
                        $user->notify(new SyncNotification('party_verification', 'failed'));
                        Log::error('Batch [Party Verification Status Sync] failed.', ['error' => $e->getMessage()]);
                    })
                    ->onQueue('sync')
                    ->dispatch();

                $user->notify(new SyncNotification('party_verification', 'started'));
            }

        } catch (RequestException $e) {
            // Log API errors but do not set cache, so we can retry on next login
            if (!in_array($e->response->status(), [401, 403])) {
                Log::error('Party verification API error.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        } catch (Throwable $e) {
            Log::error('Failed to run party verification sync on login.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Checks if the JWT token contains the specified scope.
     *
     * * @param string $token The raw Bearer token (JWT).
     * @param  string  $requiredScope  The scope to check for.
     * @return bool
     * @throws JsonException
     */
    private function tokenHasScope(string $token, string $requiredScope): bool
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return false;
        }

        // Decode Payload (2nd part)
        $payloadJson = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]));
        $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($payload) || !isset($payload['scope'])) {
            return false;
        }

        // Scopes in eHealth are typically space-separated strings
        $scopes = explode(' ', $payload['scope']);

        return in_array($requiredScope, $scopes, true);
    }
}
