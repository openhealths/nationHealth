<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

enum CarePlanApprovalJobOutcome: string
{
    case Pending = 'pending';
    case OtpRequired = 'otp_required';
    case Granted = 'granted';
    case Failed = 'failed';
}
