<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Livewire\Concerns\MedicalEvents\Referral\PrintsReferrals;
use App\Livewire\Concerns\MedicalEvents\Referral\SynchronizesReferrals;

final class ReferralSigningHarness
{
    use SynchronizesReferrals {
        referralSignData as public buildSignDbData;
        syncReferralFromRemote as public;
        persistAfterSignedCreate as public;
    }
    use PrintsReferrals {
        buildCode128BarcodeHtml as public;
        referralPrintoutHtml as public;
    }
}
