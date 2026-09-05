<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Enums\Episode\Status;
use App\Enums\Person\ConditionClinicalStatus;
use App\Enums\Person\DiagnosticReportStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class EncounterPackageBuilder
{
    /**
     * Build FHIR encounter package with optional episode.
     *
     * @param  array  $data  Validated form data
     * @param  string  $episodeType  'new' or 'existing'
     * @param  Status  $episodeStatus  Status to assign to the episode
     * @return array
     */
    public function build(array $data, string $episodeType, Status $episodeStatus = Status::ACTIVE): array
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
            ->map(
                function (array $condition, int $index) use ($data, $uuids): array {
                    if (isset($data['encounter']['diagnoses'][$index])) {
                        $condition['clinicalStatus'] = ConditionClinicalStatus::ACTIVE->value;
                    }

                    return Fhir::condition()->toFhir($condition, $uuids);
                }
            )
            ->values()
            ->toArray();

        $fhirImmunizations = collect($data['immunizations'] ?? [])
            ->map(fn (array $immunization) => Fhir::immunization()->toFhir($immunization, $uuids))
            ->values()
            ->toArray();

        $fhirDiagnosticReports = collect($data['diagnosticReports'] ?? [])
            ->map(
                function (array $diagnosticReport) use ($data, $uuids): array {
                    $encounterPeriodDate = data_get($data, 'encounter.periodDate');
                    $encounterPeriodStart = data_get($data, 'encounter.periodStart');
                    $encounterPeriodEnd = data_get($data, 'encounter.periodEnd');
                    $diagnosticReport['divisionId'] = data_get($data, 'encounter.divisionId');

                    if (($diagnosticReport['effectiveType'] ?? null) === 'period') {
                        $diagnosticReport['effectivePeriodStartDate'] = $encounterPeriodDate;
                        $diagnosticReport['effectivePeriodStartTime'] = $encounterPeriodStart;
                        $diagnosticReport['effectivePeriodEndDate'] = $encounterPeriodDate;
                        $diagnosticReport['effectivePeriodEndTime'] = $encounterPeriodEnd;
                    }

                    return Fhir::diagnosticReport()->toFhir(
                        $diagnosticReport,
                        array_merge($uuids, ['diagnosticReport' => $diagnosticReport['uuid'] ?? Str::uuid()->toString(), ]),
                        DiagnosticReportStatus::tryFrom($diagnosticReport['status'] ?? '')
                            ?? DiagnosticReportStatus::FINAL
                    );
                }
            )
            ->values()
            ->toArray();

        $fhirObservations = collect($data['observations'] ?? [])
            ->map(fn (array $observation) => Fhir::observation()->toFhir($observation, $uuids))
            ->values()
            ->toArray();

        $fhirProcedures = collect($data['procedures'] ?? [])
            ->map(
                function (array $procedure) use ($data, $uuids): array {
                    if (($procedure['performedType'] ?? null) === 'period') {
                        $procedure['performedPeriodStartDate'] = data_get($data, 'encounter.periodDate');
                        $procedure['performedPeriodStartTime'] = data_get($data, 'encounter.periodStart');
                        $procedure['performedPeriodEndDate'] = data_get($data, 'encounter.periodDate');
                        $procedure['performedPeriodEndTime'] = data_get($data, 'encounter.periodEnd');
                    }

                    return Fhir::procedure()->toFhir($procedure, $uuids);
                }
            )
            ->values()
            ->toArray();

        $fhirDevices = collect($data['devices'] ?? [])
            ->map(
                fn (array $device) =>
                    Fhir::device()->toFhir($device, $uuids)
            )
            ->values()
            ->toArray();

        $fhirDetectedIssues = collect($data['detectedIssues'] ?? [])
            ->map(
                fn (array $detectedIssue): array =>
                    Fhir::detectedIssue()->toFhir(
                        $detectedIssue,
                        $uuids
                    )
            )
            ->values()
            ->toArray();

        $fhirDeviceAssociations = Fhir::deviceAssociation()
            ->toFhirCollection($data['deviceAssociations'] ?? [], $uuids);

        $fhirClinicalImpressions = collect($data['clinicalImpressions'] ?? [])
            ->map(fn (array $clinicalImpression) => Fhir::clinicalImpression()->toFhir($clinicalImpression, $uuids))
            ->values()
            ->toArray();

        $encounterData = $data['encounter'];

        return [
            'encounter' => Fhir::encounter()->toFhir($encounterData, $fhirConditions, $uuids),
            'conditions' => $fhirConditions,
            'immunizations' => $fhirImmunizations,
            'diagnosticReports' => $fhirDiagnosticReports,
            'observations' => $fhirObservations,
            'procedures' => $fhirProcedures,
            'detectedIssues' => $fhirDetectedIssues,
            'devices' => $fhirDevices,
            'deviceAssociations' => $fhirDeviceAssociations,
            'clinicalImpressions' => $fhirClinicalImpressions
        ];
    }
}
