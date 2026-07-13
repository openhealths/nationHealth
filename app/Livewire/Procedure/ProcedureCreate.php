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
     * @param  array  $data
     * @return void
     */
    public function save(array $procedureData): void
    {
        if (Auth::user()->cannot('create', Procedure::class)) {
            Session::flash('error', __('patients.policy.create_procedure'));

            return;
        }

        if (!Auth::user()->getProcedureWriterEmployee()) {
            Session::flash('error', __('patients.messages.procedure_writer_employee_not_found'));

            return;
        }

        parent::save($procedureData);
    }

    /**
     * Submit encrypted data.
     *
     * @param  array  $data
     * @return void
     */
    public function sign(): void
    {
        if (Auth::user()->cannot('create', Procedure::class)) {
            Session::flash('error', __('patients.policy.create_procedure'));

            return;
        }

        if (!Auth::user()->getProcedureWriterEmployee()) {
            Session::flash('error', __('patients.messages.procedure_writer_employee_not_found'));

            return;
        }

        parent::sign();
    }

    /**
     * Prepared Procedure data in the local database.
     * 
     * @param  array $formattedData
     * @return int
     * @throws Throwable
     */
    protected function persist(array $formattedData): int
    {
        return DB::transaction(function () use ($formattedData) {
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
