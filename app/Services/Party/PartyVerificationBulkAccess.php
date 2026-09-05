<?php

declare(strict_types=1);

namespace App\Services\Party;

use App\Enums\JobStatus;
use App\Models\LegalEntity;
use Illuminate\Support\Facades\Cache;

/**
 * Single gate for party verification bulk/list sync.
 * Bulk path requires party_verification:read on the token scopes — role is irrelevant.
 * Without that scope (typical for ADMIN), callers must not use GET /parties/verifications.
 * Legal entity is taken from the token by eHealth; do not send legal_entity_id in query.
 */
final class PartyVerificationBulkAccess
{
    public const string BULK_SCOPE = 'party_verification:read';

    public const string DETAILS_SCOPE = 'party_verification:details';

    public const string CACHE_KEY_PREFIX = 'party_verification_last_run:';

    public const int CACHE_TTL_SECONDS = 86400;

    /**
     * @param  list<string>  $scopes
     */
    public static function canBulkSync(array $scopes): bool
    {
        return in_array(self::BULK_SCOPE, $scopes, true);
    }

    /**
     * @param  list<string>  $scopes
     */
    public static function canDetailsSync(array $scopes): bool
    {
        return in_array(self::DETAILS_SCOPE, $scopes, true);
    }

    /**
     * Manual index sync: bulk list or per-party details fallback.
     *
     * @param  list<string>  $scopes
     */
    public static function canManualSync(array $scopes): bool
    {
        return self::canBulkSync($scopes) || self::canDetailsSync($scopes);
    }

    public static function cacheKey(int|string $legalEntityId): string
    {
        return self::CACHE_KEY_PREFIX . $legalEntityId;
    }

    public static function wasSyncedRecently(LegalEntity $legalEntity): bool
    {
        return Cache::has(self::cacheKey($legalEntity->id));
    }

    public static function markSynced(LegalEntity $legalEntity): void
    {
        Cache::put(self::cacheKey($legalEntity->id), true, self::CACHE_TTL_SECONDS);
    }

    public static function isBulkSyncInProgress(LegalEntity $legalEntity): bool
    {
        $status = $legalEntity->getEntityStatus(LegalEntity::ENTITY_PARTY_VERIFICATION);

        if ($status instanceof JobStatus) {
            return $status === JobStatus::PROCESSING;
        }

        if ($status === null || $status === '') {
            return false;
        }

        return JobStatus::tryFrom((string) $status) === JobStatus::PROCESSING;
    }

    /**
     * Queue background bulk sync on login when token allows it and LE is not already covered.
     *
     * @param  list<string>  $scopes
     */
    public static function shouldQueueLoginSync(array $scopes, LegalEntity $legalEntity): bool
    {
        return self::canBulkSync($scopes)
            && !self::wasSyncedRecently($legalEntity)
            && !self::isBulkSyncInProgress($legalEntity);
    }
}
