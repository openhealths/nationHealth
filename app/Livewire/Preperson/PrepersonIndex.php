<?php

declare(strict_types=1);

namespace App\Livewire\Preperson;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Preperson\Forms\PrepersonForm as Form;
use App\Models\Preperson;
use App\Traits\LogsExceptions;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class PrepersonIndex extends Component
{
    use LogsExceptions;
    use WithPagination;

    public Form $form;

    public ?string $searchId = null;

    public ?string $searchName = null;

    public ?string $searchBirthDate = null;

    #[Locked]
    public ?int $certificatePrepersonId = null;

    /**
     * Local database ID of the registered preperson currently open in the edit modal.
     *
     * @var int|null
     */
    #[Locked]
    public ?int $editingId = null;

    /**
     * Whether the list shows the preperson searched by ID in eHealth instead of the local ones.
     *
     * @var bool
     */
    public bool $isSearching = false;

    /**
     * The preperson whose information certificate is currently open, if any.
     *
     * @return Preperson|null
     */
    #[Computed]
    public function certificatePreperson(): ?Preperson
    {
        return $this->certificatePrepersonId !== null
            ? Preperson::find($this->certificatePrepersonId)
            : null;
    }

    /**
     * Select the preperson whose information certificate should be displayed.
     *
     * @param  int  $prepersonId
     * @return void
     */
    public function selectCertificate(int $prepersonId): void
    {
        $this->certificatePrepersonId = $prepersonId;
    }

    /**
     * Load a registered preperson into the form so it can be edited in the modal.
     *
     * @param  Preperson  $preperson
     * @return void
     */
    public function startEdit(Preperson $preperson): void
    {
        if (Auth::user()->cannot('update', $preperson)) {
            Session::flash('error', __('preperson.policy.update'));

            return;
        }

        $this->editingId = $preperson->id;

        $person = Arr::only(
            Arr::toCamelCase($preperson->toArray()),
            ['uuid', 'firstName', 'lastName', 'secondName', 'birthDate', 'gender', 'emergencyContact'],
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

        $this->reset('editingId');
        Session::flash('success', __('preperson.messages.updated'));
    }

    /**
     * Delete a locally stored preperson draft.
     *
     * @param  Preperson  $preperson
     * @return void
     */
    public function deleteDraft(Preperson $preperson): void
    {
        if (Auth::user()->cannot('delete', $preperson)) {
            Session::flash('error', __('preperson.policy.delete'));

            return;
        }

        $preperson->delete();

        Session::flash('success', __('preperson.messages.draft_deleted'));
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
     * Prepersons matching the applied search filters, paginated.
     *
     * @return LengthAwarePaginator
     */
    #[Computed]
    public function prepersons(): LengthAwarePaginator
    {
        return $this->isSearching
            ? $this->searchPrepersonFromEHealth()
            : $this->paginateLocalPrepersons();
    }

    /**
     * Apply the search filters, resetting pagination to the first page.
     * When an ID is given, the preperson is searched in eHealth instead of the local ones.
     *
     * @return void
     */
    public function search(): void
    {
        $this->resetPage();

        $this->searchId = trim($this->searchId ?? '');
        $this->isSearching = false;

        if (empty($this->searchId)) {
            return;
        }

        try {
            $this->validate(['searchId' => ['uuid']]);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());

            throw $exception;
        }

        $this->isSearching = true;
    }

    /**
     * Clear all search filters and reset pagination.
     *
     * @return void
     */
    public function resetFilters(): void
    {
        $this->reset(['searchId', 'searchName', 'searchBirthDate', 'isSearching']);
        $this->resetPage();
    }

    /**
     * Paginate the prepersons of the current legal entity stored locally.
     *
     * @return LengthAwarePaginator
     */
    protected function paginateLocalPrepersons(): LengthAwarePaginator
    {
        $query = Preperson::with('createdByEmployee')->forLegalEntity(legalEntity()->id);

        if (!empty($this->searchName)) {
            $term = '%' . trim($this->searchName) . '%';

            $query->where(static function (Builder $subQuery) use ($term): void {
                $subQuery->whereLike('first_name', $term)
                    ->orWhereLike('last_name', $term)
                    ->orWhereLike('second_name', $term);
            });
        }

        if (!empty($this->searchBirthDate)) {
            $query->whereDate('birth_date', convertToYmd($this->searchBirthDate));
        }

        return $query->latest()->paginate(config('pagination.per_page'));
    }

    /**
     * Fetch the preperson searched by ID from eHealth, regardless of the legal entity it was created in.
     * Its local copy is refreshed with the response, since the row actions work with the local record.
     *
     * @return LengthAwarePaginator
     */
    protected function searchPrepersonFromEHealth(): LengthAwarePaginator
    {
        $prepersons = [];

        try {
            $validated = EHealth::preperson()->getById($this->searchId)->validate();
            $prepersons[] = Preperson::updateOrCreate(['uuid' => $validated['uuid']], $validated);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when getting a preperson by ID');
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store preperson from eHealth');
        }

        return new LengthAwarePaginator(collect($prepersons), count($prepersons), config('pagination.per_page'), 1, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
        ]);
    }

    /**
     * Render the preperson index view.
     *
     * @return View
     */
    public function render(): View
    {
        return view('livewire.preperson.preperson-index', ['prepersons' => $this->prepersons]);
    }
}
