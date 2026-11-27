<?php

declare(strict_types=1);

namespace App\Livewire\Preperson;

use App\Core\Arr;
use App\Enums\Preperson\Status;
use App\Models\LegalEntity;
use App\Models\Preperson;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Throwable;

class PrepersonEdit extends PrepersonComponent
{
    /**
     * Local database ID of the draft being continued, used to update the same record.
     *
     * @var int
     */
    public int $prepersonId;

    /**
     * Load the draft preperson into the form so its registration can be continued.
     *
     * @param  LegalEntity  $legalEntity
     * @param  Preperson  $preperson
     * @return void
     */
    public function mount(LegalEntity $legalEntity, Preperson $preperson): void
    {
        $this->getDictionary();

        $this->prepersonId = $preperson->id;

        $person = Arr::only(
            Arr::toCamelCase($preperson->toArray()),
            ['firstName', 'lastName', 'secondName', 'birthDate', 'gender', 'emergencyContact'],
        );

        // keep the phones row the emergency-contact partial binds to even when no contact was stored
        $person['emergencyContact'] ??= [];
        $person['emergencyContact']['phones'] ??= [['type' => null, 'number' => null]];

        $this->form->person = $person;

        $this->form->reasonContext = array_merge(
            $this->form->reasonContext,
            Arr::toCamelCase($preperson->reasonContext ?? []),
        );
    }

    /**
     * Validate and store the updated preperson draft locally, keeping the DRAFT status.
     *
     * @return void
     */
    public function updateLocally(): void
    {
        $preperson = Preperson::whereId($this->prepersonId)->firstOrFail();

        // A local draft save needs the edit ability (write + DRAFT), not the eHealth-registration prerequisites
        if (Auth::user()->cannot('edit', $preperson)) {
            Session::flash('error', __('preperson.policy.edit'));

            return;
        }

        $validated = $this->validateForm();

        $personData = $validated['person'];
        // note is the eHealth-facing text; reasonContext keeps the raw fields so the draft can be re-edited later
        $personData['note'] = $this->form->buildNote();
        $personData['reasonContext'] = $validated['reasonContext'];
        $personData['status'] = Status::DRAFT->value;

        if (!empty($personData['birthDate'])) {
            $personData['birthDate'] = convertToYmd($personData['birthDate']);
        }

        try {
            $preperson->update(Arr::toSnakeCase($personData));
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to update preperson');

            return;
        }

        Session::flash('success', __('preperson.messages.draft_updated'));
        $this->redirectRoute('prepersons.index', [legalEntity()], navigate: true);
    }

    /**
     * Validate, register the drafted patient in eHealth and persist the response locally.
     *
     * @return void
     */
    public function create(): void
    {
        $preperson = Preperson::whereId($this->prepersonId)->firstOrFail();

        // edit guards write + DRAFT — a draft already registered in eHealth is no longer DRAFT, so this blocks a duplicate registration
        if (Auth::user()->cannot('edit', $preperson)) {
            Session::flash('error', __('preperson.policy.edit'));

            return;
        }

        // create additionally enforces the eHealth-registration prerequisites (active inpatient healthcare service)
        if (!$this->ensureCanCreate()) {
            return;
        }

        $validated = $this->validateForm();

        $personData = $validated['person'];
        $personData['note'] = $this->form->buildNote();

        if (!empty($personData['birthDate'])) {
            $personData['birthDate'] = convertToYmd($personData['birthDate']);
        }

        // Reason_context is stored only locally, never sent to eHealth.
        $record = Arr::toSnakeCase($personData);
        $record['reason_context'] = Arr::toSnakeCase($validated['reasonContext']);

        try {
            $preperson->update($record);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to update preperson');

            return;
        }

        $this->createInEHealth($preperson, $personData);
    }

    public function render(): View
    {
        return view('livewire.preperson.preperson-edit');
    }
}
