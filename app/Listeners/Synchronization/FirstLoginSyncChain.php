<?php

declare(strict_types=1);

namespace App\Listeners\Synchronization;

use App\Models\User;
use App\Models\Equipment;
use App\Core\EHealthJob;
use App\Jobs\CompleteSync;
use App\Jobs\DivisionSync;
use App\Jobs\EmployeeSync;
use App\Jobs\EquipmentSync;
use App\Models\LegalEntity;
use App\Models\Declaration;
use App\Jobs\ConnectionSync;
use App\Jobs\LegalEntitySync;
use App\Jobs\DeclarationsSync;
use App\Jobs\EmployeeRoleSync;
use App\Jobs\PartyVerificationSync;
use App\Jobs\EmployeeRequestsSyncAll;
use App\Services\Party\PartyVerificationBulkAccess;

class FirstLoginSyncChain
{
    public function build(LegalEntity $legalEntity, array $scopes, User $user): EHealthJob
    {
        // Create a chain of jobs for synchronization

        // This is the last job in the chain
        $nextJob = new ConnectionSync(
            legalEntity: $legalEntity,
            nextEntity: new CompleteSync($legalEntity, isFirstLogin: true),
            isFirstLogin: true
        );

        $nextJob = new LegalEntitySync(
            legalEntity: $legalEntity,
            nextEntity: $nextJob,
            isFirstLogin: true
        );

        if ($user->can('sync', [Equipment::class, $scopes])) {
            $nextJob = new EquipmentSync(
                legalEntity: $legalEntity,
                nextEntity: $nextJob,
                isFirstLogin: true
            );
        }

        if ($user->can('sync', [Declaration::class, $legalEntity, $scopes])) {
            $nextJob = new DeclarationsSync(
                legalEntity: $legalEntity,
                nextEntity: $nextJob,
                isFirstLogin: true
            );
        }

        $nextJob = new EmployeeRoleSync(
            legalEntity: $legalEntity,
            nextEntity: $nextJob,
            isFirstLogin: true
        );

        // Run after EmployeeSync so parties already exist locally for cache updates.
        if (PartyVerificationBulkAccess::canBulkSync($scopes)) {
            $nextJob = new PartyVerificationSync(
                legalEntity: $legalEntity,
                nextEntity: $nextJob,
                isFirstLogin: true
            );
            PartyVerificationBulkAccess::markSynced($legalEntity);
        }

        $nextJob = new EmployeeSync(
            legalEntity: $legalEntity,
            nextEntity: $nextJob,
            isFirstLogin: true
        );

        $nextJob = new EmployeeRequestsSyncAll(
            legalEntity: $legalEntity,
            nextEntity: $nextJob,
            isFirstLogin: true
        );

        $nextJob = new DivisionSync(
            legalEntity: $legalEntity,
            nextEntity: $nextJob,
            isFirstLogin: true
        );

        return $nextJob;
    }
}
