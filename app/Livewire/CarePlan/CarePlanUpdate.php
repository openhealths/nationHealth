<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Repositories\CarePlanRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;

class CarePlanUpdate extends CarePlanCreate
{
    use WithFileUploads;

    #[\Livewire\Attributes\Locked]
    public CarePlan $carePlan;

    public function hydrate(): void
    {
        $this->authorize('view', $this->carePlan);
        $this->carePlan = app(CarePlanRepository::class)->findById($this->carePlan->id);
    }

    public function mount(LegalEntity $legalEntity, $personId = null, $encounter = null, $carePlan = null): void
    {
        $bound = $carePlan ?? request()->route('carePlan');
        $repository = app(CarePlanRepository::class);
        $this->carePlan = $repository->findById($bound instanceof CarePlan ? $bound->id : (int) $bound);
        abort_if(!$this->carePlan, 404);
        $this->authorize('view', $this->carePlan);
        parent::mount($legalEntity, $this->carePlan->personId);
        $resource = $repository->getResource($this->carePlan->id);
        $fields = \App\Services\MedicalEvents\Fhir::carePlan()->fromFhir($resource, ['timezone' => config('app.timezone'), 'dateFormat' => config('app.date_format')]);
        foreach ($fields as $field => $value) {
            if (property_exists($this->form, $field)) {
                $this->form->{$field} = $value ?? '';
            }
        }
        $this->carePlanUuid = $resource['id'];
        $this->patientUuid = $this->carePlan->person->uuid;
        $this->form->patient = $this->carePlan->person->fullName ?? '';
        $this->form->medical_number = (string) ($this->carePlan->encounterId ?? '');
        $this->refreshAuthorDisplay();
        if ($this->carePlan->encounter) {
            $this->diagnoses = $this->buildDiagnosesForUi($this->carePlan->encounter);
        }
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
            $this->form->validate();
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception);

            return;
        }

        $encounterData = $this->resolveEncounterData();

        // Re-resolve the author for the (possibly changed) terms_of_service, same as on create,
        // so a draft edited to a different "умови надання послуг" keeps a matching author.
        $author = Auth::user()?->getCarePlanWriterEmployee($this->form->termsOfService ?: null);

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
            'description' => $this->form->description ?: null,
            'note' => $this->form->note ?: null,
            'inform_with' => $this->form->informWith ?: null,
        ]);

        session()->flash('success', __('care-plan.draft_updated') ?? 'План лікування успішно збережено');

        $this->redirectRoute('care-plans.edit', [legalEntity(), $this->carePlan->id], navigate: true);
    }

    public function delete(CarePlanRepository $repository): void
    {
        $this->authorize('update', $this->carePlan);
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
            $this->form->validate($this->form->rulesForSigning());
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception, closeModal: true);

            return;
        }

        $encounterData = $this->resolveEncounterData();

        $termsOfService = $this->form->termsOfService;
        $author = Auth::user()?->getCarePlanWriterEmployee($termsOfService);
        $this->logCarePlanAuthorRoleDebug($author, $termsOfService);

        try {
            $mapping = $repository->formContext(array_replace($this->form->toArray(), [
                'uuid' => $this->carePlanUuid,
                'author' => $author?->uuid,
                'addresses' => $encounterData['addresses'],
            ]), $this->carePlan->id);
            $carePlanResource = \App\Services\MedicalEvents\Fhir::carePlan()->toFhir($mapping['data'], $mapping['context']);
            $carePlanPayload = EHealth::carePlan()->buildCreateContent($carePlanResource);

            $signedContent = signatureService()->signData(
                $carePlanPayload,
                $this->form->password,
                $this->form->knedp,
                $this->form->keyContainerUpload,
                Auth::user()->party->taxId
            );

            $finalResponse = app(\App\Classes\eHealth\Api\CarePlan::class)
                ->submitSignedCreate($this->patientUuid, $signedContent);

            if (($finalResponse['status'] ?? null) === 'failed') {
                throw new \App\Exceptions\EHealth\EHealthValidationException($finalResponse);
            }

            // Extract the actual CarePlan data
            $carePlanUuid = $finalResponse['id'] ?? null;
            $carePlanStatus = $finalResponse['status'] ?? 'new';
            $carePlanRequisition = $finalResponse['requisition'] ?? null;

            if (isset($finalResponse['result']) && is_array($finalResponse['result'])) {
                $entity = $finalResponse['result'][0] ?? $finalResponse['result'];
                $carePlanUuid = $entity['id'] ?? $carePlanUuid;
                $carePlanStatus = $entity['status'] ?? 'active';
                $carePlanRequisition = $entity['requisition'] ?? $carePlanRequisition;
            }

            $carePlanResource['status'] = \App\Enums\CarePlanStatus::fromJobResponse($finalResponse, \App\Enums\CarePlanStatus::PENDING)->value;
            $carePlanResource['requisition'] = $carePlanRequisition;
            $repository->storeResource($carePlanResource, $this->carePlan->personId, $this->carePlan->legalEntityId, $this->carePlan->id);

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
        } catch (\Throwable $exception) {
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
