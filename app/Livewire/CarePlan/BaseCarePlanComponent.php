<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Dto\CarePlan\Ehealth as CarePlanEhealth;
use App\Dto\CarePlan\Model as CarePlanData;
use App\Enums\CarePlanStatus;
use App\Enums\CarePlanTermsOfService;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Exceptions\MedicalEvents\ClinicalStorageException;
use App\Exceptions\Signature\SignatureException;
use App\Livewire\CarePlan\Concerns\HandlesCarePlanApprovalSms;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Livewire\CarePlan\Forms\PatientSearchForm;
use App\Livewire\Concerns\SubmitsClinicalOperations;
use App\Livewire\Person\Records\BasePatientComponent;
use App\Models\CarePlan;
use App\Models\Employee\Employee;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Repositories\CarePlanRepository;
use App\Repositories\MedicalEvents\Repository;
use App\Repositories\Repository as AppRepository;
use App\Services\MedicalEvents\CarePlanApprovalService;
use App\Traits\InteractsWithApprovals;
use App\Traits\SynchronizesCarePlans;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * Shared flow of the care plan create and edit pages: the form, its dictionaries and encounter data,
 * and signing and sending the care plan to eHealth.
 */
abstract class BaseCarePlanComponent extends BasePatientComponent
{
    use SynchronizesCarePlans;
    use SubmitsClinicalOperations;
    use InteractsWithApprovals;
    use HandlesCarePlanApprovalSms;

    public bool $showSignatureModal = false;

    public bool $showMethodSelectionModal = false;

    public string $patientUuid = '';

    public string $conditionUuid = '';

    public string $medicalRecordType = 'CONDITION';

    public ?string $carePlanUuid = null;

    public CarePlanForm $form;

    public PatientSearchForm $patientSearch;

    public array $categories = [];

    #[Locked]
    public array $allowedCategoryCodes = [];

    public array $diagnoses = [];

    #[Locked]
    public array $authMethods = [];

    public array $patientSearchResults = [];

    public bool $allowsPatientChange = false;

    public bool $showAdditionalSearchParams = false;

    public ?array $dictionaries = [];

    public array $doctors = [];

    /**
     * Dictionaries to load via FormTrait::getDictionary().
     */
    protected array $dictionaryNames = [
        'eHealth/care_plan_categories',
        'eHealth/encounter_classes',
        'PROVIDING_CONDITION',
    ];

    /**
     * Available encounters that have been confirmed by eHealth (for the encounter selector).
     */
    public array $availableEncounters = [];

    public array $availableEpisodes = [];

    /**
     * Error to flash when the user may not save or send the care plan, null when the action is allowed.
     *
     * @return string|null
     */
    abstract protected function permissionError(): ?string;

    /**
     * Keep the validated form in the database as a local care plan.
     *
     * @param  array{personId: int, authorId: int, encounterId: int}  $ids  Local IDs resolved while preparing the form
     * @param  bool  $forSubmission  Whether the care plan is stored to be sent to eHealth right away
     * @return CarePlan
     * @throws ClinicalStorageException
     */
    abstract protected function storeLocally(array $ids, bool $forSubmission = false): CarePlan;

    /**
     * Flash the reason and tell whether the user may save or send the care plan.
     *
     * @return bool
     */
    protected function guardPermission(): bool
    {
        $error = $this->permissionError();

        if ($error !== null) {
            Session::flash('error', $error);
        }

        return $error === null;
    }

    /**
     * Validate the form against the patient, encounter and facility, and fill the locked form fields the DTOs map from.
     *
     * @return array{personId: int, authorId: int, encounterId: int}
     * @throws ValidationException
     */
    protected function prepareForm(): array
    {
        $user = Auth::user();
        $legalEntity = legalEntity();
        $repository = AppRepository::carePlan();

        $person = $repository->findPatient((int) $this->personId);
        if ($person === null || !Str::isUuid((string) $person->uuid)) {
            throw ValidationException::withMessages(['form.patient' => __('validation.required', [
                'attribute' => __('care-plan.patient'),
            ])]);
        }

        $encounter = $repository->draftEncounter($this->form->encounter, $person, $user, $legalEntity);
        if ($encounter === null) {
            throw ValidationException::withMessages(['form.encounter' => __('validation.exists', [
                'attribute' => __('care-plan.encounter'),
            ])]);
        }

        $this->synchronizeSystemFields();
        $medicalRecords = $this->form->medicalRecords === []
            ? []
            : $repository->draftMedicalRecordLabels($person, $encounter, $user);
        $this->form->validateDraft(
            array_column($repository->doctors($legalEntity->id), 'uuid'),
            $repository->draftEpisodeUuids($person, $encounter, $user),
            array_keys($medicalRecords)
        );

        // The end can be earlier than the start on the same day, which the date rules of the form do not see
        $periodEnd = CarePlanData::periodEnd($this->form->periodEnd, $this->form);
        if ($periodEnd !== null && $periodEnd < CarePlanData::periodStart($this->form->periodStart, $this->form)) {
            throw ValidationException::withMessages(['form.periodEnd' => __('care-plan.period_end_before_start')]);
        }
        $repository->assertCategoryForEncounter($this->form->category, $encounter->uuid, $person->id);

        $addresses = $repository->encounterData($encounter->uuid, $person->id, $this->conditionUuid ?: null)['addresses'];
        if ($addresses === []) {
            throw ValidationException::withMessages(['form.category' => __('care-plan.category_diagnosis_unavailable')]);
        }

        $author = $user->getCarePlanWriterEmployee($this->form->termsOfService);
        if ($author === null || !Str::isUuid((string) $author->uuid)) {
            throw ValidationException::withMessages(['form.author' => __('care-plan.referral_api_no_active_employee')]);
        }

        $this->form->authorUuid = $author->uuid;
        $this->form->patientUuid = $person->uuid;
        $this->form->legalEntityUuid = $legalEntity->uuid;
        $this->form->addresses = $addresses;
        $this->form->medicalRecordLabels = $medicalRecords;

        return ['personId' => $person->id, 'authorId' => $author->id, 'encounterId' => $encounter->id];
    }

    /**
     * Keep the care plan locally as the save button does, and only then open the signature modal.
     * The same local record is used by sign(), since the document UUID does not change.
     *
     * @return void
     */
    public function startSigningProcess(): void
    {
        if (!$this->guardPermission()) {
            return;
        }

        try {
            $this->synchronizeSystemFields();
            $this->form->validate();
            $this->validateCategoryForSigning();
            // The care plan is kept locally before the KEP step, the same way as the save button does
            $this->storeLocally($this->prepareForm());
        } catch (ValidationException $exception) {
            $this->handleValidationFailed($exception);

            return;
        } catch (ClinicalStorageException $exception) {
            Session::flash('error', $exception->getMessage());

            return;
        }

        $this->showSignatureModal = true;
    }

    /**
     * Keep the care plan locally, sign its eHealth payload with KEP and send it to eHealth.
     *
     * @param  string  $keyContainer  Key container in base64, passed by the signature modal
     * @param  string  $password
     * @return void
     */
    public function sign(string $keyContainer = '', string $password = ''): void
    {
        $this->form->useSigningSecrets($keyContainer, $password);

        if (!$this->guardPermission()) {
            $this->showSignatureModal = false;

            return;
        }

        try {
            $ids = $this->prepareForm();
            $validated = $this->form->validate($this->form->signingRules());
            $carePlan = $this->storeLocally($ids, forSubmission: true);
            $this->carePlanUuid = $this->form->uuid;

            $signedContent = signatureService()->signData(
                objectMapper()->map($this->form, CarePlanEhealth::class)->toArray(),
                $validated['knedp'],
                $validated['keyContainer'],
                $validated['password'],
                Auth::user()->party->taxId
            );

            $result = $this->submitClinicalOperation(
                $carePlan,
                ['status' => CarePlanStatus::PENDING->value],
                fn () => EHealth::carePlan()->create($this->form->patientUuid, [
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64'
                ])
            );
        } catch (ValidationException $exception) {
            // An error of a signing field keeps the modal open, so the key can be fixed
            $this->handleValidationFailed(
                $exception,
                closeModal: !$exception->validator->errors()->hasAny(['form.knedp', 'form.keyContainer', 'form.password'])
            );

            return;
        } catch (ClinicalStorageException $exception) {
            Session::flash('error', $exception->getMessage());
            $this->showSignatureModal = false;

            return;
        } catch (SignatureException $exception) {
            $exception->handle('Error while signing care plan');
            $this->showSignatureModal = false;

            return;
        } catch (EHealthValidationException|EHealthResponseException|EHealthConnectionException $exception) {
            $exception->handle('Error while sending care plan to eHealth');
            $this->showSignatureModal = false;
            $this->showEHealthFieldError($exception);

            return;
        }

        if ($result !== null) {
            Session::flash('success', __('care-plan.submission_confirmed'));
        }

        $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan->id], navigate: true);
    }

    /**
     * eHealth reports some rules on another resource than the form field the user has to change.
     *
     * @param  EHealthValidationException|EHealthResponseException|EHealthConnectionException  $exception
     * @return void
     */
    protected function showEHealthFieldError(EHealthValidationException|EHealthResponseException|EHealthConnectionException $exception): void
    {
        if (!$exception instanceof EHealthValidationException) {
            return;
        }

        if ($exception->isCarePlanCategoryDiagnosisMismatch()) {
            $this->addError('form.category', __('errors.ehealth.messages.care_plan_category_diagnosis_mismatch'));
            $this->dispatch('scroll-to-element', selector: '#category');
        } elseif ($exception->isCarePlanPeriodEndBeforeStart()) {
            $this->addError('form.periodEnd', __('errors.ehealth.messages.period_end_before_start'));
            $this->dispatch('scroll-to-element', selector: '#period_end');
        }
    }

    /**
     * Re-resolve the care plan writer employee for the currently selected terms_of_service and
     * refresh the read-only "Автор" display field. eHealth ties the author to a specific
     * healthcare_service.providing_condition, so the effective author can change when the user
     * switches "умови надання послуг" (terms_of_service).
     */
    public function updatedFormTermsOfService(): void
    {
        $this->synchronizeSystemFields();
    }

    protected function synchronizeSystemFields(): void
    {
        $this->form->intent = 'order';
        $this->form->termsOfService = app(CarePlanRepository::class)->encounterTermsOfService(
            $this->form->encounter,
            (int) $this->personId
        );
        $this->refreshAuthorDisplay();
    }

    protected function refreshCategoryOptions(): void
    {
        $this->resolveEncounterData();
        $this->allowedCategoryCodes = app(CarePlanRepository::class)->categoryCodesForEncounter($this->form->encounter, (int) $this->personId);
        $this->resetValidation('form.category');
        if (!in_array($this->form->category, $this->allowedCategoryCodes, true)) {
            $this->form->category = count($this->allowedCategoryCodes) === 1 ? $this->allowedCategoryCodes[0] : '';
        }
    }

    protected function validateCategoryForSigning(): void
    {
        app(CarePlanRepository::class)->assertCategoryForEncounter($this->form->category, $this->form->encounter, (int) $this->personId);
    }

    protected function refreshAuthorDisplay(): void
    {
        $employee = Auth::user()?->getCarePlanWriterEmployee($this->form->termsOfService ?: null);
        if ($employee) {
            $party = $employee->party;
            $this->form->author = implode(' ', array_filter([
                $party?->last_name, $party?->first_name, $party?->second_name,
            ]));
        }
    }

    protected function loadDoctorsAndDictionaries(): void
    {
        // Load doctors for co-authors
        $legalEntity = legalEntity();
        if ($legalEntity) {
            $this->doctors = app(CarePlanRepository::class)->doctors($legalEntity->id);
        }

        // Load dictionaries via FormTrait pattern
        try {
            $this->getDictionary();
            $this->categories = $this->dictionaries['eHealth/care_plan_categories'] ?? [];
        } catch (Exception $exception) {
            report($exception);
            Log::warning('CarePlanCreate: failed to load dictionaries: ' . $exception->getMessage());
        }
    }

    /**
     * Load the patient's authentication methods from eHealth.
     *
     * These identify how the patient confirms consent, so they may only come from the
     * registry — a local fallback would fabricate legally significant data.
     */
    protected function loadPatientAuthMethods(string $personUuid): void
    {
        try {
            $this->authMethods = EHealth::person()->getAuthMethods($personUuid)->validate();
        } catch (Exception $e) {
            $this->authMethods = [];

            Log::channel('e_health_errors')->error('CarePlanCreate: failed to load patient auth methods', [
                'person_uuid' => $personUuid,
                'exception' => $e->getMessage(),
            ]);

            Session::flash('error', __('care-plan.auth_methods_unavailable'));
        }
    }

    /**
     * Handle validation failure: dispatch flash + scroll events.
     */
    protected function handleValidationFailed(ValidationException $exception, bool $closeModal = false): void
    {
        $message = $exception->validator->errors()->first() ?: (__('validation.failed'));

        Session::flash('error', $message);
        $this->setErrorBag($exception->validator->getMessageBag());

        $firstKey = (string) array_key_first($exception->validator->errors()->messages());
        $selector = match ($firstKey) {
            'form.category', 'category' => '#category',
            'form.periodEnd', 'periodEnd' => '#period_end',
            'form.periodStart', 'periodStart' => '#period_start',
            default => null,
        };

        if ($selector !== null) {
            $this->dispatch('scroll-to-element', selector: $selector);
        } else {
            $this->dispatch('scroll-to-error');
        }

        if ($closeModal) {
            $this->showSignatureModal = false;
        }
    }

    /**
     * Look up a NEW care_plan approval on the patient-scoped eHealth endpoint.
     *
     * GET /api/approvals is rejected by Cloudflare WAF; list by patient instead.
     *
     * @return array<string, mixed>|null
     */
    protected function findNewCarePlanApproval(string $carePlanUuid): ?array
    {
        $patientUuid = $this->patientUuid ?: $this->uuid;
        if ($patientUuid === '' || $carePlanUuid === '') {
            return null;
        }

        try {
            // GET /api/patients/{id}/approvals rejects `status` (422 additional properties).
            $response = EHealth::approval()->getPatientApprovals($patientUuid, [
                'granted_resource_type' => 'care_plan',
                'granted_resources' => $carePlanUuid,
            ]);
            $approvals = $response->validate();
            $approvalsData = is_array($approvals) ? ($approvals['data'] ?? $approvals) : [];
            if (!is_array($approvalsData) || $approvalsData === []) {
                return null;
            }

            foreach ($approvalsData as $approval) {
                if (!is_array($approval)) {
                    continue;
                }

                foreach ($approval['granted_resources'] ?? [] as $resource) {
                    if (($resource['identifier']['value'] ?? null) === $carePlanUuid) {
                        return $approval;
                    }
                }
            }

            $first = $approvalsData[0] ?? null;

            return is_array($first) ? $first : null;
        } catch (Exception $exception) {
            Log::warning('CarePlanCreate: Failed to fetch approvals: '.$exception->getMessage());

            return null;
        }
    }

    public function openActivationManually(): void
    {
        $carePlan = $this->carePlanUuid
            ? app(CarePlanRepository::class)->findByUuid($this->carePlanUuid, (int) legalEntity()->id, $this->personId)
            : null;

        if ($carePlan === null || !$this->guardApprovalAccess($carePlan)) {
            return;
        }

        if ($this->shouldSkipPatientAuthUi($carePlan)) {
            $this->createApproval('');

            return;
        }

        if (!$this->approvalId && $this->carePlanUuid) {
            $matchedApproval = $this->findNewCarePlanApproval($this->carePlanUuid);
            $this->approvalId = $matchedApproval['id'] ?? null;
            if (is_array($matchedApproval)) {
                $this->currentAuthMethod = $matchedApproval['authentication_method_current'] ?? $this->currentAuthMethod;
            }
        }

        if ($this->approvalId) {
            try {
                $result = app(CarePlanApprovalService::class)->pendingConfirmation($this->patientUuid, $this->approvalId);
                if ($result->isGranted()) {
                    Session::flash('success', __('care-plan.approval_verified'));
                    $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan->id], navigate: true);

                    return;
                }
                $this->currentAuthMethod = $result->authMethod;
                $this->openAuthModal();
            } catch (Throwable $exception) {
                Session::flash('error', __('care-plan.approval_result_unavailable'));
            }
        } else {
            $this->openMethodSelectionModal();
        }
    }

    public function openMethodSelectionModal(): void
    {
        $carePlan = $this->carePlanUuid
            ? app(CarePlanRepository::class)->findByUuid($this->carePlanUuid, (int) legalEntity()->id, $this->personId)
            : null;

        if ($carePlan === null || !$this->guardApprovalAccess($carePlan)) {
            return;
        }

        if ($this->shouldSkipPatientAuthUi($carePlan)) {
            $this->createApproval('');

            return;
        }

        if (!empty($this->form->periodEnd)) {
            Session::flash('error', __('care-plan.period_end_warning'));
        }

        try {
            $this->authMethods = EHealth::person()->getAuthMethods($this->patientUuid)->validate();
            $this->showMethodSelectionModal = true;
        } catch (Exception $e) {
            Log::error('CarePlanCreate: failed to load auth methods: ' . $e->getMessage());
            Session::flash('error', __('Не вдалося завантажити методи аутентифікації'));
        }
    }

    public function selectAuthMethod(string $methodUuid): void
    {
        $carePlan = $this->carePlanForSms();
        if (!$this->guardApprovalAccess($carePlan)) {
            return;
        }
        $this->currentAuthMethod = $this->selectedApprovalAuthMethod($methodUuid);
        $this->showMethodSelectionModal = false;
        $this->createApproval($methodUuid);
    }

    protected function createApproval(string $methodUuid): void
    {
        try {
            // Resolve the employee UUID that will be granted access.
            // Priority: explicitly selected doctor on the form → authenticated user's DOCTOR/SPECIALIST employee.
            // If neither resolves, skip approval creation and instruct the user to create it manually.
            $employeeUuid = (!empty($this->form->doctor) ? $this->form->doctor : null)
                ?? Auth::user()?->getCarePlanWriterEmployee($this->form->termsOfService ?: null)?->uuid;

            if (!$employeeUuid) {
                $carePlan = app(CarePlanRepository::class)->findByUuid($this->carePlanUuid, (int) legalEntity()->id, $this->personId);
                session()->flash('info', __('Не вдалося визначити лікаря для створення дозволу. Перейдіть на вкладку "Дозволи" та створіть дозвіл вручну.'));
                $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan?->id ?? $this->carePlanUuid], navigate: true);

                return;
            }

            $carePlan = app(CarePlanRepository::class)->findByUuid($this->carePlanUuid, (int) legalEntity()->id, $this->personId) ?? abort(404);
            $approvalService = app(CarePlanApprovalService::class);
            $skipsOtp = $this->shouldSkipPatientAuthUi($carePlan);

            $result = $approvalService->create(
                carePlan: $carePlan,
                patientUuid: $this->patientUuid,
                employeeUuid: $employeeUuid,
                accessLevel: 'write',
                authorizeWith: $skipsOtp ? null : ($methodUuid ?: null),
                user: Auth::user(),
                bearerToken: Session::get(config('ehealth.api.oauth.bearer_token')),
            );

            if ($result->isAsync()) {
                $this->approvalId = $result->approvalId;
                $this->pollingLinkId = $result->pollingLinkId;
                $this->isPolling = true;

                return;
            }

            $this->approvalId = $result->approvalId;

            if ($result->requiresConfirmation() && !$skipsOtp) {
                $this->currentAuthMethod = $result->authMethod ?? $this->currentAuthMethod;
                $this->openAuthModal();

                return;
            }

            Session::flash('success', $skipsOtp
                ? __('care-plan.approval_inpatient_granted')
                : __('План лікування успішно активовано.'));
            $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan->id], navigate: true);
        } catch (Exception $e) {
            Log::error('CarePlanCreate: failed to create approval: ' . $e->getMessage());
            $msg = __('Не вдалося створити запит на дозвіл: ') . $e->getMessage();
            Session::flash('error', $msg);
        }
    }

    /**
     * Called by wire:poll.2s while $isPolling === true.
     */
    public function checkApprovalJobStatus(): void
    {
        if (!$this->isPolling || !$this->pollingLinkId) {
            return;
        }

        $status = app(CarePlanApprovalService::class)->resolveAsyncJob($this->pollingLinkId);

        if ($status->isPending()) {
            return;
        }

        $this->isPolling = false;
        $this->pollingLinkId = null;

        if ($status->isFailed()) {
            $msg = $status->errorMessage ?: __('Не вдалося обробити запит на дозвіл. Спробуйте ще раз.');
            Session::flash('error', $msg);

            return;
        }

        if ($status->approvalId) {
            $this->approvalId = $status->approvalId;
        }

        $carePlan = app(CarePlanRepository::class)->findByUuid($this->carePlanUuid, (int) legalEntity()->id, $this->personId);
        $skipsOtp = $this->shouldSkipPatientAuthUi($carePlan);

        if ($status->requiresConfirmation() && !$skipsOtp) {
            $this->currentAuthMethod = $status->authMethod ?? $this->currentAuthMethod;
            $this->openAuthModal();

            return;
        }

        Session::flash('success', $skipsOtp
            ? __('care-plan.approval_inpatient_granted')
            : __('План лікування успішно активовано.'));
        $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan?->id ?? $this->carePlanUuid], navigate: true);
    }

    protected function loadAvailableEncounters(): void
    {
        $this->availableEncounters = $this->personId ? app(CarePlanRepository::class)->encounterChoices($this->personId) : [];
    }

    public function updatedFormEncounter($value): void
    {
        $this->synchronizeSystemFields();
        if ($value) {
            $encounter = app(CarePlanRepository::class)->findEncounter($value, $this->personId);
            if ($encounter) {
                if (empty($this->form->title)) {
                    $date = $encounter->period?->start ? Carbon::parse($encounter->period->start)->format('d.m.Y') : now()->format('d.m.Y');
                    $this->form->title = __('План лікування від ') . $date;
                }

                if ($encounter->episode?->value) {
                    $episode = app(CarePlanRepository::class)->findEpisode($encounter->episode->value);
                    if ($episode) {
                        $this->form->episodes = [
                            [
                                'uuid' => $episode->uuid,
                                'name' => $episode->name,
                                'date' => $episode->period?->start ? Carbon::parse($episode->period->start)->format('d.m.Y') : '',
                            ]
                        ];
                    }
                } else {
                    $this->form->episodes = [];
                }

                $this->diagnoses = $this->buildDiagnosesForUi($encounter);
            }
        } else {
            $this->diagnoses = [];
            $this->form->episodes = [];
        }
        $this->refreshCategoryOptions();
    }

    /**
     * Build the UI list of diagnoses for an encounter. EncounterDiagnose::condition() resolves to
     * the Identifier row (its uuid lives in ->value), not the actual Condition, so we look the
     * Condition up locally and, if missing, fetch and store it from eHealth on the fly.
     *
     * @return array<int, array{date: string, name: string}>
     */
    protected function buildDiagnosesForUi(Encounter $encounter): array
    {
        return $encounter->diagnoses->map(function ($d) {
            $conditionUuid = $d->condition?->value;
            $actualCondition = null;
            if ($conditionUuid) {
                $actualCondition = app(CarePlanRepository::class)->findCondition($conditionUuid, $this->personId);
                if (!$actualCondition) {
                    Log::info('CarePlanCreate: condition not found in local SQL DB, attempting to fetch from eHealth', [
                        'condition_uuid' => $conditionUuid
                    ]);
                    try {
                        $conditionData = EHealth::condition()->getById($this->uuid, $conditionUuid)->validate();
                        Repository::condition()->store([Arr::toCamelCase($conditionData)], app(CarePlanRepository::class)->findPatient($this->personId));
                        $actualCondition = app(CarePlanRepository::class)->findCondition($conditionUuid, $this->personId);
                    } catch (Exception $e) {
                        Log::error('CarePlanCreate: failed to fetch condition from eHealth', [
                            'condition_uuid' => $conditionUuid,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            return [
                'date' => $actualCondition?->asserted_date
                    ? Carbon::parse($actualCondition->asserted_date)->format('d.m.Y')
                    : '-',
                'name' => ($actualCondition?->code?->text ?: null)
                    ?? ($actualCondition?->code?->coding?->first()?->code ?: null)
                    ?? '-',
            ];
        })->toArray();
    }

    /**
     * Verify the SMS code or confirm offline document verification.
     */
    public function verify(): void
    {
        if ($this->isSmsResending) {
            Session::flash('info', __('care-plan.sms_resend_processing'));

            return;
        }
        $plan = $this->carePlanForSms();
        if (!$this->guardApprovalAccess($plan)) {
            return;
        }
        app(\App\Services\MedicalEvents\MedicalRequestOwnership::class)->approvalForCarePlan($plan, $this->approvalId ?? '');
        $this->validate($this->approvalVerificationRules(), $this->approvalVerificationMessages());

        try {
            $response = app(CarePlanApprovalService::class)->verify(
                $this->patientUuid,
                $this->approvalId,
                $this->isOfflineAuthMethod() ? null : (int) $this->verificationCode,
            );

            if ($response->successful()) {
                $this->closeAuthModal();
                Session::flash('success', __('План лікування успішно активовано.'));
                $carePlan = app(CarePlanRepository::class)->findByUuid($this->carePlanUuid, (int) legalEntity()->id, $this->personId);
                $this->redirectRoute('care-plans.show', [legalEntity(), $carePlan?->id ?? $this->carePlanUuid], navigate: true);
            }
        } catch (Exception $e) {
            Log::warning('Care plan approval confirmation failed', ['exceptionType' => $e::class, 'statusCode' => $e->getCode()]);
            $message = $e instanceof EHealthValidationException ? $e->getFormattedMessage()
                : ($e instanceof EHealthResponseException && \App\Classes\eHealth\Api\Approval::errorMessage($e->response) === 'Invalid verification code'
                    ? __('errors.ehealth.messages.invalid_verification_code') : __('care-plan.approval_verify_error'));
            $this->addError('verificationCode', $message);
            Session::flash('error', $message);
        }
    }

    protected function carePlanForSms(): CarePlan
    {
        $plan = app(CarePlanRepository::class)->findByUuid((string) $this->carePlanUuid, (int) legalEntity()->id, $this->personId);
        abort_unless($plan instanceof CarePlan, 404);

        return $plan;
    }

    protected function onSmsApprovalAlreadyGranted(): void
    {
        $this->redirectRoute('care-plans.show', [legalEntity(), $this->carePlanForSms()->id], navigate: true);
    }

    /**
     * Initialize the component.
     */
    protected function initializeComponent(): void
    {
        // Handled by BasePatientComponent for id, uuid, patientFullName
        $this->patientUuid = $this->uuid;
    }

    /**
     * Resolve the person local ID from uuid.
     */
    protected function resolvePersonId(): ?int
    {
        return $this->personId;
    }

    protected function resolveEncounterData(): array
    {
        if (!$this->form->encounter) {
            return ['id' => null, 'addresses' => [], 'period_start' => null];
        }
        $encounter = app(CarePlanRepository::class)->findEncounter($this->form->encounter, $this->personId);
        if ($encounter) {
            $this->buildDiagnosesForUi($encounter);
        }

        return app(CarePlanRepository::class)->encounterData($this->form->encounter, $this->personId, $this->conditionUuid ?: null);
    }

    /**
     * INPATIENT plans at this facility skip the SMS / method-selection step.
     * OUTPATIENT (typical PMD) still requires patient OTP.
     */
    protected function shouldSkipPatientAuthUi(?CarePlan $carePlan): bool
    {
        if ($carePlan && app(CarePlanApprovalService::class)->skipsPatientOtp($carePlan)) {
            return true;
        }

        if (strtoupper((string) $this->form->termsOfService) !== CarePlanTermsOfService::INPATIENT->value) {
            return false;
        }

        $legalEntity = legalEntity();
        if ($legalEntity === null) {
            return false;
        }

        if ($carePlan === null || $carePlan->legalEntityId === null) {
            return true;
        }

        return (int) $carePlan->legalEntityId === (int) $legalEntity->id;
    }

    protected function authorHasActiveRoleForTermsOfService(Employee $author, string $termsOfService): bool
    {
        return app(CarePlanRepository::class)->authorHasActiveRoleForTermsOfService($author, $termsOfService);
    }

    protected function logCarePlanAuthorRoleDebug(?Employee $author, string $termsOfService): void
    {
        Log::debug('Care Plan author role validation', ['employeeUuid' => $author?->uuid, 'termsOfService' => $termsOfService, 'hasMatchingRole' => $author !== null && $this->authorHasActiveRoleForTermsOfService($author, $termsOfService)]);
    }
}
