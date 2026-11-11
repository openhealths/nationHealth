<?php

declare(strict_types=1);

namespace App\Livewire\EncounterPackage;

use App\Core\BaseForm;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\MedicalEvents\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
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

    abstract protected function documentAbility(): string;

    public function mount(LegalEntity $legalEntity, ?Person $person = null, ?Preperson $preperson = null, ?Encounter $encounter = null): void
    {
        abort_unless($encounter !== null && (($person !== null && $preperson === null) || ($person === null && $preperson !== null && $this->documentAbility() === 'createServiceRequest')), 404);
        $this->legalEntityId = $legalEntity->id;
        $this->personId = $person?->id;
        $this->prepersonId = $preperson?->id;
        $this->encounterId = $encounter->id;
        $this->encounterUuid = $encounter->uuid;

        $this->loadPatientData();
        if ($this->resolveEncounterModelForStandalone() === null) {
            $this->returnToEncounter();

            return;
        }
        $this->initializeComponent();
    }

    public function hydrate(): void
    {
        if ($this->resolveEncounterModelForStandalone() === null) {
            $this->returnToEncounter();
        }
    }

    protected function resolveEncounterModelForStandalone(): ?Encounter
    {
        $encounter = Repository::encounter()->findForStandaloneDocument($this->encounterId, $this->patient());
        if (Auth::user()->cannot($this->documentAbility(), [$encounter, $this->patient(), $this->legalEntityId])) {
            Session::flash('error', __('care-plan.document_context_unavailable'));

            return null;
        }

        return $encounter;
    }

    #[Computed]
    public function encounterUrl(): string
    {
        return route($this->prepersonId !== null ? 'prepersons.encounters.package.show' : 'persons.encounters.package.show', ['legalEntity' => $this->legalEntityId, $this->prepersonId !== null ? 'preperson' : 'person' => $this->prepersonId ?? $this->personId, 'encounter' => $this->encounterId]);
    }

    protected function returnToEncounter(): void
    {
        $this->redirect($this->encounterUrl);
    }
}
