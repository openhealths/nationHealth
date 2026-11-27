<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Enums\CarePlanStatus;
use App\Exceptions\MedicalEvents\ClinicalStorageException;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\Preperson;
use App\Repositories\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class CarePlanEdit extends BaseCarePlanComponent
{
    /**
     * Local care plan being edited.
     *
     * @var CarePlan
     */
    #[Locked]
    public CarePlan $carePlan;

    public function hydrate(): void
    {
        abort_unless(Auth::user()->can('view', $this->carePlan), 404);
        $this->carePlan = Repository::carePlan()->findById($this->carePlan->id);
    }

    /**
     * Bind the care plan and fill the form with it, unless it is no longer a local draft.
     *
     * @param  LegalEntity  $legalEntity
     * @param  CarePlan|int|string|null  $carePlan
     * @param  Preperson|null  $preperson  Not used: the patient is taken from the care plan. Kept to match the parent signature.
     * @return void
     */
    public function mount(LegalEntity $legalEntity, $carePlan = null, ?Preperson $preperson = null): void
    {
        $bound = $carePlan ?? request()->route('carePlan');
        $repository = Repository::carePlan();
        $carePlan = $repository->findById($bound instanceof CarePlan ? $bound->id : (int) $bound);
        abort_if($carePlan === null, 404);
        $this->carePlan = $carePlan;
        $this->authorize('view', $this->carePlan);
        $this->authorize('manage', $this->carePlan);
        if ($repository->hasPendingSubmission($this->carePlan) || CarePlanStatus::fromStored($this->carePlan->status) !== CarePlanStatus::DRAFT) {
            Session::flash('info', __('care-plan.cannot_edit_non_draft'));
            $this->redirectRoute('care-plans.show', [legalEntity(), $this->carePlan->id], navigate: true);
            $this->skipRender();

            return;
        }
        $this->authorize('update', $this->carePlan);
        if ($this->carePlan->requiresFhirImport()) {
            Session::flash('warning', __($this->carePlan->status === CarePlanStatus::DRAFT->value ? 'care-plan.draft_backfill_required' : 'care-plan.full_sync_required'));
            $this->redirectRoute('care-plans.show', [legalEntity(), $this->carePlan->id], navigate: true);
            $this->skipRender();

            return;
        }

        parent::mount($legalEntity, $this->carePlan->person);
        $this->availableEpisodes = $repository->activeEpisodes($this->patient());
        $this->loadPatientAuthMethods($this->uuid);
        $this->loadAvailableEncounters();
        $this->loadDoctorsAndDictionaries();

        // TODO: the form is still filled by the legacy loop over Fhir::carePlan()->fromFhir(), not through a DTO.
        //  It will be changed when editing moves to the Episode and HealthcareService pattern:
        //  the model is mapped to a separate Form DTO, which is then mapped into the form with
        //  $mapper->map($data, $this->form), without $this->form->fill(...->toArray()).
        $resource = $repository->getResource($this->carePlan->id);
        $fields = \App\Services\MedicalEvents\Fhir::carePlan()->fromFhir($resource, ['timezone' => config('app.timezone'), 'dateFormat' => config('app.date_format')]);
        foreach ($fields as $field => $value) {
            if (property_exists($this->form, $field)) {
                $this->form->{$field} = $value ?? '';
            }
        }
        $this->form->uuid = $resource['id'];
        $this->form->episodes = $repository->resolveEpisodeLabels($this->form->episodes, $this->personId);
        $this->carePlanUuid = $resource['id'];
        $this->form->patient = $this->carePlan->person->fullName ?? '';
        $this->form->medical_number = (string) ($this->carePlan->encounterId ?? '');
        $this->synchronizeSystemFields();
        if ($this->carePlan->encounter) {
            $this->diagnoses = $this->buildDiagnosesForUi($this->carePlan->encounter);
        }
        $this->refreshCategoryOptions();
    }

    /**
     * Validate the form and keep the changes of the local draft.
     *
     * @return void
     */
    public function save(): void
    {
        if (!$this->guardPermission()) {
            return;
        }

        try {
            $this->storeLocally($this->prepareForm());
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception);

            return;
        } catch (ClinicalStorageException $exception) {
            Session::flash('error', $exception->getMessage());

            return;
        }

        Session::flash('success', __('care-plan.draft_updated'));
        $this->redirectRoute('care-plans.edit', [legalEntity(), $this->carePlan->id], navigate: true);
    }

    /**
     * Keep the changes the form holds in the local draft.
     *
     * @param  array{personId: int, authorId: int, encounterId: int}  $ids
     * @param  bool  $forSubmission
     * @return CarePlan
     * @throws ClinicalStorageException
     */
    protected function storeLocally(array $ids, bool $forSubmission = false): CarePlan
    {
        $repository = Repository::carePlan();
        $repository->assertFhirStorageReady(beforeSubmission: $forSubmission);

        if ($forSubmission) {
            $repository->assertDraftSubmissionAllowed($this->carePlan);
        }

        $repository->updateById($this->carePlan->id, [
            'author_id' => $ids['authorId'],
            'category' => $this->form->category,
            'co_authors' => $this->form->coAuthors,
            'context' => $this->form->context ?: null,
            'title' => $this->form->title,
            'terms_of_service' => $this->form->termsOfService ?: null,
            'period_start' => $this->form->periodStartIsoDate(),
            'period_start_time' => $this->form->periodStartTime,
            'period_end_time' => $this->form->periodEndTime,
            'period_end' => $this->form->periodEndIsoDate(),
            'encounter_id' => $ids['encounterId'],
            'addresses' => $this->form->addresses,
            'supporting_info' => [
                'episodes' => $this->form->episodes,
                'medical_records' => $this->form->medicalRecords,
            ],
            'description' => $this->form->description ?: null,
            'note' => $this->form->note ?: null,
            'inform_with' => $this->form->informWith ?: null,
        ]);

        return $repository->findById($this->carePlan->id);
    }

    protected function permissionError(): ?string
    {
        return Auth::user()?->can('update', $this->carePlan) ? null : __('care-plan.no_permission_update');
    }

    public function delete(): void
    {
        $repository = Repository::carePlan();

        $this->authorize('update', $this->carePlan);
        if ($repository->deleteDraft($this->carePlan->id)) {
            Session::flash('success', __('Чернетку плану лікування успішно видалено.'));
        } else {
            Session::flash('error', __('Можна видаляти лише чернетки планів лікування.'));
        }

        $encounter = $this->carePlan->encounter;
        if ($encounter) {
            $this->redirectRoute('persons.encounters.package.show', [legalEntity(), $this->personId, $encounter->id], navigate: true);

            return;
        }

        $this->redirectRoute('persons.care-plans', [legalEntity(), $this->personId], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.care-plan.care-plan-create');
    }
}
