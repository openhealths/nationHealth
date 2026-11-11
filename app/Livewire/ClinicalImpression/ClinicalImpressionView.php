<?php

declare(strict_types=1);

namespace App\Livewire\ClinicalImpression;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\Icd10;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\ClinicalImpression;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Services\MedicalEvents\Fhir;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Throwable;

class ClinicalImpressionView extends BasePatientComponent
{
    /**
     * ID of the clinical impression being displayed.
     *
     * @var int
     */
    #[Locked]
    public int $clinicalImpressionId;

    /**
     * eHealth ID of the clinical impression, kept so that a refresh does not have to read the record to find it.
     *
     * @var string
     */
    #[Locked]
    public string $clinicalImpressionUuid;

    /**
     * Request-scoped memoized clinical impression.
     *
     * @var ClinicalImpression|null
     */
    private ?ClinicalImpression $clinicalImpressionModel = null;

    protected array $dictionaryNames = [
        'eHealth/clinical_impression_patient_categories',
        'eHealth/resources'
    ];

    /**
     * Bind the route models and load the clinical impression being displayed.
     *
     * @param  LegalEntity  $legalEntity
     * @param  Person|null  $person
     * @param  Preperson|null  $preperson
     * @param  ClinicalImpression|null  $clinicalImpression
     * @return void
     */
    public function mount(
        LegalEntity $legalEntity,
        ?Person $person = null,
        ?Preperson $preperson = null,
        ?ClinicalImpression $clinicalImpression = null
    ): void {
        parent::mount($legalEntity, $person, $preperson);

        $this->getDictionary();

        $this->clinicalImpressionId = $clinicalImpression->id;
        $this->clinicalImpressionUuid = $clinicalImpression->uuid;

        $this->clinicalImpression();
    }

    /**
     * Refresh the clinical impression from eHealth, so that the page shows the record as it stands there now.
     *
     * @return void
     */
    public function sync(): void
    {
        try {
            $response = EHealth::clinicalImpression()->getById($this->uuid, $this->clinicalImpressionUuid);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing the clinical impression');

            return;
        }

        $clinicalImpression = $this->clinicalImpression();

        // A record of a person merged into the patient stays with its own owner
        $owner = $clinicalImpression->prepersonId !== null
            ? Preperson::findOrFail($clinicalImpression->prepersonId)
            : Person::findOrFail($clinicalImpression->personId);

        try {
            Repository::clinicalImpression()->sync($owner, [$response->validate()]);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing the clinical impression');

            return;
        }

        // Drop the memoized model so that the page renders what has just been stored
        $this->clinicalImpressionModel = null;

        Session::flash('success', __('clinical-impressions.messages.record_synced_successfully'));
    }

    /**
     * Resolve the clinical impression being displayed, scoped to the patient and the persons merged into it so that a
     * record belonging to somebody else is not reachable by its ID. Loaded again on later requests, where Livewire
     * hydrates without mount().
     *
     * @return ClinicalImpression
     */
    protected function clinicalImpression(): ClinicalImpression
    {
        return $this->clinicalImpressionModel ??= ClinicalImpression::readableFor($this->patient(), true)
            ->withAllRelations()
            ->whereId($this->clinicalImpressionId)
            ->firstOrFail();
    }

    /**
     * Name the referenced records by their codes, the way the encounter package lists them. A record not stored
     * locally has no code, so it is named by its UUID.
     *
     * @param  array  $records  Problems, findings or supporting info with their dates and codes
     * @return array
     */
    protected function namedRecords(array $records): array
    {
        $codedRecords = collect($records)->map(static fn (array $record): array => [
            ...$record,
            'code' => $record['codeCode'] ?? $record['code'] ?? null
        ]);

        $codeSystems = $codedRecords->pluck('codeSystem')->filter()->unique()->values()->all();

        // ICD-10 AM codes are kept in their own table rather than among the dictionaries
        $descriptions = dictionary()->basics()
            ->getMultipleFormatted(array_diff($codeSystems, ['eHealth/ICD10_AM/condition_codes']), onlyActive: false)
            ->toArray();
        $descriptions['eHealth/ICD10_AM/condition_codes'] = Icd10::whereIn(
            'code',
            $codedRecords->where('codeSystem', 'eHealth/ICD10_AM/condition_codes')->pluck('code')->filter()
        )->pluck('description', 'code')->all();

        // The classifiers are nested, so the codes are looked up among the child values as well
        if (in_array('eHealth/ICF/classifiers', $codeSystems, true)) {
            $descriptions['eHealth/ICF/classifiers'] = dictionary()->basics()
                ->byName('eHealth/ICF/classifiers', false)
                ->flattenedChildValues()
                ->toArray();
        }

        // Procedures and diagnostic reports are coded by the service they provide
        $services = $codedRecords->whereIn('type', ['procedure', 'diagnostic_report'])->isNotEmpty()
            ? dictionary()->services()->flattened()
            : collect();

        return $codedRecords->map(static function (array $record) use ($descriptions, $services): array {
            $service = $services->firstWhere('id', $record['code']);
            $description = $descriptions[$record['codeSystem'] ?? ''][$record['code'] ?? ''] ?? null;

            return [
                ...$record,
                'name' => match (true) {
                    $service !== null => "{$service['code']} / {$service['name']}",
                    $description !== null => "{$record['code']} - {$description}",
                    default => $record['code'] ?? ($record['id'] ?? ($record['uuid'] ?? '-'))
                }
            ];
        })->all();
    }

    public function render(): View
    {
        $clinicalImpression = $this->clinicalImpression();
        $references = Fhir::encounterPackageLoader()->clinicalImpressionsFromFhir([$clinicalImpression->toArray()])[0];

        return view('livewire.clinical-impression.clinical-impression-view')->with([
            'clinicalImpression' => $clinicalImpression,
            'problems' => $this->namedRecords($references['problems']),
            'findings' => $this->namedRecords($references['findings']),
            'supportingInfo' => $this->namedRecords($references['supportingInfo'])
        ]);
    }
}
