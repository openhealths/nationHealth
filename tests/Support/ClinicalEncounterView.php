<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Livewire\EncounterPackage\EncounterPackageView;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Models\Preperson;
use Illuminate\View\View;

class ClinicalEncounterView extends EncounterPackageView
{
    public function boot(): void
    {
    }

    public function mount(LegalEntity $legalEntity, Encounter $encounter, ?Person $person = null, ?Preperson $preperson = null): void
    {
        $this->personId = $person->id;
        $this->encounterId = $encounter->id;
        $this->encounterUuid = $encounter->uuid;
    }

    public function render(): View
    {
        return view()->file(base_path('tests/Feature/Referral/Fixtures/completion-view.blade.php'));
    }
}
