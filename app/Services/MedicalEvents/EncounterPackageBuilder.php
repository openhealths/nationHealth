<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Enums\Person\EpisodeStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class EncounterPackageBuilder
{
    /**
     * Build FHIR encounter package with optional episode.
     *
     * @param  array  $data  Validated form data
     * @param  string  $episodeType  'new' or 'existing'
     * @param  EpisodeStatus  $episodeStatus  Status to assign to the episode
     * @return array
     */
    public function build(array $data, string $episodeType, EpisodeStatus $episodeStatus = EpisodeStatus::ACTIVE): array
    {
        $uuids = [
            'encounter' => Str::uuid()->toString(),
            'visit' => Str::uuid()->toString(),
            'employee' => Auth::user()->getEncounterWriterEmployee($data['encounter']['classCode'])->uuid,
            'episode' => $data['episode']['id'] ?: Str::uuid()->toString()
        ];

        $package = $this->toFhir($data, $uuids);

        if ($episodeType === 'new') {
            $package['episode'] = Fhir::episode()->toFhir(
                $data['episode'],
                $uuids,
                $data['encounter']['periodDate'],
                $data['encounter']['periodStart'],
                $episodeStatus
            );
        }

        return array_filter($package);
    }

    /**
     * Map flat form data to a FHIR encounter package using the provided UUIDs.
     *
     * @param  array  $data  Validated form data (encounter, conditions, immunizations, etc.)
     * @param  array  $uuids  Shared UUIDs (encounter, visit, employee, episode)
     * @return array
     */
    public function toFhir(array $data, array $uuids): array
    {
        $fhirConditions = collect($data['conditions'] ?? [])
            ->map(fn (array $condition) => Fhir::condition()->toFhir($condition, $uuids))
            ->values()
            ->toArray();

        $fhirImmunizations = collect($data['immunizations'] ?? [])
            ->map(fn (array $immunization) => Fhir::immunization()->toFhir($immunization, $uuids))
            ->values()
            ->toArray();

        $fhirDiagnosticReports = collect($data['diagnosticReports'] ?? [])
            ->map(fn (array $diagnosticReport) => Fhir::diagnosticReport()->toFhir($diagnosticReport, $uuids))
            ->values()
            ->toArray();

        $fhirObservations = collect($data['observations'] ?? [])
            ->map(fn (array $observation) => Fhir::observation()->toFhir($observation, $uuids))
            ->values()
            ->toArray();

        $fhirProcedures = collect($data['procedures'] ?? [])
            ->map(fn (array $procedure) => Fhir::procedure()->toFhir($procedure, $uuids))
            ->values()
            ->toArray();

        $fhirClinicalImpressions = collect($data['clinicalImpressions'] ?? [])
            ->map(fn (array $clinicalImpression) => Fhir::clinicalImpression()->toFhir($clinicalImpression, $uuids))
            ->values()
            ->toArray();

        return [
            'encounter' => Fhir::encounter()->toFhir($data['encounter'], $fhirConditions, $uuids),
            'conditions' => $fhirConditions,
            'immunizations' => $fhirImmunizations,
            'diagnosticReports' => $fhirDiagnosticReports,
            'observations' => $fhirObservations,
            'procedures' => $fhirProcedures,
            'clinicalImpressions' => $fhirClinicalImpressions
        ];
    }
}
