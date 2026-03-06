<?php

declare(strict_types=1);

namespace App\Livewire\Procedure;

use App\Classes\Cipher\Traits\Cipher;
use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\Exceptions\ApiException as eHealthApiException;
use App\Core\Arr;
use App\Enums\Status;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Livewire\Procedure\Forms\ProcedureForm as Form;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Traits\FormTrait;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

class ProcedureComponent extends Component
{
    use FormTrait;
    use Cipher;
    use WithFileUploads;

    public Form $form;

    /**
     * ID of the patient for whom the procedure is created.
     *
     * @var int
     */
    #[Locked]
    public int $patientId;

    /**
     * Patient UUID for API requests.
     *
     * @var string
     */
    public string $patientUuid;

    /**
     * Patient full name.
     *
     * @var string
     */
    public string $patientFullName;

    /**
     * List of authorized user's divisions.
     *
     * @var array
     */
    public array $divisions;

    /**
     * List of existing patient episodes.
     *
     * @var array
     */
    public array $episodes = [];

    /**
     * Full name of employee.
     *
     * @var string
     */
    public string $employeeFullName;

    /**
     * List of founded procedure reasons.
     *
     * @var array
     */
    public array $procedureReasons = [];

    protected array $dictionaryNames = [
        'eHealth/procedure_categories',
        'eHealth/procedure_outcomes',
        'eHealth/report_origins',
        'eHealth/LOINC/observation_codes',
        'eHealth/ICF/classifiers',
        'eHealth/ICPC2/condition_codes',
        'eHealth/assistive_products'
    ];

    public function boot(): void
    {
        $this->getDictionary();

        try {
            $this->dictionaries['custom/services'] = dictionary()->services()->flattened()->toArray();
            $this->dictionaries['eHealth/assistive_products'] = dictionary()->basics()
                ->byName('eHealth/assistive_products')
                ->flattenedChildValues()
                ->toArray();
        } catch (eHealthApiException) {
            Log::channel('e_health_errors')
                ->error('Error while loading services and assistive products dictionaries in ProcedureComponent');
        }
    }

    public function mount(LegalEntity $legalEntity, int $patientId): void
    {
        $authUser = Auth::user();

        if (!$authUser) {
            throw new RuntimeException('Authenticated user not found');
        }

        $this->patientId = $patientId;
        $this->employeeFullName = $authUser->getProcedureWriterEmployee()->fullName;

        $this->setPatientData();

        // Get all active divisions of current legal entity
        $this->divisions = $legalEntity->divisions()
            ->where('status', Status::ACTIVE->name)
            ->where('is_active', '=', true)
            ->select(['uuid', 'name'])
            ->get()
            ->toArray();
    }

    /**
     * Search for procedure reasons in conditions and observations.
     *
     * @param  string  $episodeId
     * @return void
     */
    public function searchReasons(string $episodeId): void
    {
        // Validate that an episode ID is provided
        if (empty($episodeId)) {
            $this->addError('episode', 'Please select an episode first.');

            return;
        }

        try {
            $conditions = EHealth::condition()->getInEpisodeContext(
                $this->patientUuid,
                $episodeId,
                ['patient_id' => $this->patientUuid, 'episode_id' => $episodeId]
            );
            $observations = EHealth::observation()->getInEpisodeContext(
                $this->patientUuid,
                $episodeId,
                ['patient_id' => $this->patientUuid, 'episode_id' => $episodeId]
            );

            $this->procedureReasons = array_merge($conditions->getData(), $observations->getData());
        } catch (ConnectionException $exception) {
            $this->logConnectionError($exception, 'Error connecting when getting a reasons');
            Session::flash('error', "Виникла помилка. Відсутній зв'язок із ЕСОЗ.");

            return;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            $this->logEHealthException($exception, 'Error when getting a reasons');

            if ($exception instanceof EHealthValidationException) {
                Session::flash('error', $exception->getFormattedMessage());
            } else {
                Session::flash('error', 'Помилка від ЕСОЗ: ' . $exception->getMessage());
            }

            return;
        }
    }

    /**
     * Set patient data.
     *
     * @return void
     */
    protected function setPatientData(): void
    {
        $patient = Person::select(['uuid', 'first_name', 'last_name', 'second_name'])
            ->where('id', $this->patientId)
            ->firstOrFail();

        $this->patientUuid = $patient->uuid;
        $this->patientFullName = $patient->fullName;
    }

    /**
     * Get all episodes for current patient.
     *
     * @return void
     */
    public function getEpisodes(): void
    {
        try {
            $response = EHealth::episode()->getManyBySearchParams(
                $this->patientUuid,
                ['managing_organization_id' => legalEntity()->uuid]
            );
            $this->episodes = collect($response->getData())
                ->map(static fn (array $item) => Arr::only($item, ['id', 'name', 'status', 'inserted_at']))
                ->toArray();
        } catch (ConnectionException $exception) {
            $this->logConnectionError($exception, 'Error connecting when getting episodes');
            Session::flash('error', "Виникла помилка. Відсутній зв'язок із ЕСОЗ.");

            return;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            $this->logEHealthException($exception, 'Error when getting episodes');

            if ($exception instanceof EHealthValidationException) {
                Session::flash('error', $exception->getFormattedMessage());
            } else {
                Session::flash('error', 'Помилка від ЕСОЗ: ' . $exception->getMessage());
            }

            return;
        }
    }
}
