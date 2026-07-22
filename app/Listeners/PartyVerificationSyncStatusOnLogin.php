<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\EHealthUserLogin;
use App\Jobs\PartyVerificationSync;
use App\Notifications\SyncNotification;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * On subsequent logins (max once per 24h per LE), queue party verification sync.
 * Sync uses GET /api/parties/{id}/verification (party_verification:details) in a background job —
 * not the list endpoint — so the HTTP login request stays light.
 */
class PartyVerificationSyncStatusOnLogin
{
    public const string SCOPE_REQUIRED = 'party_verification:details';

    private const string CACHE_KEY_PREFIX = 'party_verification_last_run:';

    private const int CACHE_TTL_SECONDS = 86400; // 24 hours

    /**
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
            Log::info('Starting party verification sync (queued).', ['user_id' => $user->id]);

            Bus::batch([new PartyVerificationSync($legalEntity, null, false)])
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

            Cache::put($cacheKey, true, self::CACHE_TTL_SECONDS);
            $user->notify(new SyncNotification('party_verification', 'started'));
        } catch (Throwable $e) {
            Log::error('Failed to queue party verification sync on login.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
