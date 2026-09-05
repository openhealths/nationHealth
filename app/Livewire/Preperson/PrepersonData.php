<?php

declare(strict_types=1);

namespace App\Livewire\Preperson;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Preperson\Forms\PrepersonForm as Form;
use App\Models\LegalEntity;
use App\Models\MergeRequest;
use App\Models\Preperson;
use App\Traits\LogsExceptions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class PrepersonData extends Component
{
    use LogsExceptions;

    public Form $form;

    /**
     * Local database ID of the route-bound preperson shown on the page.
     *
     * @var int
     */
    #[Locked]
    public int $prepersonId;

    /**
     * Local database ID of the record loaded into the edit form.
     * On this single-record page it is a constant (this preperson), so the modal can open client-side without a round-trip.
     *
     * @var int|null
     */
    #[Locked]
    public ?int $editingId = null;

    /**
     * Initialize the component from the route-bound preperson and pre-fill the edit form.
     *
     * @param  LegalEntity  $legalEntity
     * @param  Preperson  $preperson
     * @return void
     */
    public function mount(LegalEntity $legalEntity, Preperson $preperson): void
    {
        $this->prepersonId = $preperson->id;
        $this->editingId = $preperson->id;

        $person = Arr::only(
            Arr::toCamelCase($preperson->toArray()),
            ['uuid', 'firstName', 'lastName', 'secondName', 'birthDate', 'gender', 'emergencyContact']
        );

        if (!empty($person['birthDate'])) {
            $person['birthDate'] = convertToAppDateFormat($person['birthDate']);
        }

        // keep the phones row the emergency-contact inputs bind to even when no contact was stored
        $person['emergencyContact'] ??= [];
        $person['emergencyContact']['phones'] ??= [['type' => null, 'number' => null]];

        $this->form->person = $person;
        // the reason is never edited here, but it drives the "newborn requires a contact person" update rule
        $this->form->reasonContext['reason'] = $preperson->reasonContext['reason'] ?? '';
    }

    /**
     * Validate the edited preperson, push the changes to eHealth and persist the response locally, then close the modal.
     *
     * @param  Preperson  $preperson
     * @return void
     */
    public function saveEdit(Preperson $preperson): void
    {
        if (Auth::user()->cannot('update', $preperson)) {
            Session::flash('error', __('preperson.policy.update'));

            return;
        }

        try {
            $validated = $this->form->validate($this->form->rulesForUpdate());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());

            throw $exception;
        }

        $personData = $validated['person'];

        if (!empty($personData['birthDate'])) {
            $personData['birthDate'] = convertToYmd($personData['birthDate']);
        }

        $payload = removeEmptyKeys(Arr::toSnakeCase($personData));

        try {
            $response = EHealth::preperson()->update($preperson->uuid, $payload);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when updating a preperson');

            return;
        }

        try {
            $preperson->update($response->validate());
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to update preperson');

            return;
        }

        Session::flash('success', __('preperson.messages.updated'));
    }

    /**
     * Validate the death date, register it in eHealth (which deactivates the record) and persist the response locally.
     *
     * @param  Preperson  $preperson
     * @return void
     */
    public function registerDeath(Preperson $preperson): void
    {
        if (Auth::user()->cannot('update', $preperson)) {
            Session::flash('error', __('preperson.policy.update'));

            return;
        }

        $validated = $this->form->validate($this->form->rulesForDeath());

        $payload = ['death_date' => convertToYmd($validated['deathDate'])];

        try {
            $response = EHealth::preperson()->update($preperson->uuid, $payload);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when registering a preperson death');

            return;
        }

        try {
            $preperson->update($response->validate());
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to register preperson death');

            return;
        }

        $this->form->reset('deathDate');
        Session::flash('success', __('preperson.messages.death_registered'));
    }

    /**
     * Fetch the latest preperson data from eHealth by its identifier and refresh the local record.
     *
     * @param  Preperson  $preperson
     * @return void
     */
    public function syncFromEHealth(Preperson $preperson): void
    {
        if (Auth::user()->cannot('view', $preperson)) {
            Session::flash('error', __('preperson.policy.view'));

            return;
        }

        try {
            $response = EHealth::preperson()->getById($preperson->uuid);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when fetching a preperson from eHealth');

            return;
        }

        try {
            $preperson->update($response->validate());
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to sync preperson from eHealth');

            return;
        }

        Session::flash('success', __('preperson.messages.synced'));
    }

    /**
     * Fetch the latest merge requests for this preperson from eHealth and refresh the local records.
     *
     * @param  Preperson  $preperson
     * @return void
     */
    public function syncMergeRequests(Preperson $preperson): void
    {
        if (Auth::user()->cannot('create', MergeRequest::class)) {
            Session::flash('error', __('preperson.policy.merge'));

            return;
        }

        try {
            $response = EHealth::mergeRequest()->getMergeRequests(['merge_person_id' => $preperson->uuid]);
            $mergeRequests = $response->map($response->validate(), $preperson->id);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when fetching merge requests');

            return;
        }

        try {
            if (!empty($mergeRequests)) {
                MergeRequest::upsert($mergeRequests, ['uuid'], new MergeRequest()->getFillable());
            }
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to sync merge requests');

            return;
        }

        Session::flash('success', __('preperson.messages.merge_requests_synced'));
    }

    /**
     * Render the preperson data screen.
     *
     * @return View
     */
    public function render(): View
    {
        return view('livewire.preperson.preperson-data')
            ->with([
                'preperson' => Preperson::with([
                    'insertedByUser.party',
                    'updatedByUser.party',
                    'mergeRequests.masterPerson.names'
                ])->findOrFail($this->prepersonId)
            ]);
    }
}
