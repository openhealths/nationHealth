<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Codes of the eHealth/resources dictionary: the kind of resource a reference points to.
 */
enum ResourceType: string
{
    case EMPLOYEE = 'employee';
    case LEGAL_ENTITY = 'legal_entity';
    case PATIENT = 'patient';
    case ENCOUNTER = 'encounter';
    case EPISODE_OF_CARE = 'episode_of_care';
    case PROCEDURE = 'procedure';
    case DIAGNOSTIC_REPORT = 'diagnostic_report';
}
