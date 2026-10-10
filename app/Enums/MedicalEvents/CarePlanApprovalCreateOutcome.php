<?php

declare(strict_types=1);

namespace App\Enums\MedicalEvents;

enum CarePlanApprovalCreateOutcome: string
{
    case Async = 'async';
    case OtpRequired = 'otp_required';
    case Granted = 'granted';
}
