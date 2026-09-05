<?php

declare(strict_types=1);

namespace App\Enums\Person;

use App\Traits\EnumUtils;

enum VerificationSource: string
{
    use EnumUtils;

    case DRFO = 'drfo';
    case DRACS_BIRTH = 'dracs_birth';
    case DRACS_DEATH = 'dracs_death';
    case DRACS_NAME_CHANGE = 'dracs_name_change';
    case LEGAL_CAPACITY = 'legal_capacity';
    case MVS_PASSPORT = 'mvs_passport';
    case DMS_PASSPORT = 'dms_passport';
    case NHS = 'nhs';
    case UNZR = 'unzr';
}
