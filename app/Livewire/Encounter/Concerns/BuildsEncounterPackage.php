<?php

declare(strict_types=1);

namespace App\Livewire\Encounter\Concerns;

use App\Dto\ClinicalImpression\Ehealth as ClinicalImpressionEhealth;
use App\Dto\Condition\Ehealth as ConditionEhealth;

use App\Dto\DetectedIssue\Ehealth as DetectedIssueEhealth;
use App\Dto\Device\Ehealth as DeviceEhealth;
use App\Dto\DeviceAssociation\Ehealth as DeviceAssociationEhealth;
use App\Dto\DeviceDispense\Ehealth as DeviceDispenseEhealth;
use App\Dto\DiagnosticReport\Ehealth as DiagnosticReportEhealth;
use App\Dto\Encounter\Ehealth as EncounterEhealth;
use App\Dto\Episode\Ehealth as EpisodeEhealth;
use App\Dto\FormCollection;
use App\Dto\Immunization\Ehealth as ImmunizationEhealth;
use App\Dto\Observation\Ehealth as ObservationEhealth;
use App\Dto\Procedure\Ehealth as ProcedureEhealth;
use App\Dto\Specimen\Ehealth as SpecimenEhealth;
use App\Enums\DeviceAssociation\Status as DeviceAssociationStatus;
use App\Enums\Episode\Status;
use App\Enums\Person\ConditionClinicalStatus;
use App\Enums\Person\DiagnosticReportStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;

trait BuildsEncounterPackage
{
    /**
     * Build FHIR encounter package with optional episode.
     *
     * @param  array  $data  Validated form data
     * @param  string  $episodeType  'new' or 'existing'
     * @param  Status  $episodeStatus  Status to assign to the episode
     * @return array
     */
    protected function buildEncounterPackage(array $data, string $episodeType, Status $episodeStatus = Status::ACTIVE): array
    {
        $uuids = [
            'encounter' => Str::uuid()->toString(),
            'visit' => Str::uuid()->toString(),
            'employee' => Auth::user()->getEncounterWriterEmployee($data['encounter']['classCode'])->uuid,
            'episode' => $data['episode']['id'] ?: Str::uuid()->toString()
        ];

        $package = $this->mapEncounterPackage($data, $uuids);

        if ($episodeType === 'new') {
            $package['episode'] = $this->toRepositoryDocument(app(ObjectMapperInterface::class)->map(new FormCollection($data['episode']), new EpisodeEhealth(
                id: $uuids['episode'],
                status: $episodeStatus,
                legalEntity: legalEntity()->uuid,
                employee: $uuids['employee'],
                periodDate: $data['encounter']['periodDate'],
                periodStart: $data['encounter']['periodStart'],
            ))->toArray());
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
    protected function mapEncounterPackage(array $data, array $uuids): array
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

                    return $this->toRepositoryDocument(app(ObjectMapperInterface::class)->map(new FormCollection($condition), new ConditionEhealth(
                        id: $condition['uuid'] ?? Str::uuid()->toString(),
                        encounter: $uuids['encounter'],
                        employee: $uuids['employee'],
                    ))->toArray());
                }
            )
            ->values()
            ->toArray();

        $fhirImmunizations = collect($data['immunizations'] ?? [])
            ->map(fn (array $immunization): array => $this->toRepositoryDocument(app(ObjectMapperInterface::class)->map(new FormCollection($immunization), new ImmunizationEhealth(
                id: $immunization['uuid'] ?? Str::uuid()->toString(),
                encounter: $uuids['encounter'],
                employee: $uuids['employee'],
                fallbackTime: CarbonImmutable::now()->format('H:i'),
            ))->toArray()))
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

                    return $this->toRepositoryDocument(app(ObjectMapperInterface::class)->map(new FormCollection($diagnosticReport), new DiagnosticReportEhealth(
                        id: $diagnosticReport['uuid'] ?? Str::uuid()->toString(),
                        status: DiagnosticReportStatus::tryFrom($diagnosticReport['status'] ?? '') ?? DiagnosticReportStatus::FINAL,
                        legalEntity: legalEntity()->uuid,
                        employee: $uuids['employee'],
                        encounter: $uuids['encounter'] ?? null,
                    ))->toArray());
                }
            )
            ->values()
            ->toArray();

        $fhirObservations = collect($data['observations'] ?? [])
            ->map(fn (array $observation): array => $this->toRepositoryDocument(app(ObjectMapperInterface::class)->map(new FormCollection($observation), new ObservationEhealth(
                id: $observation['uuid'] ?? Str::uuid()->toString(),
                employee: $uuids['employee'],
                encounter: $uuids['encounter'] ?? null,
                diagnosticReport: $uuids['diagnosticReport'] ?? null,
            ))->toArray()))
            ->values()
            ->toArray();

        $fhirProcedures = collect($data['procedures'] ?? [])
            ->map(fn (array $procedure): array => $this->toRepositoryDocument(app(ObjectMapperInterface::class)->map(new FormCollection($procedure), new ProcedureEhealth(
                id: $uuids['procedure'] ?? $procedure['uuid'] ?? Str::uuid()->toString(),
                legalEntity: legalEntity()->uuid,
                employee: $uuids['employee'],
                encounterUuid: $uuids['encounter'] ?? null,
            ))->toArray()))
            ->values()
            ->toArray();

        $fhirDevices = collect($data['devices'] ?? [])
            ->map(function (array $device) use ($uuids): array {
                return $this->toRepositoryDocument(app(ObjectMapperInterface::class)->map(new FormCollection($device), new DeviceEhealth(
                    id: $device['uuid'] ?? Str::uuid()->toString(),
                    encounter: $uuids['encounter'],
                    recorder: $uuids['employee'],
                ))->toArray());
            })
            ->values()
            ->toArray();

        $fhirDetectedIssues = collect($data['detectedIssues'] ?? [])
            ->map(function (array $detectedIssue) use ($uuids): array {
                $payload = app(ObjectMapperInterface::class)->map(new FormCollection($detectedIssue), new DetectedIssueEhealth(
                    id: $detectedIssue['uuid'] ?? Str::uuid()->toString(),
                    encounter: $uuids['encounter'],
                    recorder: $uuids['employee'],
                ))->toArray();

                return $this->toRepositoryDocument($payload);
            })
            ->values()
            ->toArray();

        $fhirDeviceAssociations = collect($this->dateDeviceAssociations($data['deviceAssociations'] ?? []))
            ->map(function (array $association) use ($uuids): array {
                return $this->toRepositoryDocument(app(ObjectMapperInterface::class)->map(new FormCollection($association), new DeviceAssociationEhealth(
                    id: $association['uuid'] ?? Str::uuid()->toString(),
                    encounter: $uuids['encounter'],
                    recorder: $uuids['employee'],
                ))->toArray());
            })
            ->values()
            ->toArray();

        $fhirClinicalImpressions = collect($data['clinicalImpressions'] ?? [])
            ->map(fn (array $clinicalImpression): array => $this->toRepositoryDocument(app(ObjectMapperInterface::class)->map(new FormCollection($clinicalImpression), new ClinicalImpressionEhealth(
                id: $clinicalImpression['uuid'] ?? Str::uuid()->toString(),
                encounter: $uuids['encounter'],
                employee: $uuids['employee'],
            ))->toArray()))
            ->values()
            ->toArray();

        $fhirDeviceDispenses = collect($data['deviceDispenses'] ?? [])
            ->map(function (array $deviceDispense) use ($uuids): array {
                return $this->toRepositoryDocument(app(ObjectMapperInterface::class)->map(new FormCollection($deviceDispense), new DeviceDispenseEhealth(
                    id: $deviceDispense['uuid'] ?? Str::uuid()->toString(),
                    encounter: $uuids['encounter'],
                ))->toArray());
            })
            ->values()
            ->toArray();

        $referencedSpecimenIds = collect($data['observations'] ?? [])
            ->pluck('specimenId')
            ->merge(collect($data['diagnosticReports'] ?? [])->pluck('specimenIds')->flatten())
            ->filter()
            ->unique()
            ->all();

        $fhirSpecimens = collect($data['specimens'] ?? [])
            ->map(function (array $specimen) use ($referencedSpecimenIds, $uuids): array {
                $specimen['isReferenced'] = in_array($specimen['uuid'] ?? null, $referencedSpecimenIds, true);

                return $this->toRepositoryDocument(app(ObjectMapperInterface::class)->map(new FormCollection($specimen), new SpecimenEhealth(
                    id: $specimen['uuid'] ?? Str::uuid()->toString(),
                    legalEntity: legalEntity()->uuid,
                    employee: $uuids['employee'],
                    encounter: $uuids['encounter'],
                ))->toArray());
            })
            ->values()
            ->toArray();

        $encounterData = $data['encounter'];

        return [
            'encounter' => $this->toRepositoryDocument(app(ObjectMapperInterface::class)->map(new FormCollection($encounterData), new EncounterEhealth(
                id: $encounterData['uuid'] ?? $uuids['encounter'],
                visit: $uuids['visit'],
                episode: $uuids['episode'],
                employee: $uuids['employee'] ?? null,
                conditions: $fhirConditions,
            ))->toArray()),
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

    /** New opening/closing pairs must remain a minute apart after repository hydration. */
    private function dateDeviceAssociations(array $associations): array
    {
        $now = CarbonImmutable::now();
        $perDevice = array_count_values(array_column($associations, 'deviceId'));
        foreach ($associations as $index => $association) {
            if (!empty($association['recorded'])) {
                continue;
            }

            $opensPair = ($perDevice[$association['deviceId']] ?? 1) > 1
                && in_array($association['status'], [DeviceAssociationStatus::IMPLANTED->value, DeviceAssociationStatus::ATTACHED->value], true);
            $associations[$index]['recorded'] = ($opensPair ? $now->subMinute() : $now)->toIso8601ZuluString();
        }

        return $associations;
    }

    /** The existing Repository accepts camelCase document keys; preserve nested JSON objects. */
    private function toRepositoryDocument(array $payload): array
    {
        $document = [];
        foreach ($payload as $field => $value) {
            $key = is_string($field) ? Str::camel($field) : $field;
            $document[$key] = is_array($value) ? $this->toRepositoryDocument($value) : $value;
        }

        return $document;
    }
}
