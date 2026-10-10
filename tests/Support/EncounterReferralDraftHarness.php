<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Livewire\Encounter\Concerns\ManagesEncounterReferrals;

final class EncounterReferralDraftHarness
{
    use ManagesEncounterReferrals {
        createEncounterReferralDraft as public createEncounterDraft;
    }
}
