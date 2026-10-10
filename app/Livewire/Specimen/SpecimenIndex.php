<?php

declare(strict_types=1);

namespace App\Livewire\Specimen;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Dto\Specimen\EhealthProcess;
use App\Dto\Specimen\EhealthStatusReason;
use App\Enums\Specimen\StatusReasonType;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Specimen\Forms\SpecimenActionForm;
use App\Models\MedicalEvents\Sql\Specimen;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\FormTrait;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Throwable;

class SpecimenIndex extends Component
{
    use FormTrait;

    public string $searchId = '';

    /**
     * Specimen found by its accession identifier.
     *
     * @var array
     */
    #[Locked]
    public array $specimen = [];

    protected array $dictionaryNames = [
        'specimen_types',
        'specimen_container_types',
        'specimen_invalidate_reasons',
        'specimen_reject_reasons'
    ];

    public bool $showProcessModal = false;

    public bool $showInvalidateModal = false;

    public bool $showRejectModal = false;

    public SpecimenActionForm $form;

    /**
     * Component mount.
     */
    public function mount(): void
    {
        $this->getDictionary();
    }

    /**
     * Find a specimen by its accession identifier.
     *
     * @return void
     */
    public function search(): void
    {
        $this->validate(['searchId' => ['required', 'string', 'regex:/^\d{4}-\d{4}-\d{4}-\d{4}$/']]);

        $this->specimen = [];

        try {
            $response = EHealth::specimen()->getByAccessionIdentifier($this->searchId);
            $this->specimen = Arr::toCamelCase($response->validate());
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while searching specimen by accession identifier');
        }
    }

    /**
     * Open the page of the found specimen under its patient, storing the specimen first when it is not in the database yet.
     * The page lives under the patient, so a specimen of a patient unknown to the system cannot be opened.
     *
     * @return void
     */
    public function view(): void
    {
        $patientId = data_get($this->specimen, 'subject.identifier.value');
        $specimenId = data_get($this->specimen, 'uuid');

        $patient = Person::whereUuid($patientId)->first() ?? Preperson::whereUuid($patientId)->first();

        if ($patient === null) {
            Session::flash('error', __('specimens.messages.patient_not_found'));

            return;
        }

        $specimen = Specimen::forPatient($patient)->whereUuid($specimenId)->first()
            ?? $this->storeFoundSpecimen($patient, $specimenId);

        if ($specimen === null) {
            return;
        }

        if ($patient instanceof Preperson) {
            $this->redirectRoute(
                'prepersons.specimens.view',
                [legalEntity(), 'preperson' => $patient->id, 'specimen' => $specimen->id],
                navigate: true
            );

            return;
        }

        $this->redirectRoute(
            'persons.specimens.view',
            [legalEntity(), 'person' => $patient->id, 'specimen' => $specimen->id],
            navigate: true
        );
    }

    /**
     * Store the found specimen under its patient, so that it has a page to open.
     *
     * @param  Person|Preperson  $patient
     * @param  string  $specimenId
     * @return Specimen|null
     */
    protected function storeFoundSpecimen(Person|Preperson $patient, string $specimenId): ?Specimen
    {
        try {
            $response = EHealth::specimen()->getDetails($patient->uuid, $specimenId);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading the specimen');

            return null;
        }

        try {
            Repository::specimen()->sync($patient, [$response->validate()]);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while storing the specimen');

            return null;
        }

        return Specimen::forPatient($patient)->whereUuid($specimenId)->first();
    }

    /**
     * Send the request to set the time the selected specimen was received for processing.
     *
     * @return void
     */
    public function process(): void
    {
        if (Auth::user()->cannot('process', [Specimen::class, $this->specimen])) {
            Session::flash('error', __('specimens.policy.process'));

            return;
        }

        $this->form->validate($this->form->rulesForProcessing(
            data_get($this->specimen, 'collection.collectedDateTime')
            ?? data_get($this->specimen, 'collection.collectedPeriod.end')
        ));

        try {
            $response = EHealth::specimen()->process(
                data_get($this->specimen, 'subject.identifier.value'),
                data_get($this->specimen, 'uuid'),
                app(ObjectMapperInterface::class)->map($this->form, EhealthProcess::class)->toArray()
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while processing the specimen');

            return;
        }

        logger()->debug('Job ID to further debug', $response->getData());

        $this->showProcessModal = false;
        Session::flash('success', __('specimens.messages.process_request_sent'));
    }

    /**
     * Send the request to mark the selected specimen as unavailable.
     *
     * @return void
     */
    public function invalidate(): void
    {
        if (Auth::user()->cannot('invalidate', [Specimen::class, $this->specimen])) {
            Session::flash('error', __('specimens.policy.invalidate'));

            return;
        }

        $validated = $this->form->validate($this->form->rulesForInvalidating());

        try {
            $response = EHealth::specimen()->invalidate(
                data_get($this->specimen, 'subject.identifier.value'),
                data_get($this->specimen, 'uuid'),
                app(ObjectMapperInterface::class)->map(new Collection(['reason' => $validated['invalidateReason']]), new EhealthStatusReason(StatusReasonType::INVALIDATE))->toArray()
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while invalidating the specimen');

            return;
        }

        logger()->debug('Job ID to further debug', $response->getData());

        $this->showInvalidateModal = false;
        Session::flash('success', __('specimens.messages.invalidate_request_sent'));
    }

    /**
     * Send the request to mark the selected specimen as rejected.
     *
     * @return void
     */
    public function reject(): void
    {
        if (Auth::user()->cannot('reject', [Specimen::class, $this->specimen])) {
            Session::flash('error', __('specimens.policy.reject'));

            return;
        }

        $validated = $this->form->validate($this->form->rulesForRejecting());

        try {
            $response = EHealth::specimen()->reject(
                data_get($this->specimen, 'subject.identifier.value'),
                data_get($this->specimen, 'uuid'),
                app(ObjectMapperInterface::class)->map(new Collection(['reason' => $validated['rejectReason']]), new EhealthStatusReason(StatusReasonType::REJECT))->toArray()
            );
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while rejecting the specimen');

            return;
        }

        logger()->debug('Job ID to further debug', $response->getData());

        $this->showRejectModal = false;
        Session::flash('success', __('specimens.messages.reject_request_sent'));
    }

    /**
     * Render the component.
     *
     * @return View
     */
    public function render(): View
    {
        return view('livewire.specimen.specimen-index');
    }
}
