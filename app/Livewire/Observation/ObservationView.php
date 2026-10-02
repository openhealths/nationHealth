<?php

declare(strict_types=1);

namespace App\Livewire\Observation;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Device;
use App\Models\MedicalEvents\Sql\Observation;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Throwable;

class ObservationView extends BasePatientComponent
{
    /**
     * ID of the observation being displayed.
     *
     * @var int
     */
    #[Locked]
    public int $observationId;

    /**
     * eHealth ID of the observation, kept so that a refresh does not have to read the record to find it.
     *
     * @var string
     */
    #[Locked]
    public string $observationUuid;

    /**
     * Request-scoped memoized observation.
     *
     * @var Observation|null
     */
    private ?Observation $observationModel = null;

    protected array $dictionaryNames = [
        'eHealth/observation_categories',
        'eHealth/ICF/observation_categories',
        'eHealth/LOINC/observation_codes',
        'eHealth/custom/observation_codes',
        'eHealth/ICF/classifiers',
        'eHealth/observation_methods',
        'eHealth/observation_interpretations',
        'eHealth/body_sites',
        'eHealth/report_origins',
        'eHealth/eye_colour',
        'eHealth/hair_color',
        'eHealth/hair_length',
        'GENDER',
        'eHealth/rankin_scale',
        'eHealth/vaccination_covid_groups',
        'eHealth/occupation_type'
    ];

    /**
     * Bind the route models and load the observation being displayed.
     *
     * @param  LegalEntity  $legalEntity
     * @param  Person|null  $person
     * @param  Preperson|null  $preperson
     * @param  Observation|null  $observation
     * @return void
     */
    public function mount(
        LegalEntity $legalEntity,
        ?Person $person = null,
        ?Preperson $preperson = null,
        ?Observation $observation = null
    ): void {
        parent::mount($legalEntity, $person, $preperson);

        $this->getDictionary();

        // ICF codes are nested under their groups, while the observation refers to them by the code alone
        $this->dictionaries['eHealth/ICF/classifiers'] = dictionary()->basics()
            ->byName('eHealth/ICF/classifiers')
            ->flattenedChildValues()
            ->toArray();

        $this->observationId = $observation->id;
        $this->observationUuid = $observation->uuid;

        $this->observation();
    }

    /**
     * Refresh the observation from eHealth, so that the page shows the record as it stands there now.
     *
     * @return void
     */
    public function sync(): void
    {
        try {
            $response = EHealth::observation()->getById($this->uuid, $this->observationUuid);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing the observation');

            return;
        }

        try {
            Repository::observation()->sync($this->patient(), [$response->validate()]);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing the observation');

            return;
        }

        // Drop the memoized model so that the page renders what has just been stored
        $this->observationModel = null;

        Session::flash('success', __('observations.messages.record_synced_successfully'));
    }

    /**
     * Resolve the observation being displayed, scoped to the patient so that a record belonging to somebody else
     * is not reachable by its ID. Loaded again on later requests, where Livewire hydrates without mount().
     *
     * @return Observation
     */
    protected function observation(): Observation
    {
        return $this->observationModel ??= Observation::forPatient($this->patient())
            ->withAllRelations()
            ->whereId($this->observationId)
            ->firstOrFail();
    }

    /**
     * Name of the patient's stored device the observation was made with.
     *
     * @param  string|null  $deviceId
     * @return string|null
     */
    protected function deviceName(?string $deviceId): ?string
    {
        if ($deviceId === null) {
            return null;
        }

        return Device::forPatient($this->patient())->whereUuid($deviceId)->with('names')->first()?->names->first()?->value;
    }

    public function render(): View
    {
        $observation = $this->observation();

        return view('livewire.observation.observation-view')->with([
            'observation' => $observation,
            'deviceName' => $this->deviceName($observation->device?->value)
        ]);
    }
}
