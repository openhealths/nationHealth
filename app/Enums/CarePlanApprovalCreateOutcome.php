<?php

declare(strict_types=1);

namespace App\Enums;

enum CarePlanApprovalCreateOutcome: string
{
    case Async = 'async';
    case OtpRequired = 'otp_required';
    case OfflineRequired = 'offline_required';
    case Granted = 'granted';
}
