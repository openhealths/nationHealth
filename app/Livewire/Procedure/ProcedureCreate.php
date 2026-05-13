<?php

declare(strict_types=1);

namespace App\Livewire\Procedure;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Core\Arr;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\HandlesReasonReferences;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcedureCreate extends ProcedureComponent
{
    use HandlesReasonReferences;

    /**
     * Validate and save data.
     *
     * @param  array  $data
     * @return void
     */
    public function save(array $data): void
    {
        if (Auth::user()?->cannot('create', Procedure::class)) {
            Session::flash('error', 'У вас немає дозволу на створення процедури.');

            return;
        }

        $this->form->procedures = $data;

        try {
            $validated = $this->form->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $formattedData = Repository::procedure()->formatEHealthRequest($validated['procedures']);

        try {
            $this->storeValidatedData($formattedData);
        } catch (Throwable $exception) {
            Session::flash('error', __('messages.database_error'));
            $this->logDatabaseErrors($exception, 'Error saving procedure');

            return;
        }

        Session::flash('success', 'Чернетку на створення процедури успішно збережено.');
        $this->redirectRoute('persons.index', [legalEntity()], navigate: true);
    }

    /**
     * Submit encrypted data.
     *
     * @param  array  $data
     * @return void
     */
    public function sign(array $data): void
    {
        if (Auth::user()?->cannot('create', Procedure::class)) {
            Session::flash('error', 'У вас немає дозволу на створення процедури.');

            return;
        }

        $this->form->procedures = $data;

        try {
            $validated = $this->form->validate();
            $validatedCipher = $this->form->validate($this->form->rulesForSigning());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        $formattedData = Repository::procedure()->formatEHealthRequest($validated['procedures']);

        try {
            $this->storeValidatedData($formattedData);
        } catch (Throwable $exception) {
            Session::flash('error', __('messages.database_error'));
            $this->logDatabaseErrors($exception, 'Error saving procedure');

            return;
        }

        $signedContent = signatureService()->signData(
            Arr::toSnakeCase($formattedData),
            $validatedCipher['password'],
            $validatedCipher['knedp'],
            $validatedCipher['keyContainerUpload'],
            Auth::user()->party->taxId
        );

        try {
            EHealth::procedure()->create($this->patientUuid, ['signed_data' => $signedContent]);

            Session::flash('success', 'Заявку на створення процедури успішно відправлено.');
            $this->redirectRoute('persons.index', [legalEntity()], navigate: true);
        } catch (ConnectionException $exception) {
            $this->logConnectionError($exception, 'Error connecting when creating a procedure');
            Session::flash('error', "Виникла помилка. Відсутній зв'язок із ЕСОЗ.");

            return;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            $this->logEHealthException($exception, 'Error when creating a procedure');

            if ($exception instanceof EHealthValidationException) {
                Session::flash('error', $exception->getFormattedMessage());
            } else {
                Session::flash('error', 'Помилка від ЕСОЗ: ' . $exception->getMessage());
            }

            return;
        }
    }

    /**
     * Store validated formatted data into DB.
     *
     * @param  array  $formattedData
     * @return void
     * @throws Throwable
     */
    protected function storeValidatedData(array $formattedData): void
    {
        DB::transaction(function () use ($formattedData) {
            Repository::procedure()->store([$formattedData], $this->personId);

            // Save the selected condition and observation locally if they don't exist in our database.
            $this->processReasonReferences($formattedData);
        });
    }
}
