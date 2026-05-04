<?php

declare(strict_types=1);

namespace App\Services\MedicalEvents;

use App\Services\MedicalEvents\Mappers\ConditionMapper;
use App\Services\MedicalEvents\Mappers\DiagnosticReportMapper;
use App\Services\MedicalEvents\Mappers\EncounterMapper;
use App\Services\MedicalEvents\Mappers\EpisodeMapper;
use App\Services\MedicalEvents\Mappers\ImmunizationMapper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

readonly class EncounterPackageBuilder
{
    public function __construct(
        private EncounterMapper $encounterMapper,
        private EpisodeMapper $episodeMapper,
        private ConditionMapper $conditionMapper,
        private ImmunizationMapper $immunizationMapper,
        private DiagnosticReportMapper $diagnosticReportMapper
    ) {
    }

    public function build(array $data, string $episodeType): array
    {
        $uuids = [
            'encounter' => Str::uuid()->toString(),
            'visit' => Str::uuid()->toString(),
            'employee' => Auth::user()->getEncounterWriterEmployee($data['encounter']['classCode'])->uuid,
            'episode' => $data['episode']['id'] ?: Str::uuid()->toString()
        ];

        $fhirConditions = collect($data['conditions'])
            ->map(fn (array $condition) => $this->conditionMapper->toFhir($condition, $uuids))
            ->toArray();

        $fhirImmunizations = collect($data['immunizations'] ?? [])
            ->map(fn (array $immunization) => $this->immunizationMapper->toFhir($immunization, $uuids))
            ->values()
            ->toArray();

        $fhirDiagnosticReports = collect($data['diagnosticReports'] ?? [])
            ->map(fn (array $diagnosticReport) => $this->diagnosticReportMapper->toFhir($diagnosticReport, $uuids))
            ->values()
            ->toArray();

        $fhirEncounter = $this->encounterMapper->toFhir($data['encounter'], $fhirConditions, $uuids);

        $fhirEpisode = [];
        if ($episodeType === 'new') {
            $fhirEpisode = $this->episodeMapper->toFhir(
                $data['episode'],
                $uuids,
                $data['encounter']['periodDate'],
                $data['encounter']['periodStart']
            );
        }

        return array_filter([
            'encounter' => $fhirEncounter,
            'episode' => $fhirEpisode,
            'conditions' => $fhirConditions,
            'immunizations' => $fhirImmunizations,
            'diagnosticReports' => $fhirDiagnosticReports,
        ]);
    }
}
