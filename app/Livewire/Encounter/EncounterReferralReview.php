<?php

namespace App\Livewire\Encounter;

use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use Livewire\Component;

class EncounterReferralReview extends Component
{
    public Encounter $encounter;
    public Person $person;
    public string $patientFullName = '';
    public ?int $personId = null;
    public ?int $prepersonId = null;
    public array $referrals = [];

    public function mount(
        \App\Models\LegalEntity $legalEntity,
        int $encounterId,
        ?Person $person = null,
        ?\App\Models\Preperson $preperson = null
    ) {
        $this->encounter = Encounter::findOrFail($encounterId);
        $this->person = Person::findOrFail($this->encounter->person_id);
        $this->patientFullName = $this->person->full_name ?? $this->person->fullName ;

        $this->personId = $person ? $person->id : $this->person->id;
        $this->prepersonId = $preperson ? $preperson->id : null;

        $this->referrals = [];
    }

    public function render()
    {
        return view('livewire.encounter.encounter-referral-review');
    }
}
