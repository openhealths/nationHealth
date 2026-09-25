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
        $conditions = collect($data['conditions'] ?? []);

        // A previously registered condition is only referenced by its diagnosis, it is not sent again
        $fhirConditions = $conditions
            ->map(
                function (array $condition, int $index) use ($data, $uuids): array {
                    if ($condition['isRegistered'] ?? false) {
                        return ['id' => $condition['uuid']];
                    }

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
            ->map(fn (array $procedure): array => Fhir::procedure()->toFhir($procedure, $uuids))
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

        $fhirDeviceDispenses = collect($data['deviceDispenses'] ?? [])
            ->map(fn (array $deviceDispense) => Fhir::deviceDispense()->toFhir($deviceDispense, $uuids))
            ->values()
            ->toArray();

        $referencedSpecimenIds = collect($data['observations'] ?? [])
            ->pluck('specimenId')
            ->merge(collect($data['diagnosticReports'] ?? [])->pluck('specimenIds')->flatten())
            ->filter()
            ->unique()
            ->all();

        $fhirSpecimens = collect($data['specimens'] ?? [])
            ->map(static function (array $specimen) use ($referencedSpecimenIds, $uuids): array {
                $specimen['isReferenced'] = in_array($specimen['uuid'], $referencedSpecimenIds, true);

                return Fhir::specimen()->toFhir($specimen, $uuids);
            })
            ->values()
            ->toArray();

        $encounterData = $data['encounter'];

        return [
            'encounter' => Fhir::encounter()->toFhir($encounterData, $fhirConditions, $uuids),
            'conditions' => collect($fhirConditions)
                ->reject(static fn (array $condition, int $index): bool => $conditions[$index]['isRegistered'] ?? false)
                ->values()
                ->toArray(),
            'immunizations' => $fhirImmunizations,
            'diagnosticReports' => $fhirDiagnosticReports,
            'observations' => $fhirObservations,
            'procedures' => $fhirProcedures,
            'detectedIssues' => $fhirDetectedIssues,
            'devices' => $fhirDevices,
            'deviceAssociations' => $fhirDeviceAssociations,
            'deviceDispenses' => $fhirDeviceDispenses,
            'specimens' => $fhirSpecimens,
            'clinicalImpressions' => $fhirClinicalImpressions
        ];
    }
}
