<?php

declare(strict_types=1);

namespace App\Livewire\DiagnosticReport;

use App\Core\Arr;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Fhir;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Throwable;

class DiagnosticReportEdit extends DiagnosticReportComponent
{
    #[Locked]
    public string $diagnosticReportId;

    public function mount(LegalEntity $legalEntity, int $personId, ?string $diagnosticReportId = null): void
    {
        parent::mount($legalEntity, $personId);
        $this->diagnosticReportId = $diagnosticReportId;
        $diagnosticReport = DiagnosticReport::withAllRelations()
            ->whereKey($diagnosticReportId)
            ->where('person_id', $personId)
            ->firstOrFail();

        $this->diagnosticReportUuid = $diagnosticReport->uuid;

        $diagnosticReportData = Fhir::diagnosticReport()->fromFhir(
            $diagnosticReport->toArray()
        );

        $conclusionCode = data_get($diagnosticReportData, 'conclusionCode');

        if ($conclusionCode) {
            $icd10Items = [
                [
                    'codeSystem' => 'eHealth/ICD10_AM/condition_codes',
                    'codeCode' => $conclusionCode,
                ],
            ];

            $this->loadIcd10Descriptions($icd10Items);

            $description = $this->dictionaries['eHealth/ICD10_AM/condition_codes'][$conclusionCode] ?? null;

            $diagnosticReportData['conclusionCodeLabel'] = $description
                ? $conclusionCode . ' - ' . $description
                : $conclusionCode;
        }

        $this->form->diagnosticReport = $diagnosticReportData;

        $this->form->observations = collect(Repository::observation()->getByDiagnosticReportId($diagnosticReportId))
            ->map(fn (array $observation) => Fhir::observation()->fromFhir($observation))
            ->toArray();
    }

    /**
     * Sync the formatted report and return its identifier.
     *
     * @param  array  $formattedData
     * @return int|string
     * @throws Throwable
     */
    protected function persist(array $formattedData): int|string
    {
        $this->syncValidatedData($formattedData);

        return $this->diagnosticReportId;
    }

    /**
     * Sync validated formatted data into DB.
     *
     * @param  array  $formattedData
     * @return void
     * @throws Throwable
     */
    protected function syncValidatedData(array $formattedData): void
    {
        DB::transaction(function () use ($formattedData) {
            Repository::diagnosticReport()->sync(
                $this->personId,
                [$this->fhirToSync($formattedData['diagnosticReport'])]
            );

            Repository::observation()->sync(
                $this->personId,
                array_map($this->fhirToSync(...), $formattedData['observations'] ?? [])
            );
        });
    }

    /**
     * Convert a FHIR item into snake_case sync payload keyed by uuid.
     *
     * @param  array  $fhirItem
     * @return array
     */
    private function fhirToSync(array $fhirItem): array
    {
        return Arr::toSnakeCase(
            collect($fhirItem)
                ->put('uuid', $fhirItem['id'])
                ->forget(['id'])
                ->all()
        );
    }
}
