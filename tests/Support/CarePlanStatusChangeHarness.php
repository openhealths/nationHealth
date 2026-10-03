<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Livewire\Concerns\MedicalEvents\CarePlan\ValidatesCarePlanStatusChanges;

/** Expose protected UI decisions for existing contract tests without making them Livewire actions. */
final class CarePlanStatusChangeHarness
{
    use ValidatesCarePlanStatusChanges {
        activityStatusChangeBlockReason as public;
        planCancelBlockReason as public;
        planCompleteBlockReason as public;
    }
}
