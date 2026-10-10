<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MedicalEvents\Referral;

use App\Classes\eHealth\Api\Patient\DeviceRequest;
use App\Classes\eHealth\Api\Patient\ServiceRequest;
use App\Classes\eHealth\EHealth;
use InvalidArgumentException;

trait SelectsReferralApi
{
    protected function referralRepository(string $kind): \App\Repositories\MedicalEvents\ServiceRequestRequestRepository|\App\Repositories\MedicalEvents\DeviceRequestRequestRepository
    {
        return match ($kind) {
            'service_request' => \App\Repositories\MedicalEvents\Repository::serviceRequest(),
            'device_request' => \App\Repositories\MedicalEvents\Repository::deviceRequest(),
            default => throw new InvalidArgumentException(__('care-plan.referral_wrong_activity_kind')),
        };
    }

    protected function referralApi(string $kind): ServiceRequest|DeviceRequest
    {
        return match ($kind) {
            'service_request' => EHealth::serviceRequest(),
            'device_request' => EHealth::deviceRequest(),
            default => throw new InvalidArgumentException(__('care-plan.referral_wrong_activity_kind')),
        };
    }
}
