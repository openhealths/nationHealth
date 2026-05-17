<?php

declare(strict_types=1);

namespace App\Livewire\Encounter;

use App\Classes\eHealth\EHealth;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Repositories\MedicalEvents\EncounterRepository;
use App\Livewire\Person\Records\BasePatientComponent;
use Illuminate\View\View;

class EncounterShow extends BasePatientComponent
{
    public ?Encounter $encounter = null;
    public $encounterId;
    public ?\App\Models\Person\Person $person = null;

    public function mount(\App\Models\LegalEntity $legalEntity, int $personId): void
    {
        parent::mount($legalEntity, $personId);
        
        $this->person = \App\Models\Person\Person::findOrFail($personId);
        $this->encounterId = request()->route('encounterId');
        $this->encounter = Encounter::where(function($query) {
                if (is_numeric($this->encounterId)) {
                    $query->where('id', $this->encounterId);
                }
                $query->orWhere('uuid', $this->encounterId);
            })
            ->with(['diagnoses.condition', 'period', 'reasons.coding', 'performer'])
            ->firstOrFail();
    }

    public function render(): View
    {
        return view('livewire.encounter.encounter-show');
    }
}
