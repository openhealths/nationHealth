<?php

declare(strict_types=1);

namespace App\Livewire\DiagnosticReport;

use App\Core\Arr;
use App\Models\LegalEntity;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Fhir;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Locked;
use Throwable;

class DiagnosticReportEdit extends DiagnosticReportComponent
{
    #[Locked]
    public int $diagnosticReportId;

    /**
     * Report status, shown in the eHealth status block while the report was never submitted.
     *
     * @var string
     */
    public string $statusLabel;

    public function mount(
        LegalEntity $legalEntity,
        ?Person $person = null,
        ?Preperson $preperson = null,
        ?DiagnosticReport $diagnosticReport = null
    ): void {
        parent::mount($legalEntity, $person, $preperson);

        $this->diagnosticReportId = $diagnosticReport->id;

        $diagnosticReport = DiagnosticReport::readableFor($this->patient(), true)
            ->withAllRelations()
            ->whereKey($this->diagnosticReportId)
            ->firstOrFail();

        $this->diagnosticReportUuid = $diagnosticReport->uuid;

        $this->statusLabel = $diagnosticReport->status->label();
        $this->isReadonly = Auth::user()->cannot('update', $diagnosticReport);

        $diagnosticReportFhirData = Arr::toCamelCase($diagnosticReport->toArray());

        $diagnosticReportData = Fhir::diagnosticReport()->fromFhir(
            $diagnosticReportFhirData
        );

        $diagnosticReportData['status'] = $diagnosticReport->status->value;

        if (!empty($diagnosticReportData['basedOnIdentifier'])) {
            $diagnosticReportData['isReferralAvailable'] = true;
            $diagnosticReportData['referralType'] = 'electronic';
            $diagnosticReportData['referralNumber'] = $this->loadSelectedElectronicReferral($diagnosticReportData['basedOnIdentifier']);
        }

        $selectedEmployeeIds = collect($diagnosticReportData['performerEmployeeIds'] ?? [])
            ->push($diagnosticReportData['resultsInterpreterEmployeeId'] ?? null)
            ->filter()
            ->unique();

        $missingEmployeeIds = $selectedEmployeeIds->diff(collect($this->employees)->pluck('uuid'));

        if ($missingEmployeeIds->isNotEmpty()) {
            $selectedEmployees = Employee::query()
                ->whereIn('uuid', $missingEmployeeIds)
                ->select([
                    'uuid',
                    'party_id',
                    'position',
                    'employee_type',
                    'division_uuid',
                ])
                ->with('party:id,last_name,first_name,second_name')
                ->get()
                ->map(static fn (Employee $employee): array => [
                    'uuid' => $employee->uuid,
                    'name' => $employee->fullName,
                    'position' => $employee->position,
                    'employeeType' => $employee->employeeType,
                    'divisionUuid' => $employee->divisionUuid,
                ]);

            $this->employees = collect($this->employees)
                ->concat($selectedEmployees)
                ->values()
                ->toArray();
        }
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

        $this->form->observations = collect(Repository::observation()->getByDiagnosticReportId($this->diagnosticReportId))
            ->map(fn (array $observation) => Fhir::observation()->fromFhir($observation))
            ->toArray();
    }

    /**
     * Validate and save the draft, unless it was submitted from another page since this one was opened.
     *
     * @param  array  $diagnosticReportData
     * @return void
     */
    public function save(array $diagnosticReportData): void
    {
        if (Auth::user()->cannot('update', DiagnosticReport::findOrFail($this->diagnosticReportId))) {
            Session::flash('error', __('diagnostic-reports.policy.update'));

            return;
        }

        parent::save($diagnosticReportData);
    }

    /**
     * Sign and submit the draft, unless it was submitted from another page since this one was opened.
     *
     * @return void
     */
    public function sign(): void
    {
        if (Auth::user()->cannot('update', DiagnosticReport::findOrFail($this->diagnosticReportId))) {
            Session::flash('error', __('diagnostic-reports.policy.update'));

            return;
        }

        parent::sign();
    }

    protected function loadSelectedElectronicReferral(string $uuid): string
    {
        $referral = Repository::serviceRequest()->findByUuid($uuid);

        if ($referral === null) {
            return '';
        }

        $referral->loadMissing('category');
        $category = strtolower((string) ($referral->category?->text ?? ''));
        $services = collect($this->dictionaries['custom/services'] ?? []);
        $diagnosticReportCategories = array_keys(
            $this->dictionaries['eHealth/diagnostic_report_categories'] ?? []
        );
        $service = $services->firstWhere('id', $referral->serviceId);

        $this->availableReferrals = [
            [
                'id' => $referral->uuid,
                'requisition' => $referral->requestNumber ?: $referral->uuid,
                'category' => $category !== ''
                    ? __('care-plan.referral_category.'.$category)
                    : __('encounters.electronic_referral'),
                'service' => $service,
                'isDiagnosticReportAllowed' => $service !== null
                    && in_array(
                        $service['category'] ?? null,
                        $diagnosticReportCategories,
                        true
                    ),
            ],
        ];

        return $referral->requestNumber ?? '';
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
            $uuid = data_get($this->form->diagnosticReport, 'basedOnIdentifier');

            if (data_get($this->form->diagnosticReport, 'referralType') === 'electronic' && filled($uuid)) {
                $this->storeElectronicReferralIfMissing($uuid, Auth::user()->getDiagnosticReportWriterEmployee());
            }

            Repository::diagnosticReport()->sync(
                $this->patient(),
                [$this->fhirToSync($formattedData['diagnosticReport'])]
            );

            Repository::observation()->sync(
                $this->patient(),
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
