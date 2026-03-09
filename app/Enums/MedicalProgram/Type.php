<?php

declare(strict_types=1);

namespace App\Enums\MedicalProgram;

use App\Traits\EnumUtils;

/**
 * See: https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/18352832571/MEDICAL_PROGRAM_TYPE
 */
enum Type: string
{
    use EnumUtils;

    case MEDICATION = 'MEDICATION';
    case SERVICE = 'SERVICE';
    case DEVICE = 'DEVICE';
}
