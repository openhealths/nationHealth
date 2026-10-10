<?php

declare(strict_types=1);

namespace Tests\Support;

final class CarePlanActivityPayload
{
    use \App\Livewire\Concerns\MedicalEvents\Activity\MapsCarePlanActivityPayload {
        buildCarePlanActivityPayload as public formatCarePlanActivityRequest;
    }
}
