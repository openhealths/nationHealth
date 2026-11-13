<?php

declare(strict_types=1);

namespace App\Livewire\Procedure;

use App\Models\MedicalEvents\Sql\Procedure;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\EnsuresEntityExists;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Throwable;

class ProcedureCreate extends ProcedureComponent
{
    use EnsuresEntityExists;

    /**
     * Validate and save data.
     *
     * @param  array  $procedureData
     * @return void
     */
    public function save(array $procedureData): void
    {
        if (Auth::user()->cannot('create', Procedure::class)) {
            Session::flash('error', __('procedures.policy.create'));

            return;
        }

        if (!Auth::user()->getProcedureWriterEmployee()) {
            Session::flash('error', __('procedures.messages.writer_employee_not_found'));

            return;
        }

        parent::save($procedureData);
    }

    /**
     * Submit encrypted data.
     *
     * @param  string  $keyContainer  Key container in base64
     * @param  string  $password
     * @return void
     */
    public function sign(string $keyContainer = '', string $password = ''): void
    {
        if (Auth::user()->cannot('create', Procedure::class)) {
            Session::flash('error', __('procedures.policy.create'));

            return;
        }

        if (!Auth::user()->getProcedureWriterEmployee()) {
            Session::flash('error', __('procedures.messages.writer_employee_not_found'));

            return;
        }

        parent::sign($keyContainer, $password);
    }

    /**
     * Prepared Procedure data in the local database.
     *
     * @param  array  $formattedData
     * @return int
     * @throws Throwable
     */
    protected function persist(array $formattedData): int
    {
        return DB::transaction(function () use ($formattedData) {
            $uuid = data_get($this->form->procedure, 'basedOnIdentifier');

            if (data_get($this->form->procedure, 'referralType') === 'electronic' && filled($uuid)) {
                $this->storeElectronicReferralIfMissing($uuid, Auth::user()->getProcedureWriterEmployee());
            }
            $this->processReasonReferences($formattedData);

            return Repository::procedure()->store([$formattedData], $this->patient());
        });
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
