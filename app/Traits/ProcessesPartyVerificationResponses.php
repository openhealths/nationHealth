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
