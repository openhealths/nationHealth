<?php

declare(strict_types=1);

namespace App\Livewire\Preperson;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Livewire\Preperson\Forms\PrepersonForm as Form;
use App\Models\Preperson;
use App\Traits\FormTrait;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

abstract class PrepersonComponent extends Component
{
    use FormTrait;

    public Form $form;

    /**
     * Whether the modal proposing alternative patient identification by observations is open.
     *
     * @var bool
     */
    public bool $showAlternativeIdentificationModal = false;

    /**
     * Local database ID of the registered preperson, used to build the encounter link.
     *
     * @var int|null
     */
    public ?int $createdPrepersonId = null;

    public array $dictionaryNames = [
        'GENDER',
        'PHONE_TYPE'
    ];

    /**
     * Ensure the current user may create prepersons; flashes an error when they cannot.
     *
     * @return bool
     */
    protected function ensureCanCreate(): bool
    {
        if (Auth::user()->cannot('create', Preperson::class)) {
            Session::flash('error', __('patients.policy.create'));

            return false;
        }

        return true;
    }

    /**
     * Validate the form and return the validated data.
     * On failure flashes the first error and rethrows so Livewire fills the error bag and halts the action.
     *
     * @return array
     */
    protected function validateForm(): array
    {
        try {
            return $this->form->validate($this->form->rulesForCreate());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());

            throw $exception;
        }
    }

    /**
     * Register the preperson in eHealth and persist the validated response locally.
     *
     * @param  Preperson  $preperson
     * @param  array  $personData
     * @return void
     */
    protected function createInEHealth(Preperson $preperson, array $personData): void
    {
        // Built from $personData so reason_context never leaks into the eHealth request.
        $payload = removeEmptyKeys(Arr::toSnakeCase($personData));
        $payload['external_id'] = $preperson->externalId;

        try {
            $response = EHealth::preperson()->create($payload);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when creating a preperson');

            return;
        }

        if ($response->successful()) {
            try {
                $preperson->update($response->validate());
            } catch (Throwable $exception) {
                $this->handleDatabaseErrors($exception, 'Failed to update preperson from eHealth response');

                return;
            }

            // Offer to start an "alternative identification" encounter for the freshly registered preperson
            $this->createdPrepersonId = $preperson->id;
            $this->showAlternativeIdentificationModal = true;
        }
    }
}
