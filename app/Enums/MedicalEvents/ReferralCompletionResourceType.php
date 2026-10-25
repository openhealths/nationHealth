<?php

declare(strict_types=1);

namespace App\Enums\MedicalEvents;

use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Procedure;

enum ReferralCompletionResourceType: string
{
    case ENCOUNTER = 'encounter';
    case PROCEDURE = 'procedure';
    case DIAGNOSTIC_REPORT = 'diagnostic_report';

    public function modelClass(): string
    {
        return match ($this) {
            self::ENCOUNTER => Encounter::class,
            self::PROCEDURE => Procedure::class,
            self::DIAGNOSTIC_REPORT => DiagnosticReport::class,
        };
    }
}
