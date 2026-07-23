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
     * Processes a page of party verification statuses from GET /parties/verifications (list).
     *
     * Intentionally kept as the eHealth list-response surface alongside Party::getMany().
     * Current app call sites (login job, index sync button) use getDetails +
     * processPartyVerificationDetail() with party_verification:details — they do not call this.
     * Wire this back only if product returns to list+:read sync.
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

    /**
     * Processes GET /api/parties/{id}/verification (party_verification:details).
     */
    private function processPartyVerificationDetail(
        string $partyUuid,
        EHealthResponse $response,
        LegalEntity $legalEntity
    ): void {
        $payload = $response->json();
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        $verificationStatus = data_get($data, 'verification_status');

        if (!is_string($verificationStatus) || $verificationStatus === '') {
            return;
        }

        $party = Party::query()
            ->where('uuid', $partyUuid)
            ->with('users')
            ->first();

        if (!$party) {
            return;
        }

        $oldStatus = $party->verification_status;

        if ($oldStatus !== $verificationStatus) {
            $party->update(['verification_status' => $verificationStatus]);
        }

        if ($oldStatus === 'VERIFIED' && $verificationStatus !== 'VERIFIED') {
            foreach ($party->users as $userToNotify) {
                $userToNotify->notify(new PartyVerificationStatusChanged($party, $verificationStatus, $legalEntity));
            }
        }
    }
}
