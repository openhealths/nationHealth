<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Dto\CarePlanRequest\Model as CarePlanRequestData;
use App\Enums\CarePlanStatus;
use App\Enums\Person\EncounterStatus;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Exceptions\MedicalEvents\ClinicalStorageException;
use App\Exceptions\Signature\SignatureException;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Models\CarePlan;
use App\Models\CarePlanRequest;
use App\Models\LegalEntity;
use App\Models\Person\Person;
use App\Repositories\Repository;
use App\Services\MedicalEvents\Fhir;
use App\Traits\LogsExceptions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use RuntimeException;
use Throwable;

/**
 * Create a local care plan request from the editor and keep direct signing available.
 */
class CarePlanCreate extends CarePlanFormComponent
{
    use LogsExceptions;

    /** Stable local request identifier so repeated submissions of this form create one draft. */
    #[Locked]
    public string $carePlanRequestUuid;

    /**
     * Initialize the editor in its current patient and encounter context.
     */
    public function mount(LegalEntity $legalEntity, $personId = null, $encounter = null, ?Person $person = null): void
    {
        parent::mount($legalEntity, $personId, $encounter, $person);

        $this->carePlanRequestUuid = Str::uuid()->toString();
    }

    /**
     * Validate the form and its encounter access, then persist a local request.
     */
    public function save(): void
    {
        $user = Auth::user();
        $legalEntity = legalEntity();
        if ($user === null || $legalEntity === null || $user->cannot('create', CarePlan::class)) {
            Session::flash('error', __('care-plan.no_permission_create'));

            return;
        }

        $repository = Repository::carePlan();
        $personId = (int) $this->personId;

        try {
            $this->synchronizeSystemFields();
            $this->form->validate($this->form->rulesForLocalRequest());

            $person = $repository->findPatient($personId);
            $encounter = $repository->findEncounter($this->form->encounter, $personId);
            if ($person === null || $encounter === null || $encounter->status !== EncounterStatus::FINISHED
                || !$encounter->belongsToCurrentLegalEntity() || $user->cannot('view', [$encounter, $person])) {
                throw ValidationException::withMessages(['form.encounter' => __('care-plan.document_context_unavailable')]);
            }

            $repository->assertCategoryForEncounter($this->form->category, $this->form->encounter, $personId);
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception);

            return;
        }

        try {
            $mapper = objectMapper();
            $data = $mapper->map($this->form, CarePlanRequestData::class);
            $data->authorUuid = $user->getCarePlanWriterEmployee($data->termsOfService)?->uuid;
            $data->conditionUuid = $this->conditionUuid ?: null;
            // Use the locally stored diagnoses so saving a draft has no eHealth side effects.
            $data->addresses = $repository->encounterData($data->encounter, $personId, $data->conditionUuid)['addresses'];

            $request = new CarePlanRequest();
            $request->uuid = $this->carePlanRequestUuid;
            $request->userId = $user->id;
            $request->legalEntityId = $legalEntity->id;
            $request->personId = $personId;
            $mapper->map($data, $request);

            Repository::carePlanRequest()->create($request);
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Failed to store care plan request');

            return;
        }

        Session::flash('success', __('care-plan.draft_saved'));
        $this->redirectRoute('persons.care-plans', [$legalEntity, $personId], navigate: true);
    }

    /**
     * Sign and submit a new care plan through the existing eHealth workflow.
     */
    public function sign(): void
    {
        if (Auth::user()?->cannot('create', CarePlan::class) !== false) {
            Session::flash('error', __('care-plan.no_permission_create'));

            return;
        }

        $repository = Repository::carePlan();
        $generatedUuid = null;
        try {
            $this->synchronizeSystemFields();
            $this->form->validate($this->form->rulesForSigning());
            $this->validateCategoryForSigning();
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception, closeModal: true);

            return;
        }

        try {
            $legalEntity = legalEntity();
            $encounterData = $this->resolveEncounterData();
            if (empty($encounterData['addresses'])) {
                throw new RuntimeException(__('Неможливо створити план лікування: у вибраній взаємодії відсутні діагнози (addresses). Будь ласка, переконайтеся, що взаємодія містить діагнози в ЕСОЗ та вони завантажені в локальну БД.'));
            }

            $termsOfService = $this->form->termsOfService;
            $author = Auth::user()?->getCarePlanWriterEmployee($termsOfService);
            $this->logCarePlanAuthorRoleDebug($author, $termsOfService);

            if ($author && !$this->authorHasActiveRoleForTermsOfService($author, $termsOfService)) {
                // Not a hard block: getCarePlanWriterEmployee() already tried its best to find a
                // matching employee and fell back to this one. We still submit so eHealth remains
                // the single source of truth for the "Employee does not have active role..." rule,
                // but we log loudly here so the real cause is obvious without digging through the
                // raw eHealth request/response.
                Log::warning('[CarePlan] submitting with author lacking a matching active role for terms_of_service', [
                    'author_uuid' => $author->uuid,
                    'author_position' => $author->position,
                    'terms_of_service' => $termsOfService,
                ]);
            }

            $repository->assertFhirStorageReady(beforeSubmission: true);
            $mapping = $repository->formContext(array_replace($this->form->toArray(), [
                'uuid' => $this->carePlanUuid,
                'author' => $author?->uuid,
                'addresses' => $encounterData['addresses'],
            ]));
            $carePlanResource = Fhir::carePlan()->toFhir($mapping['data'], $mapping['context']);
            $carePlanPayload = CarePlanForm::buildCreateContent($carePlanResource);
            $generatedUuid = $carePlanPayload['id'];

            $signedContent = signatureService()->signData(
                $carePlanPayload,
                $this->form->knedp,
                $this->form->keyContainerUpload,
                $this->form->password,
                Auth::user()->party->taxId
            );

            $carePlanResource['status'] = CarePlanStatus::DRAFT->value;
            $carePlan = $repository->storeResource($carePlanResource, (int) $this->personId, (int) legalEntity()->id);
            $finalResponse = $this->submitClinicalOperation($carePlan, ['status' => CarePlanStatus::PENDING->value], fn () => EHealth::carePlan()->create($this->uuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']));
            if ($finalResponse === null) {
                $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan->id], navigate: true);

                return;
            }

            $this->showSignatureModal = false;
            Session::flash('success', __('care-plan.submission_confirmed'));
            $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan->id], navigate: true);

        } catch (EHealthConnectionException $exception) {
            $this->carePlanUuid = $generatedUuid ?? $this->carePlanUuid;
            Log::error('CarePlan: connection error: ' . $exception->getMessage());
            $msg = __('care-plan.connection_error');
            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            $this->carePlanUuid = $generatedUuid ?? $this->carePlanUuid;
            Log::error('CarePlan: eHealth error: ' . $exception->getMessage());
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : __('Помилка від ЕСОЗ: ') . $exception->getMessage();

            if (
                $generatedUuid && (
                    str_contains($exception->getMessage(), 'Care plan with such id already exists')
                || str_contains($msg, 'Care plan with such id already exists')
                || (isset($exception->details['error']['message']) && str_contains($exception->details['error']['message'], 'Care plan with such id already exists'))
                )
            ) {
                try {
                    $this->synchronizeResources([['id' => $generatedUuid]], $this->personId, (int) legalEntity()->id, $this->patientUuid ?: $this->uuid);
                    $localCarePlan = $repository->findByUuid($generatedUuid, (int) legalEntity()->id, $this->personId);

                    session()->flash('success', __('План лікування вже зареєстровано в ЕСОЗ. Ви перенаправлені на сторінку детального перегляду для активації дозволу пацієнта.'));
                    $this->redirectRoute('care-plans.show', [legalEntity(), $localCarePlan->id], navigate: true);

                    return;
                } catch (Throwable $innerEx) {
                    Log::error('CarePlanCreate conflict handling failed: ' . $innerEx->getMessage(), [
                        'trace' => $innerEx->getTraceAsString(),
                    ]);
                }
            }

            Session::flash('error', $msg);
            $this->showSignatureModal = false;

            if (!($exception instanceof EHealthValidationException)) {
                return;
            }

            // eHealth reports the rule on encounter, but the user must change care plan category.
            if ($exception->isCarePlanCategoryDiagnosisMismatch()) {
                $this->addError(
                    'form.category',
                    __('errors.ehealth.messages.care_plan_category_diagnosis_mismatch')
                );
                $this->dispatch('scroll-to-element', selector: '#category');

                return;
            }

            if ($exception->isCarePlanPeriodEndBeforeStart()) {
                $this->addError(
                    'form.periodEnd',
                    __('errors.ehealth.messages.period_end_before_start')
                );
                $this->dispatch('scroll-to-element', selector: '#period_end');
            }
        } catch (ClinicalStorageException $exception) {
            Session::flash('error', $exception->getMessage());
            $this->showSignatureModal = false;
        } catch (SignatureException $exception) {
            $this->carePlanUuid = $generatedUuid ?? $this->carePlanUuid;
            $exception->handle('Error when signing care plan');
            $this->showSignatureModal = false;
        } catch (RuntimeException $exception) {
            $this->carePlanUuid = $generatedUuid ?? $this->carePlanUuid;
            Log::error('CarePlan: runtime error: ' . $exception->getMessage());
            Session::flash('error', $exception->getMessage());
            $this->showSignatureModal = false;
        } catch (Throwable $exception) {
            $this->carePlanUuid = $generatedUuid ?? $this->carePlanUuid;
            Log::error('CarePlan: unexpected error: ' . $exception->getMessage(), [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
            $msg = __('care-plan.unexpected_error');
            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        }
    }
}
