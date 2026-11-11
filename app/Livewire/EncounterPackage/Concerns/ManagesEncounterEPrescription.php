<?php

declare(strict_types=1);

namespace App\Livewire\EncounterPackage\Concerns;

use App\Classes\eHealth\EHealth;
use App\Enums\MedicalProgram\Type as MedicalProgramType;
use App\Enums\Person\EncounterStatus;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Exceptions\MedicalEvents\ClinicalStorageException;
use App\Livewire\MedicationRequest\Forms\PrescriptionRules;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\Person\Person;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\Dictionary\MedicationProgramPicker;
use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use App\Services\MedicalEvents\MedicalRequestOwnership;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Throwable;

trait ManagesEncounterEPrescription
{
    // Uses ResolvesEncounterStandaloneContext via EncounterEdit.

    public bool $showEncounterEPrescriptionDrawer = false;

    /** @var array<string, mixed> */
    public array $encounterEPrescriptionForm = [];

    /** @var list<array{uuid: string, type: string, label: string, value: string}> */
    public array $encounterEPrescriptionAuthMethods = [];

    #[Locked]
    public ?string $encounterEPrescriptionRequestIdToSign = null;

    #[Locked]
    public array $encounterEPrescriptionDraftForm = [];

    #[Locked]
    public ?string $encounterPrescriptionCreatedRequestId = null;

    public string $encounterEPrescriptionWarningMessage = '';

    /** @var list<array{id: string, name: string}> */
    public array $encounterEPrescriptionPrograms = [];

    public string $encounterEPrescriptionPlanSearch = '';

    #[Locked]
    public array $encounterEPrescriptionPlanChoices = [];

    #[Locked]
    public array $encounterEPrescriptionActivityChoices = [];

    #[Locked]
    public ?int $encounterEPrescriptionPlanId = null;

    #[Locked]
    public ?int $encounterEPrescriptionActivityId = null;

    #[Locked]
    public bool $encounterEPrescriptionApprovalRequired = false;

    public string $encounterEPrescriptionSearchQuery = '';

    /** @var list<array<string, mixed>> */
    public array $encounterEPrescriptionSearchResults = [];

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $encounterEPrescriptionSelectedMedication = null;

    #[Computed]
    public function encounterEPrescriptionCategories(): array
    {
        try {
            return dictionary()->basics()->byName('MEDICATION_REQUEST_CATEGORY')->asCodeDescription()->all();
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    /** BA08 reuses CP search and consent; searching alone has no remote or clinical side effects. */
    #[On('care-plan-approvals-changed')]
    public function searchEncounterEPrescriptionCarePlans(): void
    {
        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null || !$this->guardEncounterPrescriptionCreate($encounter)) {
            return;
        }
        try {
            $this->validate(['encounterEPrescriptionPlanSearch' => ['string', 'max:255']]);
            $this->encounterEPrescriptionPlanChoices = Repository::carePlan()->documentPlanChoices(
                $this->personId,
                $encounter->uuid,
                Auth::user(),
                $this->encounterEPrescriptionPlanSearch,
                $encounter->performer?->value
            );
            if ($this->encounterEPrescriptionPlanId !== null) {
                $plan = collect(Repository::carePlan()->documentPlanChoices(
                    $this->personId,
                    $encounter->uuid,
                    Auth::user(),
                    actingEmployeeUuid: $encounter->performer?->value
                ))->firstWhere('id', $this->encounterEPrescriptionPlanId);
                if ($plan !== null && !collect($this->encounterEPrescriptionPlanChoices)->contains('id', $plan['id'])) {
                    $this->encounterEPrescriptionPlanChoices[] = $plan;
                }
                $this->encounterEPrescriptionApprovalRequired = $plan === null || $plan['approvalRequired'];
                $this->encounterEPrescriptionActivityChoices = $this->encounterEPrescriptionApprovalRequired ? [] : Repository::carePlan()->documentActivityChoices(
                    $this->encounterEPrescriptionPlanId,
                    $this->personId,
                    $encounter->uuid,
                    Auth::user(),
                    'medication_request',
                    $encounter->performer?->value
                );
            }
        } catch (ValidationException $exception) {
            $this->encounterEPrescriptionPlanChoices = $this->encounterEPrescriptionActivityChoices = [];
            Session::flash('error', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->encounterEPrescriptionPlanChoices = $this->encounterEPrescriptionActivityChoices = [];
            report($exception);
            Session::flash('error', __('care-plan.related_plan_unavailable'));
        }
    }

    public function selectEncounterEPrescriptionCarePlan(int $planId): void
    {
        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null || !$this->guardEncounterPrescriptionCreate($encounter)) {
            return;
        }
        $this->invalidateEncounterEPrescriptionDraft();
        $this->encounterEPrescriptionPlanId = $this->encounterEPrescriptionActivityId = null;
        $this->encounterEPrescriptionActivityChoices = [];
        $this->encounterEPrescriptionApprovalRequired = false;
        if ($planId === 0) {
            return;
        }
        $this->searchEncounterEPrescriptionCarePlans();
        $choice = collect($this->encounterEPrescriptionPlanChoices)->firstWhere('id', $planId);
        if ($choice === null) {
            Session::flash('error', __('care-plan.related_plan_unavailable'));

            return;
        }
        $this->encounterEPrescriptionPlanId = $planId;
        $this->encounterEPrescriptionApprovalRequired = $choice['approvalRequired'];
        $this->searchEncounterEPrescriptionCarePlans();
    }

    public function selectEncounterEPrescriptionActivity(int $activityId): void
    {
        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null || !$this->guardEncounterPrescriptionCreate($encounter)) {
            return;
        }
        $this->searchEncounterEPrescriptionCarePlans();
        $choice = collect($this->encounterEPrescriptionActivityChoices)->firstWhere('id', $activityId);
        if ($choice === null || $this->encounterEPrescriptionApprovalRequired) {
            Session::flash('error', __('care-plan.related_plan_unavailable'));

            return;
        }
        $activity = Repository::carePlanActivity()->findOwned($this->encounterEPrescriptionPlanId, $activityId);
        $resource = Repository::carePlanActivity()->getResource($activity);
        $this->invalidateEncounterEPrescriptionDraft();
        $this->encounterEPrescriptionActivityId = $activityId;
        $this->encounterEPrescriptionForm['program_id'] = data_get($resource, 'detail.program.identifier.value', '');
        $this->encounterEPrescriptionSelectedMedication = null;
        $this->encounterEPrescriptionSearchResults = [];
        $this->encounterEPrescriptionForm['medication_id'] = '';
    }

    #[Computed]
    public function encounterEPrescriptionSelectedPlan(): ?CarePlan
    {
        return $this->encounterEPrescriptionPlanId === null ? null : Repository::carePlan()->findById($this->encounterEPrescriptionPlanId);
    }

    #[Computed]
    public function encounterEPrescriptionApprovalContext(): array
    {
        if (!$this->encounterEPrescriptionApprovalRequired || $this->encounterEPrescriptionPlanId === null) {
            return [];
        }
        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null || !$this->guardEncounterPrescriptionCreate($encounter)) {
            return [];
        }

        // Consent must target the selected encounter actor, never another available writer.
        return ['actingEmployeeUuid' => $encounter->performer->value];
    }

    private function guardEncounterPrescriptionCreate(Encounter $encounter): bool
    {
        if (Auth::user()->cannot('createMedicationRequest', [$encounter, Person::findOrFail($this->personId), legalEntity()])) {
            Session::flash('error', __('medication-requests.own_finished_encounter_required'));

            return false;
        }

        return true;
    }

    /** A selected Activity must still be offered for this patient, encounter and exact current author at submission. */
    private function encounterEPrescriptionActivityForCreate(Encounter $encounter): ?CarePlanActivity
    {
        if ($this->encounterEPrescriptionPlanId === null) {
            return null;
        }
        $choices = Repository::carePlan()->documentActivityChoices(
            $this->encounterEPrescriptionPlanId,
            $this->personId,
            $encounter->uuid,
            Auth::user(),
            'medication_request',
            $encounter->performer?->value
        );
        if (!collect($choices)->contains('id', $this->encounterEPrescriptionActivityId)) {
            throw ValidationException::withMessages(['activity' => __('care-plan.related_plan_unavailable')]);
        }

        return Repository::carePlanActivity()->findOwned($this->encounterEPrescriptionPlanId, $this->encounterEPrescriptionActivityId);
    }

    public function openEncounterEPrescriptionDrawer(): void
    {
        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null) {
            return;
        }

        $status = $encounter->status instanceof EncounterStatus
            ? $encounter->status
            : EncounterStatus::tryFrom((string) $encounter->status);

        if ($status !== EncounterStatus::FINISHED) {
            Session::flash('error', __('Електронний рецепт без плану лікування можна створити лише після завершення взаємодії.'));

            return;
        }

        if (!$this->guardEncounterPrescriptionCreate($encounter)) {
            return;
        }
        $this->encounterEPrescriptionPlanId = $this->encounterEPrescriptionActivityId = null;
        $this->encounterEPrescriptionPlanChoices = $this->encounterEPrescriptionActivityChoices = [];
        $this->encounterEPrescriptionApprovalRequired = false;
        $this->loadEncounterEPrescriptionAuthMethods($encounter);
        $this->loadEncounterEPrescriptionPrograms();
        $this->invalidateEncounterEPrescriptionDraft();

        $categories = $this->encounterEPrescriptionCategories();
        $this->encounterEPrescriptionForm = [
            'medication_id' => '',
            'program_id' => '',
            'category' => array_key_exists('community', $categories)
                ? 'community'
                : (array_key_first($categories) ?? ''),
            'medication_qty' => '1',
            'medication_unit' => 'од.',
            'signature_text' => '',
            'patient_instruction' => '',
            'route' => 'oral',
            'max_dose_per_administration' => '1',
            'max_dose_per_period' => '1',
            'started_at' => now()->format((string) config('app.date_format')),
            'ended_at' => now()->addDays(29)->format((string) config('app.date_format')),
            'duration' => 30,
            'dose_input_unit' => 'denominator',
            'dose_ingredient_id' => '',
            'dose_warning_confirmed' => false,
            'phone_confirmed' => false,
            'container_dosage' => '',
            'note' => '',
            'inform_with' => $this->encounterEPrescriptionAuthMethods[0]['value'] ?? '',
        ];

        $this->encounterEPrescriptionSearchQuery = '';
        $this->encounterEPrescriptionSearchResults = [];
        $this->encounterEPrescriptionSelectedMedication = null;
        $this->encounterEPrescriptionWarningMessage = '';
        $this->resetEncounterEPrescriptionValidation();
        $this->showEncounterEPrescriptionDrawer = true;
    }

    public function closeEncounterEPrescriptionDrawer(): void
    {
        $this->showEncounterEPrescriptionDrawer = false;
        $this->encounterEPrescriptionWarningMessage = '';
        $this->resetEncounterEPrescriptionValidation();
    }

    public function updatedEncounterEPrescriptionForm(mixed $value, ?string $key): void
    {
        if ($this->encounterEPrescriptionDraftForm !== $this->encounterEPrescriptionForm) {
            $this->invalidateEncounterEPrescriptionDraft();
        }
        if ($key !== null) {
            $this->resetValidation('encounterEPrescriptionForm.'.$key);
        }
        if (in_array($key, ['duration', 'started_at'], true)) {
            $this->calculateEncounterPrescriptionDates();
        }
        if (in_array($key, ['max_dose_per_period', 'max_dose_per_administration', 'dose_input_unit', 'dose_ingredient_id'], true)) {
            $this->encounterEPrescriptionForm['dose_warning_confirmed'] = false;
        }
        if ($key === 'inform_with') {
            $this->encounterEPrescriptionForm['phone_confirmed'] = false;
        }
        $this->encounterEPrescriptionWarningMessage = '';
    }

    private function invalidateEncounterEPrescriptionDraft(): void
    {
        $this->encounterEPrescriptionRequestIdToSign = null;
        $this->encounterEPrescriptionDraftForm = [];
        if ($this->actionType === 'sign_eprescription') {
            $this->showSignatureModal = false;
            $this->actionType = null;
        }
    }

    private function resetEncounterEPrescriptionValidation(): void
    {
        foreach ($this->getErrorBag()->keys() as $key) {
            if (str_starts_with($key, 'encounterEPrescriptionForm.')) {
                $this->resetValidation($key);
            }
        }
    }

    public function searchEncounterEPrescriptionMedications(): void
    {
        $selectedProgramId = MedicationProgramPicker::resolve($this->encounterEPrescriptionForm['program_id'] ?? null);

        $query = trim($this->encounterEPrescriptionSearchQuery);
        if (mb_strlen($query) < 3) {
            $this->encounterEPrescriptionSearchResults = [];
            $this->encounterEPrescriptionWarningMessage = __('Введіть щонайменше 3 символи для пошуку лікарського засобу.');

            return;
        }

        $filters = [
            'innm_name' => $query,
            'page' => 1,
            'page_size' => 20,
        ];

        $filters['medical_program_id'] = $selectedProgramId;

        try {
            $response = EHealth::drug()->getMany($filters)->validate();
            $this->encounterEPrescriptionSearchResults = collect(is_array($response) ? $response : [])
                ->map(static function (array $drug): array {
                    return array_replace($drug, [
                        'id' => (string) ($drug['id'] ?? ''),
                        'name' => (string) ($drug['name'] ?? ''),
                        'innm_name' => collect($drug['ingredients'] ?? [])->pluck('name')->filter()->implode(', ') ?: (string) ($drug['innm_name'] ?? ''),
                        'innm_dosage_form' => (string) ($drug['form'] ?? ($drug['innm_dosage_form'] ?? '')),
                        'ingredients' => is_array($drug['ingredients'] ?? null) ? $drug['ingredients'] : [],
                        'packages' => is_array($drug['packages'] ?? null) ? $drug['packages'] : [],
                    ]);
                })
                ->filter(static fn (array $drug): bool => $drug['id'] !== '')
                ->values()
                ->all();
            $this->encounterEPrescriptionWarningMessage = '';
        } catch (Throwable $exception) {
            Log::error('EncounterEdit: medication search failed for standalone eRx: '.$exception->getMessage());
            $this->encounterEPrescriptionSearchResults = [];
            $this->encounterEPrescriptionWarningMessage = __('Не вдалося виконати пошук лікарських засобів. Спробуйте ще раз.');
        }
    }

    public function selectEncounterEPrescriptionMedication(string $medicationId): void
    {
        $selectedMedication = collect($this->encounterEPrescriptionSearchResults)
            ->first(static fn (array $drug): bool => (string) ($drug['id'] ?? '') === $medicationId);

        if (!is_array($selectedMedication)) {
            $this->encounterEPrescriptionWarningMessage = __('Не вдалося обрати лікарський засіб. Спробуйте пошукати ще раз.');

            return;
        }

        $this->encounterEPrescriptionForm['medication_id'] = $medicationId;
        $this->invalidateEncounterEPrescriptionDraft();
        $this->resetValidation('encounterEPrescriptionForm.medication_id');
        $this->encounterEPrescriptionSelectedMedication = $selectedMedication;
        try {
            $this->encounterEPrescriptionForm['medication_unit'] = (new MedicationRequestMapper())->medicationUnit($selectedMedication);
        } catch (InvalidArgumentException $exception) {
            $this->encounterEPrescriptionForm['medication_unit'] = '';
            $this->encounterEPrescriptionWarningMessage = $exception->getMessage();

            return;
        }

        $this->encounterEPrescriptionForm['dose_ingredient_id'] = $selectedMedication['ingredients'][0]['id'] ?? '';
        $packageStep = $this->resolveEncounterMedicationPackageStep($selectedMedication);
        if ($packageStep > 0) {
            $this->encounterEPrescriptionForm['medication_qty'] = (string) $packageStep;
            $this->resetValidation('encounterEPrescriptionForm.medication_qty');
        }

        // Hide the result list after pick — selected row styling was unreadable in dark mode
        $this->encounterEPrescriptionSearchQuery = '';
        $this->encounterEPrescriptionSearchResults = [];
        $this->encounterEPrescriptionWarningMessage = '';
    }

    public function updatedEncounterEPrescriptionFormProgramId(): void
    {
        $this->invalidateEncounterEPrescriptionDraft();
        $this->resetValidation('encounterEPrescriptionForm.program_id');
        $this->encounterEPrescriptionWarningMessage = '';
        $this->encounterEPrescriptionForm['medication_id'] = '';
        $this->encounterEPrescriptionSelectedMedication = null;
        $this->encounterEPrescriptionSearchResults = [];
        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter !== null) {
            $this->validateEncounterEPrescriptionProgram($encounter);
        }
    }

    public function validateEncounterEPrescription(): void
    {
        if ($this->encounterEPrescriptionRequestIdToSign !== null && $this->encounterEPrescriptionDraftForm === $this->encounterEPrescriptionForm) {
            $this->prepareEncounterEPrescriptionSigning();

            return;
        }

        $this->invalidateEncounterEPrescriptionDraft();

        $this->encounterEPrescriptionWarningMessage = '';

        try {
            $this->validate([
                'encounterEPrescriptionForm.medication_id' => 'required|string|uuid',
                'encounterEPrescriptionForm.program_id' => 'nullable|string|uuid',
                'encounterEPrescriptionForm.category' => ['required', 'string', Rule::in(array_keys($this->encounterEPrescriptionCategories()))],
                'encounterEPrescriptionForm.medication_qty' => 'required|numeric|min:0.01',
                'encounterEPrescriptionForm.signature_text' => 'required|string|min:1',
                'encounterEPrescriptionForm.max_dose_per_administration' => 'required|numeric|min:0.01',
                'encounterEPrescriptionForm.max_dose_per_period' => 'required|numeric|min:0.01',
                'encounterEPrescriptionForm.started_at' => 'required|date',
                'encounterEPrescriptionForm.ended_at' => 'required|date|after_or_equal:encounterEPrescriptionForm.started_at',
                'encounterEPrescriptionForm.inform_with' => 'nullable|string',
                'encounterEPrescriptionForm.duration' => 'required|integer|min:1',
            ], [
                'encounterEPrescriptionForm.ended_at.after_or_equal' => __('errors.ehealth.messages.period_end_before_start'),
            ], [
                'encounterEPrescriptionForm.medication_id' => __('ідентифікатор ЛЗ'),
                'encounterEPrescriptionForm.program_id' => __('медична програма'),
                'encounterEPrescriptionForm.category' => __('категорія'),
                'encounterEPrescriptionForm.medication_qty' => __('кількість'),
                'encounterEPrescriptionForm.signature_text' => __('сигнатура'),
                'encounterEPrescriptionForm.inform_with' => __('метод автентифікації'),
                'encounterEPrescriptionForm.started_at' => __('encounters.eprescription.course_start'),
                'encounterEPrescriptionForm.ended_at' => __('encounters.eprescription.course_end'),
                'encounterEPrescriptionForm.max_dose_per_administration' => __('encounters.eprescription.max_dose_per_administration'),
                'encounterEPrescriptionForm.max_dose_per_period' => __('encounters.eprescription.max_dose_per_period'),
            ]);
        } catch (ValidationException $exception) {
            $this->encounterEPrescriptionWarningMessage = $exception->validator->errors()->first();
            $this->setErrorBag($exception->validator->getMessageBag());
            Session::flash('error', $this->encounterEPrescriptionWarningMessage);

            return;
        }

        try {
            $program = collect($this->encounterEPrescriptionPrograms)->firstWhere('id', $this->encounterEPrescriptionForm['program_id'] ?? '') ?? [];
            $this->encounterEPrescriptionForm = PrescriptionRules::normalize(
                $this->encounterEPrescriptionForm,
                $this->encounterEPrescriptionSelectedMedication ?? [],
                $program
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError('encounterEPrescriptionForm.'.$field, $messages[0]);
            }
            $this->encounterEPrescriptionWarningMessage = $exception->validator->errors()->first();
            Session::flash('error', $this->encounterEPrescriptionWarningMessage);

            return;
        } catch (InvalidArgumentException $exception) {
            $this->encounterEPrescriptionWarningMessage = $exception->getMessage();
            Session::flash('error', $this->encounterEPrescriptionWarningMessage);

            return;
        }

        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null) {
            return;
        }

        try {
            if (!$this->validateEncounterEPrescriptionProgram($encounter)) {
                return;
            }

            $employeeContext = app(MedicationRequestLifecycleService::class)->resolveEncounterEmployeeContext(
                $encounter
            );

            $formData = $this->encounterEPrescriptionForm;
            $formData['program_id'] = MedicationProgramPicker::resolve($formData['program_id'] ?? null);

            if (!$this->guardEncounterPrescriptionCreate($encounter)) {
                return;
            }
            $activity = $this->encounterEPrescriptionActivityForCreate($encounter);
            $formData['encounter_id'] = $encounter->id;
            $this->encounterEPrescriptionRequestIdToSign = $activity === null
                ? app(MedicationRequestLifecycleService::class)->createEncounterDraft($encounter, $formData, $employeeContext)
                : app(MedicationRequestLifecycleService::class)->createCarePlanDraft($activity->carePlan, $activity, $formData, $employeeContext);
            $this->encounterEPrescriptionDraftForm = $this->encounterEPrescriptionForm;

            $this->actionType = 'sign_eprescription';
            $this->showSignatureModal = true;
            Session::flash('success', __('Заявку на е-рецепт створено. Підпишіть КЕП.'));
        } catch (EHealthValidationException $exception) {
            $exception->report();
            $this->encounterEPrescriptionWarningMessage = $exception->getTranslatedMessage();
            Session::flash('error', $this->encounterEPrescriptionWarningMessage);
        } catch (ClinicalStorageException $exception) {
            $this->encounterEPrescriptionWarningMessage = $exception->getMessage();
            Session::flash('error', $this->encounterEPrescriptionWarningMessage);
        } catch (QueryException $exception) {
            $storageException = ClinicalStorageException::fromQueryException($exception, 'createEncounterEPrescription');
            $this->encounterEPrescriptionWarningMessage = $storageException->getMessage();
            Session::flash('error', $this->encounterEPrescriptionWarningMessage);
        } catch (Throwable $exception) {
            Log::error('EncounterEdit: failed to create encounter eRx: '.$exception->getMessage());
            $this->encounterEPrescriptionWarningMessage = __('Не вдалося створити заявку на рецепт: ').$exception->getMessage();
            Session::flash('error', $this->encounterEPrescriptionWarningMessage);
        }
    }

    public function prepareEncounterEPrescriptionSigning(): void
    {
        if ($this->encounterEPrescriptionPlanId !== null) {
            $encounter = $this->resolveEncounterModelForStandalone();
            if ($encounter === null || !$this->guardEncounterPrescriptionCreate($encounter)) {
                return;
            }
            try {
                $this->encounterEPrescriptionActivityForCreate($encounter);
            } catch (ValidationException $exception) {
                Session::flash('error', $exception->getMessage());

                return;
            }
        }
        if ($this->encounterEPrescriptionDraftForm !== $this->encounterEPrescriptionForm) {
            $this->invalidateEncounterEPrescriptionDraft();
            Session::flash('warning', __('medication-requests.draft_form_changed'));

            return;
        }

        $this->encounterEPrescriptionWarningMessage = '';
        $this->showSignatureModal = false;
        $this->actionType = null;
        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null || $this->encounterEPrescriptionRequestIdToSign === null) {
            return;
        }

        try {
            $requestRecord = app(MedicalRequestOwnership::class)->medicationForEncounter(
                $this->encounterEPrescriptionRequestIdToSign,
                $encounter
            );
            app(MedicationRequestLifecycleService::class)->buildSignPayload($encounter, $requestRecord, '');
            $this->actionType = 'sign_eprescription';
            $this->showSignatureModal = true;
            Session::flash('success', __('Заявку на е-рецепт створено. Підпишіть КЕП.'));
        } catch (Throwable $exception) {
            Log::warning('Encounter eRx signing preflight failed', ['exceptionType' => $exception::class]);
            $this->encounterEPrescriptionWarningMessage = $exception instanceof EHealthValidationException
                ? $exception->getTranslatedMessage()
                : ($exception instanceof ModelNotFoundException
                    ? __('care-plan.document_context_unavailable')
                    : $exception->getMessage());
            Session::flash('error', $this->encounterEPrescriptionWarningMessage);
        }
    }

    public function signEncounterEPrescription(): void
    {
        if ($this->encounterEPrescriptionDraftForm !== $this->encounterEPrescriptionForm) {
            $this->invalidateEncounterEPrescriptionDraft();
            Session::flash('warning', __('medication-requests.draft_form_changed'));

            return;
        }

        if (empty($this->encounterEPrescriptionRequestIdToSign)) {
            Session::flash('error', __('Не вибрано рецепт для підписання'));
            $this->showSignatureModal = false;
            $this->actionType = null;

            return;
        }

        $encounter = $this->resolveEncounterModelForStandalone();
        if ($encounter === null) {
            $this->showSignatureModal = false;
            $this->actionType = null;

            return;
        }

        if (!$this->guardEncounterPrescriptionCreate($encounter)) {
            return;
        }

        try {
            if ($this->encounterEPrescriptionPlanId !== null) {
                $this->encounterEPrescriptionActivityForCreate($encounter);
            }
            $requestRecord = app(MedicalRequestOwnership::class)
                ->medicationForEncounter(
                    (string) $this->encounterEPrescriptionRequestIdToSign,
                    $encounter
                );

            $validated = $this->form->validate($this->form->signingRules());

            $informWith = (string) ($this->encounterEPrescriptionForm['inform_with'] ?? ($requestRecord->informWith ?? ''));
            if ($informWith !== '' && !str_contains($informWith, '|')) {
                $matchedAuth = collect($this->encounterEPrescriptionAuthMethods)
                    ->first(static fn (array $method): bool => (string) ($method['uuid'] ?? '') === $informWith);
                if (is_array($matchedAuth)) {
                    $informWith = (string) ($matchedAuth['value'] ?? $informWith);
                }
            }

            $result = app(MedicationRequestLifecycleService::class)->signPrescription(
                $encounter,
                $requestRecord,
                [
                    'password' => $validated['password'],
                    'knedp' => $validated['knedp'],
                    'keyContainerUpload' => $validated['keyContainerUpload'],
                    'medication_unit' => $this->encounterEPrescriptionForm['medication_unit'] ?? 'од.',
                    'request_notification_disabled' => $this->encounterPrescriptionNotificationsDisabled,
                    'signer_tax_id' => Auth::user()?->party?->taxId,
                ],
                $informWith,
                0.0
            );

            $this->encounterPrescriptionCreatedRequestId = $requestRecord->uuid;
            $this->showSignatureModal = false;
            $this->actionType = null;
            $this->encounterEPrescriptionRequestIdToSign = null;
            $this->encounterEPrescriptionDraftForm = [];
            $this->form->resetSigningFields();

            $message = $result['success_message']
                ?? __('Електронний рецепт успішно створено без плану лікування.');
            Session::flash('success', $message);
            if (!empty($result['warning_message'])) {
                Session::flash('warning', $result['warning_message']);
            }
            $this->showEncounterEPrescriptionDrawer = false;
            $this->encounterEPrescriptionForm = [];
            $this->encounterEPrescriptionSearchResults = [];
            $this->encounterEPrescriptionSelectedMedication = null;
            $this->afterEncounterEPrescriptionSigned();
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = null;

        } catch (ValidationException $exception) {
            $message = $exception->validator->errors()->first() ?: __('Перевірте дані КЕП і спробуйте ще раз.');
            Session::flash('error', $message);
            $this->setErrorBag($exception->validator->getMessageBag());
        } catch (EHealthValidationException $exception) {
            $exception->report();
            $message = $exception->getTranslatedMessage();
            Session::flash('error', $message);
            $this->showSignatureModal = false;
            $this->actionType = null;
        } catch (Throwable $exception) {
            Log::error('EncounterEdit: failed to sign encounter eRx: '.$exception->getMessage());
            $message = __('Не вдалося підписати рецепт: ').$exception->getMessage();
            Session::flash('error', $message);
            $this->showSignatureModal = false;
            $this->actionType = null;
        }
    }

    protected function loadEncounterEPrescriptionAuthMethods(Encounter $encounter): void
    {
        $this->encounterEPrescriptionAuthMethods = [];
        $person = Person::find($encounter->person_id);
        if ($person === null || empty($person->uuid)) {
            return;
        }

        try {
            $authMethods = EHealth::person()->getAuthMethods($person->uuid)->validate();
            if (!is_array($authMethods)) {
                return;
            }

            $this->encounterEPrescriptionAuthMethods = collect($authMethods)->map(static function (array $method): array {
                $uuid = (string) ($method['id'] ?? ($method['uuid'] ?? ''));
                $type = (string) ($method['type'] ?? '');
                $phone = (string) ($method['phone_number'] ?? ($method['value'] ?? ''));
                $value = $uuid;
                if ($uuid !== '') {
                    $value = "{$uuid}|{$type}|{$phone}";
                }

                return [
                    'uuid' => $uuid,
                    'type' => $type,
                    'label' => trim($type.($phone !== '' ? ' · '.$phone : '')),
                    'value' => $value,
                ];
            })->filter(static fn (array $m): bool => $m['uuid'] !== '')->values()->all();
        } catch (Throwable $exception) {
            Log::warning('EncounterEdit: failed to load auth methods for eRx: '.$exception->getMessage());
        }
    }

    protected function validateEncounterEPrescriptionProgram(Encounter $encounter): bool
    {
        $programId = $this->encounterEPrescriptionForm['program_id'] ?? '';
        if (!filled($programId)) {
            return true;
        }

        try {
            $program = dictionary()->medicalPrograms()->firstWhere('id', $programId);
            $available = $program !== null && MedicationProgramPicker::forCurrentUser(collect([$program]))->isNotEmpty();
        } catch (Throwable $exception) {
            $available = false;
        }

        $message = null;
        if (!$available) {
            $message = __('medication-programs.unavailable');
        } elseif (!MedicationProgramPicker::matchesDiagnosis($program, [])) {
            $codings = MedicalEventsRepository::encounter()->primaryDiagnosisConditions($encounter)
                ->flatMap(static fn ($condition) => $condition->code?->coding ?? collect())
                ->map(static fn ($coding): array => ['system' => $coding->system, 'code' => $coding->code])
                ->all();
            if (!MedicationProgramPicker::matchesDiagnosis($program, $codings)) {
                $message = __('medication-programs.diagnosis_mismatch');
            }
        }

        if ($message !== null) {
            $this->encounterEPrescriptionWarningMessage = $message;
            $this->addError('encounterEPrescriptionForm.program_id', $message);
            Session::flash('error', $message);

            return false;
        }

        return true;
    }

    protected function loadEncounterEPrescriptionPrograms(): void
    {
        try {
            $programs = dictionary()->medicalPrograms()
                ->filter(static fn (array $program): bool => strtoupper((string) ($program['type'] ?? '')) === MedicalProgramType::MEDICATION->value);

            $this->encounterEPrescriptionPrograms = MedicationProgramPicker::forCurrentUser($programs)
                ->reject(static fn (array $program): bool => ($program['id'] ?? '') === MedicationProgramPicker::DEFAULT_ID)
                ->filter(static fn (array $program): bool => ($program['medication_request_allowed'] ?? true) !== false
                    && !data_get($program, 'medical_program_settings.care_plan_required', false))
                ->map(static fn (array $program): array => [
                    'id' => (string) ($program['id'] ?? ''),
                    'name' => (string) ($program['name'] ?? ''),
                    'settings' => $program['settings'] ?? [],
                ])
                ->filter(static fn (array $program): bool => $program['id'] !== '' && $program['name'] !== '')
                ->values()
                ->all();
        } catch (Throwable $exception) {
            Log::warning('EncounterEdit: failed to load medical programs for standalone eRx', [
                'exceptionType' => $exception::class,
            ]);
            $this->encounterEPrescriptionPrograms = [];
        }
    }

    protected function resolveEncounterMedicationPackageStep(?array $medication): float
    {
        $steps = collect($medication['packages'] ?? [])->map(static fn (array $package): float => (float) ($package['package_min_qty'] ?? ($package['min_qty'] ?? ($package['package_qty'] ?? 0))))->filter(static fn (float $step): bool => $step > 0);

        return $steps->min() ?? 0.0;
    }

    #[Computed]
    public function encounterPrescriptionQuantities(): array
    {
        return PrescriptionRules::quantities($this->encounterEPrescriptionSelectedMedication ?? []);
    }

    #[Computed]
    public function encounterPrescriptionContainers(): array
    {
        return PrescriptionRules::containers($this->encounterEPrescriptionSelectedMedication ?? []);
    }

    #[Computed]
    public function encounterPrescriptionIngredientDoses(): array
    {
        try {
            return (new MedicationRequestMapper())->ingredientDoses((float) ($this->encounterEPrescriptionForm['max_dose_per_period'] ?? 0), $this->encounterEPrescriptionForm, $this->encounterEPrescriptionSelectedMedication ?? []);
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    #[Computed]
    public function encounterPrescriptionNotificationsDisabled(): bool
    {
        $program = collect($this->encounterEPrescriptionPrograms)->firstWhere('id', $this->encounterEPrescriptionForm['program_id'] ?? '');

        return filter_var(PrescriptionRules::programSetting($program, 'request_notification_disabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function calculateEncounterPrescriptionDates(): void
    {
        try {
            $duration = (int) ($this->encounterEPrescriptionForm['duration'] ?? 0);
            if ($duration > 0) {
                $this->encounterEPrescriptionForm['ended_at'] = now()->parse(convertToYmd($this->encounterEPrescriptionForm['started_at']))->addDays($duration - 1)->toDateString();
            }
        } catch (Throwable) {
            $this->encounterEPrescriptionForm['ended_at'] = '';
        }
    }

    protected function afterEncounterEPrescriptionSigned(): void
    {
    }

}
