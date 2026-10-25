<?php

declare(strict_types=1);

namespace Tests\Support;

class CarePlanApprovals
{
    use \App\Livewire\Concerns\MedicalEvents\Approval\SelectsCarePlanApprovalAccess {
        skipsPatientOtp as public;
        resolveAccessLevel as public;
    }
    use \App\Livewire\Concerns\MedicalEvents\Approval\RequestsCarePlanApprovals {
        buildCarePlanApprovalPayload as public buildCreatePayload;
        createCarePlanApproval as public create;
    }
    use \App\Livewire\Concerns\MedicalEvents\Approval\QueuesCarePlanApproval;
    use \App\Livewire\Concerns\MedicalEvents\Approval\PollsCarePlanApprovals {
        resolveCarePlanApprovalJob as public resolveAsyncJob;
    }
}
