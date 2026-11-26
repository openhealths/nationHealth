<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Dto\CarePlan\Model as CarePlanData;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Models\Preperson;
use App\Repositories\Repository;
use App\Services\MedicalEvents\Fhir;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class CarePlanEdit extends CarePlanComponent
{
    #[Locked]
    public int $carePlanId;

    #[Locked]
    public string $draftRevision;

    #[Locked]
    public int $draftLegalEntityId;

    public function mount(
        LegalEntity $legalEntity,
        ?Person $person = null,
        ?Preperson $preperson = null,
        ?CarePlan $carePlan = null
    ): void {
        abort_if($carePlan === null, 404);

        $this->carePlanId = $carePlan->id;
        $this->draftLegalEntityId = $legalEntity->id;
        $draft = $this->draft;
        $repository = Repository::carePlan();
        $pending = $repository->hasPendingSubmission($draft);
        if ($pending || $draft->requiresFhirImport()) {
            Session::flash($pending ? 'info' : 'warning', __($pending ? 'care-plan.cannot_edit_non_draft' : 'care-plan.draft_backfill_required'));
            $this->redirectRoute('care-plans.show', [legalEntity(), $draft->id], navigate: true);
            $this->skipRender();

            return;
        }

        $this->loadPatientForm($legalEntity, $draft->person);
        $resource = $repository->getResource($draft);
        $this->draftRevision = $repository->draftRevision($resource);
        $fields = Fhir::carePlan()->fromFhir($resource, ['timezone' => config('app.timezone'), 'dateFormat' => config('app.date_format')]);
        objectMapper()->map((object) array_filter($fields, static fn (mixed $value): bool => $value !== null), $this->form);
        $this->form->episodes = $repository->resolveEpisodeLabels($this->form->episodes, $this->personId);
        $this->form->medicalNumber = (string) ($draft->encounterId ?? '');
        $this->synchronizeSystemFields();
        if ($draft->encounter) {
            $this->diagnoses = $this->buildDiagnosesForUi($draft->encounter);
        }
        $this->refreshCategoryOptions();
    }

    protected function persistDraft(): ?int
    {
        $revision = legalEntity()?->id === $this->draftLegalEntityId
            ? Repository::carePlan()->updateDraft(
                $this->carePlanId,
                (int) $this->personId,
                $this->draftLegalEntityId,
                $this->prepareDraftUpdate(...)
            )
            : null;

        if ($revision === null) {
            Session::flash('error', __('care-plan.no_permission_update'));

            return null;
        }

        $this->draftRevision = $revision;
        unset($this->draft);

        return $this->carePlanId;
    }

    protected function prepareDraftUpdate(CarePlan $draft, array $stored, string $revision): ?CarePlanData
    {
        $user = Auth::user();
        if ($user?->can('view', $draft) !== true || $user->cannot('update', $draft)) {
            return null;
        }

        $encounter = $this->prepareDraftEncounter($draft->person);
        $fields = $this->form->validateForUpdate($this->draftRevision, $revision);
        $mapper = objectMapper();
        $data = $this->mapDraftForm($mapper, $user, $draft->person, $encounter, data_get($stored, 'author.identifier.value'));
        $data->prepareDraftUpdate($stored, $fields, $mapper);

        return $data;
    }

    public function delete(): void
    {
        $carePlan = $this->draft;
        if (Auth::user()?->can('update', $carePlan) !== true) {
            Session::flash('error', __('care-plan.no_permission_update'));

            return;
        }

        $encounter = $carePlan->encounter;
        if (!Repository::carePlan()->deleteDraft($carePlan->id)) {
            Session::flash('error', __('Можна видаляти лише чернетки планів лікування.'));

            return;
        }

        Session::flash('success', __('Чернетку плану лікування успішно видалено.'));
        $this->redirectToCarePlanContext($carePlan->personId, $encounter?->id);
    }

    #[Computed]
    public function draft(): CarePlan
    {
        $draft = Repository::carePlan()->findById($this->carePlanId)
            ?? throw (new ModelNotFoundException())->setModel(CarePlan::class, [$this->carePlanId]);
        Gate::authorize('view', $draft);

        return $draft;
    }

    protected function renderCarePlan(): View
    {
        return view('livewire.care-plan.care-plan-create', ['carePlan' => $this->draft]);
    }
}
