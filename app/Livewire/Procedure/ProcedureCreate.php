<?php

declare(strict_types=1);

namespace App\Livewire\Procedure;

use App\Classes\eHealth\EHealth;
use App\Models\MedicalEvents\Sql\Procedure;
use App\Core\Arr;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\EnsuresEntityExists;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use Throwable;

class ProcedureCreate extends ProcedureComponent
{
    use EnsuresEntityExists;

    /**
     * Validate and save data.
     *
     * @param  array  $data
     * @return void
     */
    public function save(array $data): void
    {
        if (Auth::user()->cannot('create', Procedure::class)) {
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
            $this->handleDatabaseErrors($exception, 'Error saving procedure');

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
        if (Auth::user()->cannot('create', Procedure::class)) {
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
            $this->handleDatabaseErrors($exception, 'Error saving procedure');

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
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when creating a procedure');

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
            Repository::procedure()->store([$formattedData], $this->patient());

            $this->processReasonReferences($formattedData);
        });
    }
}
