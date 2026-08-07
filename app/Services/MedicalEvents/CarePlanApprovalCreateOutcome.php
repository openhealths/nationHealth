<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

enum CarePlanApprovalCreateOutcome: string
{
    case Async = 'async';
    case OtpRequired = 'otp_required';
    case Granted = 'granted';
}
