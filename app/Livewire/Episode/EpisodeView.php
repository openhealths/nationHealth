<?php

declare(strict_types=1);

namespace App\Livewire\Episode;

use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\Employee\Employee;
use App\Models\Icd10;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Coding;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\EpisodeCurrentDiagnosis;
use App\Models\MedicalEvents\Sql\EpisodeDiagnosesHistoryItem;
use App\Models\Person\Person;
use App\Models\Preperson;
use Illuminate\Contracts\View\View;

class EpisodeView extends BasePatientComponent
{
    /**
     * Episode being displayed.
     *
     * @var Episode
     */
    protected Episode $episode;

    public string $careManagerName = '';

    public string $managingOrganizationName = '';

    /**
     * Current diagnosis with the primary role.
     *
     * @var EpisodeCurrentDiagnosis|null
     */
    protected ?EpisodeCurrentDiagnosis $currentMainDiagnosis = null;

    protected array $dictionaryNames = [
        'eHealth/episode_types',
        'eHealth/ICPC2/condition_codes',
        'eHealth/episode_closing_reasons',
        'eHealth/cancellation_reasons',
        'eHealth/diagnosis_roles'
    ];

    /**
     * ICD-10 descriptions keyed by code, preloaded from the local icd_10 table.
     *
     * @var array
     */
    protected array $icd10Descriptions = [];

    /**
     * Bind the route models and load the episode being displayed.
     *
     * @param  LegalEntity  $legalEntity
     * @param  Person|null  $person
     * @param  Preperson|null  $preperson
     * @param  Episode|null  $episode
     * @return void
     */
    public function mount(
        LegalEntity $legalEntity,
        ?Person $person = null,
        ?Preperson $preperson = null,
        ?Episode $episode = null
    ): void {
        parent::mount($legalEntity, $person, $preperson);

        $this->getDictionary();

        $this->episode = $episode->load([
            'type',
            'period',
            'managingOrganization',
            'careManager',
            'statusReason.coding',
            'currentDiagnoses.condition',
            'currentDiagnoses.code.coding',
            'currentDiagnoses.role.coding',
            'diagnosesHistory.diagnoses.condition',
            'diagnosesHistory.diagnoses.code.coding',
            'diagnosesHistory.diagnoses.role.coding'
        ]);
        $this->currentMainDiagnosis = $this->episode->currentDiagnoses
            ->first(static fn (EpisodeCurrentDiagnosis $diagnosis): bool
                => $diagnosis->role?->coding->first()?->code === 'primary');

        $icd10Codes = $this->episode->currentDiagnoses
            ->concat($this->episode->diagnosesHistory->flatMap->diagnoses)
            ->map(static fn (EpisodeCurrentDiagnosis|EpisodeDiagnosesHistoryItem $diagnosis): ?Coding
                => $diagnosis->code->coding->first())
            ->filter(static fn (?Coding $coding): bool
                => $coding?->system === 'eHealth/ICD10_AM/condition_codes')
            ->pluck('code')
            ->unique();

        $this->icd10Descriptions = $icd10Codes->isEmpty()
            ? []
            : Icd10::whereIn('code', $icd10Codes)->pluck('description', 'code')->toArray();

        $organization = $this->episode->managingOrganization;

        $this->managingOrganizationName = $organization?->displayValue
            ?: LegalEntity::firstWhere('uuid', $organization?->value)?->name ?? '';

        $careManager = $this->episode->careManager;

        $this->careManagerName = $careManager?->displayValue
            ?: Employee::with('party')->firstWhere('uuid', $careManager?->value)?->party?->fullName ?? '';
    }

    /**
     * Build the "code - description" label for a diagnosis.
     *
     * ICD-10 codes are not part of the loaded dictionaries and are resolved from the local icd_10 table.
     *
     * @param  EpisodeCurrentDiagnosis|EpisodeDiagnosesHistoryItem  $diagnosis
     * @return string
     */
    public function getDiagnosisDisplay(EpisodeCurrentDiagnosis|EpisodeDiagnosesHistoryItem $diagnosis): string
    {
        $coding = $diagnosis->code->coding->first();

        if ($coding === null) {
            return '-';
        }

        $description = $coding->system === 'eHealth/ICD10_AM/condition_codes'
            ? ($this->icd10Descriptions[$coding->code] ?? null)
            : data_get($this->dictionaries, $coding->system . '.' . $coding->code);

        return trim($coding->code . ' - ' . $description, ' -');
    }

    public function render(): View
    {
        return view('livewire.episode.episode-view')->with([
            'episode' => $this->episode,
            'currentMainDiagnosis' => $this->currentMainDiagnosis
        ]);
    }
}
