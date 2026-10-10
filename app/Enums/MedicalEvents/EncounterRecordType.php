<?php

declare(strict_types=1);

namespace App\Enums\MedicalEvents;

use App\Enums\ClinicalImpression\Status as ClinicalImpressionStatus;
use App\Enums\DetectedIssue\Status as DetectedIssueStatus;
use App\Enums\Device\Status as DeviceStatus;
use App\Enums\DeviceAssociation\Status as DeviceAssociationStatus;
use App\Enums\Person\ConditionVerificationStatus;
use App\Enums\Person\DiagnosticReportStatus;
use App\Enums\Person\ImmunizationStatus;
use App\Enums\Person\ObservationStatus;
use App\Enums\Person\ProcedureStatus;
use App\Enums\Specimen\Status as SpecimenStatus;

enum EncounterRecordType: string
{
    case CONDITION = 'conditions';
    case OBSERVATION = 'observations';
    case IMMUNIZATION = 'immunizations';
    case DIAGNOSTIC_REPORT = 'diagnosticReports';
    case PROCEDURE = 'procedures';
    case CLINICAL_IMPRESSION = 'clinicalImpressions';
    case DEVICE = 'devices';
    case DEVICE_ASSOCIATION = 'deviceAssociations';
    case DETECTED_ISSUE = 'detectedIssues';
    case SPECIMEN = 'specimens';

    public function statusField(): string
    {
        return $this === self::CONDITION ? 'verificationStatus' : 'status';
    }

    public function canCancelSeparately(): bool
    {
        return $this !== self::CONDITION;
    }

    public function cancelledStatus(): string
    {
        return (match ($this) {
            self::CONDITION => ConditionVerificationStatus::ENTERED_IN_ERROR,
            self::OBSERVATION => ObservationStatus::ENTERED_IN_ERROR,
            self::IMMUNIZATION => ImmunizationStatus::ENTERED_IN_ERROR,
            self::DIAGNOSTIC_REPORT => DiagnosticReportStatus::ENTERED_IN_ERROR,
            self::PROCEDURE => ProcedureStatus::ENTERED_IN_ERROR,
            self::CLINICAL_IMPRESSION => ClinicalImpressionStatus::ENTERED_IN_ERROR,
            self::DEVICE => DeviceStatus::ENTERED_IN_ERROR,
            self::DEVICE_ASSOCIATION => DeviceAssociationStatus::ENTERED_IN_ERROR,
            self::DETECTED_ISSUE => DetectedIssueStatus::ENTERED_IN_ERROR,
            self::SPECIMEN => SpecimenStatus::ENTERED_IN_ERROR,
        })->value;
    }
}
