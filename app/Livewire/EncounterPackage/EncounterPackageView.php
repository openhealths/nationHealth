<?php

declare(strict_types=1);

namespace App\Livewire\EncounterPackage;

use App\Enums\Person\EncounterStatus;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Models\Preperson;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

class EncounterPackageView extends EncounterPackageEdit
{
    /**
     * Edit page of a draft that is not waiting for eHealth.
     *
     * @var string|null
     */
    #[Locked]
    public ?string $editUrl = null;

    /**
     * Load the encounter package for viewing, offering its edit page while it can still be changed.
     *
     * @param  LegalEntity  $legalEntity
     * @param  Encounter  $encounter
     * @param  Person|null  $person
     * @param  Preperson|null  $preperson
     * @return void
     */
    public function mount(LegalEntity $legalEntity, Encounter $encounter, ?Person $person = null, ?Preperson $preperson = null): void
    {
        parent::mount($legalEntity, $encounter, $person, $preperson);

        // The edit page leaves only a draft that is not waiting for eHealth editable
        $this->editUrl = $this->isReadonly ? null : $this->editPageUrl();

        $this->isReadonly = true;
    }

    /**
     * Offer the additional actions once the submitted encounter was processed and finished.
     *
     * @return void
     */
    #[On('submission-processed')]
    public function showAdditionalActions(): void
    {
        $this->isFinished = Encounter::whereKey($this->encounterId)->value('status') === EncounterStatus::FINISHED;
    }

    /**
     * Offer the edit page once eHealth rejected the submitted package, which stays a draft to fix.
     *
     * @return void
     */
    #[On('submission-rejected')]
    public function offerEditing(): void
    {
        $this->editUrl = $this->editPageUrl();
    }

    /**
     * Get the edit page of the encounter.
     *
     * @return string
     */
    private function editPageUrl(): string
    {
        return route(
            $this->prepersonId !== null ? 'prepersons.encounter.edit' : 'encounter.edit',
            $this->prepersonId !== null
                ? [legalEntity(), 'preperson' => $this->prepersonId, 'encounter' => $this->encounterId]
                : [legalEntity(), 'person' => $this->personId, 'encounter' => $this->encounterId]
        );
    }

    /**
     * Render the encounter package form, which is readonly here.
     *
     * @return View
     */
    public function render(): View
    {
        return view('livewire.encounter-package.encounter-package-view');
    }
}
