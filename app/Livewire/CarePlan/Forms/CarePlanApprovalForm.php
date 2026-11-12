<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Forms;

use Livewire\Form;

class CarePlanApprovalForm extends Form
{
    public string $employee_uuid = '';

    protected function rules(): array
    {
        return ['employee_uuid' => 'required|uuid'];
    }
}
