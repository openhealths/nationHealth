<?php

declare(strict_types=1);

namespace App\Livewire\Episode;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
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
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Locked;
use Throwable;

class EpisodeView extends BasePatientComponent
{
    /**
     * ID of the episode being displayed.
     *
     * @var int
     */
    #[Locked]
    public int $episodeId;

    /**
     * eHealth ID of the episode, kept so that a refresh does not have to read the record to find it.
     *
     * @var string
     */
    #[Locked]
    public string $episodeUuid;

    /**
     * Request-scoped memoized episode.
     *
     * @var Episode|null
     */
    private ?Episode $episodeModel = null;

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
     * Bind the route models and remember the episode being displayed.
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

        $this->episodeId = $episode->id;
        $this->episodeUuid = $episode->uuid;
    }

    /**
     * Refresh the episode from eHealth, so that the page shows the record as it stands there now.
     * Access is checked by the route's can middleware, which Livewire applies to every request of the component.
     *
     * @return void
     */
    public function sync(): void
    {
        try {
            $response = EHealth::episode()->getById($this->uuid, $this->episodeUuid);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing the episode');

            return;
        }

        // Stored under the episode's own owner, as an episode of a merged person or preperson stays with that record
        $owner = $this->episode()->preperson ?? $this->episode()->person;

        try {
            Repository::episode()->syncFull($owner, [$response->validate()]);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing the episode');

            return;
        }

        // Drop the memoized model so that the page renders what has just been stored
        $this->episodeModel = null;

        Session::flash('success', __('episodes.messages.record_synced_successfully'));
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

    /**
     * Resolve the episode being displayed. Loaded again on later requests, where Livewire hydrates without mount().
     *
     * @return Episode
     */
    protected function episode(): Episode
    {
        return $this->episodeModel ??= Episode::with([
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
        ])
            ->whereId($this->episodeId)
            ->firstOrFail();
    }

    /**
     * ICD-10 descriptions of the episode's current and past diagnoses, keyed by code.
     *
     * @param  Episode  $episode
     * @return array
     */
    protected function icd10Descriptions(Episode $episode): array
    {
        $icd10Codes = $episode->currentDiagnoses
            ->concat($episode->diagnosesHistory->flatMap->diagnoses)
            ->map(static fn (EpisodeCurrentDiagnosis|EpisodeDiagnosesHistoryItem $diagnosis): ?Coding
                => $diagnosis->code->coding->first())
            ->filter(static fn (?Coding $coding): bool
                => $coding?->system === 'eHealth/ICD10_AM/condition_codes')
            ->pluck('code')
            ->unique();

        return $icd10Codes->isEmpty()
            ? []
            : Icd10::whereIn('code', $icd10Codes)->pluck('description', 'code')->toArray();
    }

    public function render(): View
    {
        $episode = $this->episode();

        $this->icd10Descriptions = $this->icd10Descriptions($episode);

        $organization = $episode->managingOrganization;
        $careManager = $episode->careManager;

        return view('livewire.episode.episode-view')->with([
            'episode' => $episode,
            'currentMainDiagnosis' => $episode->currentDiagnoses
                ->first(static fn (EpisodeCurrentDiagnosis $diagnosis): bool
                    => $diagnosis->role?->coding->first()?->code === 'primary'),
            'managingOrganizationName' => $organization?->displayValue
                ?: LegalEntity::firstWhere('uuid', $organization?->value)?->name ?? '',
            'careManagerName' => $careManager?->displayValue
                ?: Employee::with('party')->firstWhere('uuid', $careManager?->value)?->party?->fullName ?? ''
        ]);
    }
}
