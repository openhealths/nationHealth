<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\Referral;

use App\Classes\eHealth\Api\Patient\DeviceRequest;
use App\Classes\eHealth\Api\Patient\ServiceRequest;
use App\Classes\eHealth\EHealth;
use InvalidArgumentException;

trait SelectsReferralApi
{
    protected function referralApi(string $kind): ServiceRequest|DeviceRequest
    {
        return match ($kind) {
            'service_request' => EHealth::serviceRequest(),
            'device_request' => EHealth::deviceRequest(),
            default => throw new InvalidArgumentException(__('care-plan.referral_wrong_activity_kind')),
        };
    }
}
