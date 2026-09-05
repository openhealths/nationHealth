<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\EHealthUserLogin;
use App\Jobs\PartyVerificationSync;
use App\Models\LegalEntity;
use App\Notifications\SyncNotification;
use App\Services\Party\PartyVerificationBulkAccess;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * On subsequent logins (max once per 24h per LE), queue party verification bulk list sync
 * only when token scopes include party_verification:read. Role (HR/OWNER/ADMIN) is not checked —
 * ADMIN without that scope is skipped and never calls the list API.
 */
class PartyVerificationSyncStatusOnLogin
{
    public const string SCOPE_REQUIRED = PartyVerificationBulkAccess::BULK_SCOPE;

    /**
     * @throws JsonException
     */
    public function handle(EHealthUserLogin $event): void
    {
        if ($event->isFirstLogin) {
            return;
        }

        $legalEntity = $event->legalEntity;

        if (!PartyVerificationBulkAccess::canBulkSync($event->scopes)) {
            Log::info('Party verification sync skipped: missing party_verification:read on token.', [
                'legal_entity_id' => $legalEntity->id,
                'user_id' => $event->user->id,
            ]);

            return;
        }

        if (PartyVerificationBulkAccess::wasSyncedRecently($legalEntity)) {
            Log::info('Party verification sync skipped: Already ran today.', [
                'legal_entity_id' => $legalEntity->id,
            ]);

            return;
        }

        if (PartyVerificationBulkAccess::isBulkSyncInProgress($legalEntity)) {
            Log::info('Party verification sync skipped: already PROCESSING.', [
                'legal_entity_id' => $legalEntity->id,
            ]);

            return;
        }

        $user = $event->user;

        try {
            $token = Crypt::decryptString($event->token);
        } catch (DecryptException) {
            $token = $event->token;
        } catch (Throwable $e) {
            Log::error('Party verification listener: Token decryption failed.', ['error' => $e->getMessage()]);

            return;
        }

        try {
            Log::info('Starting party verification sync (queued).', ['user_id' => $user->id]);

            Bus::batch([new PartyVerificationSync($legalEntity, null, false, standalone: true)])
                ->name('Party Verification Status Sync')
                ->withOption('legal_entity_id', $legalEntity->id)
                ->withOption('token', Crypt::encryptString($token))
                ->withOption('user', $user)
                ->withOption('sync_entity', LegalEntity::ENTITY_PARTY_VERIFICATION)
                ->then(function (Batch $batch) use ($user) {
                    $user->notify(new SyncNotification('party_verification', 'completed'));
                })
                ->catch(function (Batch $batch, Throwable $e) use ($user) {
                    $user->notify(new SyncNotification('party_verification', 'failed'));
                    Log::error('Batch [Party Verification Status Sync] failed.', ['error' => $e->getMessage()]);
                })
                ->onQueue('sync')
                ->dispatch();

            PartyVerificationBulkAccess::markSynced($legalEntity);
            $user->notify(new SyncNotification('party_verification', 'started'));
        } catch (Throwable $e) {
            Log::error('Failed to queue party verification sync on login.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
