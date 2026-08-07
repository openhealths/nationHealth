<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan\Forms;

use Livewire\Form;

class PatientSearchForm extends Form
{
    public string $firstName = '';

    public string $lastName = '';

    public string $birthDate = '';

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'firstName' => ['required', 'min:3'],
            'lastName' => ['required', 'min:3'],
            'birthDate' => ['required', 'date_format:' . config('app.date_format')],
        ];
    }
}
