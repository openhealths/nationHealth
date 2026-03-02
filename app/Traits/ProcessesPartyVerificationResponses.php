<?php

declare(strict_types=1);

namespace App\Traits;

use App\Classes\eHealth\EHealthResponse;
use App\Models\LegalEntity;
use App\Models\Relations\Party;
use App\Notifications\PartyVerificationStatusChanged;

trait ProcessesPartyVerificationResponses
{
    /**
     * Processes party verification statuses using an optimized upsert approach.
     * Updates ONLY the verification_status field.
     *
     * @param  EHealthResponse  $response  The API response object.
     * @param  LegalEntity  $legalEntity  The legal entity context.
     * @return void
     */
    private function processPartyVerificationResponse(EHealthResponse $response, LegalEntity $legalEntity): void
    {
        $validatedData = $response->validate();
        $eHealthStatuses = $response->map($validatedData);

        if (empty($eHealthStatuses)) {
            return;
        }

        $partyUuids = array_keys($eHealthStatuses);

        // Fetch local parties to ensure we only update existing records
        $localParties = Party::whereIn('uuid', $partyUuids)
            ->with('users')
            ->get()
            ->keyBy('uuid');

        if ($localParties->isEmpty()) {
            return;
        }

        $upsertData = [];

        foreach ($eHealthStatuses as $uuid => $newStatusItem) {
            $party = $localParties->get($uuid);

            if ($party) {
                $upsertData[] = [
                    'uuid' => $uuid,
                    // Required for upsert syntax validity, but not updated
                    'last_name' => $party->lastName,
                    'first_name' => $party->firstName,

                    // The actual field to update
                    'verification_status' => data_get($newStatusItem, 'verification_status'),
                ];
            }
        }

        // Perform the UPSERT operation unconditionally if data exists
        if (!empty($upsertData)) {
            Party::upsert(
                values: $upsertData,
                uniqueBy: ['uuid'],
                update: [
                            'verification_status'
                        ]
            );
        }

        // Handle notifications based on status changes
        foreach ($localParties as $uuid => $party) {
            $newOverallStatus = $eHealthStatuses[$uuid]['verification_status'] ?? null;
            $oldStatus = $party->verification_status;

            if ($newOverallStatus && $oldStatus && $oldStatus !== $newOverallStatus) {
                // Notify users if status changed from VERIFIED to something else
                if ($oldStatus === 'VERIFIED' && $newOverallStatus !== 'VERIFIED') {
                    foreach ($party->users as $userToNotify) {
                        $userToNotify->notify(new PartyVerificationStatusChanged($party, $newOverallStatus, $legalEntity));
                    }
                }
            }
        }
    }
}
