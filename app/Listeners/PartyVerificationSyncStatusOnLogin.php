<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Classes\eHealth\EHealth;
use App\Events\EHealthUserLogin;
use App\Models\Relations\Party;
use App\Traits\ProcessesPartyVerificationResponses;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

class PartyVerificationSyncStatusOnLogin
{
    use ProcessesPartyVerificationResponses;

    public const string SCOPE_REQUIRED = 'party_verification:details';

    private const string CACHE_KEY_PREFIX = 'party_verification_last_run:';

    private const int CACHE_TTL_SECONDS = 86400; // 24 hours

    /**
     * Handle the event using getDetails (party_verification:details) per local party.
     *
     * @throws JsonException
     */
    public function handle(EHealthUserLogin $event): void
    {
        if ($event->isFirstLogin) {
            return;
        }

        $user = $event->user;
        $legalEntity = $event->legalEntity;

        $cacheKey = self::CACHE_KEY_PREFIX . $legalEntity->id;

        if (Cache::has($cacheKey)) {
            Log::info('Party verification sync skipped: Already ran today.', ['legal_entity_id' => $legalEntity->id]);

            return;
        }

        try {
            $token = Crypt::decryptString($event->token);
        } catch (DecryptException) {
            $token = $event->token;
        } catch (Throwable $e) {
            Log::error('Party verification listener: Token decryption failed.', ['error' => $e->getMessage()]);

            return;
        }

        if (!$user->can(self::SCOPE_REQUIRED)) {
            Log::info('Party verification sync skipped: User missing required scope.', [
                'user_id' => $user->id,
                'required_scope' => self::SCOPE_REQUIRED,
            ]);

            return;
        }

        try {
            Log::info('Starting party verification sync.', ['user_id' => $user->id]);

            $parties = Party::query()
                ->whereHas(
                    'employees',
                    fn ($query) => $query->where('legal_entity_id', $legalEntity->id)
                )
                ->whereNotNull('uuid')
                ->orderBy('id')
                ->get();

            foreach ($parties as $party) {
                try {
                    $response = EHealth::party()
                        ->withToken($token)
                        ->getDetails($party->uuid);

                    $this->processPartyVerificationDetail($party->uuid, $response, $legalEntity);
                } catch (Throwable $e) {
                    Log::warning('Party verification sync: failed for party', [
                        'party_uuid' => $party->uuid,
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Cache::put($cacheKey, true, self::CACHE_TTL_SECONDS);
        } catch (RequestException $e) {
            if (!in_array($e->response->status(), [401, 403], true)) {
                Log::error('Party verification API error.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }
        } catch (Throwable $e) {
            Log::error('Failed to run party verification sync on login.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
