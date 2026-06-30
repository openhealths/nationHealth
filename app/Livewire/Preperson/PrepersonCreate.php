<?php

declare(strict_types=1);

namespace App\Livewire\Preperson;

use App\Core\Arr;
use App\Livewire\Preperson\Forms\PrepersonForm;
use App\Models\Preperson;
use App\Traits\FormTrait;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

class PrepersonCreate extends Component
{
    use FormTrait;

    public PrepersonForm $form;

    public int $formKey = 1;

    public array $dictionaryNames = [
        'GENDER',
        'PHONE_TYPE'
    ];

    public function mount(): void
    {
        $this->getDictionary();
    }

    /**
     * Validate and store an unidentified patient (preperson) draft locally.
     *
     * @return void
     */
    public function createLocally(): void
    {
        if (Auth::user()->cannot('create', Preperson::class)) {
            Session::flash('error', __('patients.policy.create'));

            return;
        }

        try {
            $validated = $this->form->validate($this->form->rulesForCreate());
            $this->formKey++;
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());
            $this->formKey++;

            return;
        }

        $personData = $validated['person'];

        if (!empty($personData['birthDate'])) {
            $personData['birthDate'] = convertToYmd($personData['birthDate']);
        }

        try {
            DB::transaction(static function () use ($personData): void {
                $preperson = Preperson::create(Arr::toSnakeCase($personData));
                $preperson->externalId = $preperson->buildExternalId();
                $preperson->save();
            });
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store preperson');

            return;
        }

        Session::flash('success', __('patients.messages.preperson_created'));
        $this->redirectRoute('persons.index', [legalEntity()], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.preperson.preperson-create');
    }
}
