<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Enums\CarePlanStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Exceptions\MedicalEvents\ClinicalStorageException;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Repositories\CarePlanRepository;
use App\Repositories\Repository;
use App\Services\MedicalEvents\Fhir;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use Throwable;

class CarePlanUpdate extends CarePlanCreate
{
    use WithFileUploads;

    #[Locked]
    public CarePlan $carePlan;

    public function hydrate(): void
    {
        abort_unless(Auth::user()->can('view', $this->carePlan), 404);
        $this->carePlan = Repository::carePlan()->findById($this->carePlan->id);
    }

    public function mount(LegalEntity $legalEntity, $personId = null, $encounter = null, $carePlan = null): void
    {
        $bound = $carePlan ?? request()->route('carePlan');
        $repository = Repository::carePlan();
        $carePlan = $repository->findById($bound instanceof CarePlan ? $bound->id : (int) $bound);
        abort_if($carePlan === null, 404);
        $this->carePlan = $carePlan;
        abort_if(Auth::user()->cannot('view', $this->carePlan) || Auth::user()->cannot('manage', $this->carePlan), 404);
        if ($repository->hasPendingSubmission($this->carePlan) || CarePlanStatus::fromStored($this->carePlan->status) !== CarePlanStatus::DRAFT) {
            Session::flash('info', __('care-plan.cannot_edit_non_draft'));
            $this->redirectRoute('care-plans.show', [legalEntity(), $this->carePlan->id], navigate: true);
            $this->skipRender();

            return;
        }
        if (Auth::user()->cannot('update', $this->carePlan)) {
            Session::flash('error', __('care-plan.no_permission_update'));

            return;
        }
        if ($this->carePlan->requiresFhirImport()) {
            Session::flash('warning', __($this->carePlan->status === CarePlanStatus::DRAFT->value ? 'care-plan.draft_backfill_required' : 'care-plan.full_sync_required'));
            $this->redirectRoute('care-plans.show', [legalEntity(), $this->carePlan->id], navigate: true);
            $this->skipRender();

            return;
        }

        parent::mount($legalEntity, $this->carePlan->personId);
        $resource = $repository->getResource($this->carePlan->id);
        $fields = Fhir::carePlan()->fromFhir($resource, ['timezone' => config('app.timezone'), 'dateFormat' => config('app.date_format')]);
        foreach ($fields as $field => $value) {
            if (property_exists($this->form, $field)) {
                $this->form->{$field} = $value ?? '';
            }
        }
        $this->form->episodes = $repository->resolveEpisodeLabels($this->form->episodes, $this->personId);
        $this->carePlanUuid = $resource['id'];
        $this->patientUuid = $this->carePlan->person->uuid;
        $this->form->patient = $this->carePlan->person->fullName ?? '';
        $this->form->medical_number = (string) ($this->carePlan->encounterId ?? '');
        $this->synchronizeSystemFields();
        if ($this->carePlan->encounter) {
            $this->diagnoses = $this->buildDiagnosesForUi($this->carePlan->encounter);
        }
        $this->refreshCategoryOptions();
    }

    /**
     * Update existing local draft.
     */
    public function save(CarePlanRepository $repository): void
    {
        if (Auth::user()?->cannot('update', $this->carePlan)) {
            Session::flash('error', __('care-plan.no_permission_update'));

            return;
        }

        try {
            $this->synchronizeSystemFields();
            $this->form->validate();
            $this->validateAuthorAndRelationships();
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception);

            return;
        }

        $encounterData = $this->resolveEncounterData();

        // Re-resolve the author for the (possibly changed) terms_of_service, same as on create,
        // so a draft edited to a different "умови надання послуг" keeps a matching author.
        $author = $repository->creationAuthorForEncounter($this->form->encounter, $this->personId);

        try {
            $repository->assertFhirStorageReady();
            $repository->updateById($this->carePlan->id, [
                'author_id' => $author?->id ?? $this->carePlan->author_id,
                'category' => $this->form->category,
                'co_authors' => $this->form->coAuthors,
                'context' => $this->form->context ?: null,
                'title' => $this->form->title,
                'terms_of_service' => $this->form->termsOfService ?: null,
                'period_start' => $this->form->periodStartIsoDate(),
                'period_start_time' => $this->form->periodStartTime,
                'period_end_time' => $this->form->periodEndTime,
                'period_end' => $this->form->periodEndIsoDate(),
                'encounter_id' => $encounterData['id'],
                'addresses' => $encounterData['addresses'],
                'supporting_info' => [
                    'episodes' => $this->form->episodes,
                    'medical_records' => $this->form->medicalRecords,
                ],
                'based_on' => $this->form->basedOn ?: null,
                'part_of' => $this->form->partOf ?: null,
                'description' => $this->form->description ?: null,
                'note' => $this->form->note ?: null,
                'inform_with' => $this->form->informWith ?: null,
            ]);
        } catch (ClinicalStorageException $exception) {
            Session::flash('error', $exception->getMessage());

            return;
        }

        session()->flash('success', __('care-plan.draft_updated') ?? 'План лікування успішно збережено');

        $this->redirectRoute('care-plans.edit', [legalEntity(), $this->carePlan->id], navigate: true);
    }

    public function delete(CarePlanRepository $repository): void
    {
        if (Auth::user()->cannot('update', $this->carePlan)) {
            Session::flash('error', __('care-plan.no_permission_update'));

            return;
        }
        if (isset($this->carePlan) && $this->carePlan->exists) {
            if ($repository->deleteDraft($this->carePlan->id)) {
                session()->flash('success', __('Чернетку плану лікування успішно видалено.'));
            } else {
                session()->flash('error', __('Можна видаляти лише чернетки планів лікування.'));
            }
        }

        $encounter = $this->carePlan->encounter;
        if ($encounter) {
            $this->redirectRoute('encounter.view', [legalEntity(), $this->personId, $encounter->id], navigate: true);

            return;
        }

        $this->redirectRoute('persons.care-plans', [legalEntity(), $this->personId], navigate: true);
    }

    /**
     * Sign with KEP and send to eHealth (Update current plan).
     */
    public function sign(CarePlanRepository $repository): void
    {
        if (Auth::user()?->cannot('update', $this->carePlan)) {
            Session::flash('error', __('care-plan.no_permission_update'));

            return;
        }

        try {
            $this->synchronizeSystemFields();
            $this->form->validate($this->form->rulesForSigning());
            $this->validateAuthorAndRelationships();
            $this->validateCategoryForSigning();
            $repository->assertDraftSubmissionAllowed($this->carePlan);
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception);

            return;
        }

        $encounterData = $this->resolveEncounterData();

        $termsOfService = $this->form->termsOfService;
        $author = $repository->creationAuthorForEncounter($this->form->encounter, $this->personId);
        $this->logCarePlanAuthorRoleDebug($author, $termsOfService);

        try {
            $repository->assertFhirStorageReady(beforeSubmission: true);
            $mapping = $repository->formContext(array_replace($this->form->toArray(), [
                'uuid' => $this->carePlanUuid,
                'author' => $author?->uuid,
                'addresses' => $encounterData['addresses'],
            ]), $this->carePlan->id);
            $carePlanResource = Fhir::carePlan()->toFhir($mapping['data'], $mapping['context']);
            $carePlanPayload = CarePlanForm::buildCreateContent($carePlanResource);

            $signedContent = signatureService()->signData(
                $carePlanPayload,
                $this->form->password,
                $this->form->knedp,
                $this->form->keyContainerUpload,
                Auth::user()->party->taxId
            );

            $carePlanResource['status'] = CarePlanStatus::DRAFT->value;
            $carePlan = $repository->storeResource($carePlanResource, (int) $this->personId, (int) legalEntity()->id, $this->carePlan->id);
            $finalResponse = $this->submitClinicalOperation($carePlan, ['status' => CarePlanStatus::PENDING->value], fn () => EHealth::carePlan()->create($this->patientUuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']));
            if ($finalResponse === null) {
                $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan->id], navigate: true);

                return;
            }

            session()->flash('success', __('care-plan.signed_and_sent'));

            $this->redirectRoute('care-plans.show', [legalEntity(), $this->carePlan->id], navigate: true);

        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlan: connection error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.connection_error'));
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            if (method_exists($exception, 'report')) {
                $exception->report();
            }
            Log::error('CarePlan: eHealth error: ' . $exception->getMessage());
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : 'Помилка від ЕСОЗ: ' . $exception->getMessage();
            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (ClinicalStorageException $exception) {
            Session::flash('error', $exception->getMessage());
            $this->showSignatureModal = false;
        } catch (Throwable $exception) {
            Log::error('CarePlan: unexpected error: ' . $exception->getMessage(), [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
            Session::flash('error', __('care-plan.unexpected_error'));
            $this->showSignatureModal = false;
        }
    }

    public function render()
    {
        // Reuse the same view as Create
        return view('livewire.care-plan.care-plan-create');
    }
}
