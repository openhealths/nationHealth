<?php

declare(strict_types=1);

namespace App\Livewire\Preperson\Forms;

use App\Core\BaseForm;
use App\Rules\InDictionary;
use App\Rules\NameFields;

class PrepersonForm extends BaseForm
{
    public array $person = [
        'unidentifiedReason' => '',
        'emergencyContact' => [
            'phones' => [['type' => null, 'number' => null]]
        ]
    ];

    /**
     * Validation rules for creating an unidentified patient (preperson).
     *
     * @return array
     */
    public function rulesForCreate(): array
    {
        return [
            'person.firstName' => ['nullable', 'min:3', new NameFields()],
            'person.lastName' => ['nullable', 'min:3', new NameFields()],
            'person.secondName' => ['nullable', 'min:3', new NameFields()],
            'person.birthDate' => ['nullable', 'date_format:' . config('app.date_format')],
            'person.gender' => ['required', 'string', new InDictionary('GENDER')],
            'person.emergencyContact.firstName' => ['nullable', 'min:3', new NameFields()],
            'person.emergencyContact.lastName' => ['nullable', 'min:3', new NameFields()],
            'person.emergencyContact.secondName' => ['nullable', 'min:3', new NameFields()],
            'person.emergencyContact.phones.*.type' => ['nullable', 'string', 'distinct'],
            'person.emergencyContact.phones.*.number' => [
                'nullable',
                'string',
                'regex:/^\+38[0-9]{10}$/',
                'distinct'
            ]
        ];
    }
}
