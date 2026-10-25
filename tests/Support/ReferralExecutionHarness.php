<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Traits\MedicalEvents\UpdatesReferralExecution;

final class ReferralExecutionHarness
{
    use UpdatesReferralExecution {
        completeReferral as public;
        cancelReferralUsage as public;
        takeReferralIntoWork as public takeIntoWork;
    }
}
