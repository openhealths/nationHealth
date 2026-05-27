<?php

declare(strict_types=1);

namespace App\Livewire\License;

use App\Classes\eHealth\EHealth;
use App\Models\LegalEntity;
use App\Models\License;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Class for updating an additional license. Primary license can't be updated, see: https://e-health-ua.atlassian.net/wiki/spaces/ESOZ/pages/17533829974/BP-ESOZ-003-0003+MIS
 */
class LicenseEdit extends LicenseComponent
{
    public function mount(LegalEntity $legalEntity, License $license): void
    {
        $this->uuid = $license->uuid;
        $this->form->fill($license);
    }

    public function update(): void
    {
        if (Auth::user()->cannot('update', License::whereUuid($this->uuid)->first())) {
            Session::flash('error', 'У вас немає дозволу на оновлення ліцензії');

            return;
        }

        try {
            $validated = $this->form->validate();
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $response = EHealth::license()->update($this->uuid, $this->form->formatForApi($validated));

            try {
                $validated = $response->validate();
                License::whereUuid($this->uuid)->update($response->map($validated));

                Session::flash('success', __('licenses.success.updated'));
                $this->redirectRoute('license.index', [legalEntity()], navigate: true);
            } catch (Exception $exception) {
                $this->handleDatabaseErrors($exception, 'Error while updating license');

                return;
            }
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when updating a license');

            return;
        }
    }

    public function render(): View
    {
        return view('livewire.license.license-edit');
    }
}
