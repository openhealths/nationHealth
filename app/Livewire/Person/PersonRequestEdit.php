<?php

declare(strict_types=1);

namespace App\Livewire\Person;

use App\Core\Arr;
use App\Models\LegalEntity;
use App\Models\Person\PersonRequest;
use Illuminate\View\View;

/**
 * Used for editing draft
 */
class PersonRequestEdit extends PersonComponent
{
    public function mount(LegalEntity $legalEntity, PersonRequest $personRequest): void
    {
        $this->baseMount();

        $this->personId = $personRequest->id;
        $this->isIncapacitated = PersonRequest::whereId($this->personId)->whereHas('confidantPersons')->exists();

        if ($this->isIncapacitated) {
            $person = $personRequest->confidantPersons->first()->person->toArray();

            $this->selectedConfidantPersonId = $person['uuid'];
            $this->confidantPerson = [$person];
        }

        $this->form->person = Arr::toCamelCase(
            $personRequest->load([
                'names',
                'addresses',
                'documents',
                'phones',
                'authenticationMethods',
                'confidantPersons.documentsRelationship'
            ])->toArray()
        );

        $this->address = $this->form->person['addresses'][0];

        if (empty($this->form->person['phones'])) {
            $this->form->person['phones'] = [['type' => null, 'number' => null]];
        }

        if (empty($this->form->person['authenticationMethods'])) {
            $this->form->person['authenticationMethods'] = [['type' => null]];
        }

        if ($this->form->person['confidantPersons']) {
            // Get the confidant person relationship data (contains documentsRelationship)
            $confidantPersonRelation = $this->form->person['confidantPersons'][0];
            $this->form->person['confidantPerson'] = $confidantPersonRelation;
            unset($this->form->person['confidantPersons']);

            // Get the actual person data and merge it with relationship data
            $personData = $personRequest->confidantPersons->first()->person->load('phones', 'documents')->toArray();

            // Merge person data into newConfidantPerson for the blade template
            $this->newConfidantPerson = array_merge($personData, [
                'personId' => $personData['uuid'],
                'documentsRelationship' => $confidantPersonRelation['documentsRelationship'] ?? []
            ]);

            $this->form->person['confidantPerson']['personId'] = $personData['uuid'];
        } else {
            $this->form->person['confidantPerson']['documentsRelationship'] = [];
            $this->newConfidantPerson = ['documentsRelationship' => []];
        }
    }

    public function render(): View
    {
        return view('livewire.person.person-edit');
    }
}
