<?php

declare(strict_types=1);

namespace App\Livewire\Condition;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Throwable;

class ConditionView extends BasePatientComponent
{
    /**
     * ID of the condition being displayed.
     *
     * @var int
     */
    #[Locked]
    public int $conditionId;

    /**
     * eHealth ID of the condition, kept so that a refresh does not have to read the record to find it.
     *
     * @var string
     */
    #[Locked]
    public string $conditionUuid;

    /**
     * Request-scoped memoized condition.
     *
     * @var Condition|null
     */
    private ?Condition $conditionModel = null;

    protected array $dictionaryNames = [
        'eHealth/ICPC2/condition_codes',
        'eHealth/condition_severities',
        'eHealth/condition_stages',
        'eHealth/body_sites',
        'eHealth/report_origins'
    ];

    /**
     * Bind the route models and load the condition being displayed.
     *
     * @param  LegalEntity  $legalEntity
     * @param  Person|null  $person
     * @param  Preperson|null  $preperson
     * @param  Condition|null  $condition
     * @return void
     */
    public function mount(
        LegalEntity $legalEntity,
        ?Person $person = null,
        ?Preperson $preperson = null,
        ?Condition $condition = null
    ): void {
        parent::mount($legalEntity, $person, $preperson);

        $this->getDictionary();

        $this->conditionId = $condition->id;
        $this->conditionUuid = $condition->uuid;

        $this->condition();
    }

    /**
     * Refresh the condition from eHealth, so that the page shows the record as it stands there now.
     *
     * @return void
     */
    public function sync(): void
    {
        try {
            $response = EHealth::condition()->getById($this->uuid, $this->conditionUuid);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing the condition');

            return;
        }

        $condition = $this->condition();

        // A record of a person merged into the patient stays with its own owner
        $owner = $condition->prepersonId !== null
            ? Preperson::findOrFail($condition->prepersonId)
            : Person::findOrFail($condition->personId);

        try {
            Repository::condition()->sync($owner, [$response->validate()]);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing the condition');

            return;
        }

        // Drop the memoized model so that the page renders what has just been stored
        $this->conditionModel = null;

        Session::flash('success', __('conditions.messages.record_synced_successfully'));
    }

    /**
     * Resolve the condition being displayed, scoped to the patient and the persons merged into it so that a record
     * belonging to somebody else is not reachable by its ID. Loaded again on later requests, where Livewire hydrates
     * without mount().
     *
     * @return Condition
     */
    protected function condition(): Condition
    {
        return $this->conditionModel ??= Condition::readableFor($this->patient(), true)
            ->withAllRelations()
            ->whereId($this->conditionId)
            ->firstOrFail();
    }

    public function render(): View
    {
        return view('livewire.condition.condition-view')->with([
            'condition' => $this->condition()
        ]);
    }
}
