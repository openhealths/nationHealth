<?php

declare(strict_types=1);

namespace App\Livewire\Specimen;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Specimen\Forms\SpecimenActionForm;
use App\Models\MedicalEvents\Sql\Specimen;
use App\Services\MedicalEvents\Fhir;
use App\Traits\FormTrait;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SpecimenIndex extends Component
{
    use FormTrait;

    public string $searchId = '';

    /**
     * Specimen found by its accession identifier. Its subject is used only to address the patient in the requests,
     * so that nothing identifying the patient is shown.
     *
     * @var array
     */
    #[Locked]
    public array $specimen = [];

    protected array $dictionaryNames = [
        'specimen_types',
        'specimen_container_types',
        'specimen_invalidate_reasons',
        'specimen_reject_reasons',
        'specimen_conditions',
        'specimen_collection_methods',
        'specimen_container_additives',
        'fasting_statuses',
        'eHealth/body_sites',
        'GENDER'
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

        $validated = $this->form->validate($this->form->rulesForProcessing(
            data_get($this->specimen, 'collection.collectedDateTime')
            ?? data_get($this->specimen, 'collection.collectedPeriod.end')
        ));

        try {
            $response = EHealth::specimen()->process(
                data_get($this->specimen, 'subject.identifier.value'),
                data_get($this->specimen, 'uuid'),
                Arr::toSnakeCase(Fhir::specimen()->toProcessFhir($validated))
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
                Arr::toSnakeCase(Fhir::specimen()->toInvalidateFhir($validated))
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
                Arr::toSnakeCase(Fhir::specimen()->toRejectFhir($validated))
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
