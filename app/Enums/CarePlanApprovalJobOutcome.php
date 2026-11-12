<?php

declare(strict_types=1);

namespace App\Enums;

enum CarePlanApprovalJobOutcome: string
{
    case Pending = 'pending';
    case OtpRequired = 'otp_required';
    case OfflineRequired = 'offline_required';
    case Granted = 'granted';
    case Failed = 'failed';
}
