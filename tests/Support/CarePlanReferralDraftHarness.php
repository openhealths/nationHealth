<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Livewire\CarePlan\Concerns\ManagesCarePlanReferrals;

final class CarePlanReferralDraftHarness
{
    use ManagesCarePlanReferrals {
        createCarePlanReferralDraft as public createCarePlanDraft;
    }
}
