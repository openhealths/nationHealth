<?php

declare(strict_types=1);

namespace App\Enums\MedicalEvents;

use App\Traits\EnumUtils;

/**
 * Types of patient medical records the encounter package can be searched for and refer to.
 */
enum RecordType: string
{
    use EnumUtils;

    case EPISODE = 'episodes';
    case ENCOUNTER = 'encounter';
    case PROCEDURE = 'procedure';
    case DIAGNOSTIC_REPORT = 'diagnosticReport';
    case CONDITION = 'condition';
    case OBSERVATION = 'observation';

    /**
     * Name of the records of this type as the search offers them.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::EPISODE => __('episodes.plural'),
            self::ENCOUNTER => __('encounters.plural'),
            self::PROCEDURE => __('procedures.plural'),
            self::DIAGNOSTIC_REPORT => __('diagnostic-reports.plural'),
            self::CONDITION => __('conditions.plural'),
            self::OBSERVATION => __('observations.plural')
        };
    }
}
