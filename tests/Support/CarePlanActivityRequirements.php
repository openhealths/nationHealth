<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Livewire\Concerns\MedicalEvents\Activity\ValidatesActivityRequirements;
use App\Livewire\Concerns\MedicalEvents\Activity\ChecksDeviceProgramParticipation;

class CarePlanActivityRequirements
{
    use ValidatesActivityRequirements {
        providingConditionsBlockReason as public;
        rehabReasonReferenceBlockReason as public;
        isRehabCategory as public;
    }
    use ChecksDeviceProgramParticipation {
        resolveParticipatingProgramIds as public;
        assessDeviceActivity as public assess;
    }
}
