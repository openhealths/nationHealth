<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Dto\CarePlan\Form as FormData;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\Repository;
use App\Services\MedicalEvents\Fhir;
use ArrayObject;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class CarePlanEdit extends CarePlanDraftComponent
{
    public function mount(
        LegalEntity $legalEntity,
        ?Person $person = null,
        ?Preperson $preperson = null,
        ?CarePlan $carePlan = null
    ): void {
        $draft = $carePlan ?? request()->route('carePlan');
        abort_unless($draft instanceof CarePlan, 404);

        parent::mount($legalEntity, $draft->person);
        $this->initializeDraftForm($legalEntity, $draft->person);
        $this->draftId = $draft->id;
        $this->draftUuid = $draft->uuid ?? '';
        $repository = Repository::carePlan();
        if ($draft->requiresFhirImport()) {
            Session::flash('warning', __('care-plan.draft_backfill_required'));
            $this->redirectRoute('care-plans.show', [legalEntity(), $draft->id], navigate: true);
            $this->skipRender();

            return;
        }

        $resource = $repository->getResource($draft);
        $this->draftRevision = $repository->draftRevision($resource);
        $fields = Fhir::carePlan()->fromFhir($resource, ['timezone' => config('app.timezone'), 'dateFormat' => config('app.date_format')]);
        $mapper = objectMapper();
        $mapper->map($mapper->map(new ArrayObject($fields), FormData::class), $this->form);
        $this->form->episodes = $repository->resolveEpisodeLabels($this->form->episodes, $this->personId);
        $this->form->medicalNumber = (string) ($draft->encounterId ?? '');
        $this->synchronizeSystemFields();
        if ($draft->encounter) {
            $this->diagnoses = $this->buildDiagnosesForUi($draft->encounter);
        }
        $this->refreshCategoryOptions();
    }

    public function delete(): void
    {
        $carePlan = $this->draftId === null ? null : Repository::carePlan()->findById($this->draftId);
        if ($carePlan === null || Auth::user()?->can('update', $carePlan) !== true) {
            Session::flash('error', __('care-plan.no_permission_update'));

            return;
        }

        $encounter = $carePlan->encounter;
        if (!Repository::carePlan()->deleteDraft($carePlan->id)) {
            Session::flash('error', __('care-plan.cannot_edit_non_draft'));

            return;
        }

        Session::flash('success', __('care-plan.draft_deleted'));
        $this->redirectToCarePlanContext($carePlan->personId, $encounter?->id);
    }

    public function render(): View
    {
        return view('livewire.care-plan.care-plan-create');
    }
}
