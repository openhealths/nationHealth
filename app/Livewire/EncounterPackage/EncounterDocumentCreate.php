<?php

declare(strict_types=1);

namespace App\Livewire\EncounterPackage;

use App\Core\BaseForm;
use App\Enums\Person\EncounterStatus;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

abstract class EncounterDocumentCreate extends BasePatientComponent
{
    use WithFileUploads;

    public BaseForm $form;

    #[Locked]
    public int $legalEntityId;

    #[Locked]
    public int $encounterId;

    #[Locked]
    public string $encounterUuid;

    public bool $showSignatureModal = false;

    public ?string $actionType = null;

    abstract protected function documentPermission(): string;

    public function mount(LegalEntity $legalEntity, ?Person $person = null, ?Preperson $preperson = null, ?Encounter $encounter = null): void
    {
        abort_unless($person !== null && $preperson === null && $encounter !== null, 404);
        $this->legalEntityId = $legalEntity->id;
        $this->personId = $person->id;
        $this->encounterId = $encounter->id;
        $this->encounterUuid = $this->resolveEncounterModelForStandalone()->uuid;

        parent::mount($legalEntity, $person);
    }

    public function hydrate(): void
    {
        $this->resolveEncounterModelForStandalone();
    }

    protected function resolveEncounterModelForStandalone(): ?Encounter
    {
        abort_unless((int) legalEntity()->id === $this->legalEntityId, 404);
        $encounter = Repository::encounter()->findForStandaloneDocument($this->encounterId, $this->patient());
        $this->authorize('view', [$encounter, $this->patient()]);
        $this->authorize($this->documentPermission());
        abort_unless($encounter->status === EncounterStatus::FINISHED, 404);

        return $encounter;
    }

    #[Computed]
    public function encounterUrl(): string
    {
        return route('encounter.view', ['legalEntity' => $this->legalEntityId, 'person' => $this->personId, 'encounter' => $this->encounterId]);
    }

    protected function returnToEncounter(): void
    {
        $this->redirectRoute('encounter.view', ['legalEntity' => $this->legalEntityId, 'person' => $this->personId, 'encounter' => $this->encounterId]);
    }
}
