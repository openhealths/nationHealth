<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Services\MedicalEvents\Mappers\ClinicalImpressionMapper;
use App\Services\MedicalEvents\Mappers\ConditionMapper;
use App\Services\MedicalEvents\Mappers\DeviceRequestMapper;
use App\Services\MedicalEvents\Mappers\DiagnosticReportMapper;
use App\Services\MedicalEvents\Mappers\EncounterMapper;
use App\Services\MedicalEvents\Mappers\EpisodeMapper;
use App\Services\MedicalEvents\Mappers\ImmunizationMapper;
use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use App\Services\MedicalEvents\Mappers\ObservationMapper;
use App\Services\MedicalEvents\Mappers\ProcedureMapper;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;

final class Fhir
{
    public static function clinicalImpression(): ClinicalImpressionMapper
    {
        return app(ClinicalImpressionMapper::class);
    }

    public static function condition(): ConditionMapper
    {
        return app(ConditionMapper::class);
    }

    public static function encounter(): EncounterMapper
    {
        return app(EncounterMapper::class);
    }

    public static function episode(): EpisodeMapper
    {
        return app(EpisodeMapper::class);
    }

    public static function immunization(): ImmunizationMapper
    {
        return app(ImmunizationMapper::class);
    }

    public static function observation(): ObservationMapper
    {
        return app(ObservationMapper::class);
    }

    public static function diagnosticReport(): DiagnosticReportMapper
    {
        return app(DiagnosticReportMapper::class);
    }

    public static function procedure(): ProcedureMapper
    {
        return app(ProcedureMapper::class);
    }

    public static function medicationRequest(): MedicationRequestMapper
    {
        return app(MedicationRequestMapper::class);
    }

    public static function serviceRequest(): ServiceRequestMapper
    {
        return app(ServiceRequestMapper::class);
    }

    public static function deviceRequest(): DeviceRequestMapper
    {
        return app(DeviceRequestMapper::class);
    }

    public static function encounterPackage(): EncounterPackageBuilder
    {
        return app(EncounterPackageBuilder::class);
    }
}
