<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Party;

use App\Enums\JobStatus;
use App\Models\LegalEntity;
use App\Services\Party\PartyVerificationBulkAccess;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PartyVerificationBulkAccessTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function can_bulk_sync_requires_read_scope(): void
    {
        $this->assertTrue(PartyVerificationBulkAccess::canBulkSync(['party_verification:read']));
        $this->assertFalse(PartyVerificationBulkAccess::canBulkSync(['party_verification:details']));
        $this->assertTrue(PartyVerificationBulkAccess::canManualSync(['party_verification:details']));
        $this->assertFalse(PartyVerificationBulkAccess::canManualSync(['employee:read']));
    }

    #[Test]
    public function should_queue_login_sync_respects_cache_and_processing(): void
    {
        $typeId = \Illuminate\Support\Facades\DB::table('legal_entity_types')->where('name', 'PRIMARY_CARE')->value('id')
            ?? \Illuminate\Support\Facades\DB::table('legal_entity_types')->insertGetId(['name' => 'PRIMARY_CARE']);

        $legalEntity = LegalEntity::create([
            'uuid' => (string) Str::uuid(),
            'status' => 'ACTIVE',
            'sync_status' => 'COMPLETED',
            'legal_entity_type_id' => $typeId,
            'is_active' => true,
        ]);

        Cache::forget(PartyVerificationBulkAccess::cacheKey($legalEntity->id));

        $scopes = [PartyVerificationBulkAccess::BULK_SCOPE];

        $this->assertTrue(PartyVerificationBulkAccess::shouldQueueLoginSync($scopes, $legalEntity));

        PartyVerificationBulkAccess::markSynced($legalEntity);
        $this->assertFalse(PartyVerificationBulkAccess::shouldQueueLoginSync($scopes, $legalEntity));

        Cache::forget(PartyVerificationBulkAccess::cacheKey($legalEntity->id));
        $legalEntity->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_PARTY_VERIFICATION);
        $this->assertFalse(PartyVerificationBulkAccess::shouldQueueLoginSync($scopes, $legalEntity->fresh()));
    }
}
