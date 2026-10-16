<?php

declare(strict_types=1);

namespace App\Livewire\Encounter;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Throwable;

class EncounterView extends BasePatientComponent
{
    /**
     * ID of the encounter being displayed.
     *
     * @var int
     */
    #[Locked]
    public int $encounterId;

    /**
     * eHealth ID of the encounter, kept so that a refresh does not have to read the record to find it.
     *
     * @var string
     */
    #[Locked]
    public string $encounterUuid;

    /**
     * Request-scoped memoized encounter.
     *
     * @var Encounter|null
     */
    private ?Encounter $encounterModel = null;

    protected array $dictionaryNames = [
        'eHealth/encounter_classes',
        'eHealth/encounter_types',
        'eHealth/ICPC2/reasons',
        'eHealth/ICPC2/actions',
        'eHealth/diagnosis_roles',
        'eHealth/cancellation_reasons',
        'eHealth/encounter_admit_source',
        'eHealth/encounter_re_admission',
        'eHealth/encounter_discharge_disposition',
        'eHealth/encounter_discharge_department'
    ];

    /**
     * Bind the route models and load the encounter being displayed.
     *
     * @param  LegalEntity  $legalEntity
     * @param  Person|null  $person
     * @param  Preperson|null  $preperson
     * @param  Encounter|null  $encounter
     * @return void
     */
    public function mount(
        LegalEntity $legalEntity,
        ?Person $person = null,
        ?Preperson $preperson = null,
        ?Encounter $encounter = null
    ): void {
        parent::mount($legalEntity, $person, $preperson);

        $this->getDictionary();

        $this->encounterId = $encounter->id;
        $this->encounterUuid = $encounter->uuid;

        $this->encounter();
    }

    /**
     * Refresh the encounter from eHealth, so that the page shows the record as it stands there now.
     *
     * @return void
     */
    public function sync(): void
    {
        try {
            $response = EHealth::encounter()->getById($this->uuid, $this->encounterUuid);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing the encounter');

            return;
        }

        $encounter = $this->encounter();

        // A record of a person merged into the patient stays with its own owner
        $owner = $encounter->preperson_id !== null
            ? Preperson::findOrFail($encounter->preperson_id)
            : Person::findOrFail($encounter->person_id);

        try {
            Repository::encounter()->sync($owner, [$response->validate()]);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing the encounter');

            return;
        }

        // Drop the memoized model so that the page renders what has just been stored
        $this->encounterModel = null;

        Session::flash('success', __('encounters.messages.record_synced_successfully'));
    }

    /**
     * Resolve the encounter being displayed, scoped to the patient and the persons merged into it so that a record
     * belonging to somebody else is not reachable by its ID. Loaded again on later requests, where Livewire hydrates
     * without mount().
     *
     * @return Encounter
     */
    protected function encounter(): Encounter
    {
        return $this->encounterModel ??= Encounter::readableFor($this->patient(), true)
            ->withRelationships()
            ->whereId($this->encounterId)
            ->firstOrFail();
    }

    public function render(): View
    {
        return view('livewire.encounter.encounter-view')->with([
            'encounter' => $this->encounter()
        ]);
    }
}
