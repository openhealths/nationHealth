<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\Api\CarePlan as CarePlanApi;
use App\Classes\eHealth\EHealth;
use App\Classes\eHealth\EHealth as SubmissionApi;
use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Enums\CarePlanActivityKind;
use App\Enums\CarePlanApprovalCreateOutcome;
use App\Enums\CarePlanApprovalJobOutcome;
use App\Enums\CarePlanStatus;
use App\Enums\JobStatus;
use App\Enums\MedicalProgram\Type;
use App\Enums\Person\AuthenticationMethod;
use App\Enums\Person\ServiceRequestStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthJobTimeoutException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\CarePlanFullSync;
use App\Jobs\RemoteEHealthLinksProcessing;
use App\Livewire\CarePlan\Forms\CarePlanActivityForm;
use App\Livewire\CarePlan\Forms\CarePlanEPrescriptionForm;
use App\Livewire\CarePlan\Forms\CarePlanForm;
use App\Livewire\CarePlan\Forms\CarePlanReferralForm;
use App\Livewire\Concerns\ReadsClinicalSnapshots;
use App\Livewire\Concerns\SubmitsClinicalOperations;
use App\Livewire\MedicationRequest\Forms\PrescriptionRules;
use App\Livewire\Referral\Forms\ServiceRequestForm;
use App\Models\CarePlan;
use App\Models\CarePlanActivity;
use App\Models\Employee\Employee;
use App\Models\Icd10;
use App\Models\LegalEntity;
use App\Models\MedicalEvents\Sql\Approval as PatientApproval;
use App\Models\MedicalEvents\Sql\Condition;
use App\Models\MedicalEvents\Sql\DeviceRequestRequest;
use App\Models\MedicalEvents\Sql\DiagnosticReport;
use App\Models\MedicalEvents\Sql\Encounter;
use App\Models\MedicalEvents\Sql\Episode;
use App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest;
use App\Models\MedicalEvents\Sql\Observation;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\User;
use App\Repositories\CarePlanActivityRepository;
use App\Repositories\CarePlanRepository;
use App\Repositories\MedicalEvents\Repository as MedicalEventsRepository;
use App\Repositories\Repository;
use App\Services\Dictionary\Collections\BasicDictionaryCollection;
use App\Services\Dictionary\DictionaryManager;
use App\Services\Dictionary\MedicationProgramPicker;
use App\Services\Dictionary\ServiceSearch;
use App\Services\MedicalEvents\DeviceProgramParticipationGuard;
use App\Services\MedicalEvents\Fhir;
use App\Services\MedicalEvents\FhirResource;
use App\Services\MedicalEvents\Mappers\DeviceRequestMapper;
use App\Services\MedicalEvents\Mappers\MedicationRequestMapper;
use App\Services\MedicalEvents\Mappers\ServiceRequestMapper;
use App\Services\MedicalEvents\MedicalRequestOwnership;
use App\Services\MedicalEvents\MedicationRequestLifecycleService;
use App\Services\MedicalEvents\PatientApprovalService;
use App\Services\MedicalEvents\ReferralRequestLifecycleService;
use App\Traits\InteractsWithApprovals;
use App\Traits\SynchronizesCarePlans;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Form;
use Livewire\WithFileUploads;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;
use UnitEnum;

abstract class CarePlanComponent extends Component
{
    use InteractsWithApprovals;
    use WithFileUploads;

    // Everything the server derives is #[Locked]: it is sent to the browser on every request
    // and would otherwise come back whatever the client made of it. Only properties bound with
    // wire:model or entangled with Alpine stay writable.

    #[Locked]
    public int $carePlanId = 0;

    protected CarePlan $carePlan;

    #[Locked]
    public array $planSubmission = [];

    #[Locked]
    public ?string $inpatientApprovalStatus = null;

    public bool $showSignatureModal = false;
    public string $actionType = ''; // 'cancel', 'complete', 'sign_activity', 'complete_activity', 'cancel_activity'
    public string $statusReason = ''; // Used when cancelling or completing

    #[Locked]
    public ?int $activityToSign = null;

    #[Locked]
    public array $dictionaries = [];

    /** Entangled with Alpine in the method selection modal, so it cannot be locked. */
    #[Locked]
    public array $authMethods = [];

    public bool $showMethodSelectionModal = false;

    #[Locked]
    public ?string $carePlanUuid = null;

    // Drawer visibility controls (entangled with Alpine)
    public bool $showServiceDrawer = false;
    public bool $showServiceSearchDrawer = false;
    public bool $showMedicationDrawer = false;
    public bool $showMedicationSearchDrawer = false;
    public bool $showMedicationFormDrawer = false;
    public bool $showMedicalDeviceDrawer = false;
    public bool $showMedicalDeviceSearchDrawer = false;
    public bool $showMedicalDeviceFormDrawer = false;

    /** @var list<string> */
    #[Locked]
    public array $participatingDeviceProgramIds = [];

    #[Locked]
    public string $deviceParticipationWarning = '';

    public bool $showEPrescriptionDrawer = false;
    public CarePlanEPrescriptionForm $ePrescriptionForm;

    #[Locked]
    public ?array $ePrescriptionSelectedActivity = null;

    #[Locked]
    public ?array $ePrescriptionSelectedProduct = null;

    #[Locked]
    public ?array $ePrescriptionSelectedProgram = null;

    #[Locked]
    public float $ePrescriptionRemainingQty = 0.0;

    public bool $ePrescriptionSkipTreatmentPeriod = true;

    #[Locked]
    public bool $ePrescriptionShowDailyDoseWarning = false;

    #[Locked]
    public bool $ePrescriptionShowRemainingQtyWarning = false;

    #[Locked]
    public string $ePrescriptionRemainingQtyWarningMessage = '';

    #[Locked]
    public string $ePrescriptionWarningMessage = '';

    #[Locked]
    public array $ePrescriptionMultiples = [];

    #[Locked]
    public array $ePrescriptionPackages = [];

    #[Locked]
    public array $ePrescriptionAuthMethods = [];

    /** @var list<array{id:int,uuid:string,label:string}> */
    #[Locked]
    public array $ePrescriptionEligibleEncounters = [];

    #[Locked]
    public ?string $ePrescriptionRequestIdToSign = null;

    #[Locked]
    public string $printableContent = '';

    #[Locked]
    public array $activePrescriptions = [];

    // Outgoing Referral State Variables
    public bool $showReferralDrawer = false;
    public CarePlanReferralForm $referralForm;

    #[Locked]
    public ?array $referralSelectedActivity = null;

    #[Locked]
    public float $referralRemainingQty = 0.0;

    #[Locked]
    public bool $referralShowRemainingQtyWarning = false;

    #[Locked]
    public string $referralWarningMessage = '';

    #[Locked]
    public ?string $referralRequestIdToSign = null;

    #[Locked]
    public array $activeReferrals = [];

    #[Locked]
    public string $referralServiceCategory = '';

    #[Locked]
    public string $referralServiceType = 'service';

    #[Locked]
    public array $referralCategoryOptions = [];

    /** Package step for device eRx (packaging_count); 0 when unknown / service referral. */
    #[Locked]
    public int $referralDevicePackageQty = 0;

    /** @var list<array{uuid: string, label: string, raw: string}> */
    #[Locked]
    public array $referralAuthMethods = [];

    public string $referralExplanatoryLetter = '';

    // Search and selection parameters
    public string $searchQuery = '';

    #[Locked]
    public array $searchResults = [];

    #[Locked]
    public int $searchPage = 1;

    #[Locked]
    public ?array $selectedProduct = null;

    public string $selectedProgram = '';

    // Linked justification references (grounds)
    #[Locked]
    public array $linkedGrounds = [];

    #[Locked]
    public array $availableReports = [];

    #[Locked]
    public array $availableObservations = [];

    // Activity Form state
    public CarePlanActivityForm $activityForm;

    public CarePlanForm $form;

    public string $outcomeCode = ''; // For outcomeCodeableConcept

    /** Built by addOutcomeReference()/removeOutcomeReference(), never posted by the browser. */
    #[Locked]
    public array $outcomeReferences = [];

    public ?string $selectedOutcomeReference = null;

    #[Locked]
    public array $availableConditions = [];

    /**
     * @return list<array{uuid: string, type: string, name: string, date: string}>
     */
    public function getSelectedOutcomeReferencesDetailsProperty(): array
    {
        $catalog = collect($this->availableConditions)
            ->map(fn (array $item): array => $item + ['type' => __('Діагноз/Стан')])
            ->concat(collect($this->availableObservations)->map(fn (array $item): array => $item + ['type' => __('Спостереження')]))
            ->concat(collect($this->availableReports)->map(fn (array $item): array => $item + ['type' => __('Діагностичний звіт')]))
            ->keyBy('uuid');

        return collect($this->outcomeReferences)
            ->map(function (string $uuid) use ($catalog): ?array {
                $item = $catalog->get($uuid);
                if (!is_array($item)) {
                    return null;
                }

                return [
                    'uuid' => $uuid,
                    'type' => (string) ($item['type'] ?? ''),
                    'name' => (string) ($item['name'] ?? $uuid),
                    'date' => (string) ($item['date'] ?? '-'),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function addOutcomeReference(): void
    {
        if ($this->selectedOutcomeReference === null || $this->selectedOutcomeReference === '') {
            return;
        }

        if (!in_array($this->selectedOutcomeReference, $this->outcomeReferences, true)) {
            $this->outcomeReferences[] = $this->selectedOutcomeReference;
        }

        $this->selectedOutcomeReference = null;
    }

    public function removeOutcomeReference(string $uuid): void
    {
        $this->outcomeReferences = array_values(array_filter(
            $this->outcomeReferences,
            static fn (string $reference): bool => $reference !== $uuid
        ));
    }

    public function boot(): void
    {
        if ($this->carePlanId !== 0) {
            $this->carePlan = Repository::carePlan()->findById($this->carePlanId);
            abort_if(Auth::user()->cannot('view', $this->carePlan), 404);
            if (property_exists($this, 'activityId') && $this->activityId !== 0) {
                $this->activity = $this->ownedActivity($this->activityId);
            }
        }
    }

    public function hydrate(): void
    {
        // boot() restores request-local models before property updates and actions.
    }

    protected function authorizeCarePlanActivityWrite(): bool
    {
        // Re-read consent for every mutation: a page opened before expiry is not a grant.
        $plan = Repository::carePlan()->findById($this->carePlan->id);
        if ($plan === null || $plan->requiresFhirImport() || Auth::user()->cannot('manageActivities', $plan)) {
            Session::flash('error', __('care-plan.prescribing_patient_access_required'));

            return false;
        }

        return true;
    }

    public function getCanManageActivitiesProperty(): bool
    {
        return !$this->isTerminalCarePlan && !$this->carePlan->requiresFhirImport()
            && Auth::user()?->can('manageActivities', $this->carePlan) === true;
    }

    protected function authorizeCarePlanWrite(): void
    {
        if (Auth::user()->cannot('manage', $this->carePlan)) {
            $message = __('care-plan.no_permission_update');
            Session::flash('error', $message);
            throw ValidationException::withMessages(['carePlan' => $message]);
        }
        if ($this->carePlan->requiresFhirImport()) {
            $message = __($this->carePlan->status === CarePlanStatus::DRAFT->value ? 'care-plan.draft_backfill_required' : 'care-plan.full_sync_required');
            Session::flash('error', $message);
            throw ValidationException::withMessages(['carePlan' => $message]);
        }
    }

    protected function ownedActivity(int $activityId): CarePlanActivity
    {
        return Repository::carePlanActivity()->findOwned($this->carePlan->id, $activityId);
    }

    protected function ownedActivityByBasedOnUuid(?string $activityUuid): CarePlanActivity
    {
        return Repository::carePlanActivity()->findOwnedByUuid($this->carePlan->id, $activityUuid);
    }

    protected function bootCarePlan(CarePlan $carePlan): void
    {
        abort_if(Auth::user()->cannot('view', $carePlan), 404);

        $this->carePlan = Repository::carePlan()->findById($carePlan->id);
        $this->carePlanId = $this->carePlan->id;

        $personId = $this->carePlan->personId;

        $grounds = $this->availableGrounds($personId);
        $this->availableConditions = $grounds['conditions'];
        $this->availableReports = $grounds['reports'];
        $this->availableObservations = $grounds['observations'];

        // Basic dictionaries and medical programs come from different eHealth endpoints.
        // They are loaded separately so a missing optional dictionary cannot leave the
        // programme dropdowns silently empty.
        try {
            $basics = app(DictionaryManager::class)->basics();
            $this->dictionaries['care_plan_categories'] = $basics->byName('eHealth/care_plan_categories')
                ?->asCodeDescription()
                ?->toArray() ?? [];

            $this->dictionaries['care_plan_activity_outcomes'] = $basics->byName('eHealth/care_plan_activity_outcomes')
                ?->asCodeDescription()
                ?->toArray() ?? [];

            $this->dictionaries['care_plan_activity_goals'] = $basics->byName('eHealth/care_plan_activity_goals')
                ?->asCodeDescription()
                ?->toArray() ?? [];

            $this->dictionaries['care_plan_cancel_reasons'] = $basics->byName('eHealth/care_plan_cancel_reasons')
                ?->asCodeDescription()
                ?->toArray() ?? [];

            $this->dictionaries['care_plan_complete_reasons'] = $basics->byName('eHealth/care_plan_complete_reasons')
                ?->asCodeDescription()
                ?->toArray() ?? [];

            $this->dictionaries['care_plan_activity_complete_reasons'] = $basics->byName('eHealth/care_plan_activity_complete_reasons')
                ?->asCodeDescription()
                ?->toArray() ?? [];

            $this->dictionaries['care_plan_activity_cancel_reasons'] = $basics->byName('eHealth/care_plan_activity_cancel_reasons')
                ?->asCodeDescription()
                ?->toArray() ?? [];

            $this->dictionaries['MEDICATION_REQUEST_REJECT_REASON'] = $basics->byName('MEDICATION_REQUEST_REJECT_REASON')
                ?->asCodeDescription()
                ?->toArray()
                ?? ($basics->byName('eHealth/MEDICATION_REQUEST_REJECT_REASON')
                    ?->asCodeDescription()
                    ?->toArray()
                    ?? []);

            $this->dictionaries['device_definition_classification_type'] = $basics->byName('device_definition_classification_type')
                ?->asCodeDescription()
                ?->toArray() ?? [];

            $this->dictionaries['eHealth/assistive_devices'] = $basics->byName('eHealth/assistive_devices')
                ?->asCodeDescription()
                ?->toArray() ?? [];

            $this->dictionaries['device_definition_packaging_type'] = $basics->byName('device_definition_packaging_type')
                ?->asCodeDescription()
                ?->toArray() ?? [];

            $this->dictionaries['device_unit'] = $basics->byName('device_unit')
                ?->asCodeDescription()
                ?->toArray() ?? [];
        } catch (Exception $exception) {
            Log::warning('CarePlanShow: failed to load basic dictionaries: ' . $exception->getMessage());
        }

        try {
            // Medical programs, split by type; device/medication lists are role-filtered.
            $programs = app(DictionaryManager::class)->medicalPrograms();
            $this->dictionaries['medical_programs'] = $programs
                ->pluck('name', 'id')
                ->toArray() ?? [];

            $this->dictionaries['medical_programs_medication'] = $this->filterMedicationPrograms(
                $programs->filter(fn ($program) => strtoupper($program['type'] ?? '') === Type::MEDICATION->value)
            )->pluck('name', 'id')->toArray() ?? [];

            $this->dictionaries['medical_programs_device'] = $this->filterDevicePrograms(
                $programs->filter(fn ($program) => strtoupper($program['type'] ?? '') === Type::DEVICE->value)
            )->pluck('name', 'id')->toArray() ?? [];

            $this->dictionaries['medical_programs_service'] = $this->filterServicePrograms(
                $programs->filter(fn ($program) => strtoupper($program['type'] ?? '') === Type::SERVICE->value)
            )->pluck('name', 'id')->toArray() ?? [];
        } catch (Exception $exception) {
            Log::warning('CarePlanShow: failed to load medical programs: ' . $exception->getMessage());
        }

        $this->carePlanUuid = $this->carePlan->uuid;
        $this->patientId = $this->carePlan->person->uuid;
        $this->loadDeviceProgramParticipationState();

        $this->activePrescriptions = Repository::carePlanActivity()
            ->linkedDocuments($this->carePlan->activities->pluck('uuid')->all(), 'medication_request', forDisplay: true)
            ->map(function ($record): array {
                $row = $record->toArray();
                $row['based_on_uuid'] = $record->basedOn?->value;

                return $row;
            })
            ->all();
        $this->loadActiveReferrals();

        $action = request()->query('action');
        if (in_array($action, ['cancel', 'complete'], true) && $this->canChangePlanLifecycle) {
            $this->openSignatureModal($action);
        }
    }

    /**
     * Unsigned local draft that still needs a KEP before it exists in eHealth.
     */
    public function getCanSignPlanProperty(): bool
    {
        return $this->carePlanStatusCode() === CarePlanStatus::DRAFT->value
            && !$this->carePlan->requiresFhirImport()
            && !in_array($this->planSubmission['status'] ?? null, [JobStatus::PENDING->value, JobStatus::PROCESSING->value, JobStatus::PROCESSED->value], true)
            && !Repository::carePlan()->hasPendingSubmission($this->carePlan);
    }

    /**
     * Signed in eHealth, still `new`, and the current doctor has no confirmed approval yet.
     */
    public function getCanRequestPatientApprovalProperty(): bool
    {
        return filled($this->carePlan->uuid)
            && !$this->isTerminalCarePlan
            && !$this->isInpatientPlanOfCurrentOrganization
            && !$this->carePlan->requiresFhirImport()
            && $this->carePlanStatusCode() === CarePlanStatus::PENDING->value
            && !in_array($this->planSubmission['status'] ?? null, [JobStatus::PENDING->value, JobStatus::PROCESSING->value], true)
            && !$this->hasGrantedApprovalForCurrentDoctor
            && !Repository::carePlan()->hasPendingSubmission($this->carePlan);
    }

    /**
     * Status actions require a complete registered plan and a current write approval.
     */
    public function getCanChangePlanLifecycleProperty(): bool
    {
        if (!filled($this->carePlan->uuid) || $this->isTerminalCarePlan || $this->carePlan->requiresFhirImport()
            || !Auth::user()?->can('manage', $this->carePlan)
            || Repository::carePlan()->hasPendingSubmission($this->carePlan)) {
            return false;
        }

        return in_array($this->carePlanStatusCode(), [CarePlanStatus::PENDING->value, CarePlanStatus::ACTIVE->value], true)
            && $this->hasGrantedApprovalForCurrentDoctor;
    }

    public function getCanCancelPlanProperty(): bool
    {
        return $this->canChangePlanLifecycle
            && $this->isCurrentDoctorPlanAuthor()
            && $this->planCancelBlockReason($this->carePlan) === null;
    }

    public function getCanCompletePlanProperty(): bool
    {
        return $this->canChangePlanLifecycle
            && $this->planCompleteBlockReason($this->carePlan) === null;
    }

    protected function isCurrentDoctorPlanAuthor(): bool
    {
        $authorUuid = $this->carePlan->authorReference?->value ?? $this->carePlan->author?->uuid;

        return filled($authorUuid) && $authorUuid === $this->currentCarePlanEmployee()?->uuid;
    }

    protected function guardPlanLifecycleAccess(string $action): bool
    {
        if ($this->canChangePlanLifecycle && ($action !== 'cancel' || $this->isCurrentDoctorPlanAuthor())) {
            return false;
        }
        Session::flash('error', __('care-plan.lifecycle_access_required'));
        $this->showSignatureModal = false;

        return true;
    }

    public function getIsInpatientPlanOfCurrentOrganizationProperty(): bool
    {
        return $this->carePlan->skipsPatientOtp();
    }

    public function getCanRequestInpatientAccessProperty(): bool
    {
        return $this->isInpatientPlanOfCurrentOrganization
            && !$this->isTerminalCarePlan
            && in_array($this->carePlanStatusCode(), [CarePlanStatus::PENDING->value, CarePlanStatus::ACTIVE->value], true)
            && !$this->carePlan->requiresFhirImport()
            && !$this->hasGrantedApprovalForCurrentDoctor
            && !in_array($this->inpatientApprovalStatus, ['queued', 'pending'], true)
            && !Repository::carePlan()->hasPendingSubmission($this->carePlan);
    }

    public function getHasGrantedApprovalForCurrentDoctorProperty(): bool
    {
        return $this->carePlan->hasGrantedApprovalForEmployeeUuid(
            $this->currentCarePlanEmployee()?->uuid
        );
    }

    #[On('care-plan-approvals-changed')]
    public function onCarePlanApprovalsChanged(): void
    {
        $this->refreshCarePlan();
    }

    protected function rulesForSigning(): array
    {
        $statusReasonOptional = in_array($this->actionType, [
            'sign_activity',
            'sign_plan',
            'sign_eprescription',
            'sign_servicerequest',
            'sign_devicerequest',
            'recall_referral',
        ], true);

        $rules = [
            'statusReason' => $statusReasonOptional ? 'nullable|string' : 'required|string',
        ];

        if ($this->requiresDigitalSignature()) {
            foreach ($this->form->signatureRules() as $field => $rule) {
                $rules['form.'.$field] = $rule;
            }
        }

        if ($this->actionType === 'recall_referral') {
            $rules['referralExplanatoryLetter'] = 'required|string|min:3';
        }

        return $rules;
    }

    /**
     * Care Plan and Activity completion use unsigned action bodies.
     */
    protected function requiresDigitalSignature(): bool
    {
        return !in_array($this->actionType, ['complete', 'complete_activity'], true);
    }

    public function getStatusReasonsProperty(): array
    {
        if (in_array($this->actionType, ['cancel_referral', 'recall_referral'], true)) {
            $operation = $this->actionType === 'recall_referral' ? 'recall' : 'cancel';

            return $this->dictionaries['service_request_'.$operation.'_reasons'] ?? [];
        }
        if ($this->actionType === 'complete') {
            return $this->dictionaries['care_plan_complete_reasons'] ?? [];
        }
        if ($this->actionType === 'complete_activity') {
            return $this->dictionaries['care_plan_activity_complete_reasons'] ?? [];
        }
        if ($this->actionType === 'cancel_activity') {
            return $this->dictionaries['care_plan_activity_cancel_reasons'] ?? [];
        }
        if (in_array($this->actionType, ['reject_prescription', 'cancel_prescription'], true)) {
            return $this->dictionaries['MEDICATION_REQUEST_REJECT_REASON'] ?? [];
        }

        return $this->dictionaries['care_plan_cancel_reasons'] ?? [];
    }

    public function updatedFormKeyContainerUpload(): void
    {
        $upload = $this->form->keyContainerUpload ?? null;

        if ($upload && method_exists($upload, 'getClientOriginalName')) {
            $this->form->keyContainerFileName = $upload->getClientOriginalName();
        } elseif ($upload === null) {
            $this->form->keyContainerFileName = '';
        }
    }

    public function updateSelectedProgramParticipation(): void
    {
        $this->activityForm->program = $this->selectedProgram;

        if (method_exists($this, 'refreshDeviceSelectionWarning')) {
            $this->refreshDeviceSelectionWarning();
        }
    }

    public function openSignatureModal(string $actionType, ?int $activityId = null, ?string $requestUuid = null): void
    {
        if (in_array($actionType, ['cancel_referral', 'recall_referral'], true)) {
            // A registered referral has its own status permissions, even after its
            // care plan closes or the physician no longer has care-plan write access.
            try {
                $request = app(MedicalRequestOwnership::class)
                    ->serviceForPerson((string) $requestUuid, $this->carePlan->personId);
            } catch (ModelNotFoundException) {
                Session::flash('error', __('care-plan.document_context_unavailable'));

                return;
            }
            $operation = $actionType === 'recall_referral' ? 'recall' : 'cancel';
            if (Auth::user()->cannot($operation, $request)) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return;
            }
        } else {
            $this->authorizeCarePlanWrite();
            if (in_array($actionType, ['sign_activity', 'cancel_activity', 'complete_activity'], true)) {
                if (!$this->authorizeCarePlanActivityWrite()) {
                    return;
                }
            }
            if ($this->guardTerminalCarePlanMutation()) {
                return;
            }
        }

        if (in_array($actionType, ['cancel', 'complete'], true) && $this->guardPlanLifecycleAccess($actionType)) {
            return;
        }

        if ($actionType === 'sign_plan') {
            try {
                Repository::carePlan()->assertDraftSigningReady($this->carePlan);
            } catch (ValidationException $exception) {
                Session::flash('error', $exception->validator->errors()->first());
                $this->showSignatureModal = false;

                return;
            }
        }

        try {
            $activity = $activityId ? $this->ownedActivity($activityId) : null;
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        }

        if (in_array($actionType, ['cancel_activity', 'complete_activity'], true) && $activityId) {

            if ($activity) {
                $blockReason = $this->activityStatusChangeBlockReason($activity, $actionType);

                if ($blockReason !== null) {
                    Session::flash('error', $blockReason);

                    return;
                }
            }
        }

        if ($actionType === 'cancel') {
            $blockReason = $this->planCancelBlockReason($this->carePlan);

            if ($blockReason !== null) {
                Session::flash('error', $blockReason);

                return;
            }
        }

        if ($actionType === 'complete') {
            $blockReason = $this->planCompleteBlockReason($this->carePlan);

            if ($blockReason !== null) {
                Session::flash('error', $blockReason);

                return;
            }
        }

        if (in_array($actionType, ['cancel_referral', 'recall_referral'], true)) {
            $operation = $actionType === 'recall_referral' ? 'recall' : 'cancel';
            try {
                $this->dictionaries['service_request_'.$operation.'_reasons'] = dictionary()->basics()->byName('eHealth/service_request_'.$operation.'_reasons')->asCodeDescription()->all();
            } catch (Throwable $exception) {
                Log::warning('Referral status reasons could not be loaded', ['exceptionType' => $exception::class]);
                Session::flash('error', __('Не вдалося завантажити довідник причин зміни направлення.'));
                $this->showSignatureModal = false;

                return;
            }
        }
        $this->actionType = $actionType;
        $this->activityToSign = $activityId;
        if ($requestUuid !== null && $requestUuid !== '') {
            if (in_array($actionType, ['cancel_prescription', 'sign_eprescription', 'reject_prescription'], true)) {
                $this->ePrescriptionRequestIdToSign = $requestUuid;
            } elseif (in_array($actionType, ['cancel_referral', 'recall_referral', 'sign_servicerequest', 'sign_devicerequest'], true)) {
                $this->referralRequestIdToSign = $requestUuid;
            }
        }
        $this->statusReason = ''; // Reset reason
        if ($actionType !== 'recall_referral') {
            $this->referralExplanatoryLetter = '';
        }
        $this->outcomeCode = ''; // Reset outcome
        $this->outcomeReferences = []; // Reset references
        $this->showSignatureModal = true;
    }

    protected function currentCarePlanEmployee(): ?Employee
    {
        $user = Auth::user();
        if ($user === null) {
            return null;
        }

        $termsOfService = $this->carePlan->termsOfServiceCode();

        return $user->getCarePlanWriterEmployee(is_string($termsOfService) ? $termsOfService : null)
            ?? $user->activeDoctorEmployee();
    }

    protected function carePlanStatusCode(): string
    {
        return CarePlanStatus::normalize($this->carePlan->status);
    }

    public function getIsTerminalCarePlanProperty(): bool
    {
        return CarePlanStatus::fromStored($this->carePlan->status)->isTerminal();
    }

    protected function guardTerminalCarePlanMutation(): bool
    {
        if (!$this->isTerminalCarePlan) {
            return false;
        }

        Session::flash('error', __('care-plan.cannot_mutate_terminal_care_plan', [
            'status' => CarePlanStatus::labelFor($this->carePlan->status),
        ]));

        return true;
    }

    protected function refreshCarePlan(): void
    {
        $this->carePlan = Repository::carePlan()->findById($this->carePlan->id);
        unset($this->canManageActivities, $this->hasGrantedApprovalForCurrentDoctor, $this->canRequestPatientApproval, $this->canRequestInpatientAccess, $this->canChangePlanLifecycle, $this->canCancelPlan, $this->canCompletePlan);
        $repository = Repository::carePlanActivity();
        if (property_exists($this, 'activity') && $this->activity instanceof CarePlanActivity) {
            $this->activity = $repository->findOwned($this->carePlan->id, $this->activity->id);
        }
        $this->activePrescriptions = $repository->linkedDocuments($this->carePlan->activities->pluck('uuid')->all(), 'medication_request', forDisplay: true)
            ->map(function ($record): array {
                $row = $record->toArray();
                $row['based_on_uuid'] = $record->basedOn?->value;

                return $row;
            })->all();
        $this->loadActiveReferrals();
    }

    protected function loadActiveReferrals(): void
    {
        $activityUuids = $this->carePlan->activities->pluck('uuid')->all();
        $repository = Repository::carePlanActivity();
        $this->activeReferrals = [];
        foreach (['service_request', 'device_request'] as $kind) {
            foreach ($repository->linkedDocuments($activityUuids, $kind) as $record) {
                $this->activeReferrals[] = $this->normalizeReferralForView($record, $kind);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeReferralForView(Model $record, string $kind): array
    {
        $category = $record->category?->text;
        $priority = $record->priority?->text;

        return [
            'uuid' => $record->getAttribute('uuid'),
            'kind' => $kind,
            'based_on_uuid' => $record->basedOn?->value,
            'status' => $record->getAttribute('status'),
            'status_label' => $this->resolveReferralStatusLabel((string) $record->getAttribute('status')),
            'request_number' => $record->getAttribute('request_number'),
            'quantity' => $record->getAttribute('quantity'),
            'started_at' => $kind === 'service_request' ? $record->getRawOriginal('started_at') : $record->getAttribute('started_at'),
            'ended_at' => $kind === 'service_request' ? $record->getRawOriginal('ended_at') : $record->getAttribute('ended_at'),
            'service_id' => $kind === 'service_request' ? $record->getAttribute('service_id') : null,
            'device_id' => $kind === 'device_request' ? $record->getAttribute('device_id') : null,
            'product_code' => $kind === 'service_request'
                ? $record->getAttribute('service_id')
                : $record->getAttribute('device_id'),
            'category' => $category,
            'category_label' => $this->referralCategoryLabel($category),
            'priority' => $priority,
            'priority_label' => $this->referralPriorityLabel($priority),
            'note' => $record->getAttribute('note'),
            'program_id' => $record->getAttribute('program_id'),
            'employee_name' => $record->employee?->full_name ?? null,
        ];
    }

    protected function resolveReferralStatusLabel(string $status): string
    {
        return ServiceRequestStatus::labelFor($status);
    }

    protected function referralCategoryLabel(?string $category): ?string
    {
        if ($category === null || $category === '') {
            return null;
        }

        $key = 'care-plan.referral_category.' . $category;

        return Lang::has($key) ? __($key) : $category;
    }

    protected function referralPriorityLabel(?string $priority): ?string
    {
        if ($priority === null || $priority === '') {
            return null;
        }

        $key = 'care-plan.referral_priority.' . $priority;

        return Lang::has($key) ? __($key) : $priority;
    }

    protected function scopeDocumentsToActivity(string $activityUuid): void
    {
        $this->activePrescriptions = array_values(array_filter(
            $this->activePrescriptions,
            static fn (array $prescription): bool => ($prescription['based_on_uuid'] ?? null) === $activityUuid
        ));

        $this->activeReferrals = array_values(array_filter(
            $this->activeReferrals,
            static fn (array $referral): bool => ($referral['based_on_uuid'] ?? null) === $activityUuid
        ));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $programs
     * @return Collection<int, array<string, mixed>>
     */
    protected function filterDevicePrograms(Collection $programs): Collection
    {
        $user = Auth::user();
        $filtered = $programs->where('is_active', '=', true);

        if ($user) {
            $roles = $user->allowedRoles;
            $mainSpeciality = $user->getMainSpeciality(legalEntity());

            $filtered = $filtered->filter(function (array $program) use ($roles, $user, $mainSpeciality): bool {
                $allowedEmployeeTypes = Arr::get($program, 'medical_program_settings.employee_types_to_create_request', []);
                if (!empty($allowedEmployeeTypes) && $roles->intersect($allowedEmployeeTypes)->isEmpty()) {
                    return false;
                }

                if ($user->hasAllowedRole(Role::SPECIALIST->value) || $user->hasAllowedRole(Role::DOCTOR->value)) {
                    $allowedSpecialities = Arr::get($program, 'medical_program_settings.speciality_types_care_plan_activity_allowed')
                        ?? (Arr::get($program, 'medical_program_settings.speciality_types_request_allowed')
                            ?? Arr::get($program, 'medical_program_settings.speciality_types_allowed', []));

                    if (!empty($allowedSpecialities)) {
                        return $mainSpeciality->intersect($allowedSpecialities)->isNotEmpty();
                    }
                }

                return true;
            });
        }

        $filtered = $filtered->filter(
            fn (array $program): bool => $this->carePlan->providingConditionsBlockReason($program) === null
        );

        if ($this->participatingDeviceProgramIds !== []) {
            $filtered = app(DeviceProgramParticipationGuard::class)
                ->filterProgramsForParticipation($filtered, $this->participatingDeviceProgramIds);
        }

        return $filtered;
    }

    protected function loadDeviceProgramParticipationState(): void
    {
        $guard = app(DeviceProgramParticipationGuard::class);
        $this->participatingDeviceProgramIds = $guard->resolveParticipatingProgramIds(legalEntity());
        $this->deviceParticipationWarning = $this->participatingDeviceProgramIds === []
            ? __('care-plan.device_program_participation_sync_hint')
            : '';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $programs
     * @return Collection<int, array<string, mixed>>
     */
    protected function filterMedicationPrograms(Collection $programs): Collection
    {
        return MedicationProgramPicker::forCurrentUser($programs);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $programs
     * @return Collection<int, array<string, mixed>>
     */
    protected function filterServicePrograms(Collection $programs): Collection
    {
        $user = Auth::user();
        if (!$user) {
            return $programs->where('is_active', '=', true);
        }

        $roles = $user->allowedRoles;
        $mainSpeciality = $user->getMainSpeciality(legalEntity());

        return $programs
            ->where('is_active', '=', true)
            ->filter(function (array $program) use ($roles, $user, $mainSpeciality): bool {
                $allowedEmployeeTypes = Arr::get($program, 'medical_program_settings.employee_types_to_create_request', []);
                if (!empty($allowedEmployeeTypes) && $roles->intersect($allowedEmployeeTypes)->isEmpty()) {
                    return false;
                }

                if ($user->hasAllowedRole(Role::SPECIALIST->value) || $user->hasAllowedRole(Role::DOCTOR->value)) {
                    $allowedSpecialities = Arr::get($program, 'medical_program_settings.speciality_types_care_plan_activity_allowed')
                        ?? (Arr::get($program, 'medical_program_settings.speciality_types_request_allowed')
                            ?? Arr::get($program, 'medical_program_settings.speciality_types_allowed', []));

                    if (!empty($allowedSpecialities)) {
                        return $mainSpeciality->intersect($allowedSpecialities)->isNotEmpty();
                    }
                }

                return true;
            });
    }

    public function render()
    {
        return $this->renderCarePlan();
    }

    abstract protected function renderCarePlan();

    use ReadsClinicalSnapshots;

    use SubmitsClinicalOperations;
    use SynchronizesCarePlans;

    #[Locked]
    public bool $activateAfterSubmission = false;

    public bool $showActivationPrompt = false;

    protected function continuePlanActivation(): void
    {
        if (!$this->activateAfterSubmission || !$this->canRequestPatientApproval || Session::get($this->activationPromptSessionKey(), false)) {
            return;
        }
        $this->activateAfterSubmission = false;
        $this->showActivationPrompt = true;
    }

    public function acceptPlanActivation(): void
    {
        $this->authorizeCarePlanWrite();
        Session::put($this->activationPromptSessionKey(), true);
        $this->showActivationPrompt = false;
        $this->openMethodSelectionModal();
    }

    public function dismissPlanActivation(): void
    {
        Session::put($this->activationPromptSessionKey(), true);
        $this->showActivationPrompt = false;
    }

    protected function activationPromptSessionKey(): string
    {
        return 'care-plan.activation-prompt.'.$this->carePlan->id.'.'.($this->planSubmission['id'] ?? 'imported');
    }

    public function refreshDisplayedDetails(): void
    {
        $this->remotePlanDetails = [];

        if (!Auth::user()->can('view', $this->carePlan)) {
            return;
        }

        if (!$this->carePlan->uuid || in_array(CarePlanStatus::fromStored($this->carePlan->status), [CarePlanStatus::PENDING, CarePlanStatus::DRAFT], true)) {
            return;
        }

        try {
            $this->remotePlanDetails = $this->displayedCarePlanDetails($this->carePlan, $this->dictionaries ?? []);
        } catch (Throwable $exception) {
            report($exception);
            Session::flash('error', __('device-requests.messages.plan_details_unavailable'));
        }
    }

    public function sign(): void
    {
        $repository = Repository::carePlan();
        $activityRepository = Repository::carePlanActivity();
        if (in_array($this->actionType, ['recall_referral', 'cancel_referral'], true)) {
            // Recheck the registered referral's own permission when signing, including after its care plan closes.
            try {
                $request = app(MedicalRequestOwnership::class)
                    ->serviceForPerson((string) $this->referralRequestIdToSign, $this->carePlan->personId);
            } catch (ModelNotFoundException) {
                Session::flash('error', __('care-plan.document_context_unavailable'));

                return;
            }
            $operation = $this->actionType === 'recall_referral' ? 'recall' : 'cancel';
            if (Auth::user()->cannot($operation, $request)) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return;
            }
        } else {
            $this->authorizeCarePlanWrite();
        }
        if (in_array($this->actionType, ['cancel', 'complete'], true) && $this->guardPlanLifecycleAccess($this->actionType)) {
            return;
        }
        try {
            $validated = $this->validate($this->rulesForSigning());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        if ($this->actionType === 'sign_activity') {
            $this->signActivity($repository, $activityRepository);

            return;
        }

        if ($this->actionType === 'complete') {
            $this->completePlan();

            return;
        }

        if ($this->actionType === 'sign_eprescription') {
            if (!method_exists($this, 'signEPrescription')) {
                Session::flash('error', __('care-plan.unexpected_error'));
                $this->showSignatureModal = false;

                return;
            }

            $this->signEPrescription();

            return;
        }

        if ($this->actionType === 'sign_servicerequest' || $this->actionType === 'sign_devicerequest') {
            if (!method_exists($this, 'signReferral')) {
                Session::flash('error', __('care-plan.unexpected_error'));
                $this->showSignatureModal = false;

                return;
            }

            $this->signReferral();

            return;
        }

        if (in_array($this->actionType, ['complete_activity', 'cancel_activity'])) {
            $this->signStatusActivity($activityRepository);

            return;
        }

        if ($this->actionType === 'cancel_prescription' || $this->actionType === 'reject_prescription') {
            if (!method_exists($this, 'signRejectPrescription')) {
                Session::flash('error', __('care-plan.unexpected_error'));
                $this->showSignatureModal = false;

                return;
            }

            // eHealth medication-request flow in this app uses reject (no separate cancel signer).
            $this->signRejectPrescription();

            return;
        }

        if ($this->actionType === 'cancel_referral') {
            if (!method_exists($this, 'signCancelReferral')) {
                Session::flash('error', __('care-plan.unexpected_error'));
                $this->showSignatureModal = false;

                return;
            }

            $this->signCancelReferral();

            return;
        }

        if ($this->actionType === 'recall_referral') {
            if (!method_exists($this, 'signRecallReferral')) {
                Session::flash('error', __('care-plan.unexpected_error'));
                $this->showSignatureModal = false;

                return;
            }

            $this->signRecallReferral();

            return;
        }

        if ($this->actionType === 'sign_plan') {
            if ($this->carePlan->status !== CarePlanStatus::DRAFT->value) {
                $this->showSignatureModal = false;

                return;
            }
            $this->signPlan($repository);

            return;
        }

        if (empty($this->carePlan->uuid) || $this->carePlan->status === CarePlanStatus::DRAFT->value) {
            Session::flash('error', __('care-plan.care_plan_not_synced'));
            $this->showSignatureModal = false;

            return;
        }

        if ($this->actionType !== 'cancel') {
            $this->showSignatureModal = false;

            return;
        }

        if ($this->actionType === 'cancel') {
            $planCancelBlock = $this->planCancelBlockReason($this->carePlan);

            if ($planCancelBlock !== null) {
                Session::flash('error', $planCancelBlock);
                $this->showSignatureModal = false;

                return;
            }
        }

        $systemMap = [
            'cancel' => 'eHealth/care_plan_cancel_reasons',
            'complete' => 'eHealth/care_plan_complete_reasons',
        ];

        $statusReasonCodeableConcept = [
            'coding' => [
                [
                    'system' => $systemMap[$this->actionType] ?? 'eHealth/care_plan_cancel_reasons',
                    'code' => $this->statusReason,
                ]
            ]
        ];

        try {
            $payloadForSign = $this->buildCarePlanStatusChangePayload($statusReasonCodeableConcept);

            Log::info('CarePlanShow: signing care-plan status change', [
                'actionType' => $this->actionType,
                'carePlanId' => $this->carePlan->id,
            ]);

            $signedContent = signatureService()->signData(
                $payloadForSign,
                $this->form->password,
                $this->form->knedp,
                $this->form->keyContainerUpload,
                Auth::user()->party->taxId
            );

            $finalResponse = $this->submitClinicalOperation($this->carePlan, ['status' => CarePlanStatus::CANCELLED->value, 'statusReason' => $statusReasonCodeableConcept], fn () => app(CarePlanApi::class)->cancel(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                [
                    'signed_data' => $signedContent,
                    'signed_data_encoding' => 'base64',
                ]
            ));
            if ($finalResponse === null) {
                return;
            }

            $this->refreshCarePlan();

            session()->flash('success', __('care-plan.care_plan_cancelled'));
            $this->showSignatureModal = false;
            $this->redirectAfterCarePlanClosed();

        } catch (EHealthJobTimeoutException $exception) {
            $exception->report();
            Session::flash('error', $exception->getMessage());
            $this->showSignatureModal = false;
        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlanShow: connection error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.connection_error'));
            $this->showSignatureModal = false;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            if (method_exists($exception, 'report')) {
                $exception->report();
            }
            Log::error('CarePlanShow: eHealth error: ' . $exception->getMessage(), [
                'details' => method_exists($exception, 'getDetails') ? $exception->getDetails() : null
            ]);
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : __('care-plan.ehealth_error_prefix') . $exception->getMessage();

            if ($this->actionType === 'cancel' && $this->isCarePlanAlreadyCancelledError($exception)) {
                $this->saveCarePlanDraft(['status' => CarePlanStatus::CANCELLED->value], $this->carePlan->personId, $this->carePlan->legalEntityId, $this->carePlan->id);
                $this->refreshCarePlan();
            }

            Session::flash('error', $msg);
            $this->showSignatureModal = false;
        } catch (Throwable $exception) {
            Log::error('CarePlanShow: unexpected error: ' . $exception->getMessage(), [
                'exception' => $exception,
                'actionType' => $this->actionType,
            ]);
            Session::flash(
                'error',
                __('care-plan.unexpected_error') . ' ' . $exception->getMessage()
            );
            $this->showSignatureModal = false;
        }
    }

    protected function signPlan(CarePlanRepository $repository): void
    {
        try {
            $repository->assertDraftSigningReady($this->carePlan);
            $resource = $repository->getResource($this->carePlan->id);
            $payload = $this->buildCreateCarePlanContent($resource);
            $signedContent = signatureService()->signData($payload, $this->form->password, $this->form->knedp, $this->form->keyContainerUpload, Auth::user()->party->taxId);
            $result = $this->submitClinicalOperation($this->carePlan, ['status' => CarePlanStatus::PENDING->value], fn () => EHealth::carePlan()->create($this->carePlan->person->uuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']));
            if ($result === null) {
                $this->activateAfterSubmission = true;
                $this->planSubmission = $repository->submissionState($this->carePlan);

                return;
            }
            $this->refreshCarePlan();
            Session::flash('success', __('care-plan.signed_and_sent'));
            $this->showSignatureModal = false;
            $this->activateAfterSubmission = true;
            $this->continuePlanActivation();
        } catch (Throwable $exception) {
            report($exception);
            Session::flash('error', match (true) {
                $exception instanceof ValidationException => $exception->validator->errors()->first(),
                $exception instanceof EHealthValidationException => $exception->getFormattedMessage(),
                default => __('care-plan.unexpected_error'),
            });
            $this->planSubmission = $repository->submissionState($this->carePlan);
            if ($exception instanceof ValidationException) {
                $this->setErrorBag($exception->validator->getMessageBag());
            } else {
                $this->showSignatureModal = false;
            }
        }
    }

    public function completePlan(): void
    {
        $repository = Repository::carePlan();
        $this->authorizeCarePlanWrite();
        if ($this->guardPlanLifecycleAccess('complete')) {
            return;
        }
        $this->validate([
            'statusReason' => 'required|string'
        ]);

        if (empty($this->carePlan->uuid)) {
            Session::flash('error', __('care-plan.care_plan_not_synced'));
            $this->showSignatureModal = false;

            return;
        }

        $blockReason = $this->planCompleteBlockReason($this->carePlan);

        if ($blockReason !== null) {
            Session::flash('error', $blockReason);
            $this->showSignatureModal = false;

            return;
        }

        $statusReasonCodeableConcept = [
            'coding' => [
                [
                    'system' => 'eHealth/care_plan_complete_reasons',
                    'code' => $this->statusReason,
                ]
            ]
        ];

        try {
            $finalResponse = $this->submitClinicalOperation($this->carePlan, ['status' => CarePlanStatus::COMPLETED->value, 'statusReason' => $statusReasonCodeableConcept], fn () => app(CarePlanApi::class)->complete(
                $this->carePlan->person->uuid,
                $this->carePlan->uuid,
                [
                    'status_reason' => $statusReasonCodeableConcept,
                ]
            ));
            if ($finalResponse === null) {
                return;
            }

            $this->refreshCarePlan();

            session()->flash('success', __('care-plan.care_plan_completed'));
            $this->showSignatureModal = false;
            $this->redirectAfterCarePlanClosed();

        } catch (EHealthJobTimeoutException $exception) {
            $exception->report();
            Session::flash('error', $exception->getMessage());
            $this->showSignatureModal = false;
        } catch (EHealthConnectionException $exception) {
            Log::error('CarePlanShow: connection error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.connection_error'));
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            if (method_exists($exception, 'report')) {
                $exception->report();
            }
            Log::error('CarePlanShow: eHealth error: ' . $exception->getMessage(), [
                'details' => method_exists($exception, 'getDetails') ? $exception->getDetails() : null
            ]);
            $msg = $exception instanceof EHealthValidationException
                ? $exception->getFormattedMessage()
                : __('care-plan.ehealth_error_prefix') . $exception->getMessage();
            Session::flash('error', $msg);
        } catch (Throwable $exception) {
            Log::error('CarePlanShow: unexpected error: ' . $exception->getMessage());
            Session::flash('error', __('care-plan.unexpected_error'));
        }
    }

    protected function signActivity(CarePlanRepository $repository, CarePlanActivityRepository $activityRepository): void
    {
        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }
        $keepModalOpen = false;
        try {
            $activity = $this->ownedActivity((int) $this->activityToSign);
            if ($activity->status !== CarePlanStatus::DRAFT->value) {
                throw ValidationException::withMessages(['activityToSign' => __('care-plan.activity_signed')]);
            }
            if (method_exists($this, 'getDeviceSignReadinessWarning')) {
                $warning = $this->getDeviceSignReadinessWarning($activity);
                if ($warning !== null) {
                    throw ValidationException::withMessages(['activityToSign' => $warning]);
                }
            }
            $resource = $activityRepository->getResource($activity->id);
            if (data_get($resource, 'detail.kind') === 'service_request') {

                $this->activityForm->assertServiceUnit($this->carePlan, (string) data_get($resource, 'detail.quantity.code', ''));
                $this->activityForm->assertServicePeriod($this->carePlan, $resource['detail']);
            }
            $this->assertMedicationActivityProgramForSigning($resource);
            $this->assertDeviceActivityQuantityForSigning($resource);
            $payload = $this->buildCreateCarePlanActivityContent($resource);
            $signedContent = signatureService()->signData($payload, $this->form->password, $this->form->knedp, $this->form->keyContainerUpload, Auth::user()->party->taxId);
            $result = $this->submitClinicalOperation($activity, ['status' => 'scheduled'], fn () => EHealth::carePlanActivity()->create($this->carePlan->person->uuid, $this->carePlan->uuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']));
            if ($result === null) {
                $this->redirectRoute('care-plans.activities.show', [legalEntity(), $this->carePlan->id, $activity->id], navigate: true);

                return;
            }
            $this->syncPlanStatus();
            $this->refreshCarePlan();
            Session::flash('success', __('care-plan.activity_signed'));
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException) {
                $keepModalOpen = true;
                $this->setErrorBag($exception->validator->errors());
            }
            report($exception);
            Session::flash('error', $exception instanceof EHealthValidationException ? $exception->getFormattedMessage() : ($exception instanceof ValidationException ? $exception->validator->errors()->first() : __('care-plan.unexpected_error')));
        } finally {
            if (!$keepModalOpen) {
                $this->showSignatureModal = false;
            }
        }
    }

    protected function signStatusActivity(CarePlanActivityRepository $activityRepository): void
    {
        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }
        if (!$this->activityToSign) {
            Session::flash('error', __('care-plan.no_activity_selected'));

            return;
        }
        $activity = $this->ownedActivity((int) $this->activityToSign);
        $blockReason = $this->activityStatusChangeBlockReason($activity, $this->actionType);
        if ($blockReason !== null) {
            Session::flash('error', $blockReason);

            return;
        }
        try {
            $complete = $this->actionType === 'complete_activity';
            $reason = FhirResource::make()->coding($complete ? 'eHealth/care_plan_activity_complete_reasons' : 'eHealth/care_plan_activity_cancel_reasons', $this->statusReason)->toCodeableConcept();
            $api = EHealth::carePlanActivity();
            $outcomes = [];
            if ($complete) {
                $this->validate(['outcomeCode' => ['required', 'string']]);
                if ($this->outcomeReferences !== []) {
                    $this->addError('outcomeReferences', __('Для завершення оберіть результат із довідника. Посилання на записи не передаються цією операцією.'));

                    return;
                }
                $outcomes = [FhirResource::make()->coding('eHealth/care_plan_activity_outcomes', $this->outcomeCode)->toCodeableConcept()];
                $payload = $this->buildCompleteCarePlanActivityContent($reason, $outcomes);
                $result = $this->submitClinicalOperation($activity, ['status' => 'completed', 'statusReason' => $reason, 'outcomeCodeableConcept' => $outcomes], fn () => $api->complete($this->carePlan->person->uuid, $this->carePlan->uuid, $activity->uuid, $payload));
            } else {
                $snapshot = $this->fetchActivityCancelSnapshot($this->carePlan->person->uuid, $this->carePlan->uuid, $activity->uuid);
                $content = $this->buildCancelCarePlanActivityContent($snapshot, $reason);
                $signed = signatureService()->signData($content, $this->form->password, $this->form->knedp, $this->form->keyContainerUpload, Auth::user()->party->taxId);
                $result = $this->submitClinicalOperation($activity, ['status' => 'cancelled', 'statusReason' => $reason], fn () => $api->cancel($this->carePlan->person->uuid, $this->carePlan->uuid, $activity->uuid, ['signed_data' => $signed, 'signed_data_encoding' => 'base64']));
            }
            if ($result === null) {
                return;
            }
            $this->refreshCarePlan();
            Session::flash('success', __($complete ? 'care-plan.activity_completed' : 'care-plan.activity_cancelled'));
            $this->showSignatureModal = false;
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->getMessageBag());
        } catch (Throwable $exception) {
            report($exception);
            Session::flash('error', $exception instanceof EHealthValidationException ? $exception->getFormattedMessage() : __('care-plan.unexpected_error'));
            $this->showSignatureModal = false;
        }
    }

    public function openMethodSelectionModal(): void
    {
        if (!$this->guardApprovalAccess($this->carePlan)) {
            return;
        }
        if ($this->guardTerminalCarePlanMutation()) {
            return;
        }

        try {
            Repository::carePlan()->assertApprovalCreationReady($this->carePlan);
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());

            return;
        }

        if (empty($this->carePlan->uuid)) {
            Session::flash('error', __('План лікування ще не синхронізовано з ЕСОЗ.'));

            return;
        }

        if ($this->carePlan->skipsPatientOtp()) {
            $submission = Repository::carePlan()->latestSubmissionLink($this->carePlan);
            if ($submission === null) {
                $this->createApproval('');

                return;
            }
            try {
                $employeeUuid = $this->currentCarePlanEmployee()?->uuid;
                $bearerToken = session()->get(config('ehealth.api.oauth.bearer_token'));
                if (!$employeeUuid || !$bearerToken) {
                    throw new RuntimeException(__('care-plan.approval_create_error'));
                }
                Repository::carePlan()->queueInpatientApproval($submission, legalEntity(), Auth::user(), $bearerToken, $employeeUuid);
                $this->inpatientApprovalStatus = 'queued';
                Session::flash('info', __('care-plan.inpatient_access_processing'));
            } catch (Throwable $exception) {
                report($exception);
                Session::flash('error', __('care-plan.inpatient_access_failed'));
            }

            return;
        }

        try {
            $methods = EHealth::person()->getAuthMethods($this->carePlan->person->uuid)->validate();
            $this->authMethods = app(PatientApprovalService::class)
                ->enrichAuthenticationMethods($methods, $this->carePlan->person);
            $this->showMethodSelectionModal = true;
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to load auth methods: ' . $e->getMessage());
            Session::flash('error', __('Не вдалося завантажити методи аутентифікації'));
        }
    }

    protected function isCarePlanAlreadyCancelledError(Throwable $exception): bool
    {
        if ($exception instanceof EHealthValidationException) {
            return $exception->isCarePlanAlreadyCancelled();
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'cannot be cancelled')
            && str_contains($message, 'cancelled');
    }

    protected function redirectAfterCarePlanClosed(): void
    {
        $this->carePlan = Repository::carePlan()->findById($this->carePlan->id);

        $personId = $this->carePlan->personId;
        $encounterId = $this->carePlan->encounterId;

        if ($personId && $encounterId) {
            $this->redirectRoute('persons.encounters.package.show', [legalEntity(), $personId, $encounterId], navigate: true);

            return;
        }

        if ($personId) {
            $this->redirectRoute('persons.care-plans', [legalEntity(), $personId], navigate: true);
        }
    }

    public function selectAuthMethod(string $methodUuid): void
    {
        if (!$this->guardApprovalAccess($this->carePlan)) {
            return;
        }
        $this->currentAuthMethod = $this->selectedApprovalAuthMethod($methodUuid);
        $this->showMethodSelectionModal = false;
        $this->createApproval($methodUuid);
    }

    protected function createApproval(string $methodUuid): void
    {
        try {
            $employeeUuid = $this->currentCarePlanEmployee()?->uuid;

            if (!$employeeUuid) {
                Session::flash('error', __('Не вдалося визначити лікаря для створення дозволу.'));

                return;
            }

            $result = $this->createCarePlanApproval(
                carePlan: $this->carePlan,
                patientUuid: $this->carePlan->person->uuid,
                employeeUuid: $employeeUuid,
                accessLevel: 'write',
                authorizeWith: $this->carePlan->skipsPatientOtp() ? null : ($methodUuid ?: null),
                user: Auth::user(),
                bearerToken: session()->get(config('ehealth.api.oauth.bearer_token')),
            );

            if (($result['outcome']->value === 'async')) {
                $this->approvalId = $result['approvalId'];
                $this->pollingLinkId = $result['pollingLinkId'];
                $this->isPolling = true;
                Session::flash('info', __('care-plan.approval_access_processing'));

                return;
            }

            $this->approvalId = $result['approvalId'];

            if (in_array($result['outcome']->value, ['otp_required', 'offline_required'], true)) {
                $this->currentAuthMethod = $result['authMethod'] ?? $this->currentAuthMethod;
                $this->openAuthModal();

                return;
            }

            $this->refreshCarePlan();
            $this->dispatch('refreshApprovals');
            Session::flash(
                'success',
                $this->carePlan->skipsPatientOtp()
                    ? __('care-plan.approval_inpatient_granted')
                    : __('План лікування успішно активовано.')
            );
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to create approval: ' . $e->getMessage());
            Session::flash('error', __('Не вдалося створити запит на дозвіл: ') . $e->getMessage());
        }
    }

    public function checkApprovalJobStatus(): void
    {
        if (!$this->isPolling || !$this->pollingLinkId) {
            return;
        }

        $status = $this->resolveCarePlanApprovalJob($this->pollingLinkId);

        if (($status['outcome']->value === 'pending')) {
            return;
        }

        $this->isPolling = false;
        $this->pollingLinkId = null;

        if (($status['outcome']->value === 'failed')) {
            Session::flash('error', $status['errorMessage'] ?: __('Не вдалося обробити запит на дозвіл.'));

            return;
        }

        if ($status['approvalId']) {
            $this->approvalId = $status['approvalId'];
        }

        if (in_array($status['outcome']->value, ['otp_required', 'offline_required'], true)) {
            $this->currentAuthMethod = $status['authMethod'] ?? $this->currentAuthMethod;
            $this->openAuthModal();

            return;
        }

        $this->refreshCarePlan();
        $this->dispatch('refreshApprovals');
        Session::flash(
            'success',
            $this->carePlan->skipsPatientOtp()
                ? __('care-plan.approval_inpatient_granted')
                : __('План лікування успішно активовано.')
        );
    }

    public function verify(): void
    {
        if ($this->isSmsResending) {
            Session::flash('info', __('care-plan.sms_resend_processing'));

            return;
        }
        if (!$this->guardApprovalAccess($this->carePlan)) {
            return;
        }
        app(MedicalRequestOwnership::class)->approvalForCarePlan($this->carePlan, $this->approvalId ?? '');
        try {
            $this->validate($this->approvalVerificationRules(), $this->approvalVerificationMessages());
        } catch (ValidationException $exception) {
            Session::flash('error', $exception->validator->errors()->first());
            $this->setErrorBag($exception->validator->getMessageBag());

            return;
        }

        try {
            $this->verifyCarePlanApproval(
                $this->carePlan->person->uuid,
                $this->approvalId,
                $this->isOfflineAuthMethod() ? null : (int) $this->verificationCode,
            );
        } catch (Exception $e) {
            Log::warning('Care plan approval confirmation failed', ['exceptionType' => $e::class, 'statusCode' => $e->getCode()]);
            $message = $e instanceof EHealthValidationException ? $e->getFormattedMessage()
                : ($e instanceof EHealthResponseException && EHealth::approval()->errorMessage($e->response) === 'Invalid verification code'
                    ? __('errors.ehealth.messages.invalid_verification_code') : __('care-plan.approval_verify_error'));
            $this->addError('verificationCode', $message);
            Session::flash('error', $message);

            return;
        }
        $this->resetValidation('verificationCode');
        $this->closeAuthModal();
        try {
            $this->refreshCarePlan();
            $this->dispatch('refreshApprovals');
        } catch (Throwable $exception) {
            Log::warning('Verified care plan approval view could not be refreshed', ['exceptionType' => $exception::class]);
            Session::flash('warning', __('care-plan.approval_verified_refresh_pending'));

            return;
        }
        Session::flash('success', __('care-plan.approval_verified'));
    }

    protected function carePlanForSms(): CarePlan
    {
        return $this->carePlan;
    }

    protected function onSmsApprovalAlreadyGranted(): void
    {
        $this->refreshCarePlan();
        $this->dispatch('refreshApprovals');
    }

    #[Locked]
    public ?string $planSyncBatchId = null;

    public function sync(): void
    {
        if (Auth::user()->cannot('view', $this->carePlan)) {
            Session::flash('error', __('care-plan.sync_access_denied'));

            return;
        }
        if (empty($this->carePlan->uuid)) {
            Session::flash('error', __('care-plan.care_plan_not_synced'));

            return;
        }

        if ($this->planSyncBatchId !== null) {
            $this->checkPlanSyncStatus();

            return;
        }
        $token = session()->get(config('ehealth.api.oauth.bearer_token'));
        if (!is_string($token) || $token === '') {
            Session::flash('error', __('care-plan.sync_session_expired'));

            return;
        }
        $batch = Bus::batch([new CarePlanFullSync(
            legalEntity(),
            standalone: true,
            resourceUuid: $this->carePlan->uuid,
            patientContext: [
                'patient_uuid' => $this->carePlan->person->uuid,
                'person_id' => $this->carePlan->personId,
                'patient_care_plan_registry' => true,
                'care_plan_approvals' => true,
            ],
        )])
            ->withOption('legal_entity_id', legalEntity()->id)
            ->withOption('patient_uuid', $this->carePlan->person->uuid)
            ->withOption('person_id', $this->carePlan->personId)
            ->withOption('patient_care_plan_registry', true)
            ->withOption('care_plan_approvals', true)
            ->withOption('sync_entity', LegalEntity::ENTITY_CARE_PLAN)
            ->withOption('token', Crypt::encryptString($token))
            ->withOption('user', Auth::user())
            ->name(CarePlanFullSync::BATCH_NAME)->onQueue('sync')->dispatch();
        $this->planSyncBatchId = $batch->id;
        Session::flash('info', __('care-plan.sync_processing'));
    }

    public function checkPlanSyncStatus(): void
    {
        if ($this->planSyncBatchId === null) {
            return;
        }
        $batch = Bus::findBatch($this->planSyncBatchId);
        if ($batch !== null && !$batch->finished() && !$batch->cancelled()) {
            return;
        }
        $this->planSyncBatchId = null;
        $this->refreshCarePlan();
        $this->dispatch('refreshApprovals');
        if ($batch !== null && (int) Cache::get('care-plan-sync-denied:'.$batch->id, 0) > 0) {
            Session::flash('warning', __('care-plan.sync_partial_access', [
                'count' => (int) Cache::get('care-plan-sync-denied:'.$batch->id, 0),
            ]));

            return;
        }
        Session::flash(
            $batch !== null && !$batch->hasFailures() && !$batch->cancelled() ? 'success' : 'error',
            __($batch !== null && !$batch->hasFailures() && !$batch->cancelled() ? 'care-plan.data_synced' : 'care-plan.sync_error')
        );
    }

    protected function buildCarePlanStatusChangePayload(array $statusReasonCodeableConcept): array
    {
        return $this->buildCancelCarePlanContent($this->fetchCarePlanCancelSnapshot($this->carePlan->person->uuid, $this->carePlan->uuid), $statusReasonCodeableConcept);
    }

    public function syncPlanStatus(): bool
    {
        try {
            $this->synchronizeResources([['id' => $this->carePlan->uuid]], $this->carePlan->personId, $this->carePlan->legalEntityId, $this->carePlan->person->uuid);
            $this->syncCarePlanApprovals($this->carePlan);
            $this->refreshCarePlan();
            $this->dispatch('refreshApprovals');

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    #[Computed]
    public function serviceMinutesAllowed(): bool
    {
        return $this->carePlan->allowsServiceMinutes();
    }

    #[Computed]
    public function serviceCreationCapacity(): ?array
    {
        $product = $this->activityForm->product_reference ?? null;
        if (!is_string($product) || $product === '') {
            return null;
        }

        return Repository::carePlanActivity()->serviceCreationCapacity(
            $this->carePlan,
            $product,
            $this->activityForm->product_reference_type ?? 'service',
            filled($this->activityForm->id ?? null) ? (int) $this->activityForm->id : null
        );
    }

    public bool $confirmingActivityDeletion = false;

    #[Locked]
    public ?int $activityToDelete = null;

    #[Locked]
    public string $deviceSelectionWarning = '';

    #[Locked]
    public int $deviceSearchTotalPages = 1;

    #[Locked]
    public int $deviceSearchTotalEntries = 0;

    public string $deviceSearchModelNumber = '';

    #[Locked]
    public array $deviceQuantityCatalog = [];

    /** @var array<int, array<string, mixed>> */
    #[Locked]
    public array $deviceSearchCatalog = [];

    public function updatedSelectedProgram(): void
    {
        $this->activityForm->program = $this->selectedProgram;
        $kindLower = strtolower((string) ($this->activityForm->kind ?? ''));
        if (str_contains($kindLower, 'device') && $this->selectedProduct !== null && filled($this->selectedProgram)) {
            try {
                $this->loadDeviceQuantityCatalog($this->selectedProduct, $this->selectedProgram);
            } catch (Throwable $exception) {
                $this->deviceQuantityCatalog = [];
                session()->flash('error', __('care-plan.device_search_error'));
            }
        }

        if (!empty($this->activityForm->id)) {
            $this->selectedProduct = null;
            $this->activityForm->product_reference = '';
            $this->activityForm->product_codeable_concept = '';
        }

        $this->searchQuery = '';
        $this->searchResults = [];
        $this->searchPage = 1;

        if ($this->showMedicalDeviceSearchDrawer) {
            $this->loadMedicalDeviceSearchResults();
        }
    }

    public function initActivityForm(string $kind): void
    {
        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }
        if ($this->guardTerminalCarePlanMutation()) {
            return;
        }

        $this->resetActivitySelectionState($kind);

        $this->activityForm->reset();
        $this->activityForm->fill([
            'id' => null,
            'kind' => $kind,
            'program' => $this->selectedProgram,
            'quantity' => '1',
            'quantity_system' => str_contains(strtolower($kind), 'medication')
                ? 'MEDICATION_UNIT'
                : (str_contains(strtolower($kind), 'device') ? 'device_unit' : 'SERVICE_UNIT'),
            'quantity_code' => str_contains(strtolower($kind), 'device') ? '' : 'PIECE',
            'daily_amount' => str_contains(strtolower($kind), 'medication') ? '1' : '',
            'daily_amount_system' => str_contains(strtolower($kind), 'medication') ? 'MEDICATION_UNIT' : '',
            'daily_amount_code' => str_contains(strtolower($kind), 'medication') ? 'PIECE' : '',
            'reason_code' => '',
            'reason_reference' => '',
            'goal' => '',
            'description' => '',
            'scheduled_period_start' => now()->format('d.m.Y'),
            'scheduled_period_end' => now()->addDays(10)->format('d.m.Y'),
            'product_reference' => '',
            'product_codeable_concept' => '',
        ]);
    }

    public function editActivity(int $activityId): void
    {
        $repository = Repository::carePlanActivity();
        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }
        $activity = $this->ownedActivity($activityId);
        if (!$activity) {
            return;
        }

        $this->activityForm->reset();
        $this->activityForm->fill([
            'id' => $activity->id,
            'kind' => $activity->resolvedKind(),
            'program' => $activity->program ?? '',
            'quantity' => is_array($activity->quantity) ? ($activity->quantity['value'] ?? '') : $activity->quantity,
            'quantity_system' => $activity->quantitySystem ?? '',
            'quantity_code' => $activity->quantityCode ?? '',
            'daily_amount' => $activity->dailyAmount ?? '',
            'daily_amount_system' => $activity->dailyAmountSystem ?? '',
            'daily_amount_code' => $activity->dailyAmountCode ?? '',
            'reason_code' => $activity->reasonCode ?? '',
            'reason_reference' => $activity->reasonReference ?? '',
            'goal' => is_array($activity->goal) ? (string) ($activity->goal[0] ?? '') : (string) ($activity->goal ?? ''),
            'description' => $activity->description ?? '',
            'scheduled_period_start' => $activity->scheduledPeriodStart?->format('d.m.Y') ?? '',
            'scheduled_period_end' => $activity->scheduledPeriodEnd?->format('d.m.Y') ?? '',
            'product_reference' => $activity->productReference ?? '',
            'product_reference_type' => $activity->productReferenceType,
            'product_codeable_concept' => $activity->productCodeableConcept ?? '',
        ]);

        // Load pre-selected product info
        $this->selectedProduct = null;
        if (!empty($activity->productReference)) {
            try {
                $kindLower = strtolower($this->activityForm->kind);
                if (str_contains($kindLower, 'service')) {
                    $this->selectedProduct = dictionary()->services()->flattened()
                        ->firstWhere('id', $activity->productReference);
                } elseif (str_contains($kindLower, 'medication')) {
                    $programId = $this->activityForm->program ?? $activity->program;
                    $filters = ['innm_dosage_id' => $activity->productReference];
                    if (!empty($programId)) {
                        $filters['medical_program_id'] = $programId;
                    }
                    $response = EHealth::drug()->getMany($filters);
                    $data = $response->validate();
                    if (empty($data)) {
                        $response = EHealth::drug()->getMany(['innm_id' => $activity->productReference]);
                        $data = $response->validate();
                    }
                    if (!empty($data)) {
                        $this->selectedProduct = $data[0];
                    }
                } elseif (str_contains($kindLower, 'device')) {
                    $programId = $this->activityForm->program ?? $activity->program;
                    $filters = !empty($programId) ? ['medical_program_id' => $programId] : [];
                    $reference = (string) $activity->productReference;
                    $devices = $this->resolveDevicesByExactUuid($reference, $filters);
                    $this->selectedProduct = isset($devices[0]) ? $this->compactDeviceForState($devices[0]) : null;
                    if ($this->selectedProduct === null && $reference !== '') {
                        $this->selectedProduct = [
                            'id' => $reference,
                            'uuid' => $reference,
                            'name' => __('care-plan.device_name_unavailable'),
                        ];
                    }
                }
            } catch (Exception $e) {
                Log::warning('CarePlanShow: failed to preload product reference: ' . $e->getMessage());
            }
        }

        // Initialize linked justification grounds
        $this->linkedGrounds = [];
        if (!empty($activity->reasonReference)) {
            foreach ($activity->reasonReference as $ref) {
                $parts = explode('/', $ref);
                if (count($parts) === 2) {
                    $this->addLinkedGround($parts[0], $parts[1]);
                } else {
                    $uuid = $ref;
                    if (collect($this->availableConditions)->contains('uuid', $uuid)) {
                        $this->addLinkedGround('Condition', $uuid);
                    } elseif (collect($this->availableReports)->contains('uuid', $uuid)) {
                        $this->addLinkedGround('DiagnosticReport', $uuid);
                    } elseif (collect($this->availableObservations)->contains('uuid', $uuid)) {
                        $this->addLinkedGround('Observation', $uuid);
                    } else {
                        $this->addLinkedGround('Condition', $uuid);
                    }
                }
            }
        }

        $kindLower = strtolower($this->activityForm->kind);
        $this->selectedProgram = $activity->program ?? '';
        if ($this->selectedProgram === '') {
            $this->selectedProgram = match (true) {
                str_contains($kindLower, 'medication') => $this->resolveMedicationProgramId() ?? '',
                // Keep empty for devices — program is optional.
                default => '',
            };
        }
        $this->activityForm->program = $this->selectedProgram;

        if (str_contains($kindLower, 'service')) {
            $this->showServiceDrawer = true;
        } elseif (str_contains($kindLower, 'medication')) {
            $this->showMedicationFormDrawer = true;
        } elseif (str_contains($kindLower, 'device')) {
            $this->showMedicalDeviceFormDrawer = true;
        } else {
            $this->showServiceDrawer = true;
        }
    }

    public function openMedicalDeviceSearch(): void
    {
        // Program is optional: empty selectedProgram means a device request without
        // medical program (usable as Encounter Package based_on later).
        $this->activityForm->program = filled($this->selectedProgram) ? $this->selectedProgram : null;
        $this->showMedicalDeviceDrawer = false;
        $this->showMedicalDeviceSearchDrawer = true;
        $this->searchPage = 1;
        $this->loadMedicalDeviceSearchResults();
    }

    public function resetDeviceSearchFilters(): void
    {
        $this->searchQuery = '';
        $this->deviceSearchModelNumber = '';
        $this->searchPage = 1;
        $this->loadMedicalDeviceSearchResults();
    }

    public function goToDeviceSearchPage(int $page): void
    {
        $this->searchPage = max(1, $page);
        $this->paginateDeviceSearchResults();
    }

    public function updatedSearchQuery(): void
    {
        if (!$this->showMedicalDeviceSearchDrawer) {
            return;
        }

        $this->searchPage = 1;
        $this->loadMedicalDeviceSearchResults();
    }

    public function updatedDeviceSearchModelNumber(): void
    {
        if (!$this->showMedicalDeviceSearchDrawer) {
            return;
        }

        $this->searchPage = 1;
        $this->loadMedicalDeviceSearchResults();
    }

    public function confirmDeleteActivity(int $activityId): void
    {
        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }
        if ($this->guardTerminalCarePlanMutation()) {
            return;
        }

        $this->activityToDelete = $activityId;
        $this->confirmingActivityDeletion = true;
    }

    public function cancelDeleteActivity(): void
    {
        $this->confirmingActivityDeletion = false;
        $this->activityToDelete = null;
    }

    public function deleteActivity(int $activityId): void
    {
        $repository = Repository::carePlanActivity();
        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }
        $activity = $repository->findById($activityId);
        if (!$activity || $activity->carePlanId !== $this->carePlan->id) {
            Session::flash('error', __('care-plan.activity_not_found'));
            $this->cancelDeleteActivity();

            return;
        }

        $statusVal = $activity->status instanceof UnitEnum
            ? $activity->status->value
            : $activity->status;
        $activityStatus = strtolower(is_array($statusVal)
            ? ($statusVal['coding'][0]['code'] ?? ($statusVal['text'] ?? ''))
            : (string) $statusVal);

        if ($activityStatus !== CarePlanStatus::DRAFT->value) {
            Session::flash('error', __('care-plan.activity_delete_only_draft'));
            $this->cancelDeleteActivity();

            return;
        }

        if (!$repository->deleteById($activityId)) {
            Session::flash('error', __('care-plan.activity_delete_has_referrals'));
            $this->cancelDeleteActivity();

            return;
        }

        $this->cancelDeleteActivity();
        Session::flash('success', __('care-plan.activity_deleted'));
        $this->refreshCarePlan();
    }

    public function saveActivity(): void
    {
        $repository = Repository::carePlanActivity();
        $this->persistActivityDraft($repository, andSign: false);
    }

    /**
     * Persist the local activity draft, then open the KEP modal for sign_activity.
     */
    public function saveActivityAndSign(): void
    {
        $repository = Repository::carePlanActivity();
        $this->persistActivityDraft($repository, andSign: true);
    }

    /**
     * @return void
     */
    protected function persistActivityDraft(CarePlanActivityRepository $repository, bool $andSign): void
    {
        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }
        $usesAlternativeSchedule = false;
        if (!empty($this->activityForm->id)) {
            $activity = $this->ownedActivity((int) $this->activityForm->id);
            $usesAlternativeSchedule = $activity->scheduledTiming !== null || $activity->scheduledString !== null;
            if ($activity->status !== CarePlanStatus::DRAFT->value) {
                Session::flash('error', __('care-plan.activity_delete_only_draft'));

                return;
            }
        }
        $kindLower = strtolower((string) ($this->activityForm->kind ?? ''));
        if (str_contains($kindLower, 'medication')) {
            $this->activityForm->program = $this->resolveMedicationProgramId();
        } elseif (str_contains($kindLower, 'device')) {
            // Keep explicit empty — do not auto-pick a default device program.
            $this->activityForm->program = filled($this->selectedProgram) ? $this->selectedProgram : null;
        } elseif (!empty($this->selectedProgram)) {
            $this->activityForm->program = $this->selectedProgram;
        }

        $this->syncDeviceProductReferenceFromSelection();

        $programId = $this->activityForm->program ?? ($this->selectedProgram ?? null);
        if (str_contains($kindLower, 'service')) {
            $this->activityForm->quantity_system = 'SERVICE_UNIT';
        }
        $rules = $this->activityForm->rulesForDraft(
            $usesAlternativeSchedule,
            $this->carePlan->termsOfServiceCode(),
            str_contains($kindLower, 'device') ? $this->resolveDeviceRequestAllowedCodeTypes($programId) : []
        );

        $programPayload = $this->resolveMedicalProgramPayload(is_string($programId) ? $programId : null);

        if (str_contains($kindLower, 'medication') && ($programPayload === null || ($programPayload['type'] ?? null) !== 'MEDICATION')) {
            $message = __('care-plan.medication_program_required');
            Session::flash('error', $message);
            $this->addError('activityForm.program', $message);

            return;
        }
        if ($programPayload !== null) {
            $providingBlock = $this->carePlan->providingConditionsBlockReason($programPayload);
            if ($providingBlock !== null) {
                Session::flash('error', $providingBlock);
                $this->addError('activityForm.program', $providingBlock);

                return;
            }

            $codings = collect($this->carePlan->addresses ?? [])->flatMap(
                static fn (array $address): array => $address['coding'] ?? []
            )->all();
            if (!MedicationProgramPicker::matchesDiagnosis($programPayload, $codings)) {
                $message = __('care-plan.medical_program_diagnosis_mismatch');
                Session::flash('error', $message);
                $this->addError('activityForm.program', $message);

                return;
            }
        }

        $rehabBlock = $this->carePlan->rehabReasonReferenceBlockReason($this->linkedGrounds);
        if ($rehabBlock !== null) {
            Session::flash('error', $rehabBlock);
            $this->addError('linkedGrounds', $rehabBlock);

            return;
        }

        try {
            $validated = ['activityForm' => $this->activityForm->validate($rules)];
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            Session::flash('error', $exception->validator->errors()->first());

            return;
        }

        $activityStart = convertToYmd($validated['activityForm']['scheduled_period_start'] ?? '');
        $activityEnd = convertToYmd($validated['activityForm']['scheduled_period_end'] ?? '');
        $periodError = $activityStart === '' && $activityEnd === ''
            ? null : $this->validateActivityPeriodAgainstCarePlan($activityStart, $activityEnd);
        if ($periodError !== null) {
            Session::flash('error', $periodError);
            $this->addError('activityForm.scheduled_period_start', $periodError);

            return;
        }

        if (str_contains($kindLower, 'medication')) {
            $product = $this->selectedProduct;

            if (empty($product) && !empty($this->activityForm->product_reference)) {
                try {
                    $response = EHealth::drug()->getMany(['innm_dosage_id' => $this->activityForm->product_reference]);
                    $data = $response->validate();
                    if (empty($data)) {
                        $response = EHealth::drug()->getMany(['innm_id' => $this->activityForm->product_reference]);
                        $data = $response->validate();
                    }
                    if (!empty($data)) {
                        $product = $data[0];
                    }
                } catch (Throwable $e) {
                    // ignore
                }
            }

            if (!empty($product)) {
                $expectedUnit = $this->resolveMedicationDenumeratorUnit($product);
                $quantityCode = strtoupper((string) ($validated['activityForm']['quantity_code'] ?? ''));
                if ($quantityCode !== strtoupper($expectedUnit)) {
                    $message = __('care-plan.medication_unit_mismatch', ['unit' => $expectedUnit]);
                    Session::flash('error', $message);
                    $this->addError('activityForm.quantity_code', $message);

                    return;
                }

                $packageStep = (float) ($product['packages'][0]['package_min_qty'] ?? 0);
                if ($packageStep <= 0) {
                    $packageStep = (float) ($product['packages'][0]['package_qty'] ?? 0);
                }
                $quantity = (float) ($validated['activityForm']['quantity'] ?? 0);
                if ($packageStep > 0) {
                    $quotient = $quantity / $packageStep;
                    if (abs($quotient - round($quotient)) > 1e-6) {
                        $message = __('care-plan.medication_qty_packaging', ['count' => $packageStep]);
                        Session::flash('error', $message);
                        $this->addError('activityForm.quantity', $message);

                        return;
                    }
                }
            } elseif (!empty($this->activityForm->product_reference)) {
                $message = __('Не вдалося перевірити одиниці виміру препарату. Будь ласка, знайдіть і оберіть препарат зі списку ще раз.');
                Session::flash('error', $message);
                $this->addError('activityForm.quantity_code', $message);

                return;
            }
        }

        if (str_contains($kindLower, 'device') && !empty($this->selectedProduct)) {
            $programForDevice = $validated['activityForm']['program']
                ?? ($this->activityForm->program
                    ?? (filled($this->selectedProgram) ? $this->selectedProgram : null));
            // Program participation constraints only apply when a medical program is chosen.
            if (filled($programForDevice)) {
                $guard = app(DeviceProgramParticipationGuard::class);
                if (!$guard->deviceAllowsCarePlanActivity($this->selectedProduct, $programForDevice)) {
                    $message = __('care-plan.device_care_plan_activity_not_allowed');
                    Session::flash('error', $message);
                    $this->addError('activityForm.product_reference', $message);

                    return;
                }
            }

            $packaging = $this->selectedProduct['packaging'] ?? [];
            $packagingCount = (int) ($packaging['packaging_count'] ?? 0);
            $packagingUnit = isset($packaging['packaging_unit'])
                ? $this->normalizeDeviceUnitCode((string) $packaging['packaging_unit'])
                : null;
            $quantity = (int) ($validated['activityForm']['quantity'] ?? 0);
            $quantityCode = $this->normalizeDeviceUnitCode((string) ($validated['activityForm']['quantity_code'] ?? ''));

            if ($packagingUnit !== null && $quantityCode !== '' && strcasecmp($packagingUnit, $quantityCode) !== 0) {
                $message = __('care-plan.device_quantity_unit_mismatch', ['unit' => $packagingUnit]);
                Session::flash('error', $message);
                $this->addError('activityForm.quantity_code', $message);

                return;
            }

            if (!filled($programForDevice) && $packagingCount > 0 && $quantity % $packagingCount !== 0) {
                $message = __('care-plan.device_quantity_packaging', ['count' => $packagingCount]);
                Session::flash('error', $message);
                $this->addError('activityForm.quantity', $message);

                return;
            }

            if (filled($programForDevice)) {
                try {
                    $this->loadDeviceQuantityCatalog($this->selectedProduct, $programForDevice);
                    $options = $this->deviceQuantityOptions();
                } catch (Throwable) {
                    $options = [];
                }
                if (!in_array($quantity, $options, true)) {
                    $message = __('care-plan.device_quantity_options_required');
                    Session::flash('error', $message);
                    $this->addError('activityForm.quantity', $message);

                    return;
                }
            }

            // Persist dictionary-normalized unit code for the eHealth payload.
            if ($packagingUnit !== null) {
                $this->activityForm->quantity_code = $packagingUnit;
                $validated['activityForm']['quantity_code'] = $packagingUnit;
            }
            $this->activityForm->quantity_system = 'device_unit';
            $validated['activityForm']['quantity_system'] = 'device_unit';
        }

        // Compile reason reference identifiers from linked justifications
        $reasonReferences = collect($this->linkedGrounds)->map(fn ($g) => $g['type'] . '/' . $g['uuid'])->toArray();

        $program = !empty($validated['activityForm']['program']) ? $validated['activityForm']['program'] : null;
        if (str_contains(strtolower($validated['activityForm']['kind']), 'medication') && empty($program)) {
            $program = $this->resolveMedicationProgramId();
        }
        // Device program stays null when intentionally omitted (Encounter based_on path).

        $medicationUnit = str_contains($kindLower, 'medication')
            ? ($validated['activityForm']['quantity_code'] ?? null)
            : null;

        $activityData = [
            'kind' => $validated['activityForm']['kind'],
            'quantity' => !empty($validated['activityForm']['quantity']) ? $validated['activityForm']['quantity'] : null,
            'quantity_system' => !empty($validated['activityForm']['quantity_system']) ? $validated['activityForm']['quantity_system'] : null,
            'quantity_code' => !empty($validated['activityForm']['quantity_code']) ? $validated['activityForm']['quantity_code'] : null,
            'daily_amount' => !empty($validated['activityForm']['daily_amount']) ? $validated['activityForm']['daily_amount'] : null,
            'daily_amount_system' => $medicationUnit ? 'MEDICATION_UNIT' : null,
            'daily_amount_code' => $medicationUnit,
            'description' => !empty($validated['activityForm']['description']) ? $validated['activityForm']['description'] : null,
            'product_reference' => !empty($validated['activityForm']['product_reference']) ? $validated['activityForm']['product_reference'] : null,
            'product_reference_type' => $this->activityForm->product_reference_type ?? null,
            'product_codeable_concept' => !empty($this->activityForm->product_codeable_concept) ? $this->activityForm->product_codeable_concept : null,
            'program' => $program,
            'reason_code' => !empty($validated['activityForm']['reason_code']) ? $validated['activityForm']['reason_code'] : null,
            'reason_reference' => !empty($reasonReferences) ? $reasonReferences : null,
            'goal' => !empty($validated['activityForm']['goal'])
                ? [(string) $validated['activityForm']['goal']]
                : null,
            'scheduled_period_start' => $activityStart,
            'scheduled_period_end' => $activityEnd,
        ];

        if ($usesAlternativeSchedule) {
            if ($activityStart !== '' || $activityEnd !== '') {
                $this->addError('activityForm.scheduled_period_start', __('Збережено інший тип розкладу. Зміна типу розкладу потребує окремого редактора.'));

                return;
            }
            unset($activityData['scheduled_period_start'], $activityData['scheduled_period_end']);
        }

        try {
            if (!empty($this->activityForm->id)) {
                $activityId = (int) $this->activityForm->id;
                $this->saveActivityDraft($activityData, $this->carePlan, $activityId);
                Session::flash('success', __('care-plan.activity_updated'));
            } else {
                $activityData['care_plan_id'] = $this->carePlan->id;
                $activityData['author_id'] = Auth::user()?->getCarePlanWriterEmployee($this->carePlan->termsOfServiceCode())?->id;
                $activityData['status'] = CarePlanStatus::DRAFT->value;

                $created = $this->saveActivityDraft($activityData, $this->carePlan);
                $activityId = (int) $created->id;
                Session::flash('success', __('care-plan.activity_draft_saved'));
            }
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            Session::flash('error', $exception->validator->errors()->first());

            return;
        }

        $this->activityForm->id = $activityId;
        $this->refreshCarePlan();

        // Close drawers
        $this->dispatch('close-drawers');

        if ($andSign) {
            $this->openSignatureModal('sign_activity', $activityId);
        }
    }

    public function searchServices(): void
    {
        if (empty($this->searchQuery)) {
            $this->searchResults = [];

            return;
        }

        try {
            $this->searchResults = ServiceSearch::search(
                trim($this->searchQuery),
                static fn (array $params): array => EHealth::service()->getMany($params)->validate(),
                $this->searchPage
            );
        } catch (Exception $e) {
            Log::error("Failed to search services: " . $e->getMessage());
            $this->searchResults = [];
        }
    }

    public function searchMedications(): void
    {
        if (empty($this->searchQuery)) {
            $this->searchResults = [];

            return;
        }

        try {
            $filters = [
                'innm_name' => $this->searchQuery,
                'page' => $this->searchPage,
                'page_size' => 15,
            ];

            $filters['medical_program_id'] = $this->resolveMedicationProgramId();

            $response = EHealth::drug()->getMany($filters);

            $this->searchResults = $response->validate();
        } catch (Exception $e) {
            Log::error("Failed to search medications: " . $e->getMessage());
            $this->searchResults = [];
        }
    }

    public function searchMedicalDevices(): void
    {
        $this->searchPage = 1;
        $this->loadMedicalDeviceSearchResults();
    }

    protected function loadMedicalDeviceSearchResults(): void
    {
        $programId = $this->resolveDeviceProgramId();

        try {
            $query = trim($this->searchQuery);
            $filters = [];
            if (filled($programId)) {
                $filters['medical_program_id'] = $programId;
            }

            $modelNumber = trim($this->deviceSearchModelNumber);
            if ($modelNumber !== '') {
                $filters['model_number'] = $modelNumber;
            }

            $uuidQuery = $this->normalizeDeviceUuidQuery($query);

            // Exact UUID search must never fall through to the full program catalog.
            if ($uuidQuery !== null) {
                $devices = $this->resolveDevicesByExactUuid($uuidQuery, $filters);
            } else {
                $devices = $this->fetchAllDeviceDefinitions($filters);
                if ($query !== '') {
                    $devices = $this->filterDevicesByQuery($devices, $query);
                }
            }

            $devices = $this->sortDeviceSearchResults($devices, $query);
            $guard = app(DeviceProgramParticipationGuard::class);
            $this->deviceSearchCatalog = array_values(array_filter(
                array_map(
                    fn (array $device): array => $this->compactDeviceForState($device),
                    $devices
                ),
                function (array $device) use ($guard, $programId, $uuidQuery): bool {
                    if ($uuidQuery !== null) {
                        return $this->deviceMatchesUuid($device, $uuidQuery);
                    }

                    // Without a program, only require an active device definition.
                    if (!filled($programId)) {
                        $isActive = $device['is_active'] ?? ($device['isActive'] ?? true);

                        return filter_var($isActive, FILTER_VALIDATE_BOOLEAN);
                    }

                    return $guard->deviceAllowsCarePlanActivity($device, $programId);
                }
            ));

            $perPage = 20;
            $this->deviceSearchTotalEntries = count($this->deviceSearchCatalog);
            $this->deviceSearchTotalPages = max(1, (int) ceil($this->deviceSearchTotalEntries / $perPage));

            if ($this->searchPage > $this->deviceSearchTotalPages) {
                $this->searchPage = $this->deviceSearchTotalPages;
            }

            $this->paginateDeviceSearchResults();
        } catch (Exception $e) {
            Log::error('Failed to search medical devices: ' . $e->getMessage());
            $this->searchResults = [];
            $this->deviceSearchCatalog = [];
            $this->deviceQuantityCatalog = [];
            $this->deviceSearchTotalEntries = 0;
            $this->deviceSearchTotalPages = 1;
        }
    }

    protected function paginateDeviceSearchResults(): void
    {
        $perPage = 20;
        $offset = ($this->searchPage - 1) * $perPage;
        $this->searchResults = array_slice($this->deviceSearchCatalog, $offset, $perPage);
    }

    protected function fetchAllDeviceDefinitions(array $filters): array
    {
        $pageSize = (int) config('ehealth.api.page_size', 300);
        $page = 1;
        $all = [];
        $seenIds = [];

        do {
            $response = EHealth::deviceDefinition()->getMany(array_merge($filters, [
                'page' => $page,
                'page_size' => $pageSize,
            ]));

            $items = $response->validate();
            foreach ($items as $item) {
                if (isset($seenIds[$item['id']])) {
                    throw ValidationException::withMessages(['paging' => __('care-plan.device_search_error')]);
                }
                $seenIds[$item['id']] = true;
            }
            $all = array_merge($all, $items);
            $page++;
            $hasMore = $response->isNotLast();
        } while ($hasMore);

        $indexed = [];
        foreach ($all as $device) {
            $id = (string) ($device['id'] ?? ($device['uuid'] ?? ''));
            if ($id !== '') {
                $indexed[$id] = $device;
            }
        }

        return array_values($indexed);
    }

    protected function normalizeDeviceUuidQuery(string $query): ?string
    {
        $normalized = strtolower(trim($query));
        $normalized = trim($normalized, '{}');
        // Strip zero-width / BOM characters that often sneak in when pasting UUIDs.
        $normalized = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', '', $normalized) ?? $normalized;

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    /**
     * Resolve at most one device definition for an exact UUID search.
     *
     * @param  array<string, mixed>  $programFilters
     * @return list<array<string, mixed>>
     */
    protected function resolveDevicesByExactUuid(string $uuid, array $programFilters): array
    {
        $deviceById = $this->fetchDeviceDefinitionByUuid($uuid);
        if ($deviceById !== null) {
            return [$deviceById];
        }

        return $this->filterDevicesByUuid(
            $this->fetchAllDeviceDefinitions($programFilters),
            $uuid
        );
    }

    /**
     * @return list<string>
     */
    protected function extractDeviceUuids(array $device): array
    {
        $values = [];
        foreach (['id', 'uuid', 'external_id'] as $key) {
            $value = $device[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    protected function deviceMatchesUuid(array $device, string $uuid): bool
    {
        foreach ($this->extractDeviceUuids($device) as $candidate) {
            if (strcasecmp($candidate, $uuid) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $devices
     * @return list<array<string, mixed>>
     */
    protected function filterDevicesByUuid(array $devices, string $uuid): array
    {
        return array_values(array_filter(
            $devices,
            fn (array $device): bool => $this->deviceMatchesUuid($device, $uuid)
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fetchDeviceDefinitionByUuid(string $uuid): ?array
    {
        try {
            $data = EHealth::deviceDefinition()->getById($uuid)->validate();
            if (!is_array($data) || $data === []) {
                return null;
            }

            if (isset($data['id']) || isset($data['uuid'])) {
                return $this->deviceMatchesUuid($data, $uuid) ? $data : null;
            }

            $first = $data[0] ?? null;
            if (is_array($first) && $this->deviceMatchesUuid($first, $uuid)) {
                return $first;
            }
        } catch (Throwable $exception) {
            Log::debug('Care plan device UUID lookup failed: ' . $exception->getMessage(), [
                'uuid' => $uuid,
            ]);
        }

        return null;
    }

    protected function filterDevicesByQuery(array $devices, string $query): array
    {
        $needle = mb_strtolower($query);

        return array_values(array_filter($devices, function (array $device) use ($needle): bool {
            $haystacks = array_merge(
                [
                    $this->resolveDeviceDisplayName($device),
                    (string) ($device['model_number'] ?? ''),
                ],
                $this->extractDeviceUuids($device),
            );

            foreach ($device['device_names'] ?? [] as $deviceName) {
                if (is_array($deviceName) && !empty($deviceName['name'])) {
                    $haystacks[] = (string) $deviceName['name'];
                }
            }

            foreach ($device['classification_types'] ?? [] as $classificationType) {
                if (is_array($classificationType)) {
                    $haystacks[] = (string) ($classificationType['name'] ?? '');
                    $haystacks[] = (string) ($classificationType['code'] ?? '');
                }
            }

            foreach ($haystacks as $haystack) {
                if ($haystack !== '' && mb_stripos($haystack, $needle) !== false) {
                    return true;
                }
            }

            return false;
        }));
    }

    protected function sortDeviceSearchResults(array $devices, string $query): array
    {
        usort($devices, function (array $left, array $right) use ($query): int {
            if ($query !== '') {
                $leftScore = $this->deviceSearchRelevanceScore($left, $query);
                $rightScore = $this->deviceSearchRelevanceScore($right, $query);

                if ($leftScore !== $rightScore) {
                    return $rightScore <=> $leftScore;
                }
            }

            return strcasecmp(
                $this->resolveDeviceDisplayName($left),
                $this->resolveDeviceDisplayName($right)
            );
        });

        return $devices;
    }

    protected function deviceSearchRelevanceScore(array $device, string $query): int
    {
        $needle = mb_strtolower($query);
        $name = mb_strtolower($this->resolveDeviceDisplayName($device));
        $modelNumber = mb_strtolower((string) ($device['model_number'] ?? ''));
        $uuids = array_map(strtolower(...), $this->extractDeviceUuids($device));

        if (in_array($needle, $uuids, true)) {
            return 1000;
        }

        if ($name === $needle || $modelNumber === $needle) {
            return 900;
        }

        if (str_starts_with($name, $needle) || str_starts_with($modelNumber, $needle)) {
            return 700;
        }

        if (mb_stripos($name, $needle) !== false) {
            return 500;
        }

        if (mb_stripos($modelNumber, $needle) !== false) {
            return 400;
        }

        return 100;
    }

    protected function enrichDeviceForDisplay(array $device): array
    {
        $device['display_name'] = $this->resolveDeviceDisplayName($device);
        $device['display_packaging'] = $this->formatDevicePackaging($device);
        $device['display_type'] = $this->resolveDeviceTypeName($device);
        $device['display_code'] = $this->resolveDeviceClassificationCode($device) ?? '-';
        $device['display_uuid'] = $this->extractDeviceUuids($device)[0] ?? '';

        return $device;
    }

    /**
     * Keep only fields needed for selection/form UI so Livewire snapshots stay small.
     *
     * @param  array<string, mixed>  $device
     * @return array<string, mixed>
     */
    protected function compactDeviceForState(array $device): array
    {
        $enriched = $this->enrichDeviceForDisplay($device);
        $id = (string) ($enriched['id'] ?? ($enriched['uuid'] ?? ($enriched['display_uuid'] ?? '')));

        return [
            'id' => $id !== '' ? $id : null,
            'uuid' => (string) ($enriched['uuid'] ?? $id),
            'name' => $enriched['display_name'],
            'display_name' => $enriched['display_name'],
            'display_packaging' => $enriched['display_packaging'],
            'display_type' => $enriched['display_type'],
            'display_code' => $enriched['display_code'],
            'display_uuid' => $enriched['display_uuid'] !== '' ? $enriched['display_uuid'] : $id,
            'model_number' => $enriched['model_number'] ?? null,
            'packaging' => is_array($enriched['packaging'] ?? null) ? $enriched['packaging'] : null,
            'classification_types' => is_array($enriched['classification_types'] ?? null)
                ? $enriched['classification_types']
                : null,
            'device_names' => is_array($enriched['device_names'] ?? null) ? $enriched['device_names'] : null,
            'program_devices' => is_array($enriched['program_devices'] ?? null) ? $enriched['program_devices'] : null,
            'is_active' => $enriched['is_active'] ?? ($enriched['isActive'] ?? true),
        ];
    }

    protected function resolveDeviceDisplayName(array $device): string
    {
        if (!empty($device['name']) && is_string($device['name'])) {
            return $device['name'];
        }

        $deviceNames = $device['device_names'] ?? [];
        if (is_array($deviceNames)) {
            foreach ($deviceNames as $deviceName) {
                if (is_array($deviceName) && !empty($deviceName['name'])) {
                    return (string) $deviceName['name'];
                }
            }
        }

        if (!empty($device['description']) && is_string($device['description'])) {
            return $device['description'];
        }

        return (string) ($device['model_number'] ?? ($device['id'] ?? ''));
    }

    protected function resolveDeviceTypeName(array $device): string
    {
        if (!empty($device['type_name'])) {
            return (string) $device['type_name'];
        }

        if (!empty($device['classification_type_name'])) {
            return (string) $device['classification_type_name'];
        }

        $classificationTypes = $device['classification_types'] ?? [];
        if (is_array($classificationTypes) && !empty($classificationTypes[0]['name'])) {
            return (string) $classificationTypes[0]['name'];
        }

        return '-';
    }

    protected function formatDevicePackaging(array $device): string
    {
        $packaging = $device['packaging'] ?? null;
        if (!is_array($packaging)) {
            if (is_string($device['package_description'] ?? null)) {
                return $device['package_description'];
            }

            return '-';
        }

        $parts = array_filter([
            $packaging['packaging_type'] ?? null,
            isset($packaging['packaging_count']) ? (string) $packaging['packaging_count'] : null,
            $packaging['packaging_unit'] ?? null,
        ]);

        return $parts !== [] ? implode(' ', $parts) : '-';
    }

    public function selectSearchedDevice(string $deviceId): void
    {
        $deviceId = strtolower(trim($deviceId));
        if ($deviceId === '') {
            return;
        }

        $product = collect($this->searchResults)
            ->first(fn (array $device): bool => $this->deviceMatchesUuid($device, $deviceId));

        if ($product === null) {
            $product = collect($this->deviceSearchCatalog)
                ->first(fn (array $device): bool => $this->deviceMatchesUuid($device, $deviceId));
        }

        // Catalog may be empty on the next Livewire request if the eHealth payload
        // was too large for the snapshot — always fall back to a fresh UUID lookup.
        if ($product === null) {
            $product = $this->fetchDeviceDefinitionByUuid($deviceId);
        }

        if (!is_array($product)) {
            Session::flash('error', __('care-plan.device_search_no_results', ['query' => $deviceId]));

            return;
        }

        $this->selectProduct($this->compactDeviceForState($product), 'device_request');
    }

    #[On('care-plan-service-selected')]
    public function selectCarePlanServiceFromCatalog(array $service): void
    {
        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }
        $product = dictionary()->services()->flattened()
            ->firstWhere('id', $service['id'] ?? null);
        if ($product === null) {
            Session::flash('error', __('care-plan.service_selection_unavailable'));

            return;
        }
        $this->resetValidation('activityForm.product_reference');
        $this->selectProduct($product, 'service_request');
    }

    public function selectProduct(array $product, string $kind): void
    {
        $this->selectedProduct = $kind === 'device_request'
            ? $this->compactDeviceForState($product)
            : $product;

        if ($kind !== 'device_request') {
            $this->activityForm->product_reference = $product['id'] ?? ($product['uuid'] ?? ($product['code'] ?? ''));
        }

        if ($kind === 'service_request') {
            $this->activityForm->product_codeable_concept = '';
            $this->activityForm->product_reference_type = ($product['type'] ?? '') === 'service_group' || ($product['is_group'] ?? false) ? 'service_group' : 'service';
            $this->activityForm->quantity_system = 'SERVICE_UNIT';
            $this->activityForm->quantity_code = $this->activityForm->quantity_code ?: 'PIECE';
            $this->showServiceSearchDrawer = false;
            $this->showServiceDrawer = true;
        } elseif ($kind === 'medication_request') {
            $unit = $this->resolveMedicationDenumeratorUnit($product);
            $this->activityForm->quantity_system = 'MEDICATION_UNIT';
            $this->activityForm->quantity_code = $unit;
            $this->activityForm->daily_amount_system = 'MEDICATION_UNIT';
            $this->activityForm->daily_amount_code = $unit;
            $this->activityForm->program = $this->resolveMedicationProgramId();

            $packageQuantity = (float) ($product['packages'][0]['package_min_qty'] ?? 0);
            if ($packageQuantity <= 0) {
                $packageQuantity = (float) ($product['packages'][0]['package_qty'] ?? 0);
            }
            $this->activityForm->quantity = (string) ($packageQuantity > 0 ? $packageQuantity : 1);

            $this->showMedicationSearchDrawer = false;
            $this->showMedicationFormDrawer = true;
        } elseif ($kind === 'device_request') {
            $this->activityForm->quantity_system = 'device_unit';
            $packaging = $product['packaging'] ?? null;
            $packagingUnit = is_array($packaging) && !empty($packaging['packaging_unit'])
                ? (string) $packaging['packaging_unit']
                : 'piece';
            $this->activityForm->quantity_code = $this->normalizeDeviceUnitCode($packagingUnit);
            $this->activityForm->program = filled($this->selectedProgram) ? $this->selectedProgram : null;
            if (is_array($packaging) && !empty($packaging['packaging_count'])) {
                $this->activityForm->quantity = (int) $packaging['packaging_count'];
            }

            $this->applyDeviceProductFieldsFromSelection($this->selectedProduct);

            $programDevice = app(DeviceProgramParticipationGuard::class)
                ->resolveProgramDevice($this->selectedProduct, $this->activityForm->program ?: null);
            if (filled($this->activityForm->program)) {
                try {
                    $this->loadDeviceQuantityCatalog($this->selectedProduct, $this->activityForm->program);
                } catch (Throwable) {
                    $this->deviceQuantityCatalog = [];
                    Session::flash('error', __('care-plan.device_quantity_options_required'));
                }
            }
            $maxDaily = isset($programDevice['max_daily_count']) ? (int) $programDevice['max_daily_count'] : null;
            $this->deviceSelectionWarning = $maxDaily !== null && $maxDaily > 0
                ? __('care-plan.device_max_daily_count_hint', ['count' => $maxDaily])
                : '';
            $this->showMedicalDeviceDrawer = false;
            $this->showMedicalDeviceSearchDrawer = false;
            $this->showMedicalDeviceFormDrawer = true;
        }
    }

    /** Revalidate old drafts against the current program catalog immediately before KEP. */
    protected function assertMedicationActivityProgramForSigning(array $resource): void
    {
        if (data_get($resource, 'detail.kind') !== 'medication_request') {
            return;
        }
        $programId = data_get($resource, 'detail.program.identifier.value');
        $program = is_string($programId) ? $this->resolveMedicalProgramPayload($programId) : null;
        if ($program === null || ($program['type'] ?? null) !== 'MEDICATION') {
            throw ValidationException::withMessages(['activityToSign' => __('care-plan.medication_program_required')]);
        }
    }

    protected function assertDeviceActivityQuantityForSigning(array $resource): void
    {
        $detail = $resource['detail'] ?? [];
        if (($detail['kind'] ?? null) !== 'device_request' || empty($detail['program'])) {
            return;
        }
        $programId = (string) data_get($detail, 'program.identifier.value', '');
        $definitionId = data_get($detail, 'productReference.identifier.value');
        $classification = data_get($detail, 'productCodeableConcept.coding.0');
        $filters = ['medical_program_id' => $programId, 'is_active' => true];
        if (is_array($classification)) {
            $filters['classification_type_system'] = $classification['system'] ?? '';
            $filters['classification_type_code'] = $classification['code'] ?? '';
        }
        $catalog = $this->fetchAllDeviceDefinitions($filters);
        $guard = app(DeviceProgramParticipationGuard::class);
        $selected = $definitionId !== null
            ? collect($catalog)->firstWhere('id', $definitionId)
            : collect($catalog)->first(fn (array $device): bool => $guard->deviceAllowsCarePlanActivity($device, $programId));
        $selectedClassification = data_get($selected, 'classification_types.0');
        $programDevice = is_array($selected) ? $guard->resolveProgramDevice($selected, $programId) : null;
        $counts = [];
        foreach ($catalog as $device) {
            if (data_get($device, 'classification_types.0.system') === ($selectedClassification['system'] ?? null)
                && data_get($device, 'classification_types.0.code') === ($selectedClassification['code'] ?? null)
                && $guard->deviceAllowsCarePlanActivity($device, $programId)
                && data_get($device, 'packaging.packaging_unit') === data_get($detail, 'quantity.code')) {
                $counts[] = (int) data_get($device, 'packaging.packaging_count', 0);
            }
        }
        $options = $this->buildDeviceQuantityOptions(
            $counts,
            (int) ($programDevice['max_daily_count'] ?? 0),
            (string) data_get($detail, 'scheduledPeriod.start', ''),
            (string) data_get($detail, 'scheduledPeriod.end', '')
        );
        $quantity = data_get($detail, 'quantity.value');
        if (!is_numeric($quantity) || (float) $quantity !== (float) (int) $quantity || !in_array((int) $quantity, $options, true)) {
            throw ValidationException::withMessages(['activityToSign' => __('care-plan.device_quantity_options_required')]);
        }
    }

    protected function loadDeviceQuantityCatalog(array $device, string $programId): void
    {
        $this->deviceQuantityCatalog = [];
        $classification = $device['classification_types'][0] ?? null;
        if (!is_array($classification) || empty($classification['system']) || empty($classification['code'])) {
            return;
        }
        $this->deviceQuantityCatalog = $this->fetchAllDeviceDefinitions([
            'medical_program_id' => $programId,
            'classification_type_system' => $classification['system'],
            'classification_type_code' => $classification['code'],
            'is_active' => true,
        ]);
    }

    #[Computed]
    public function deviceQuantityOptions(): array
    {
        $programId = $this->activityForm->program ?? null;
        if (!filled($programId) || $this->selectedProduct === null) {
            return [];
        }
        $guard = app(DeviceProgramParticipationGuard::class);
        $programDevice = $guard->resolveProgramDevice($this->selectedProduct, $programId);
        $maxDailyCount = (int) ($programDevice['max_daily_count'] ?? 0);
        $unit = (string) data_get($this->selectedProduct, 'packaging.packaging_unit', '');
        $counts = [];
        foreach ($this->deviceQuantityCatalog as $device) {
            if ($guard->deviceAllowsCarePlanActivity($device, $programId)
                && (string) data_get($device, 'packaging.packaging_unit', '') === $unit) {
                $counts[] = (int) data_get($device, 'packaging.packaging_count', 0);
            }
        }

        return $this->buildDeviceQuantityOptions(
            $counts,
            $maxDailyCount,
            convertToYmd($this->activityForm->scheduled_period_start ?? ''),
            convertToYmd($this->activityForm->scheduled_period_end ?? '')
        );
    }

    public function addLinkedGround(string $type, string $uuid): void
    {
        $exists = collect($this->linkedGrounds)->contains('uuid', $uuid);
        if ($exists) {
            return;
        }

        $name = 'Unknown Record';
        $date = '-';
        if ($type === 'Condition') {
            $item = collect($this->availableConditions)->firstWhere('uuid', $uuid);
            if ($item) {
                $name = $item['name'];
                $date = $item['date'];
            }
        } elseif ($type === 'DiagnosticReport') {
            $item = collect($this->availableReports)->firstWhere('uuid', $uuid);
            if ($item) {
                $name = $item['name'];
                $date = $item['date'];
            }
        } elseif ($type === 'Observation') {
            $item = collect($this->availableObservations)->firstWhere('uuid', $uuid);
            if ($item) {
                $name = $item['name'];
                $date = $item['date'];
            }
        }

        $this->linkedGrounds[] = [
            'type' => $type,
            'uuid' => $uuid,
            'name' => $name,
            'date' => $date,
        ];
    }

    public function removeLinkedGround(string $uuid): void
    {
        $this->linkedGrounds = collect($this->linkedGrounds)
            ->filter(fn ($g) => $g['uuid'] !== $uuid)
            ->values()
            ->toArray();
    }

    protected function validateActivityPeriodAgainstCarePlan(string $activityStart, string $activityEnd): ?string
    {
        if ($activityStart === '' && $activityEnd === '') {
            return null;
        }
        if ($activityStart === '' || $activityEnd === '') {
            return __('care-plan.activity_period_both_dates_required');
        }
        if ($activityStart > $activityEnd) {
            return __('care-plan.activity_period_end_before_start');
        }

        $bounds = $this->resolveEHealthPeriodBounds($this->carePlan);
        $planStart = $bounds['start'];
        $planEnd = $bounds['end'];

        if ($planStart) {
            $planStartDate = $planStart->copy()->setTimezone(config('app.timezone', 'Europe/Kyiv'))->format('Y-m-d');
            if ($activityStart < $planStartDate) {
                return __('care-plan.activity_period_before_plan_start');
            }
        } elseif ($this->carePlan->period_start) {
            $planStartDate = $this->carePlan->period_start->format('Y-m-d');
            if ($activityStart < $planStartDate) {
                return __('care-plan.activity_period_before_plan_start');
            }
        }

        if ($planEnd) {
            $planEndDate = $planEnd->copy()->setTimezone(config('app.timezone', 'Europe/Kyiv'))->format('Y-m-d');
            if ($activityEnd > $planEndDate) {
                return __('care-plan.activity_period_after_plan_end');
            }
        } elseif ($this->carePlan->period_end) {
            $planEndDate = $this->carePlan->period_end->format('Y-m-d');
            if ($activityEnd > $planEndDate) {
                return __('care-plan.activity_period_after_plan_end');
            }
        }

        return null;
    }

    protected function ensureCarePlanEffectivePeriodSynced(CarePlanRepository $repository): void
    {
        $this->carePlan = Repository::carePlan()->findById($this->carePlan->id);

        if ($this->carePlan->effectivePeriod && $this->resolveEHealthPeriodBounds($this->carePlan)['start']) {
            return;
        }

        try {
            $planData = $this->fetchCarePlanDetails($this->carePlan->person->uuid, $this->carePlan->uuid);
            $this->synchronizeResources(['data' => [$planData]], $this->carePlan->personId, (int) legalEntity()->id);
            $this->carePlan = $repository->findById($this->carePlan->id);
        } catch (Exception $e) {
            Log::warning('CarePlanShow: failed to sync effective period before activity sign: ' . $e->getMessage());
        }
    }

    protected function resolveMedicationDenumeratorUnit(array $product): string
    {
        $ingredients = $product['ingredients'] ?? [];
        if (is_array($ingredients)) {
            foreach ($ingredients as $ingredient) {
                $unit = $ingredient['dosage']['denumerator_unit'] ?? null;
                if (!empty($unit)) {
                    return (string) $unit;
                }
            }
        }

        return (string) ($product['innm_dosage_form'] ?? 'PIECE');
    }

    protected function syncDeviceProductReferenceFromSelection(): void
    {
        if (empty($this->selectedProduct)) {
            return;
        }

        $kindLower = strtolower($this->activityForm->kind ?? '');
        if (!str_contains($kindLower, 'device')) {
            return;
        }

        $this->applyDeviceProductFieldsFromSelection($this->selectedProduct);
    }

    protected function applyDeviceProductFieldsFromSelection(array $product): void
    {
        $programId = $this->resolveDeviceProgramId();
        $allowedTypes = $this->resolveDeviceRequestAllowedCodeTypes($programId);
        $allowsDeviceDefinition = in_array('DEVICE_DEFINITION', $allowedTypes, true);
        $allowsClassification = in_array('CLASSIFICATION_TYPE', $allowedTypes, true);

        $deviceId = (string) ($product['id'] ?? ($product['uuid'] ?? ''));
        $classificationCode = $this->resolveDeviceClassificationCode($product);

        if ($allowsDeviceDefinition && $deviceId !== '') {
            $this->activityForm->product_reference = $deviceId;
            $this->activityForm->product_codeable_concept = '';

            return;
        }

        if ($allowsClassification && $classificationCode !== null && $classificationCode !== '') {
            $this->activityForm->product_codeable_concept = $classificationCode;
            $this->activityForm->product_reference = '';

            return;
        }

        // Program settings missing/unknown: prefer device definition UUID alone.
        if ($deviceId !== '') {
            $this->activityForm->product_reference = $deviceId;
            $this->activityForm->product_codeable_concept = '';

            return;
        }

        if ($classificationCode !== null && $classificationCode !== '') {
            $this->activityForm->product_codeable_concept = $classificationCode;
            $this->activityForm->product_reference = '';
        }
    }

    protected function resolveMedicationProgramId(): ?string
    {
        return MedicationProgramPicker::resolve($this->selectedProgram);
    }

    protected function resolveDeviceProgramId(): ?string
    {
        // Empty means an intentional device request without medical program.
        // Do not auto-pick a default — that would block Encounter Package based_on.
        return filled($this->selectedProgram) ? $this->selectedProgram : null;
    }

    protected function basicDictionaryCodes(BasicDictionaryCollection $basics, array $names): array
    {
        foreach ($names as $name) {
            try {
                return $basics->byName($name)->asCodeDescription()->toArray();
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return [];
    }

    protected function resetActivitySelectionState(string $kind): void
    {
        $this->searchQuery = '';
        $this->searchResults = [];
        $this->searchPage = 1;
        $this->deviceSearchTotalPages = 1;
        $this->deviceSearchTotalEntries = 0;
        $this->deviceSearchModelNumber = '';
        $this->deviceSearchCatalog = [];
        $this->deviceQuantityCatalog = [];
        $this->selectedProduct = null;
        $this->linkedGrounds = [];
        $this->deviceSelectionWarning = '';
        $this->selectedProgram = '';

        $kindLower = strtolower($kind);
        $this->selectedProgram = match (true) {
            str_contains($kindLower, 'medication') => $this->resolveMedicationProgramId() ?? '',
            // Device program starts empty so the user can choose one or leave none.
            str_contains($kindLower, 'device') => '',
            default => '',
        };
    }

    protected function resolveDeviceClassificationCode(array $device): ?string
    {
        if (!empty($device['classification_type_code'])) {
            return (string) $device['classification_type_code'];
        }

        if (!empty($device['code']) && !preg_match('/^[0-9a-f]{8}-/i', (string) $device['code'])) {
            return (string) $device['code'];
        }

        $classificationTypes = $device['classification_types'] ?? [];
        if (is_array($classificationTypes) && !empty($classificationTypes[0]['code'])) {
            return (string) $classificationTypes[0]['code'];
        }

        return null;
    }

    protected function normalizeDeviceUnitCode(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return $code;
        }

        $dictionary = $this->dictionaries['device_unit'] ?? [];
        if (is_array($dictionary) && $dictionary !== []) {
            foreach (array_keys($dictionary) as $key) {
                if (strcasecmp((string) $key, $code) === 0) {
                    return (string) $key;
                }
            }
        }

        return strtolower($code);
    }

    protected function resolveDeviceRequestAllowedCodeTypes(?string $programId): array
    {
        if (empty($programId)) {
            return [];
        }

        try {
            $program = dictionary()->medicalPrograms()->firstWhere('id', $programId);
            $types = $program['medical_program_settings']['device_request_allowed_code_types'] ?? [];

            return is_array($types) ? $types : [];
        } catch (Exception) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveMedicalProgramPayload(?string $programId): ?array
    {
        if ($programId === null || $programId === '') {
            return null;
        }

        foreach ([
            $this->dictionaries['medical_programs'][$programId] ?? null,
            $this->dictionaries['medical_programs_medication'][$programId] ?? null,
            $this->dictionaries['medical_programs_device'][$programId] ?? null,
        ] as $candidate) {
            // UI dictionaries often store id => name (string); only accept full program payloads.
            if (is_array($candidate) && (isset($candidate['medical_program_settings']) || isset($candidate['id']))) {
                return $candidate;
            }
        }

        try {
            $program = dictionary()->medicalPrograms()->firstWhere('id', $programId);
            if (is_array($program) && $program !== []) {
                return $program;
            }
        } catch (Throwable) {
            // Dictionary unavailable in some test contexts.
        }

        return null;
    }

    protected function getDeviceSignReadinessWarning(CarePlanActivity $activity): ?string
    {
        if (!str_contains(strtolower((string) $activity->kind), 'device')) {
            return null;
        }

        $assessment = app(DeviceProgramParticipationGuard::class)
            ->assess($this->carePlan, $activity, legalEntity());

        if ($assessment->warnings !== []) {
            $this->deviceParticipationWarning = implode(' ', $assessment->warnings);
        }

        return $assessment->blockingMessage();
    }

    public function initEPrescriptionForm(int $activityId): void
    {
        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }

        try {
            $activity = $this->ownedActivity($activityId);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        }

        $activityStatus = strtolower(is_array($activity->status)
            ? ($activity->status['coding'][0]['code'] ?? ($activity->status['text'] ?? ''))
            : (string) $activity->status);

        $blockedActivityStatuses = ['cancelled', 'completed'];

        if ($this->isTerminalCarePlan) {
            Session::flash('error', __('care-plan.cannot_mutate_terminal_care_plan', [
                'status' => CarePlanStatus::labelFor($this->carePlan->status),
            ]));

            return;
        }

        if (in_array($activityStatus, $blockedActivityStatuses)) {
            Session::flash('error', __('Виписування рецепту заборонено: це призначення вже завершено або скасовано.'));

            return;
        }

        if ($activity->resolvedKind() !== 'medication_request') {
            Session::flash('error', __('care-plan.eprescription_wrong_activity_kind'));

            return;
        }

        try {
            EHealth::carePlanActivity()->assertRegisteredInEHealth($this->carePlan->person?->uuid, $this->carePlan->uuid, $activity->uuid);
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());

            return;
        }

        $this->ePrescriptionSelectedProduct = null;
        $this->ePrescriptionWarningMessage = '';
        $this->ePrescriptionPackages = [];
        $this->ePrescriptionMultiples = [];

        try {
            $this->ePrescriptionSelectedProduct = $this->resolveDrugForActivity($activity);
            if ($this->ePrescriptionSelectedProduct && !empty($this->ePrescriptionSelectedProduct['packages'])) {
                $this->ePrescriptionPackages = $this->ePrescriptionSelectedProduct['packages'];
                $this->ePrescriptionMultiples = (new PrescriptionRules())->quantities($this->ePrescriptionSelectedProduct);
            }
        } catch (Exception $e) {
            Log::warning('CarePlanShow: failed to fetch drug details: ' . $e->getMessage());
        }

        if (!$this->ePrescriptionSelectedProduct) {
            Session::flash('error', __('care-plan.medication_not_available_for_program'));

            return;
        }

        $this->ePrescriptionSelectedProgram = null;
        $this->ePrescriptionSkipTreatmentPeriod = true;
        if (!empty($activity->program)) {
            $program = dictionary()->medicalPrograms()->firstWhere('id', $activity->program);
            if ($program) {
                $this->ePrescriptionSelectedProgram = $program;
                $settings = $this->ePrescriptionSelectedProgram['settings'] ?? [];
                $this->ePrescriptionSkipTreatmentPeriod = filter_var($settings['skip_treatment_period'] ?? true, FILTER_VALIDATE_BOOLEAN);
            }
        }

        // The patient's authentication method is legally significant: it must come from the
        // eHealth registry, never from a local fallback.
        $this->ePrescriptionAuthMethods = [];
        if (!$this->carePlanPrescriptionNotificationsDisabled) {
            try {
                $this->ePrescriptionAuthMethods = EHealth::person()->getAuthMethods($this->carePlan->person->uuid)->validate();
            } catch (Exception $e) {
                Log::channel('e_health_errors')->error('CarePlanShow: failed to fetch patient auth methods', [
                    'care_plan_id' => $this->carePlan->id,
                    'activity_id' => $activity->id,
                    'exception' => $e->getMessage(),
                ]);
                Session::flash('error', __('care-plan.auth_methods_unavailable'));

                return;
            }

            if (empty($this->ePrescriptionAuthMethods)) {
                Session::flash('error', __('care-plan.auth_methods_empty'));

                return;
            }
        }

        $issuedQty = MedicalEventsRepository::medicationRequest()->issuedCarePlanQuantity($activity->uuid);

        $activityQty = $activity->quantity;
        $this->ePrescriptionRemainingQty = $activityQty === null
            ? 1.0
            : max(0.0, (float) $activityQty - (float) $issuedQty);

        try {
            $eHealthActivity = EHealth::carePlanActivity()->getDetails(
                (string) $this->carePlan->person->uuid,
                (string) $this->carePlan->uuid,
                (string) $activity->uuid
            )->validate();
            if ($eHealthActivity['id'] !== $activity->uuid) {
                throw new RuntimeException('Activity details do not match the requested resource.');
            }
            $eHealthRemaining = data_get($eHealthActivity, 'detail.remainingQuantity.value');
            if ($eHealthRemaining !== null) {
                $this->ePrescriptionRemainingQty = max(0.0, (float) $eHealthRemaining);
            }
        } catch (Exception $e) {
            Log::warning('CarePlanShow: failed to fetch eHealth activity remaining qty: ' . $e->getMessage());
        }

        if ($activityQty === null) {
            $this->ePrescriptionWarningMessage = __('У призначенні плану лікування не вказано кількість. Перевірте дані в ЕСОЗ перед підписанням рецепту.');
        }

        $unit = (new MedicationRequestMapper())->medicationUnit($this->ePrescriptionSelectedProduct);
        $this->ePrescriptionMultiples = (new PrescriptionRules())->quantities($this->ePrescriptionSelectedProduct, $this->ePrescriptionRemainingQty);
        $packageStep = $this->resolveMedicationPackageStep($this->ePrescriptionSelectedProduct ?? []);

        if ($packageStep > 0 && $this->ePrescriptionRemainingQty > 0 && $this->ePrescriptionRemainingQty < $packageStep) {
            $message = __('care-plan.medication_remaining_below_packaging', [
                'remaining' => $this->ePrescriptionRemainingQty,
                'count' => $packageStep,
            ]);
            Session::flash('error', $message);

            return;
        }

        $defaultQty = !empty($this->ePrescriptionMultiples)
            ? $this->ePrescriptionMultiples[0]
            : $packageStep;

        if ($this->ePrescriptionRemainingQty > 0 && $defaultQty > $this->ePrescriptionRemainingQty) {
            $defaultQty = $this->ePrescriptionRemainingQty;
            if (!$this->isMedicationQtyDivisible($defaultQty, $this->ePrescriptionSelectedProduct ?? [])) {
                $defaultQty = $packageStep <= $this->ePrescriptionRemainingQty
                    ? $packageStep
                    : $this->ePrescriptionRemainingQty;
            }
        }

        $this->ePrescriptionShowDailyDoseWarning = false;
        $this->ePrescriptionShowRemainingQtyWarning = false;
        $this->ePrescriptionRemainingQtyWarningMessage = '';
        $this->ePrescriptionSelectedActivity = $activity->toArray();

        $employeeContext = app(MedicationRequestLifecycleService::class)
            ->resolveEmployeeContext($this->carePlan, null, Auth::user()->activeDoctorEmployee()?->id);
        $eligibleEncounters = app(MedicationRequestLifecycleService::class)
            ->findEligibleEncountersForEPrescription(
                (int) $this->carePlan->person_id,
                $employeeContext['employee_uuid'] ?? null
            );

        $this->ePrescriptionEligibleEncounters = $eligibleEncounters
            ->map(static function ($encounter): array {
                $endedAt = $encounter->period?->end;
                $dateLabel = $endedAt
                    ? Carbon::parse($endedAt)->format('d.m.Y H:i')
                    : ($encounter->created_at?->format('d.m.Y H:i') ?? '');

                return [
                    'id' => (int) $encounter->id,
                    'uuid' => (string) $encounter->uuid,
                    'label' => trim(__('Завершена взаємодія від :date (:uuid)', ['date' => $dateLabel, 'uuid' => $encounter->uuid])),
                ];
            })
            ->values()
            ->all();

        $defaultEncounterId = count($this->ePrescriptionEligibleEncounters) === 1
            ? (string) $this->ePrescriptionEligibleEncounters[0]['id']
            : '';

        // eHealth MedicationRequest expects a drug id; activity.productReference is INNM dosage.
        $resolvedMedicationId = (string) ($this->ePrescriptionSelectedProduct['id'] ?? $activity->productReference);

        $this->ePrescriptionForm->reset();
        $this->ePrescriptionForm->fill([
            'activity_id' => $activity->id,
            'encounter_id' => $defaultEncounterId,
            'medication_id' => $resolvedMedicationId,
            'started_at' => now()->toDateString(),
            'duration' => 10,
            'dose_input_unit' => 'denominator',
            'dose_ingredient_id' => $this->ePrescriptionSelectedProduct['ingredients'][0]['id'] ?? '',
            'dose_warning_confirmed' => false,
            'phone_confirmed' => false,
            'ended_at' => '',
            'medication_qty' => $defaultQty,
            'medication_unit' => $unit,
            'signature_text' => '',
            'max_dose_per_period' => (float) $activity->dailyAmount ?: 1.0,
            'max_dose_per_administration' => 1.0,
            'inform_with' => !empty($this->ePrescriptionAuthMethods) ? ($this->ePrescriptionAuthMethods[0]['uuid'] ?? '') : '',
            'container_dosage' => '',
            'program_id' => $activity->program,
            'note' => '',
            'route' => 'oral',
        ]);

        // Prefer OTP/THIRD_PERSON option value with pipe encoding when available.
        if (!empty($this->ePrescriptionAuthMethods)) {
            $first = $this->ePrescriptionAuthMethods[0];
            $methodId = $first['uuid'] ?? ($first['id'] ?? '');
            $type = $first['type'] ?? '';
            $valueLabel = $first['phone_number'] ?? ($first['alias'] ?? '');
            if ($methodId !== '') {
                $this->ePrescriptionForm->inform_with = "{$methodId}|{$type}|{$valueLabel}";
            }
        }

        if ($this->ePrescriptionEligibleEncounters === []) {
            Session::flash('error', __('care-plan.eprescription_encounter_none'));

            return;
        }

        $this->calculateTreatmentDates();
        $this->showEPrescriptionDrawer = true;
    }

    public function updatedEPrescriptionForm($value, $name): void
    {
        $this->ePrescriptionWarningMessage = '';
        $this->ePrescriptionShowDailyDoseWarning = false;
        if (in_array($name, ['max_dose_per_period', 'max_dose_per_administration', 'dose_input_unit', 'dose_ingredient_id'], true)) {
            $this->ePrescriptionForm->dose_warning_confirmed = false;
        }
        if ($name === 'inform_with') {
            $this->ePrescriptionForm->phone_confirmed = false;
        }

        if (str_contains($name, 'started_at') || str_contains($name, 'duration')) {
            $this->calculateTreatmentDates();
        }
    }

    public function calculateTreatmentDates(): void
    {
        if (empty($this->ePrescriptionForm->started_at) || empty($this->ePrescriptionForm->duration)) {
            return;
        }

        try {
            $start = Carbon::createFromFormat('Y-m-d', $this->ePrescriptionForm->started_at);
            $duration = (int) $this->ePrescriptionForm->duration;

            if ($duration < 1) {
                return;
            }

            $maxPeriod = (new PrescriptionRules())->programSetting($this->ePrescriptionSelectedProgram, 'request_max_period_day');
            if ($maxPeriod !== null && $duration > (int) $maxPeriod) {
                $this->ePrescriptionWarningMessage = __('Тривалість курсу лікування (:duration днів) перевищує максимальний період курсу за обраною програмою (:max_period днів).', ['duration' => $duration, 'max_period' => $maxPeriod]);
            } else {
                $this->ePrescriptionWarningMessage = '';
            }

            $end = $start->copy()->addDays($duration - 1);
            $this->ePrescriptionForm->ended_at = $end->toDateString();
        } catch (Exception $e) {
            // Invalid date format
        }
    }

    public function confirmExceededDailyDose(bool $confirm): void
    {
        $this->ePrescriptionShowDailyDoseWarning = false;
        $this->ePrescriptionForm->dose_warning_confirmed = $confirm;
        if ($confirm) {
            $this->validateEPrescription();
        }
    }

    public function validateEPrescription(): void
    {
        $this->ePrescriptionWarningMessage = '';
        $this->ePrescriptionShowDailyDoseWarning = false;

        try {
            $this->ePrescriptionForm->validate();
        } catch (ValidationException $exception) {
            $this->ePrescriptionWarningMessage = $exception->validator->errors()->first();
            $this->setErrorBag($exception->validator->getMessageBag());
            Session::flash('error', $this->ePrescriptionWarningMessage);
            $this->dispatch('scroll-to-error');

            return;
        }

        try {
            $this->ePrescriptionForm->fill((new PrescriptionRules())->normalize(
                $this->ePrescriptionForm->toArray(),
                $this->ePrescriptionSelectedProduct ?? [],
                $this->ePrescriptionSelectedProgram ?? [],
                (float) ($this->ePrescriptionSelectedActivity['daily_amount'] ?? ($this->ePrescriptionSelectedActivity['dailyAmount'] ?? 0))
            ));
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError('ePrescriptionForm.'.$field, $messages[0]);
            }
            $this->ePrescriptionShowDailyDoseWarning = array_key_exists('dose_warning_confirmed', $exception->errors());
            $this->ePrescriptionWarningMessage = $exception->validator->errors()->first();
            Session::flash($this->ePrescriptionShowDailyDoseWarning ? 'warning' : 'error', $this->ePrescriptionWarningMessage);

            return;
        }
        $qty = (float) $this->ePrescriptionForm->medication_qty;

        if ($qty > $this->ePrescriptionRemainingQty && $this->ePrescriptionSelectedActivity['quantity'] !== null) {
            $this->ePrescriptionWarningMessage = __('Кількість ЛЗ в рецепті (:quantity) перевищує залишкову кількість у плані лікування (:remaining). Виписування неможливе.', ['quantity' => $qty, 'remaining' => $this->ePrescriptionRemainingQty]);
            Session::flash('error', $this->ePrescriptionWarningMessage);

            return;
        }

        if (!$this->ePrescriptionSkipTreatmentPeriod) {
            $lastActivePrescription = MedicalEventsRepository::medicationRequest()->lastActiveForTreatment($this->carePlan->personId, $this->ePrescriptionForm->medication_id);

            if ($lastActivePrescription && $lastActivePrescription->endedAt) {
                $lastEnd = Carbon::parse($lastActivePrescription->endedAt);
                $today = now();
                $remainingDays = $today->diffInDays($lastEnd, false);

                if ($remainingDays > 0) {
                    $prevDuration = $lastActivePrescription->startedAt ? Carbon::parse($lastActivePrescription->startedAt)->diffInDays($lastEnd) + 1 : 10;
                    $allowedDaysBeforeEnd = $prevDuration >= 21 ? 7 : 3;

                    if ($remainingDays > $allowedDaysBeforeEnd) {
                        $this->ePrescriptionWarningMessage = __('Повторний Е-Рецепт на той же МНН можна виписати за :allowed_days днів до закінчення терміну лікування попереднього Е-Рецепту. Попередній рецепт діє до :date (залишилось :remaining_days днів).', ['allowed_days' => $allowedDaysBeforeEnd, 'date' => $lastEnd->format('d.m.Y'), 'remaining_days' => $remainingDays]);
                        Session::flash('error', $this->ePrescriptionWarningMessage);

                        return;
                    }
                }
            }
        }

        $this->submitEPrescriptionRequest();
    }

    public function submitEPrescriptionRequest(): void
    {
        try {
            $employeeContext = app(MedicationRequestLifecycleService::class)
                ->resolveEmployeeContext($this->carePlan, null, Auth::user()->activeDoctorEmployee()?->id);
            $activity = $this->ownedActivity((int) $this->ePrescriptionForm->activity_id);

            $uuid = app(MedicationRequestLifecycleService::class)->createCarePlanDraft(
                $this->carePlan,
                $activity,
                $this->ePrescriptionForm->toArray(),
                $employeeContext
            );

            $this->showEPrescriptionDrawer = false;
            $this->ePrescriptionRequestIdToSign = $uuid;
            Session::flash('success', __('Заявку на е-рецепт створено. Підпишіть КЕП.'));
            $this->openSignatureModal('sign_eprescription');

        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
        } catch (EHealthValidationException $exception) {
            $exception->report();
            Session::flash('error', $exception->getTranslatedMessage());
        } catch (EHealthResponseException $e) {
            if ($e->getCode() === 403 || $e->response->status() === 403) {
                Log::warning('CarePlanShow: 403 access denied when submitting ePrescription. Prompting for approval.');
                Session::flash('warning', __('Відсутній доступ до медичних даних. Будь ласка, надішліть запит на доступ пацієнту.'));
                $this->openMethodSelectionModal();
            } else {
                Log::error('CarePlanShow: failed to create ePrescription API error: ' . $e->getMessage());
                Session::flash('error', __('Не вдалося створити заявку на рецепт: ') . $e->getMessage());
            }
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to create ePrescription: ' . $e->getMessage());
            Session::flash('error', __('Не вдалося створити заявку на рецепт: ') . $e->getMessage());
        }
    }

    #[Locked]
    public array $carePlanImportedPrescriptions = [];

    public array $carePlanPrescriptionFilters = ['employee_id' => '', 'status' => '', 'request_number' => '', 'created_from' => '', 'created_to' => '', 'started_at_from' => '', 'started_at_to' => '', 'ended_at_from' => '', 'ended_at_to' => ''];

    #[Locked]
    public ?string $prescriptionSmsConfirmationId = null;

    #[Locked]
    public array $ePrescriptionDispenses = [];

    public function syncEPrescriptions(): void
    {
        if (Auth::user()->cannot('care_plan:read') || Auth::user()->cannot('medication_request:read') || Auth::user()->cannot('medication_request_request:read')) {
            Session::flash('error', __('medication-requests.permission_denied'));

            return;
        }
        try {
            $filters = array_filter($this->carePlanPrescriptionFilters, static fn (string $value): bool => $value !== '');
            $lifecycle = app(MedicationRequestLifecycleService::class);
            $activeItems = $lifecycle->getByCarePlan($this->carePlan->uuid, $filters);
            $draftItems = $lifecycle->getRequestsByCarePlan($this->carePlan->uuid, array_intersect_key($filters, array_flip(['employee_id', 'status'])));
            $draftItems = $lifecycle->filterDocuments($draftItems, $filters);
            // Match confirmed prescriptions against the pre-import requests. A newly
            // imported MR must not hide its older unsigned MRR with the same number.
            $localRequests = Repository::carePlanActivity()->linkedDocuments($this->carePlan->activities->pluck('uuid')->all(), 'medication_request');
            $updatedCount = MedicalEventsRepository::medicationRequest()->importCarePlanDocuments($this->carePlan, $activeItems, $draftItems);
            MedicalEventsRepository::medicationRequest()->synchronizeCarePlanDocuments($localRequests, $activeItems, $draftItems);
            $this->carePlanImportedPrescriptions = array_merge(
                array_map(static fn (array $item): array => $item + ['_resource_type' => MedicationRequestRequest::TYPE_PRESCRIPTION], $activeItems),
                array_map(static fn (array $item): array => $item + ['_resource_type' => MedicationRequestRequest::TYPE_REQUEST], $draftItems)
            );
            $this->refreshCarePlan();
            Session::flash('success', __('medication-requests.care_plan_sync', ['count' => $updatedCount]));
        } catch (Exception $exception) {
            Log::warning('Care-plan prescription synchronization failed', ['exceptionType' => $exception::class]);
            Session::flash('error', __('medication-requests.search_failed'));
        }
    }

    public function signEPrescription(): void
    {
        if (empty($this->ePrescriptionRequestIdToSign)) {
            Session::flash('error', __('Не вибрано заявку на рецепт для підписання'));
            $this->showSignatureModal = false;

            return;
        }

        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }

        try {
            $requestRecord = app(MedicalRequestOwnership::class)
                ->medicationForPerson((string) $this->ePrescriptionRequestIdToSign, (int) $this->carePlan->personId);

            $result = app(MedicationRequestLifecycleService::class)->signPrescription(
                $this->carePlan,
                $requestRecord,
                array_merge($this->form->toArray(), [
                    'request_notification_disabled' => filter_var(
                        (new PrescriptionRules())->programSetting($this->ePrescriptionSelectedProgram, 'request_notification_disabled', false),
                        FILTER_VALIDATE_BOOLEAN
                    ),
                    'medication_unit' => $this->ePrescriptionForm->medication_unit ?? 'од.',
                    'signer_tax_id' => Auth::user()?->party?->taxId,
                ]),
                $requestRecord->informWith ?? '',
                $this->ePrescriptionRemainingQty
            );

            if (!empty($result['show_remaining_qty_warning']) || !empty($result['warning_message'])) {
                $this->ePrescriptionShowRemainingQtyWarning = true;
                $this->ePrescriptionRemainingQtyWarningMessage = (string) ($result['warning_message'] ?? '');
            }

            if (!empty($result['warning_message'])) {
                Session::flash('warning', $result['warning_message']);
            }

            Session::flash('success', $result['success_message']);
            $this->showSignatureModal = false;
            $this->ePrescriptionRequestIdToSign = null;
            try {
                $this->refreshCarePlan();
            } catch (Throwable $exception) {
                Log::warning('Signed prescription page refresh is pending', ['exceptionType' => $exception::class]);
                Session::flash('warning', trim(($result['warning_message'] ?? '')."\n".__('care-plan.eprescription_signed_refresh_pending')));
            }

        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        } catch (EHealthValidationException $e) {
            $e->report();
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to sign E-Prescription validation: ' . $translatedMsg);
            Session::flash('error', $translatedMsg);
            $this->showSignatureModal = false;
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to sign E-Prescription: ' . $e->getMessage());
            Session::flash('error', __('Помилка при підписанні рецепту: ') . $e->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function rejectPrescription(string $requestId): void
    {
        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }

        try {
            $requestRecord = app(MedicalRequestOwnership::class)
                ->medicationForPerson($requestId, (int) $this->carePlan->personId);

            if (in_array(strtolower((string) $requestRecord->status), ['new', 'draft'], true)) {
                app(MedicationRequestLifecycleService::class)->rejectPrescription($this->carePlan, $requestRecord);
                $this->refreshCarePlan();
                Session::flash('success', __('Електронний рецепт успішно відхилено.'));
            } else {
                $this->ePrescriptionRequestIdToSign = $requestId;
                $this->openSignatureModal('reject_prescription');
            }
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        } catch (EHealthValidationException $e) {
            $e->report();
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to reject prescription validation: ' . $translatedMsg);
            Session::flash('error', $translatedMsg);
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to reject prescription: ' . $e->getMessage());
            Session::flash('error', __('Не вдалося відхилити рецепт: ') . $e->getMessage());
        }
    }

    public function signRejectPrescription(): void
    {
        if (empty($this->ePrescriptionRequestIdToSign)) {
            Session::flash('error', __('Не вибрано рецепт для відхилення'));
            $this->showSignatureModal = false;

            return;
        }

        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }

        try {
            $requestRecord = app(MedicalRequestOwnership::class)
                ->medicationForPerson((string) $this->ePrescriptionRequestIdToSign, (int) $this->carePlan->personId);

            app(MedicationRequestLifecycleService::class)->rejectPrescription(
                $this->carePlan,
                $requestRecord,
                array_merge($this->form->toArray(), ['signer_tax_id' => Auth::user()?->party?->taxId]),
                $this->statusReason
            );

            $this->showSignatureModal = false;
            $this->refreshCarePlan();
            Session::flash('success', __('Електронний рецепт успішно відхилено.'));

        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        } catch (EHealthValidationException $e) {
            $e->report();
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to reject prescription validation: ' . $translatedMsg);
            Session::flash('error', $translatedMsg);
            $this->showSignatureModal = false;
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to reject prescription: ' . $e->getMessage());
            $errorMsg = __('Не вдалося відхилити рецепт: ') . $e->getMessage();
            Session::flash('error', $errorMsg);
            $this->showSignatureModal = false;
        }
    }

    public function requestPrescriptionSms(string $prescriptionId): void
    {
        $record = app(MedicalRequestOwnership::class)->medicationForPerson($prescriptionId, (int) $this->carePlan->personId);
        if (Auth::user()->cannot('resend', $record)) {
            Session::flash('error', __('medication-requests.permission_denied'));

            return;
        }
        $this->prescriptionSmsConfirmationId = $prescriptionId;
    }

    public function resendPrescriptionSms(string $prescriptionId): void
    {
        $record = app(MedicalRequestOwnership::class)->medicationForPerson($prescriptionId, (int) $this->carePlan->personId);
        if (Auth::user()->cannot('resend', $record) || $this->prescriptionSmsConfirmationId !== $prescriptionId) {
            Session::flash('error', __('medication-requests.sms_confirmation'));

            return;
        }
        $this->prescriptionSmsConfirmationId = null;
        try {
            app(MedicationRequestLifecycleService::class)->resendSms($this->carePlan->person->uuid, $prescriptionId);
            Session::flash('success', __('medication-requests.sms_sent'));
        } catch (Exception $exception) {
            Log::warning('Prescription SMS resend failed', ['exceptionType' => $exception::class]);
            Session::flash('error', __('medication-requests.sms_unavailable'));
        }
    }

    public function loadPrintoutForm(string $prescriptionId): string
    {
        try {
            $record = app(MedicalRequestOwnership::class)
                ->medicationForPerson($prescriptionId, (int) $this->carePlan->personId);
            if (Auth::user()->cannot('view', $record)) {
                Session::flash('error', __('medication-requests.permission_denied'));

                return '';
            }

            // Only the validated official patient leaflet may be presented as a prescription printout.
            $this->printableContent = app(MedicationRequestLifecycleService::class)->fetchPrintoutFromEhealth(
                $this->carePlan->person->uuid,
                $prescriptionId
            );
            $this->dispatch('printoutLoaded');

            return $this->printableContent;
        } catch (Exception $exception) {
            Log::warning('Prescription printout unavailable', ['exceptionType' => $exception::class]);
            Session::flash('error', __('medication-requests.printout_unavailable'));

            return '';
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveDrugForActivity(CarePlanActivity $activity): ?array
    {
        if (empty($activity->productReference)) {
            return null;
        }

        $filters = ['innm_dosage_id' => $activity->productReference];
        if (!empty($activity->program)) {
            $filters['medical_program_id'] = $activity->program;
        }

        $data = EHealth::drug()->getMany($filters)->validate();
        if (!empty($data[0])) {
            return $data[0];
        }

        $fallback = EHealth::drug()->getMany(['innm_id' => $activity->productReference])->validate();

        return $fallback[0] ?? null;
    }

    protected function resolveMedicationPackageStep(array $drug): float
    {
        $steps = collect($drug['packages'] ?? [])->map(static fn (array $package): float => (float) ($package['package_min_qty'] ?? ($package['min_qty'] ?? ($package['package_qty'] ?? 0))))->filter(static fn (float $step): bool => $step > 0);

        return $steps->min() ?? 0.0;
    }

    protected function isMedicationQtyDivisible(float $qty, array $drug): bool
    {
        return (new PrescriptionRules())->isAllowedQuantity($qty, $drug);
    }

    #[Computed]
    public function carePlanPrescriptionContainers(): array
    {
        return (new PrescriptionRules())->containers($this->ePrescriptionSelectedProduct ?? []);
    }

    #[Computed]
    public function carePlanPrescriptionIngredientDoses(): array
    {
        try {
            return (new MedicationRequestMapper())->ingredientDoses((float) ($this->ePrescriptionForm->max_dose_per_period ?? 0), $this->ePrescriptionForm->toArray(), $this->ePrescriptionSelectedProduct ?? []);
        } catch (Exception) {
            return [];
        }
    }

    #[Computed]
    public function carePlanPrescriptionNotificationsDisabled(): bool
    {
        return filter_var((new PrescriptionRules())->programSetting($this->ePrescriptionSelectedProgram, 'request_notification_disabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Show the session message in this response and scroll to the invalid field.
     */
    protected function failEPrescriptionField(string $message, string $field): void
    {
        $this->ePrescriptionWarningMessage = $message;
        $this->addError($field, $message);
        Session::flash('error', $message);
        $this->dispatch('scroll-to-error');
    }

    public function blockPrescription(string $prescriptionId): void
    {
        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }

        try {
            $requestRecord = app(MedicalRequestOwnership::class)
                ->medicationForPerson($prescriptionId, (int) $this->carePlan->personId);

            app(MedicationRequestLifecycleService::class)->block($this->carePlan->person->uuid, $prescriptionId, [
                'status_reason' => 'Призупинення або блокування призначення',
            ]);
            MedicalEventsRepository::medicationRequest()->saveStatus($requestRecord, 'blocked');
            $this->refreshCarePlan();
            Session::flash('success', __('Рецепт успішно заблоковано в ЕСОЗ.'));
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to block prescription: ' . $e->getMessage());
            Session::flash('error', __('Помилка блокування рецепту: ') . $e->getMessage());
        }
    }

    public function unblockPrescription(string $prescriptionId): void
    {
        if (!$this->authorizeCarePlanActivityWrite()) {
            return;
        }

        try {
            $requestRecord = app(MedicalRequestOwnership::class)
                ->medicationForPerson($prescriptionId, (int) $this->carePlan->personId);

            app(MedicationRequestLifecycleService::class)->unblock($this->carePlan->person->uuid, $prescriptionId, []);
            MedicalEventsRepository::medicationRequest()->saveStatus($requestRecord, 'active');
            $this->refreshCarePlan();
            Session::flash('success', __('Рецепт успішно розблоковано в ЕСОЗ.'));
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to unblock prescription: ' . $e->getMessage());
            Session::flash('error', __('Помилка розблокування рецепту: ') . $e->getMessage());
        }
    }

    public function checkDispenseHistory(string $prescriptionId): void
    {
        $record = app(MedicalRequestOwnership::class)->medicationForPerson($prescriptionId, (int) $this->carePlan->personId);
        if (Auth::user()->cannot('dispenses', $record)) {
            Session::flash('error', __('medication-requests.permission_denied'));

            return;
        }
        try {
            $this->ePrescriptionDispenses = app(MedicationRequestLifecycleService::class)->getDispenseHistory($this->carePlan->person->uuid, $prescriptionId);
            if ($this->ePrescriptionDispenses === []) {
                Session::flash('info', __('medication-requests.dispenses_empty'));
            }
        } catch (Exception $exception) {
            $this->ePrescriptionDispenses = [];
            Log::warning('Prescription dispense history failed', ['exceptionType' => $exception::class]);
            Session::flash('error', __('medication-requests.detail_unavailable'));
        }
    }

    use SubmitsClinicalOperations;

    #[Computed]
    public function referralPriorityOptions(): array
    {
        $name = ($this->referralForm->kind ?? '') === 'device_request'
            ? 'device_request_priority'
            : 'SERVICE_REQUEST_PRIORITY';

        return dictionary()->basics()->byName($name)->asCodeDescription()->all();
    }

    public function initReferralForm(int $activityId): void
    {
        if ($this->authorizeCarePlanActivityWrite() === false) {
            return;
        }

        try {
            $activity = $this->ownedActivity($activityId);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        }

        $activityStatus = strtolower(is_array($activity->status)
            ? ($activity->status['coding'][0]['code'] ?? ($activity->status['text'] ?? ''))
            : (string) $activity->status);

        $blockedActivityStatuses = ['cancelled', 'completed'];

        if ($this->isTerminalCarePlan) {
            Session::flash('error', __('care-plan.cannot_mutate_terminal_care_plan', [
                'status' => CarePlanStatus::labelFor($this->carePlan->status),
            ]));

            return;
        }

        if (in_array($activityStatus, $blockedActivityStatuses)) {
            Session::flash('error', __('Виписування направлення заборонено: це призначення вже завершено або скасовано.'));

            return;
        }

        $resolvedKind = $activity->resolvedKind();
        if (!in_array($resolvedKind, ['service_request', 'device_request'], true)) {
            Session::flash('error', __('care-plan.referral_wrong_activity_kind'));

            return;
        }

        try {
            EHealth::carePlanActivity()->assertRegisteredInEHealth($this->carePlan->person?->uuid, $this->carePlan->uuid, $activity->uuid);
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());

            return;
        }

        if ($resolvedKind === 'device_request') {
            $this->redirectRoute('persons.device-requests.create', [
                'legalEntity' => legalEntity(),
                'person' => $this->carePlan->person,
                'carePlan' => $this->carePlan->id,
                'activity' => $activity->id,
            ]);

            return;
        }

        $existingDraft = app(ReferralRequestLifecycleService::class)->findDraftByActivity($activity);
        if ($existingDraft) {
            if (app(ReferralRequestLifecycleService::class)->trySyncDraftFromEHealth($this->carePlan, $activity, $existingDraft, $resolvedKind)) {
                if ($activity->status === 'scheduled') {
                    $this->saveActivityDraft(['status' => 'in-progress'], $this->carePlan, $activity->id);
                }
                $this->refreshCarePlan();
                $documentLabel = $resolvedKind === 'device_request'
                    ? __('care-plan.document_type_device_eprescription')
                    : __('care-plan.document_type_service_referral');
                Session::flash('success', __('care-plan.referral_already_in_ehealth_synced', ['document' => $documentLabel]));

                return;
            }

            $this->referralRequestIdToSign = $existingDraft->uuid;
            $documentLabel = $resolvedKind === 'device_request'
                ? __('care-plan.document_type_device_eprescription')
                : __('care-plan.document_type_service_referral');
            Session::flash('info', __('care-plan.referral_unsigned_draft_found', ['document' => $documentLabel]));
        }

        $this->showSignatureModal = false;
        $this->resetValidation();
        $this->referralWarningMessage = '';
        $this->referralDevicePackageQty = 0;

        // Calculate remaining quantity
        $activityQty = (float) ($activity->quantity ?? 0);
        $issuedQty = app(ReferralRequestLifecycleService::class)->sumIssuedQuantity($activity);
        $this->referralRemainingQty = $activity->quantity === null
            ? 1.0
            : max(0.0, $activityQty - $issuedQty);

        $defaultQuantity = min($this->referralRemainingQty, 1.0);
        if ($resolvedKind === 'device_request') {
            $this->referralDevicePackageQty = $this->resolveDevicePackageQuantity($activity);
            if ($this->referralDevicePackageQty <= 0) {
                Session::flash('error', __('care-plan.device_package_qty_unknown'));

                return;
            }

            if ($this->referralRemainingQty < $this->referralDevicePackageQty) {
                Session::flash('error', __('care-plan.device_remaining_below_packaging', [ 'remaining' => $this->referralRemainingQty, 'count' => $this->referralDevicePackageQty, ]));

                return;
            }

            // Default to one package; never fall back to 1 piece when package > 1.
            $defaultQuantity = (float) $this->referralDevicePackageQty;
        }

        $activityResource = Repository::carePlanActivity()->getResource($activity);
        $code = data_get($activityResource, 'detail.productReference.identifier.value')
            ?? ($activity->productCodeableConcept ?? ($activity->productReference ?? 'од.'));
        $this->referralServiceType = data_get($activityResource, 'detail.productReference.identifier.type.coding.0.code', 'service');
        $this->referralCategoryOptions = [];
        if ($this->referralServiceType === 'service_group') {
            try {
                $group = dictionary()->services()->flattened()->firstWhere('id', $code);
                if (($group['type'] ?? '') !== 'service_group' || empty($group['request_allowed'])) {
                    Session::flash('error', __('care-plan.service_group_referral_not_allowed'));

                    return;
                }
                $this->referralCategoryOptions = dictionary()->basics()
                    ->byName('eHealth/SNOMED/service_request_categories')->asCodeDescription()
                    ->except('transfer_of_care')->all(); // Transfers use the encounter discharge flow.
                if ($this->referralCategoryOptions === []) {
                    Session::flash('error', __('care-plan.referral_categories_unavailable'));

                    return;
                }
            } catch (Throwable $exception) {
                report($exception);
                Session::flash('error', __('care-plan.referral_categories_unavailable'));

                return;
            }
        }
        $category = $resolvedKind === 'service_request' && $this->referralServiceType !== 'service_group'
            ? $this->resolveServiceCategory((string) $code)
            : null;

        $this->referralServiceCategory = $category ?? ($this->referralServiceType === 'service_group' ? '' : 'procedure');

        $schedule = $activityResource['detail']['scheduledPeriod'] ?? [];
        $draftData = $existingDraft ? MedicalEventsRepository::serviceRequest()->formData($existingDraft) : [];
        if ($this->referralServiceType === 'service_group') {
            $this->referralServiceCategory = $draftData['category'] ?? '';
        }
        $occurrenceDates = $this->resolveReferralOccurrenceDates(
            isset($schedule['start']) ? Carbon::parse($schedule['start'], 'UTC')->setTimezone(config('app.timezone')) : null,
            isset($schedule['end']) ? Carbon::parse($schedule['end'], 'UTC')->setTimezone(config('app.timezone')) : null
        );
        if ($existingDraft) {
            foreach (['started_at' => 'start', 'ended_at' => 'end'] as $field => $boundary) {
                if (!empty($draftData[$field])) {
                    $instant = CarbonImmutable::parse($draftData[$field], 'UTC')->setTimezone(config('app.timezone'));
                    $occurrenceDates[$field] = $instant->format('d.m.Y');
                    $occurrenceDates[$boundary.'_time'] = $instant->format('H:i');
                }
            }
        }

        $reasonReference = [];
        $grounds = collect($this->availableConditions)->concat($this->availableReports)->concat($this->availableObservations)->keyBy('uuid');
        $activity->reasonReferences()->with('type.coding')->get()->each(function ($identifier) use (&$reasonReference, $grounds) {
            $typeCode = $identifier->type->first()?->coding?->first()?->code ?? 'condition';
            $reasonReference[] = [
                'type' => $typeCode,
                'uuid' => $identifier->value,
                'name' => $grounds->get($identifier->value)['name'] ?? ($identifier->displayValue ?? __('care-plan.ground_name_unavailable')),
            ];
        });

        $informWith = '';
        $this->referralAuthMethods = [];
        try {
            $authMethods = EHealth::person()->getAuthMethods($this->carePlan->person->uuid)->validate();
            if (is_array($authMethods)) {
                $this->referralAuthMethods = collect($authMethods)->map(static function (array $method): array {
                    $uuid = (string) ($method['id'] ?? ($method['uuid'] ?? ''));
                    $type = (string) ($method['type'] ?? '');
                    $value = (string) ($method['phone_number'] ?? ($method['value'] ?? ''));

                    return [
                        'uuid' => $uuid,
                        'label' => trim($type.($value !== '' ? ' · '.$value : '')),
                        'raw' => $uuid !== '' ? "{$uuid}|{$type}|{$value}" : '',
                    ];
                })->filter(static fn (array $m): bool => $m['uuid'] !== '')->values()->all();

                if ($this->referralAuthMethods !== []) {
                    $informWith = $this->referralAuthMethods[0]['raw'];
                }
            }
        } catch (Throwable $e) {
            Log::warning('CarePlanShow: failed to load auth methods for referral: '.$e->getMessage());
        }

        $this->referralForm->reset();
        $this->referralForm->fill([
            'activity_id' => $activity->id,
            'kind' => $resolvedKind,
            'code' => $code,
            'code_label' => $this->resolveReferralServiceLabel($activity),
            'quantity' => $defaultQuantity,
            'started_at' => $occurrenceDates['started_at'],
            'ended_at' => $occurrenceDates['ended_at'],
            'start_time' => $occurrenceDates['start_time'],
            'end_time' => $occurrenceDates['end_time'],
            'priority' => 'routine',
            'intent' => 'order',
            'category' => $this->referralServiceCategory,
            'category_label' => $this->resolveReferralCategoryLabel($this->referralServiceCategory),
            'note' => '',
            'patient_instruction' => '',
            'reason_reference' => $reasonReference,
            'inform_with' => $informWith,
            'program_id' => $activity->program ?? '',
            'supporting_info' => []
        ]);

        if ($existingDraft) {
            $this->referralForm->fill(array_replace($this->referralForm->toArray(), Arr::only($draftData, [
                'quantity', 'priority', 'intent', 'note', 'patient_instruction', 'inform_with', 'supporting_info',
            ])));
        }

        $this->referralShowRemainingQtyWarning = false;
        $this->referralSelectedActivity = $activity->toArray();
        $this->showReferralDrawer = true;
    }

    public function saveReferralDraft(): void
    {
        $this->validateReferral(false);
    }

    public function validateReferral(bool $sign = true): void
    {
        $plan = Repository::carePlan()->findById($this->carePlan->id);
        if (Auth::user()->cannot('manageActivities', $plan)) {
            Session::flash('error', __('care-plan.policy.manage_activities_denied'));

            return;
        }
        if (!$sign && ($this->referralForm->kind ?? null) !== 'service_request') {
            Session::flash('error', __('care-plan.referral_wrong_activity_kind'));

            return;
        }

        $this->referralWarningMessage = '';
        $this->referralShowRemainingQtyWarning = false;

        if ($this->referralForm->kind === 'service_request' && $this->referralServiceType !== 'service_group') {
            $this->referralForm->category = $this->referralServiceCategory ?: ($this->referralForm->category ?? 'procedure');
        }

        $this->referralForm->validate($this->referralForm->rulesForReferral(
            array_keys($this->referralPriorityOptions()),
            array_keys($this->referralCategoryOptions),
            $this->referralServiceType === 'service_group'
        ));
        $period = $this->referralOccurrencePeriod();

        if ($this->referralForm->kind === 'service_request') {
            $activityProgram = $this->referralSelectedActivity['program'] ?? null;
            $this->referralForm->program_id = !empty($activityProgram) ? $activityProgram : null;
        }

        $qty = (float) $this->referralForm->quantity;
        if ($qty > $this->referralRemainingQty) {
            $this->referralShowRemainingQtyWarning = true;
            $this->referralWarningMessage = __('Кількість перевищує залишок за призначенням (') . $this->referralRemainingQty . ')';
            $this->flashUserError(__('Кількість перевищує залишок за призначенням.'));

            return;
        }

        if (
            ($this->referralForm->kind ?? '') === 'device_request'
            && $this->referralDevicePackageQty <= 0
        ) {
            $message = __('care-plan.device_package_qty_unknown');
            $this->referralWarningMessage = $message;
            $this->flashUserError($message);

            return;
        }

        if (
            ($this->referralForm->kind ?? '') === 'device_request'
            && $this->referralDevicePackageQty > 0
            && ((int) $qty % $this->referralDevicePackageQty) !== 0
        ) {
            $message = __('care-plan.device_quantity_packaging', ['count' => $this->referralDevicePackageQty]);
            $this->referralWarningMessage = $message;
            $this->flashUserError($message);

            return;
        }

        // Propose to sign
        $this->showReferralDrawer = false;

        try {
            $activity = $this->ownedActivity((int) $this->referralForm->activity_id);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        }
        if ($sign && $activity) {
            $existingDraft = app(ReferralRequestLifecycleService::class)->findDraftByActivity($activity);
            if ($existingDraft && app(ReferralRequestLifecycleService::class)->trySyncDraftFromEHealth(
                $this->carePlan,
                $activity,
                $existingDraft,
                $this->referralForm->kind
            )) {
                $this->refreshCarePlan();
                Session::flash('success', __('care-plan.referral_already_in_ehealth_synced', [
                    'document' => __('care-plan.document_type_service_referral'),
                ]));

                return;
            }
        }

        try {
            $this->carePlan = Repository::carePlan()->findById($this->carePlan->id);

            $employeeContext = app(ReferralRequestLifecycleService::class)->resolveEmployeeContext(
                $this->carePlan,
                $activity,
                Auth::user()?->activeDoctorEmployee()?->id
            );

            $this->referralRequestIdToSign = app(ReferralRequestLifecycleService::class)->createCarePlanDraft(
                $this->carePlan,
                array_replace($this->referralForm->toArray(), $period),
                $qty,
                $employeeContext,
                prequalify: $sign
            );
            if (!$sign) {
                $this->showReferralDrawer = true;
                $this->showSignatureModal = false;
                $this->actionType = '';
                Session::flash('success', __('referrals.draft_saved'));

                return;
            }
            $this->assertReferralOccurrencePeriod($period);
            $signAction = $this->referralForm->kind === 'service_request'
                ? 'sign_servicerequest'
                : 'sign_devicerequest';
            $this->openSignatureModal($signAction);
            Session::flash('success', __('care-plan.referral_request_created'));
        } catch (ValidationException $exception) {
            $this->showReferralDrawer = true;
            $this->showSignatureModal = false;
            $this->actionType = '';
            $this->flashUserError($exception->validator->errors()->first());

            throw $exception;
        } catch (EHealthValidationException $exception) {
            $exception->report();
            $this->showReferralDrawer = true;
            $this->flashUserError($exception->getTranslatedMessage());
        } catch (Exception $exception) {
            $this->showReferralDrawer = true;
            Log::error('CarePlanShow: failed to create referral request: ' . $exception->getMessage());
            $kindLabel = ($this->referralForm->kind ?? '') === 'device_request'
                ? __('електронний рецепт на медичні вироби')
                : __('заявку на направлення');
            $this->flashUserError(__('Не вдалося створити ').$kindLabel.': '.$exception->getMessage());
        }
    }

    public function resendReferralSms(string $requestId, string $kind): void
    {
        if ($this->authorizeCarePlanActivityWrite() === false) {
            return;
        }
        if ($kind === 'device_request') {
            $this->redirectRoute('persons.device-requests', ['legalEntity' => legalEntity(), 'person' => $this->carePlan->person]);

            return;
        }
        try {
            app(MedicalRequestOwnership::class)
                ->referralForPerson($requestId, (int) $this->carePlan->personId);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        }

        try {
            $response = app(ReferralRequestLifecycleService::class)->resendSms($this->carePlan->person->uuid, $requestId, $kind);

            if ($response->successful()) {
                Session::flash('success', __('care-plan.referral_sms_resent'));

                return;
            }

            Session::flash('error', __('referrals.sms_failed'));
        } catch (EHealthValidationException $exception) {
            Log::error('CarePlanShow: failed to resend referral SMS validation: ' . $exception->getTranslatedMessage());
            Session::flash('error', $exception->getTranslatedMessage());
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() === 403) {
                Log::warning('CarePlanShow: referral SMS resend forbidden by eHealth ACL', [
                    'request_id' => $requestId,
                    'person_uuid' => $this->carePlan->person->uuid,
                ]);
                Session::flash('warning', __('care-plan.referral_sms_forbidden'));

                return;
            }

            Log::error('CarePlanShow: failed to resend referral SMS response: ' . $exception->getMessage());
            Session::flash('error', __('Помилка надсилання СМС: ') . $exception->getMessage());
        } catch (Exception $exception) {
            Log::error('CarePlanShow: failed to resend referral SMS: ' . $exception->getMessage());
            Session::flash('error', __('Помилка надсилання СМС: ') . $exception->getMessage());
        }
    }

    public function signReferral(): void
    {
        if (empty($this->referralRequestIdToSign)) {
            $this->flashUserError(__('Не вибрано документ для підписання'));
            $this->showSignatureModal = false;

            return;
        }

        $this->carePlan = Repository::carePlan()->findById($this->carePlan->id);

        try {
            $requestRecord = app(MedicalRequestOwnership::class)
                ->referralForPerson((string) $this->referralRequestIdToSign, (int) $this->carePlan->personId);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        }

        $kind = $requestRecord instanceof ServiceRequestRequest
            ? 'service_request'
            : 'device_request';

        if ($kind === 'device_request') {
            $this->redirectRoute('persons.device-requests.create', ['legalEntity' => legalEntity(), 'person' => $this->carePlan->person, 'draft' => $requestRecord->uuid]);

            return;
        }

        try {
            $activity = $this->ownedActivityByBasedOnUuid($requestRecord->basedOn?->value);

            $employeeContext = $this->resolveReferralEmployeeContext($requestRecord, $activity);

            $uuids = [
                'person_uuid' => $this->carePlan->person->uuid,
                'encounter_uuid' => $this->carePlan->encounter?->uuid ?? null,
                'episode_uuid' => $this->carePlan->episodeUuid(),
                'employee_uuid' => $employeeContext['employee_uuid'],
                'legal_entity_uuid' => $employeeContext['legal_entity_uuid'],
            ];

            $dbData = $this->buildReferralSignDbData($requestRecord, $activity);
            $this->assertReferralOccurrencePeriod($dbData);

            if ($kind === 'service_request') {
                $resource = Fhir::serviceRequest()->toFhir($dbData, $uuids + ['carePlanUuid' => $this->carePlan->uuid, 'resource' => MedicalEventsRepository::serviceRequest()->getResource($requestRecord)]);
                $signPayload = ServiceRequestForm::buildCreateContent($resource);
            } else {
                $signPayload = (new DeviceRequestMapper())->toCreateSignedContent($dbData, $uuids, (string) $this->carePlan->uuid, (string) $activity->uuid);
            }

            $signedContent = signatureService()->signData(
                $signPayload,
                $this->form->password,
                $this->form->knedp,
                $this->form->keyContainerUpload,
                Auth::user()->party->taxId
            );

            $this->assertReferralOccurrencePeriod($dbData);
            $requestRecord = MedicalEventsRepository::serviceRequest()->storeResource($resource, $this->carePlan->personId, $requestRecord->employeeId, $requestRecord->divisionId);
            $finalResponse = $this->submitClinicalOperation($requestRecord, ['status' => 'active'], fn () => SubmissionApi::serviceRequest()->createSigned($this->carePlan->person->uuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']));
            if ($finalResponse === null) {
                $this->redirectRoute('care-plans.activities.show', [legalEntity(), $this->carePlan->id, $activity->id], navigate: true);

                return;
            }

            $dbData = app(ReferralRequestLifecycleService::class)->persistAfterSignedCreate(
                $dbData,
                $finalResponse,
                $kind,
                (int) $this->carePlan->personId
            );

            $this->finalizeSignedReferral($dbData, $kind, $activity, alreadyPersisted: true);

        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

        } catch (ValidationException $exception) {
            if (collect($exception->errors())->keys()->contains(static fn (string $key): bool => in_array($key, ['form.password', 'form.knedp', 'form.keyContainerUpload'], true))) {
                throw $exception;
            }
            $this->showSignatureModal = false;
            $this->initReferralForm($activity->id);
            $this->setErrorBag($exception->validator->errors());
            $this->flashUserError($exception->validator->errors()->first());
        } catch (EHealthValidationException $e) {
            if ($e->isDuplicateReferralError()) {
                try {
                    $activity = $this->ownedActivityByBasedOnUuid($requestRecord->basedOn?->value);

                    $dbData = $this->buildReferralSignDbData($requestRecord, $activity);
                    $dbData = app(ReferralRequestLifecycleService::class)->syncReferralFromRemote(
                        $this->carePlan,
                        $activity,
                        $requestRecord,
                        $kind,
                        $dbData
                    );
                    $this->finalizeSignedReferral($dbData, $kind, $activity, alreadyPersisted: true, alreadyExists: true);
                } catch (Exception $syncException) {
                    Log::error('CarePlanShow: failed to sync referral after duplicate eHealth id: ' . $syncException->getMessage());
                    $this->flashUserError(__('Документ вже існує в ЕСОЗ, але не вдалося синхронізувати локальні дані: ') . $syncException->getMessage());
                    $this->showSignatureModal = false;
                }

                return;
            }

            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to sign referral validation: ' . $translatedMsg);
            $this->flashUserError($translatedMsg);
            $this->showSignatureModal = false;
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to sign referral: ' . $e->getMessage());
            $this->flashUserError(__('Не вдалося підписати документ: ') . $e->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function cancelReferral(string $requestId, string $kind): void
    {
        if ($kind === 'device_request') {
            $this->redirectRoute('persons.device-requests', ['legalEntity' => legalEntity(), 'person' => $this->carePlan->person]);

            return;
        }
        try {
            $record = app(MedicalRequestOwnership::class)->serviceForPerson($requestId, (int) $this->carePlan->personId);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));

            return;
        }
        if (Auth::user()->cannot('cancel', $record)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }
        $this->openSignatureModal('cancel_referral', null, $requestId);
    }

    public function recallReferral(string $requestId, string $kind): void
    {
        try {
            $record = app(MedicalRequestOwnership::class)->serviceForPerson($requestId, (int) $this->carePlan->personId);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));

            return;
        }
        if (Auth::user()->cannot('recall', $record)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }
        $this->referralExplanatoryLetter = '';
        $this->openSignatureModal('recall_referral', null, $requestId);
    }

    public function signRecallReferral(): void
    {
        if (empty($this->referralRequestIdToSign)) {
            Session::flash('error', __('Не вибрано направлення для відкликання'));
            $this->showSignatureModal = false;

            return;
        }

        $letter = trim((string) $this->referralExplanatoryLetter);
        if ($letter === '') {
            $this->addError('referralExplanatoryLetter', __('care-plan.referral_recall_letter_required'));

            return;
        }

        try {
            $record = app(MedicalRequestOwnership::class)
                ->referralForPerson((string) $this->referralRequestIdToSign, (int) $this->carePlan->personId);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        }
        $service = $record instanceof ServiceRequestRequest ? $record : null;
        $device = $service ? null : $record;

        $record = $service ?: $device;

        if (!$service) {
            Session::flash('error', __('care-plan.referral_recall_service_only'));
            $this->showSignatureModal = false;

            return;
        }

        if (Auth::user()->cannot('recall', $service)) {
            Session::flash('error', __('referrals.messages.action_unavailable'));

            return;
        }

        $this->validate(['statusReason' => ['required', 'string']]);
        try {
            $api = EHealth::serviceRequest();
            $snapshot = $this->fetchServiceRequestSnapshot($this->carePlan->person->uuid, $record->uuid);
            if (Auth::user()->cannot('registeredAction', [ServiceRequestRequest::class, $snapshot, 'recall'])) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return;
            }
            $record = MedicalEventsRepository::serviceRequest()->storeResource((new ServiceRequestMapper())->normalize($snapshot), (int) $this->carePlan->personId, $record->employeeId, $record->divisionId);
            $payload = ServiceRequestForm::buildStatusContent($snapshot, $this->statusReason, $letter, 'eHealth/service_request_recall_reasons');

            $signedContent = signatureService()->signData(
                $payload,
                $this->form->password,
                $this->form->knedp,
                $this->form->keyContainerUpload,
                Auth::user()->party->taxId
            );

            $finalResponse = $this->submitClinicalOperation($record, ['status' => 'recalled', 'statusReason' => $payload['status_reason'], 'explanatoryLetter' => $letter], fn () => SubmissionApi::serviceRequest()->recall($this->carePlan->person->uuid, $record->uuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']), 'service_request:recall');
            if ($finalResponse === null) {
                return;
            }

            $this->persistReferralStatusFromJob(
                $finalResponse,
                $record,
                ServiceRequestStatus::RECALLED
            );
            $this->showSignatureModal = false;
            $this->referralExplanatoryLetter = '';
            $this->refreshCarePlan();
            Session::flash('success', __('care-plan.referral_recall_success'));
        } catch (EHealthValidationException $e) {
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to recall referral validation: '.$translatedMsg);
            Session::flash('error', $translatedMsg);
            $this->showSignatureModal = false;
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to recall referral: '.$e->getMessage());
            Session::flash('error', __('Не вдалося відкликати направлення: ').$e->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function signCancelReferral(): void
    {
        if (empty($this->referralRequestIdToSign)) {
            Session::flash('error', __('Не вибрано направлення для скасування'));
            $this->showSignatureModal = false;

            return;
        }

        try {
            $record = app(MedicalRequestOwnership::class)
                ->referralForPerson((string) $this->referralRequestIdToSign, (int) $this->carePlan->personId);
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        }
        $service = $record instanceof ServiceRequestRequest ? $record : null;
        $device = $service ? null : $record;

        $record = $service ?: $device;

        $kind = $service ? 'service_request' : 'device_request';
        if ($service) {
            if (Auth::user()->cannot('cancel', $service)) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return;
            }
            $this->validate(['statusReason' => ['required', 'string'], 'referralExplanatoryLetter' => ['required', 'string']]);
        }

        if ($kind === 'device_request') {
            $this->redirectRoute('persons.device-requests', ['legalEntity' => legalEntity(), 'person' => $this->carePlan->person]);

            return;
        }

        try {
            $payload = [
                'status_reason' => $this->statusReason ?: 'entered-in-error'
            ];

            if ($kind === 'service_request') {
                $api = EHealth::serviceRequest();
                $snapshot = $this->fetchServiceRequestSnapshot($this->carePlan->person->uuid, $record->uuid);
                if (Auth::user()->cannot('registeredAction', [ServiceRequestRequest::class, $snapshot, 'error'])) {
                    Session::flash('error', __('referrals.messages.action_unavailable'));

                    return;
                }
                $record = MedicalEventsRepository::serviceRequest()->storeResource((new ServiceRequestMapper())->normalize($snapshot), (int) $this->carePlan->personId, $record->employeeId, $record->divisionId);
                $payload = ServiceRequestForm::buildStatusContent($snapshot, $this->statusReason, trim($this->referralExplanatoryLetter), 'eHealth/service_request_cancel_reasons');
            }

            $signedContent = signatureService()->signData(
                $kind === 'service_request' ? $payload : Arr::toSnakeCase($payload),
                $this->form->password,
                $this->form->knedp,
                $this->form->keyContainerUpload,
                Auth::user()->party->taxId
            );

            $finalResponse = $this->submitClinicalOperation($record, ['status' => 'entered-in-error', 'statusReason' => $payload['status_reason'], 'explanatoryLetter' => trim($this->referralExplanatoryLetter)], fn () => SubmissionApi::serviceRequest()->cancel($this->carePlan->person->uuid, $record->uuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']), 'service_request:cancel');
            if ($finalResponse === null) {
                return;
            }

            $this->persistReferralStatusFromJob(
                $finalResponse,
                $record,
                ServiceRequestStatus::ENTERED_IN_ERROR
            );
            $this->showSignatureModal = false;
            $this->refreshCarePlan();
            Session::flash('success', __('care-plan.referral_cancel_success'));
        } catch (EHealthValidationException $e) {
            $translatedMsg = $e->getTranslatedMessage();
            Log::error('CarePlanShow: failed to cancel referral validation: ' . $translatedMsg);
            Session::flash('error', $translatedMsg);
            $this->showSignatureModal = false;
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to cancel referral: ' . $e->getMessage());
            Session::flash('error', __('Не вдалося скасувати направлення: ') . $e->getMessage());
            $this->showSignatureModal = false;
        }
    }

    public function loadReferralPrintoutForm(string $requestId): string
    {
        try {
            $record = app(MedicalRequestOwnership::class)->serviceForPerson($requestId, (int) $this->carePlan->personId);
            if (Auth::user()->cannot('viewRecord', $record)) {
                Session::flash('error', __('referrals.messages.action_unavailable'));

                return '';
            }
            $html = app(ReferralRequestLifecycleService::class)->buildPrintoutHtml($record, $requestId);
            $this->printableContent = $html;

            return $html;
        } catch (RuntimeException $exception) {
            Session::flash('error', $exception->getMessage());

            return '';
        } catch (Exception $e) {
            Log::error('CarePlanShow: failed to load referral printout: ' . $e->getMessage());
            Session::flash('error', __('Не вдалося завантажити друковану форму.'));

            return '';
        }
    }

    public function syncReferralFromEHealth(string $requestUuid, string $kind): void
    {
        $this->authorizeCarePlanWrite();

        try {
            $ownership = app(MedicalRequestOwnership::class);
            $requestRecord = match ($kind) {
                'service_request' => $ownership->serviceForPerson($requestUuid, (int) $this->carePlan->personId),
                'device_request' => $ownership->deviceForPerson($requestUuid, (int) $this->carePlan->personId),
                default => throw new ModelNotFoundException(),
            };
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

            return;
        }

        try {
            $activity = $this->ownedActivityByBasedOnUuid($requestRecord->basedOn?->value);

            $before = [
                'status' => (string) $requestRecord->status,
                'request_number' => (string) ($requestRecord->requestNumber ?? ''),
                'quantity' => (string) $requestRecord->quantity,
                'started_at' => $requestRecord->startedAt?->format('Y-m-d'),
                'ended_at' => $requestRecord->endedAt?->format('Y-m-d'),
            ];

            $dbData = $this->buildReferralSignDbData($requestRecord, $activity);
            app(ReferralRequestLifecycleService::class)->syncReferralFromRemote(
                $this->carePlan,
                $activity,
                $requestRecord,
                $kind,
                $dbData
            );

            ($requestRecord instanceof ServiceRequestRequest ? MedicalEventsRepository::serviceRequest() : MedicalEventsRepository::deviceRequest())->refreshRecord($requestRecord);

            $after = [
                'status' => (string) $requestRecord->status,
                'request_number' => (string) ($requestRecord->requestNumber ?? ''),
                'quantity' => (string) $requestRecord->quantity,
                'started_at' => $requestRecord->startedAt?->format('Y-m-d'),
                'ended_at' => $requestRecord->endedAt?->format('Y-m-d'),
            ];

            Log::info('CarePlanShow: referral synced from eHealth', [
                'request_uuid' => $requestUuid,
                'person_uuid' => $this->carePlan->person->uuid,
                'kind' => $kind,
                'before' => $before,
                'after' => $after,
            ]);

            if ($activity->status === 'scheduled') {
                $this->saveActivityDraft(['status' => 'in-progress'], $this->carePlan, $activity->id);
            }

            $this->refreshCarePlan();

            $changes = [];
            foreach ($before as $field => $value) {
                if (($after[$field] ?? null) !== $value) {
                    $changes[] = match ($field) {
                        'status' => __('статус: ') . $this->resolveReferralStatusLabel($value) . ' → ' . $this->resolveReferralStatusLabel((string) $after[$field]),
                        'request_number' => __('номер: ') . ($value ?: '—') . ' → ' . ($after[$field] ?: '—'),
                        'quantity' => __('кількість: ') . $value . ' → ' . $after[$field],
                        'started_at' => __('початок: ') . ($value ?: '—') . ' → ' . ($after[$field] ?: '—'),
                        'ended_at' => __('кінець: ') . ($value ?: '—') . ' → ' . ($after[$field] ?: '—'),
                        default => $field,
                    };
                }
            }

            Session::flash('success', $changes === [] ? __('care-plan.referral_sync_no_changes') : __('care-plan.referral_sync_updated', ['changes' => implode('; ', $changes)]));
        } catch (ModelNotFoundException) {
            Session::flash('error', __('care-plan.document_context_unavailable'));
            $this->showSignatureModal = false;
            $this->actionType = '';

        } catch (Exception $exception) {
            Log::error('CarePlanShow: failed to sync referral from eHealth: ' . $exception->getMessage());
            Session::flash('error', __('Не вдалося оновити направлення з ЕСОЗ: ') . $exception->getMessage());
        }
    }

    protected function resolveReferralServiceLabel(CarePlanActivity $activity): string
    {
        $display = $activity->productReferenceIdentifier?->displayValue;
        if (filled($display)) {
            return $display;
        }
        try {
            $service = $this->findServiceInCatalog(dictionary()->services()->all(), (string) ($activity->productReferenceIdentifier?->value ?? $activity->productReference));
            if (filled($service['name'] ?? null)) {
                return trim(($service['code'] ?? '').' '.$service['name']);
            }
        } catch (Throwable $exception) {
            Log::warning('Care plan service label unavailable', ['exceptionType' => $exception::class]);
        }

        return __('care-plan.service_name_unavailable');
    }

    protected function resolveServiceCategory(string $serviceId): ?string
    {
        try {
            $catalog = dictionary()->services()->all();

            if (!is_array($catalog)) {
                return null;
            }

            $category = $this->findServiceCategoryInCatalog($catalog, $serviceId);

            return $category !== null ? (string) $category : null;
        } catch (Exception $exception) {
            Log::warning('CarePlanShow: failed to resolve service category: ' . $exception->getMessage());
        }

        return null;
    }

    /**
     * @param  array<mixed>  $nodes
     */
    protected function findServiceCategoryInCatalog(array $nodes, string $serviceId): ?string
    {
        return $this->findServiceInCatalog($nodes, $serviceId)['category'] ?? null;
    }

    protected function findServiceInCatalog(array $nodes, string $serviceId): ?array
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            if (($node['id'] ?? null) === $serviceId) {
                return $node;
            }

            foreach (['services', 'groups'] as $childKey) {
                if (!empty($node[$childKey]) && is_array($node[$childKey])) {
                    $service = $this->findServiceInCatalog($node[$childKey], $serviceId);
                    if ($service !== null) {
                        return $service;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array{started_at: string, ended_at: string}
     */
    protected function resolveReferralOccurrenceDates(?Carbon $scheduledStart, ?Carbon $scheduledEnd): array
    {
        $minStart = now()->addMinutes(30)->startOfMinute();
        $start = $scheduledStart && $scheduledStart->greaterThan($minStart)
            ? $scheduledStart->copy()
            : $minStart->copy();
        $end = $scheduledEnd?->copy() ?? $start->copy()->addMonths(3);

        return [
            'started_at' => $start->format('d.m.Y'),
            'ended_at' => $end->format('d.m.Y'),
            'start_time' => $start->format('H:i'),
            'end_time' => $end->format('H:i'),
        ];
    }

    protected function referralOccurrencePeriod(): array
    {
        $start = CarbonImmutable::createFromFormat(
            '!d.m.Y H:i',
            $this->referralForm->started_at.' '.$this->referralForm->start_time,
            config('app.timezone')
        );
        $end = CarbonImmutable::createFromFormat(
            '!d.m.Y H:i',
            $this->referralForm->ended_at.' '.$this->referralForm->end_time,
            config('app.timezone')
        );
        foreach (['start_time' => $start, 'end_time' => $end] as $field => $date) {
            if ($date->format('H:i') !== $this->referralForm->{$field}) {
                throw ValidationException::withMessages([
                    'referralForm.'.$field => __('referrals.period_fields.nonexistent_time'),
                ]);
            }
        }
        $period = ['started_at' => $start->toIso8601String(), 'ended_at' => $end->toIso8601String()];
        $this->assertReferralOccurrencePeriod($period);

        return $period;
    }

    protected function assertReferralOccurrencePeriod(array $period): void
    {
        $serverNow = CarbonImmutable::now();
        $rules = [
            'referralForm.start_time' => ['required', 'date', 'after_or_equal:'.$serverNow->toIso8601String()],
            'referralForm.ended_at' => ['required', 'date', 'after_or_equal:referralForm.start_time'],
        ];
        if (array_key_exists('priority', $period)) {
            $rules['referralForm.priority'] = ['required', Rule::in(array_keys($this->referralPriorityOptions()))];
        }
        $validator = Validator::make(['referralForm' => [
            'start_time' => $period['started_at'] ?? null,
            'ended_at' => $period['ended_at'] ?? null,
            'priority' => $period['priority'] ?? null,
        ]], $rules, [
            'referralForm.start_time.after_or_equal' => __('errors.ehealth.messages.occurrence_start_in_past').' '.__('referrals.period_fields.comparison_context', [
                'start' => $period['started_at'] ?? '',
                'now' => $serverNow->toIso8601String(),
            ]),
            'referralForm.ended_at.after_or_equal' => __('errors.ehealth.messages.period_end_before_start'),
        ]);
        if ($validator->fails() && isset($validator->failed()['referralForm.start_time']['AfterOrEqual'])) {
            Log::notice('Care plan referral start is in the past', [
                'draft_uuid' => $this->referralRequestIdToSign,
                'start' => $period['started_at'] ?? null,
                'end' => $period['ended_at'] ?? null,
                'server_now' => $serverNow->toIso8601String(),
                'timezone' => config('app.timezone'),
            ]);
        }
        $validator->validate();
    }

    /**
     * Resolve device packaging_count from eHealth device definition (package step for eRx).
     */
    protected function resolveDevicePackageQuantity(CarePlanActivity $activity): int
    {
        $reference = (string) ($activity->productReference ?? '');
        if ($reference === '') {
            return 0;
        }

        try {
            $device = $this->findDeviceDefinitionByReference($reference, $activity->program);

            if (!is_array($device)) {
                return 0;
            }

            $packaging = $device['packaging'] ?? null;
            $count = is_array($packaging)
                ? (int) ($packaging['packaging_count'] ?? ($packaging['packagingCount'] ?? 0))
                : 0;

            return max(0, $count);
        } catch (Throwable $exception) {
            Log::warning('CarePlanShow: failed to resolve device package quantity: '.$exception->getMessage());

            return 0;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findDeviceDefinitionByReference(string $reference, ?string $programId): ?array
    {
        try {
            $data = EHealth::deviceDefinition()->getById($reference)->validate();
            if (is_array($data) && (isset($data['id']) || isset($data['uuid']))) {
                return $data;
            }
            if (isset($data[0]) && is_array($data[0])) {
                return $data[0];
            }
        } catch (Throwable $exception) {
            Log::debug('CarePlanShow: device definition getById failed: '.$exception->getMessage(), [
                'uuid' => $reference,
            ]);
        }

        $filters = ['page_size' => 100];
        if (!empty($programId)) {
            $filters['medical_program_id'] = $programId;
        }

        $page = 1;
        $maxPages = 10;

        do {
            $filters['page'] = $page;
            $response = EHealth::deviceDefinition()->getMany($filters);
            $device = collect($response->validate())->first(
                static fn (array $item): bool => (string) ($item['id'] ?? ($item['uuid'] ?? '')) === $reference
            );

            if (is_array($device)) {
                return $device;
            }

            $paging = $response->getPaging();
            $totalPages = (int) ($paging['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages && $page <= $maxPages);

        return null;
    }

    protected function flashUserError(string $message): void
    {
        Session::flash('error', $message);
    }

    protected function flashUserSuccess(string $message): void
    {
        Session::flash('success', $message);
    }

    /**
     * @param  array<string, mixed>  $finalResponse
     */
    protected function persistReferralStatusFromJob(
        array $finalResponse,
        ServiceRequestRequest|DeviceRequestRequest $record,
        ServiceRequestStatus $fallback
    ): void {
        $result = $finalResponse['result'] ?? null;
        $entity = is_array($result) ? ($result[0] ?? $result) : $finalResponse;
        $raw = is_array($entity) ? ($entity['status'] ?? null) : null;
        $resolved = is_string($raw) ? ServiceRequestStatus::resolve($raw) : null;

        if ($record instanceof ServiceRequestRequest) {
            MedicalEventsRepository::serviceRequest()->updateClinicalStatus($record, ($resolved ?? $fallback)->value);
        } else {
            MedicalEventsRepository::deviceRequest()->saveStatus($record, ($resolved ?? $fallback)->value);
        }
    }

    /**
     * @return array{
     *     employee_id: int|null,
     *     division_id: int|null,
     *     employee_uuid: string|null,
     *     legal_entity_uuid: string|null
     * }
     */
    protected function resolveReferralEmployeeContext(
        ServiceRequestRequest|DeviceRequestRequest $requestRecord,
        CarePlanActivity $activity
    ): array {
        $context = app(ReferralRequestLifecycleService::class)->resolveEmployeeContext(
            $this->carePlan,
            $activity,
            $requestRecord->employeeId
        );

        return [
            'employee_id' => $requestRecord->employeeId ?? $context['employee_id'],
            'division_id' => $requestRecord->divisionId ?? $context['division_id'],
            'employee_uuid' => $context['employee_uuid'],
            'legal_entity_uuid' => $context['legal_entity_uuid'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildReferralSignDbData(
        ServiceRequestRequest|DeviceRequestRequest $requestRecord,
        CarePlanActivity $activity
    ): array {
        $employeeContext = $this->resolveReferralEmployeeContext($requestRecord, $activity);

        return app(ReferralRequestLifecycleService::class)->buildSignDbData(
            $requestRecord,
            $activity,
            $this->carePlan,
            $employeeContext
        );
    }

    /**
     * @param  array<string, mixed>  $dbData
     */
    protected function finalizeSignedReferral(array $dbData, string $kind, CarePlanActivity $activity, bool $alreadyPersisted = false, bool $alreadyExists = false): void
    {
        if (!$alreadyPersisted) {
            if ($kind === 'service_request') {
                app(ReferralRequestLifecycleService::class)->saveServiceRequest($dbData, $this->carePlan->personId);
            } else {
                MedicalEventsRepository::deviceRequest()->store($dbData, $this->carePlan->person_id);
            }
        }

        if ($activity->status === 'scheduled') {
            $this->saveActivityDraft(['status' => 'in-progress'], $this->carePlan, $activity->id);
        }

        $this->showSignatureModal = false;
        $documentLabel = $kind === 'device_request'
            ? __('Електронний рецепт на медичні вироби')
            : __('Електронне направлення');
        $finalStatusCode = strtolower((string) ($dbData['status'] ?? ''));
        if (in_array($finalStatusCode, ['pending', 'processing'], true)) {

            Session::flash('warning', $documentLabel.__(' прийнято в обробку ЕСОЗ. Фінальний статус з’явиться після завершення асинхронної задачі.'));
        } elseif ($alreadyExists) {
            $this->flashUserSuccess($documentLabel.__(' вже існував у ЕСОЗ. Локальні дані синхронізовано.'));
        } else {
            $message = __('care-plan.referral_submission_confirmed');
            if (filled($dbData['request_number'] ?? null)) {
                $message .= ' '.__('care-plan.document_number', ['number' => $dbData['request_number']]);
            }
            $this->flashUserSuccess($message);
        }
        $this->refreshCarePlan();
    }

    protected function resolveReferralCategoryLabel(string $category): string
    {
        $key = 'care-plan.referral_category.' . $category;

        return Lang::has($key) ? __($key) : $category;
    }

    #[Locked]
    public ?int $smsResendLinkId = null;

    #[Locked]
    public bool $isSmsResending = false;

    public function resendSms(): void
    {
        if (!$this->approvalId || $this->smsResent || $this->isSmsResending
            || AuthenticationMethod::confirmationMethod($this->currentAuthMethod ?? []) !== AuthenticationMethod::OTP) {
            return;
        }
        try {
            $plan = $this->carePlanForSms();
            if (!$this->guardApprovalAccess($plan)) {
                return;
            }
            app(MedicalRequestOwnership::class)->approvalForCarePlan($plan, $this->approvalId);
            $this->resetValidation('verificationCode');
            $this->isSmsResending = true;
            $token = session()->get(config('ehealth.api.oauth.bearer_token'));
            if (!$token || !Auth::user() || !legalEntity()) {
                throw new RuntimeException(__('care-plan.sync_session_expired'));
            }
            $result = $this->requestCarePlanApprovalSmsResend($plan, $this->approvalId, legalEntity(), Auth::user(), $token);
            if ($result['status'] === 'pending') {
                $this->smsResendLinkId = $result['linkId'];
                Session::flash('info', __('care-plan.sms_resend_processing'));

                return;
            }
            $this->finishSmsResend($result['status']);
        } catch (Throwable $exception) {
            $this->isSmsResending = false;
            Log::warning('Care plan approval SMS resend failed', ['exceptionType' => $exception::class]);
            $message = $exception instanceof EHealthValidationException ? $exception->getFormattedMessage()
                : ($exception instanceof RuntimeException ? $exception->getMessage() : __('care-plan.sms_resend_error'));
            $this->addError('verificationCode', $message);
            Session::flash('error', $message);
        }
    }

    public function checkSmsResendStatus(): void
    {
        if ($this->smsResendLinkId === null || !$this->approvalId) {
            return;
        }
        $plan = $this->carePlanForSms();
        if (Auth::user()->cannot('view', $plan)) {
            Session::flash('error', __('care-plan.approval_access_denied'));

            return;
        }
        $state = MedicalEventsRepository::approval()->carePlanSmsResendState($plan, $this->approvalId, $this->smsResendLinkId);
        if (in_array($state['status'], ['PENDING', 'PROCESSING'], true)) {
            return;
        }
        $this->finishSmsResend(strtolower($state['status']));
    }

    protected function finishSmsResend(string $status): void
    {
        $this->smsResendLinkId = null;
        $this->isSmsResending = false;
        if ($status === 'granted') {
            $this->closeAuthModal();
            Session::flash('success', __('care-plan.approval_verified'));
            $this->onSmsApprovalAlreadyGranted();
        } elseif ($status === 'processed') {
            $this->smsResent = true;
            $this->verificationCode = '';
            $this->resetValidation('verificationCode');
            Session::flash('success', __('care-plan.sms_resent'));
        } else {
            $this->smsResent = false;
            $this->addError('verificationCode', __('care-plan.sms_resend_error'));
            Session::flash('error', __('care-plan.sms_resend_error'));
        }
    }

    protected function buildCreateCarePlanContent(array $resource): array
    {
        Validator::make($resource, [
            'id' => ['required', 'uuid'], 'title' => ['required', 'string'],
            'category.coding' => ['required', 'array', 'min:1'],
            'period.start' => ['required', 'date'],
            'encounter.identifier.value' => ['required', 'uuid'],
            'author.identifier.value' => ['required', 'uuid'],
            'addresses' => ['required', 'array', 'min:1'],
            'termsOfService.coding' => ['required', 'array', 'min:1'],
            'basedOn' => ['nullable', 'array'],
            'basedOn.identifier.value' => ['required_with:basedOn', 'uuid'],
            'partOf' => ['nullable', 'array'],
            'partOf.identifier.value' => ['required_with:partOf', 'uuid'],
        ])->validate();
        $content = array_intersect_key($resource, array_flip(['id', 'category', 'title', 'description', 'period', 'supportingInfo', 'note', 'encounter', 'addresses', 'author', 'contributor', 'termsOfService', 'informWith', 'basedOn', 'partOf']));
        $content['intent'] = 'order';
        $content['status'] = 'new';

        return $this->resourcePayload($content);
    }

    protected function buildCancelCarePlanContent(array $snapshot, array $statusReason): array
    {
        Validator::make($snapshot, ['id' => ['required', 'uuid']])->validate();
        unset($snapshot['activities']);
        $snapshot['status_reason'] = $statusReason;

        return $snapshot;
    }

    protected function resourcePayload(array $data): array
    {
        $result = [];
        foreach (Arr::toSnakeCase($data) as $key => $value) {
            if (is_array($value)) {
                $value = $this->resourcePayload($value);
            }
            if ($value !== null && $value !== '' && $value !== []) {
                $result[$key] = $value;
            }
        }

        return array_is_list($data) ? array_values($result) : $result;
    }

    protected function buildCreateCarePlanActivityContent(array $resource): array
    {
        Validator::make($resource, [
            'id' => ['required', 'uuid'], 'author' => ['required', 'array', 'min:1'],
            'author.*.identifier.value' => ['required', 'uuid'],
            'carePlan.identifier.value' => ['required', 'uuid'],
            'detail.kind' => ['required', 'in:'.implode(',', array_column(CarePlanActivityKind::cases(), 'value'))],
        ])->validate();
        $detail = $resource['detail'];
        $kind = CarePlanActivityKind::from($detail['kind']);
        $requiresProgram = $kind === CarePlanActivityKind::MEDICATION_REQUEST;
        $rules = [
            'program' => [$requiresProgram ? 'required' : 'sometimes', 'array'],
            'program.identifier.value' => ['required_with:program', 'uuid'],
            'quantity.value' => [isset($detail['program']) || $kind === CarePlanActivityKind::SERVICE_REQUEST ? 'required' : 'sometimes', $kind === CarePlanActivityKind::MEDICATION_REQUEST ? 'numeric' : 'integer', 'gt:0'],
            'scheduledPeriod' => [isset($detail['program']) && !isset($detail['scheduledTiming']) && !isset($detail['scheduledString']) ? 'required' : 'sometimes', 'array'],
            'scheduledPeriod.start' => ['required_with:scheduledPeriod', 'date'],
            'scheduledPeriod.end' => ['required_with:scheduledPeriod', 'date', 'after_or_equal:scheduledPeriod.start'],
        ];
        Validator::make($detail, $rules, [], ['quantity.value' => __('care-plan.ehealth_fields.quantity_value')])->validate();
        if ($kind !== CarePlanActivityKind::MEDICATION_REQUEST && isset($detail['quantity']['value'])) {
            $detail['quantity']['value'] = (int) $detail['quantity']['value'];
        }
        if (isset($detail['productReference']) === isset($detail['productCodeableConcept'])) {
            throw ValidationException::withMessages(['product' => 'Exactly one product variant is required.']);
        }
        $content = array_intersect_key($resource, array_flip(['id', 'author', 'carePlan']));
        $content['detail'] = array_intersect_key($detail, array_flip(['kind', 'productReference', 'productCodeableConcept', 'reasonCode', 'reasonReference', 'goal', 'quantity', 'location', 'performer', 'dailyAmount', 'scheduledPeriod', 'scheduledTiming', 'scheduledString', 'description', 'program', 'doNotPerform']));
        $content['detail']['status'] = 'scheduled';

        return $this->resourcePayload($content);
    }

    protected function buildCancelCarePlanActivityContent(array $snapshot, array $reason): array
    {
        Validator::make($snapshot, ['id' => ['required', 'uuid'], 'detail' => ['required', 'array']])->validate();
        $snapshot['detail']['status_reason'] = $reason;

        return $snapshot;
    }

    protected function buildCompleteCarePlanActivityContent(array $reason, array $outcomes): array
    {
        Validator::make(['reason' => $reason, 'outcomes' => $outcomes], [
            'reason.coding' => ['required', 'array', 'min:1'],
            'outcomes' => ['required', 'array', 'min:1'],
            'outcomes.*.coding' => ['required', 'array', 'min:1'],
        ])->validate();

        return ['detail' => ['status_reason' => $reason], 'outcome_codeable_concept' => $outcomes];
    }

    protected function buildCarePlanApprovalPayload(
        CarePlan $carePlan,
        string $employeeUuid,
        string $accessLevel = 'write',
        ?string $authorizeWith = null,
        ?LegalEntity $legalEntity = null,
    ): array {
        $payload = [
            'resources' => [
                [
                    'identifier' => [
                        'type' => [
                            'coding' => [['system' => 'eHealth/resources', 'code' => 'care_plan']],
                        ],
                        'value' => $carePlan->uuid,
                    ],
                ],
            ],
            'granted_to' => [
                'identifier' => [
                    'type' => [
                        'coding' => [['system' => 'eHealth/resources', 'code' => 'employee']],
                    ],
                    'value' => $employeeUuid,
                ],
            ],
            'access_level' => $accessLevel,
            'authorize_with' => $authorizeWith ?: null,
        ];

        if ($carePlan->skipsPatientOtp($legalEntity) || empty($payload['authorize_with'])) {
            unset($payload['authorize_with']);
        }

        return $payload;
    }

    protected function createCarePlanApproval(
        CarePlan $carePlan,
        string $patientUuid,
        string $employeeUuid,
        string $accessLevel = 'write',
        ?string $authorizeWith = null,
        ?LegalEntity $legalEntity = null,
        ?User $user = null,
        ?string $bearerToken = null,
    ): array {
        Repository::carePlan()->assertApprovalCreationReady($carePlan);
        $legalEntity ??= legalEntity();
        if (!$legalEntity || !$user || !$bearerToken || $patientUuid !== $carePlan->person?->uuid) {
            throw new RuntimeException(__('care-plan.approval_create_error'));
        }
        $repository = MedicalEventsRepository::approval();
        Repository::carePlan()->assertApprovalRecipient($employeeUuid, $legalEntity->id);
        $confirmWithoutOtp = $carePlan->skipsPatientOtp($legalEntity);
        $lock = Cache::lock('care-plan-approval-create:'.$carePlan->id.':'.$employeeUuid.':'.$accessLevel, 120);
        if (!$lock->get()) {
            throw new RuntimeException(__('care-plan.approval_processing'));
        }
        $link = null;
        $creationConfirmed = false;
        try {
            $existing = $repository->reusableForCarePlan($carePlan, $employeeUuid, $accessLevel);
            if ($existing !== null) {
                if ($existing->getAttribute('is_verified') && $existing->expiresAt?->isFuture() === true) {
                    return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $existing->uuid, 'pollingLinkId' => null, 'authMethod' => null];
                }
                $existingLink = $existing->ehealthLinks->filter(fn ($candidate): bool => data_get($candidate->job?->request_data, 'operation') !== 'resendSms')->sortByDesc('id')->first();
                if ($existingLink !== null && !in_array($existingLink->status, ['PROCESSED', 'FAILED'], true)) {
                    return $this->carePlanApprovalResultForLink($existingLink->id);
                }
                try {
                    $data = $this->requireApprovalResponse(EHealth::approval()->withToken($bearerToken)->getApprovalDetails($patientUuid, $existing->uuid))->validate();
                } catch (EHealthResponseException $exception) {
                    if ($exception->response->status() !== 404) {
                        throw $exception;
                    }
                    $repository->markUnavailable($existing);
                    $data = []; // A missing remote resource expires local access without inventing approval fields.
                }
                if ($data !== []) {
                    $repository->sync($data, $carePlan, $existing);
                }
                $existing->refresh();
                if ($this->carePlanApprovalIsGranted($data)) {
                    return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $existing->uuid, 'pollingLinkId' => null, 'authMethod' => null];
                }
                if (!$existing->getAttribute('is_verified') && $existing->expiresAt?->isFuture() === true
                    && ($confirmWithoutOtp || $authorizeWith === null || $existing->authorizeWith === $authorizeWith)) {
                    if ($confirmWithoutOtp) {
                        $this->confirmCarePlanApprovalWithoutOtp($patientUuid, $existing->uuid, $bearerToken);

                        return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $existing->uuid, 'pollingLinkId' => null, 'authMethod' => null];
                    }

                    return $this->carePlanApprovalResultFromData($data, $patientUuid, $existing->authorizeWith);
                }
            }
            $payload = $this->buildCarePlanApprovalPayload($carePlan, $employeeUuid, $accessLevel, $authorizeWith, $legalEntity);
            $link = $repository->beginCarePlanRequest($carePlan, $payload, $confirmWithoutOtp);
            $response = $this->requireApprovalResponse(EHealth::approval()->withToken($bearerToken)->createApproval($patientUuid, $payload));
            $data = $response->validate();
            if ($response->getStatusCode() === 202) {
                $href = collect($data['links'] ?? [])->first(fn (array $candidate): bool => str_contains((string) ($candidate['href'] ?? ''), '/jobs/'))['href'] ?? null;
                if (!$href) {
                    throw new RuntimeException(__('care-plan.submission_unconfirmed'));
                }
                $repository->trackRequest($link, $href, $data);
                Bus::batch([new RemoteEHealthLinksProcessing($link, $legalEntity, standalone: true)])
                    ->withOption('legal_entity_id', $legalEntity->id)
                    ->withOption('token', Crypt::encryptString($bearerToken))
                    ->withOption('user', $user)
                    ->name(RemoteEHealthLinksProcessing::BATCH_NAME)->onQueue('sync')->dispatch();

                return ['outcome' => CarePlanApprovalCreateOutcome::Async, 'approvalId' => null, 'pollingLinkId' => $link->id, 'authMethod' => null];
            }
            $repository->completeCarePlanRequest($link, $data);
            $creationConfirmed = true;
            if ($confirmWithoutOtp && empty($data['is_verified'])) {
                $this->confirmCarePlanApprovalWithoutOtp($patientUuid, $data['id'], $bearerToken);

                return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $data['id'], 'pollingLinkId' => null, 'authMethod' => null];
            }

            return $this->carePlanApprovalResultFromData($data, $patientUuid, $authorizeWith);
        } catch (Throwable $exception) {
            if ($link !== null && !$creationConfirmed && ($exception instanceof EHealthValidationException
                || ($exception instanceof EHealthResponseException && $exception->getCode() >= 400 && $exception->getCode() < 500 && $exception->getCode() !== 408))) {
                RemoteEHealthLinksProcessing::markSubmissionFailed($link);
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    protected function carePlanApprovalResultForLink(int $linkId): array
    {
        $result = $this->resolveCarePlanApprovalJob($linkId);
        if (($result['outcome']->value === 'failed')) {
            throw new RuntimeException($result['errorMessage'] ?: __('care-plan.approval_result_unavailable'));
        }
        if (($result['outcome']->value === 'granted')) {
            return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $result['approvalId'], 'pollingLinkId' => null, 'authMethod' => null];
        }
        if (in_array($result['outcome']->value, ['otp_required', 'offline_required'], true)) {
            return ['outcome' => ($result['outcome']->value === 'otp_required') ? CarePlanApprovalCreateOutcome::OtpRequired : CarePlanApprovalCreateOutcome::OfflineRequired, 'approvalId' => $result['approvalId'], 'pollingLinkId' => null, 'authMethod' => $result['authMethod']];
        }

        return ['outcome' => CarePlanApprovalCreateOutcome::Async, 'approvalId' => null, 'pollingLinkId' => $linkId, 'authMethod' => null];
    }

    protected function pendingCarePlanApprovalConfirmation(string $patientUuid, string $approvalId, ?string $authorizeWith = null): array
    {
        $response = $this->requireApprovalResponse(EHealth::approval()->getApprovalDetails($patientUuid, $approvalId));
        $data = $response->validate();
        if (($data['id'] ?? null) !== $approvalId) {
            throw new RuntimeException(__('care-plan.approval_result_unavailable'));
        }
        $result = $this->carePlanApprovalResultFromData($data, $patientUuid, $authorizeWith);
        if (($result['outcome']->value === 'granted')) {
            MedicalEventsRepository::approval()->storePatientResponse($patientUuid, $approvalId, $data);
        }

        return $result;
    }

    protected function carePlanApprovalResultFromData(array $data, ?string $patientUuid = null, ?string $authorizeWith = null): array
    {
        if ($this->carePlanApprovalIsGranted($data)) {
            return ['outcome' => CarePlanApprovalCreateOutcome::Granted, 'approvalId' => $data['id'], 'pollingLinkId' => null, 'authMethod' => null];
        }
        if (($data['is_verified'] ?? null) !== false || empty($data['expires_at'])
            || !CarbonImmutable::parse($data['expires_at'])->isFuture()) {
            throw new RuntimeException(__('care-plan.approval_result_unavailable'));
        }
        $method = $this->extractCarePlanApprovalAuthMethod($data);
        $method = app(PatientApprovalService::class)->resolveConfirmationMethod($method ?? [], $patientUuid, $data['authorize_with'] ?? $authorizeWith);
        if (isset($data['id']) && AuthenticationMethod::confirmationMethod($method ?? []) !== null) {
            $outcome = AuthenticationMethod::confirmationMethod($method) === AuthenticationMethod::OTP
                ? CarePlanApprovalCreateOutcome::OtpRequired : CarePlanApprovalCreateOutcome::OfflineRequired;

            return ['outcome' => $outcome, 'approvalId' => $data['id'], 'pollingLinkId' => null, 'authMethod' => $method];
        }

        throw new RuntimeException(__('care-plan.approval_result_unavailable'));
    }

    protected function carePlanApprovalIsGranted(array $data): bool
    {
        return ($data['is_verified'] ?? false) === true
            && !empty($data['expires_at'])
            && CarbonImmutable::parse($data['expires_at'])->isFuture();
    }

    protected function verifyCarePlanApproval(string $patientUuid, string $approvalId, ?int $code, ?string $bearerToken = null): EHealthResponse
    {
        $response = EHealth::approval()->verifyConfirmed($patientUuid, $approvalId, $code, $bearerToken);
        MedicalEventsRepository::approval()->storePatientResponse($patientUuid, $approvalId, $response->validate());

        return $response;
    }

    protected function confirmCarePlanApprovalWithoutOtp(string $patientUuid, string $approvalId, ?string $bearerToken = null): EHealthResponse
    {
        return $this->verifyCarePlanApproval($patientUuid, $approvalId, null, $bearerToken);
    }

    protected function requireApprovalResponse(EHealthResponse $response): EHealthResponse
    {
        if (!$response->successful()) {
            throw new EHealthResponseException($response);
        }

        return $response;
    }

    protected function resendCarePlanApprovalSms(string $patientUuid, string $approvalId, ?string $bearerToken = null): EHealthResponse
    {
        $key = 'care-plan-otp-resend:'.$patientUuid.':'.$approvalId;

        if (!Cache::add($key, true, now()->addMinutes(10))) {
            throw new RuntimeException(__('validation.sms_already_resent'));
        }

        try {
            $api = EHealth::approval();
            if ($bearerToken !== null) {
                $api->withToken($bearerToken);
            }

            return $this->requireApprovalResponse($api->resendSms($patientUuid, $approvalId));
        } catch (Throwable $exception) {
            if ($exception instanceof EHealthValidationException
                || ($exception instanceof EHealthResponseException && $exception->getCode() >= 400 && $exception->getCode() < 500 && $exception->getCode() !== 408)) {
                Cache::forget($key);
            }
            throw $exception;
        }
    }

    protected function syncCarePlanApprovals(CarePlan $carePlan): void
    {
        // Uses Get approvals filters (granted_resource_type + granted_resources) via syncApprovals.
        MedicalEventsRepository::approval()->syncApprovals($carePlan, 'care_plan', []);
    }

    protected function requestCarePlanApprovalSmsResend(CarePlan $plan, string $approvalId, LegalEntity $legalEntity, User $user, string $bearerToken): array
    {
        $patientUuid = $plan->person->uuid;
        $api = EHealth::approval()->withToken($bearerToken);
        $approval = MedicalEventsRepository::approval()->carePlanApproval($plan, $approvalId);
        if ($approval->getAttribute('is_verified') && $approval->expiresAt?->isFuture() === true) {
            return ['status' => 'granted'];
        }
        if ($approval->expiresAt?->isFuture() !== true) {
            throw new RuntimeException(__('care-plan.approval_result_unavailable'));
        }
        if ($plan->skipsPatientOtp($legalEntity)) {
            throw new RuntimeException(__('care-plan.sms_not_required'));
        }
        try {
            $response = $this->resendCarePlanApprovalSms($patientUuid, $approvalId, $bearerToken);
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() !== 409 || EHealth::approval()->errorMessage($exception->response) !== 'Approval is already verified') {
                throw $exception;
            }
            $details = $this->requireApprovalResponse($api->getApprovalDetails($patientUuid, $approvalId));
            $confirmed = $details->validate();
            if (($confirmed['id'] ?? null) !== $approvalId || !$this->carePlanApprovalIsGranted($confirmed)) {
                throw $exception;
            }
            MedicalEventsRepository::approval()->storePatientResponse($patientUuid, $approvalId, $confirmed);

            return ['status' => 'granted'];
        }
        $link = MedicalEventsRepository::approval()->beginCarePlanSmsResend($plan, $approvalId);
        if ($response->getStatusCode() === 202) {
            $jobData = $response->validate();
            $href = collect($jobData['links'] ?? [])->first(fn (array $candidate): bool => str_contains((string) ($candidate['href'] ?? ''), '/jobs/'))['href'] ?? null;
            if (!$href) {
                MedicalEventsRepository::approval()->finishCarePlanSmsResend($link, ['status' => 'failed']);
                throw new RuntimeException(__('care-plan.submission_unconfirmed'));
            }
            MedicalEventsRepository::approval()->trackRequest($link, $href, $jobData);
            try {
                Bus::batch([new RemoteEHealthLinksProcessing($link, $legalEntity, standalone: true)])
                    ->withOption('legal_entity_id', $legalEntity->id)
                    ->withOption('token', Crypt::encryptString($bearerToken))
                    ->withOption('user', $user)
                    ->name(RemoteEHealthLinksProcessing::BATCH_NAME)->onQueue('sync')->dispatch();
            } catch (Throwable $exception) {
                MedicalEventsRepository::approval()->finishCarePlanSmsResend($link, ['status' => 'failed']);
                throw $exception;
            }

            return ['status' => 'pending', 'linkId' => $link->id];
        }
        MedicalEventsRepository::approval()->finishCarePlanSmsResend($link, ['status' => 'processed', 'response_data' => $response->validate()]);

        return ['status' => 'processed'];
    }

    protected function resolveCarePlanApprovalJob(int $pollingLinkId): array
    {
        $link = MedicalEventsRepository::approval()->requestLink($pollingLinkId);
        if (!$link || !$link->job || !$link->linkable instanceof PatientApproval) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => __('care-plan.approval_result_unavailable')];
        }
        if (strtoupper((string) $link->job->status) === 'FAILED') {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => $this->formatCarePlanApprovalJobError($link->job->response_data ?? [])];
        }
        $approval = $link->linkable;
        if ($approval->getAttribute('is_verified') && $approval->expiresAt?->isFuture() === true) {
            return ['outcome' => CarePlanApprovalJobOutcome::Granted, 'approvalId' => $approval->uuid, 'authMethod' => null, 'errorMessage' => null];
        }
        if ($approval->expiresAt !== null && !$approval->expiresAt->isFuture()) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => __('care-plan.approval_result_unavailable')];
        }
        if (!empty($link->job->response_data['confirmationError'])) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => __('care-plan.approval_verify_error')];
        }
        if (strtoupper((string) $link->job->status) !== 'PROCESSED') {
            return ['outcome' => CarePlanApprovalJobOutcome::Pending, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => null];
        }
        try {
            $data = EHealth::approval()->validateResourceData($link->job->response_data ?? []);
            if (($data['authentication_method_current'] ?? null) === null && $approval->approvable instanceof CarePlan
                && $approval->approvable->skipsPatientOtp()) {
                return ['outcome' => CarePlanApprovalJobOutcome::Pending, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => null];
            }
            $authorizeWith = $approval->authorizeWith ?: data_get($link->job?->request_data, 'authorize_with');
            $result = $this->carePlanApprovalResultFromData($data, $approval->approvable?->person?->uuid, $authorizeWith);
        } catch (ValidationException) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => __('care-plan.approval_result_unavailable')];
        } catch (RuntimeException $exception) {
            return ['outcome' => CarePlanApprovalJobOutcome::Failed, 'approvalId' => null, 'authMethod' => null, 'errorMessage' => $exception->getMessage()];
        }

        return ['outcome' => ($result['outcome']->value === 'granted') ? CarePlanApprovalJobOutcome::Granted
                : (($result['outcome']->value === 'otp_required') ? CarePlanApprovalJobOutcome::OtpRequired : CarePlanApprovalJobOutcome::OfflineRequired), 'approvalId' => $result['approvalId'], 'authMethod' => $result['authMethod'], 'errorMessage' => null];
    }

    protected function extractCarePlanApprovalAuthMethod(array $data): ?array
    {
        $method = $data['response_data']['authentication_method_current']
            ?? ($data['data']['authentication_method_current']
                ?? ($data['authentication_method_current']
                    ?? ($data['urgent']['authentication_method_current'] ?? null)));

        return is_array($method) ? $method : null;
    }

    protected function formatCarePlanApprovalJobError(array $jobResult): string
    {
        if (isset($jobResult['error']['invalid']) && is_array($jobResult['error']['invalid'])) {
            $errors = [];

            foreach ($jobResult['error']['invalid'] as $invalid) {
                $entry = $invalid['entry'] ?? '';
                $rules = $invalid['rules'] ?? [];

                foreach ($rules as $rule) {
                    $errors[] = ($entry ? $entry.': ' : '').($rule['description'] ?? '');
                }
            }

            if ($errors !== []) {
                return 'Помилка від ЕСОЗ: '.implode(', ', $errors);
            }
        }

        if (isset($jobResult['error']['message'])) {
            return 'Помилка від ЕСОЗ: '.$jobResult['error']['message'];
        }

        return __('care-plan.approval_create_error');
    }

    protected function activityStatusChangeBlockReason(CarePlanActivity $activity, string $action): ?string
    {
        $open = Repository::carePlanActivity()->findOpenDocumentsForActivity($activity);
        if ($open === []) {
            return null;
        }

        $reasons = collect($open)
            ->map(fn (array $doc): string => $this->describeOpenDocument($doc))
            ->unique()
            ->values()
            ->all();

        $key = $action === 'complete_activity'
            ? 'care-plan.cannot_complete_activity_open_docs'
            : 'care-plan.cannot_cancel_activity_open_docs';

        return __($key, ['reasons' => implode('; ', $reasons)]);
    }

    protected function planCancelBlockReason(CarePlan $carePlan): ?string
    {
        $status = CarePlanStatus::fromStored($carePlan->status);
        if ($status->isTerminal()) {
            return __('care-plan.cannot_mutate_terminal_care_plan', [
                'status' => $status->label(),
            ]);
        }

        $blocking = Repository::carePlanActivity()->findActivitiesBlockingPlanCancel($carePlan);
        if ($blocking->isEmpty()) {
            return null;
        }

        $statuses = $blocking
            ->map(fn (CarePlanActivity $activity): string => strtolower((string) $activity->status))
            ->unique()
            ->map(fn (string $status): string => __('care-plan.status.'.$this->normalizeStatusKey($status)))
            ->values()
            ->all();

        return __('care-plan.cannot_cancel_plan_blocking_activities', [
            'statuses' => implode(', ', $statuses),
        ]);
    }

    protected function planCompleteBlockReason(CarePlan $carePlan): ?string
    {
        $status = CarePlanStatus::fromStored($carePlan->status);
        if ($status->isTerminal()) {
            return __('care-plan.cannot_mutate_terminal_care_plan', [
                'status' => $status->label(),
            ]);
        }

        Repository::carePlanActivity()->loadActivities($carePlan);

        $open = Repository::carePlanActivity()->findActivitiesBlockingPlanComplete($carePlan);

        if ($open->isNotEmpty()) {
            $statuses = $open
                ->map(fn (CarePlanActivity $activity): string => strtolower((string) $activity->status))
                ->unique()
                ->map(fn (string $activityStatus): string => __('care-plan.status.'.$this->normalizeStatusKey($activityStatus)))
                ->values()
                ->all();

            return __('care-plan.cannot_complete_plan_open_activities', [
                'statuses' => implode(', ', $statuses),
            ]);
        }

        $hasCompleted = $carePlan->activities->contains(
            fn (CarePlanActivity $activity): bool => strtolower((string) $activity->status) === 'completed'
        );

        if (!$hasCompleted) {
            return __('care-plan.cannot_complete_plan_no_completed_activity');
        }

        return null;
    }

    protected function describeOpenDocument(array $doc): string
    {
        $typeLabel = match ($doc['type']) {
            'medication_request_request' => __('care-plan.open_doc_type.medication_request_request'),
            'medication_request' => __('care-plan.open_doc_type.medication_request'),
            'service_request' => __('care-plan.open_doc_type.service_request'),
            'device_request' => __('care-plan.open_doc_type.device_request'),
            default => $doc['type'],
        };

        $statusLabel = __('care-plan.status.'.$this->normalizeStatusKey($doc['status']));

        return $typeLabel.' ('.$statusLabel.')';
    }

    protected function normalizeStatusKey(string $status): string
    {
        return str_replace('-', '_', strtolower($status));
    }

    protected function carePlanResourceFromFields(array $data, mixed ...$context): array
    {
        $data = Arr::toCamelCase($data);
        $options = $context[0] ?? [];
        $resource = $options['resource'] ?? [];
        $resource['id'] = $data['uuid'] ?? ($options['id'] ?? ($resource['id'] ?? null));

        if (!is_string($resource['id']) || $resource['id'] === '') {
            throw new InvalidArgumentException('Care Plan mapping requires an allocated resource UUID.');
        }

        foreach (['title', 'description', 'note', 'intent', 'status', 'requisition', 'context', 'statusReason'] as $field) {
            if (array_key_exists($field, $data)) {
                $resource[$field] = $data[$field];
            }
        }

        foreach (['category' => 'eHealth/care_plan_categories', 'termsOfService' => 'PROVIDING_CONDITION'] as $field => $system) {
            if (array_key_exists($field, $data)) {
                if (!is_string($data[$field]) || $data[$field] !== data_get($resource, $field.'.coding.0.code')) {
                    $resource[$field] = $this->carePlanConcept($data[$field], $system);
                }
            }
        }

        if (array_key_exists('author', $data) || !array_key_exists('author', $resource)) {
            $value = $data['author'] ?? ($options['authorUuid'] ?? ($options['employeeUuid'] ?? null));
            if ($value === null || $value === '') {
                unset($resource['author']);
            } elseif (!is_string($value) || $value !== data_get($resource, 'author.identifier.value')) {
                $resource['author'] = is_array($value) ? $value : FhirResource::make()
                    ->coding('eHealth/resources', 'employee')
                    ->toIdentifier($value);
            }
        }
        if (array_key_exists('encounter', $data) || !array_key_exists('encounter', $resource)) {
            $value = $data['encounter'] ?? ($options['encounterUuid'] ?? null);
            if ($value === null || $value === '') {
                unset($resource['encounter']);
            } elseif (!is_string($value) || $value !== data_get($resource, 'encounter.identifier.value')) {
                $resource['encounter'] = is_array($value) ? $value : FhirResource::make()
                    ->coding('eHealth/resources', 'encounter')
                    ->toIdentifier($value);
            }
        }
        if (array_key_exists('careManager', $data) || !array_key_exists('careManager', $resource)) {
            $value = $data['careManager'] ?? ($options['careManagerUuid'] ?? null);
            if ($value === null || $value === '') {
                unset($resource['careManager']);
            } elseif (!is_string($value) || $value !== data_get($resource, 'careManager.identifier.value')) {
                $resource['careManager'] = is_array($value) ? $value : FhirResource::make()
                    ->coding('eHealth/resources', 'employee')
                    ->toIdentifier($value);
            }
        }
        if (array_key_exists('managingOrganization', $data) || !array_key_exists('managingOrganization', $resource)) {
            $value = $data['managingOrganization'] ?? ($options['managingOrganizationUuid'] ?? null);
            if ($value === null || $value === '') {
                unset($resource['managingOrganization']);
            } elseif (!is_string($value) || $value !== data_get($resource, 'managingOrganization.identifier.value')) {
                $resource['managingOrganization'] = is_array($value) ? $value : FhirResource::make()
                    ->coding('eHealth/resources', 'legal_entity')
                    ->toIdentifier($value);
            }
        }

        foreach (['addresses', 'supportingInfo', 'contributor'] as $field) {
            if (array_key_exists($field, $data)) {
                $resource[$field] = $data[$field];
            } elseif (array_key_exists($field, $options) && !array_key_exists($field, $resource)) {
                $resource[$field] = $options[$field];
            }
        }

        foreach (['basedOn', 'partOf'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if ($value === null || $value === '') {
                unset($resource[$field]);
            } else {
                // Preserve the original display metadata when an unchanged link is reopened.
                $resource[$field] = $this->carePlanExistingReference($value, 'care_plan', isset($resource[$field]) ? [$resource[$field]] : []);
            }
        }

        if (array_key_exists('coAuthors', $data)) {
            $existing = $resource['contributor'] ?? [];
            $resource['contributor'] = array_map(fn (mixed $value): array => $this->carePlanExistingReference($value, 'employee', $existing), $data['coAuthors']);
        }

        if (array_key_exists('episodes', $data)) {
            $otherInfo = array_values(array_filter($resource['supportingInfo'] ?? [], static fn (array $reference): bool => data_get($reference, 'identifier.type.coding.0.code') !== 'episode_of_care'));
            $existing = $resource['supportingInfo'] ?? [];
            $episodes = array_map(fn (mixed $episode): array => $this->carePlanExistingReference(is_array($episode) && !isset($episode['identifier']) ? ($episode['uuid'] ?? ($episode['id'] ?? '')) : $episode, 'episode_of_care', $existing), $data['episodes']);
            $resource['supportingInfo'] = [...$otherInfo, ...$episodes];
        }

        if (array_key_exists('medicalRecords', $data)) {
            $episodes = array_values(array_filter($resource['supportingInfo'] ?? [], static fn (array $reference): bool => data_get($reference, 'identifier.type.coding.0.code') === 'episode_of_care'));
            $resource['supportingInfo'] = [...$episodes, ...$data['medicalRecords']];
        }

        if (array_key_exists('period', $data)) {
            $resource['period'] = $data['period'];
        } else {
            foreach (['start', 'end'] as $boundary) {
                $field = 'period'.ucfirst($boundary);
                if (array_key_exists($field, $data)) {
                    $resource['period'][$boundary] = $this->carePlanDateTime($data[$field], $data[$field.'Time'] ?? null, $options['timezone'] ?? 'UTC', $resource['period'][$boundary] ?? null, $boundary === 'end', $options['dateFormat'] ?? 'Y-m-d');
                }
            }
        }

        if (array_key_exists('informWith', $data)) {
            $resource['informWith'] = is_array($data['informWith']) || $data['informWith'] === null
                ? $data['informWith']
                : ($data['informWith'] === '' ? null : ['authMethodId' => $data['informWith']]);
        }

        return $resource;
    }

    protected function carePlanFieldsFromResource(array $data, mixed ...$context): array
    {
        $options = $context[0] ?? [];
        $form = array_intersect_key($data, array_flip(['title', 'description', 'note', 'intent', 'status', 'requisition', 'addresses', 'supportingInfo', 'basedOn', 'partOf', 'context']));
        $form['uuid'] = $data['id'];
        foreach (['category', 'termsOfService'] as $field) {
            $form[$field] = data_get($data, $field.'.coding.0.code', '');
        }
        foreach (['author', 'encounter', 'careManager', 'managingOrganization', 'basedOn', 'partOf'] as $field) {
            $form[$field] = data_get($data, $field.'.identifier.value', '');
        }
        $form['coAuthors'] = array_map(static fn (array $reference): string => $reference['identifier']['value'], $data['contributor'] ?? []);
        $form['episodes'] = [];
        $form['medicalRecords'] = [];
        foreach ($data['supportingInfo'] ?? [] as $reference) {
            if (data_get($reference, 'identifier.type.coding.0.code') === 'episode_of_care') {
                $form['episodes'][] = ['uuid' => $reference['identifier']['value']];
            } else {
                $form['medicalRecords'][] = $reference;
            }
        }
        foreach (['start', 'end'] as $boundary) {
            $field = 'period'.ucfirst($boundary);
            $value = data_get($data, 'period.'.$boundary);
            $date = $value ? CarbonImmutable::parse($value)->setTimezone($options['timezone'] ?? 'UTC') : null;
            $form[$field] = $date?->format($options['dateFormat'] ?? 'Y-m-d') ?? '';
            $form[$field.'Time'] = $date?->format('H:i:s') ?? '';
        }
        if (array_key_exists('informWith', $data)) {
            $form['informWith'] = data_get($data, 'informWith.authMethodId', '');
        }

        return $form;
    }

    protected function carePlanConcept(mixed $value, string $system): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_array($value) ? $value : FhirResource::make()->coding($system, (string) $value)->toCodeableConcept();
    }

    protected function carePlanReference(mixed $value, string $type): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('A reference requires a resource UUID.');
        }

        return FhirResource::make()->coding('eHealth/resources', $type)->toIdentifier($value);
    }

    protected function carePlanExistingReference(mixed $value, string $type, array $existing): array
    {
        foreach ($existing as $reference) {
            if (is_string($value) && data_get($reference, 'identifier.value') === $value && data_get($reference, 'identifier.type.coding.0.code') === $type) {
                return $reference;
            }
        }

        return $this->carePlanReference($value, $type);
    }

    protected function carePlanDateTime(?string $date, ?string $time, string $timezone, ?string $original, bool $end, string $dateFormat): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        $hasTime = str_contains($date, 'T') || preg_match('/\d{2}:\d{2}/', $date) === 1;
        $parsed = CarbonImmutable::hasFormat($date, $dateFormat)
            ? CarbonImmutable::createFromFormat('!'.$dateFormat, $date, $timezone)
            : CarbonImmutable::parse($date, $timezone);
        if ($original !== null) {
            $previous = CarbonImmutable::parse($original)->setTimezone($timezone);
            if (($hasTime && $previous->equalTo($parsed)) || (!$hasTime && $previous->toDateString() === $parsed->toDateString() && ($time === null || $time === '' || $previous->format(strlen($time) === 5 ? 'H:i' : 'H:i:s') === $time))) {
                return $original;
            }
        }
        if (!$hasTime) {
            $parsed = $parsed->setTimeFromTimeString($time ?: ($end ? '23:59:59' : '00:00:00'));
        }

        return rtrim(rtrim($parsed->utc()->format('Y-m-d\TH:i:s.u'), '0'), '.').'Z';
    }

    protected function carePlanActivityResourceFromFields(array $data, mixed ...$context): array
    {
        $data = Arr::toCamelCase($data);
        $options = $context[0] ?? [];
        $resource = $options['resource'] ?? [];
        $resource['id'] = $data['uuid'] ?? $options['id'] ?? $resource['id'] ?? null;
        if (!is_string($resource['id']) || $resource['id'] === '') {
            throw new InvalidArgumentException('Care Plan Activity mapping requires an allocated resource UUID.');
        }

        if (array_key_exists('author', $data)) {
            $authors = is_array($data['author']) && array_is_list($data['author']) ? $data['author'] : [$data['author']];
            $resource['author'] = array_map(fn (mixed $author): array => $this->carePlanActivityReference($author, 'employee'), $authors);
        } elseif (!isset($resource['author']) && isset($options['employeeUuid'])) {
            $resource['author'] = [$this->carePlanActivityReference($options['employeeUuid'], 'employee')];
        }
        if (array_key_exists('carePlan', $data) || isset($options['carePlanUuid'])) {
            $resource['carePlan'] = $this->carePlanActivityReference($data['carePlan'] ?? $options['carePlanUuid'], 'care_plan');
        }

        $detail = array_replace($resource['detail'] ?? [], $data['detail'] ?? []);
        foreach (['kind', 'status', 'doNotPerform', 'description', 'scheduledTiming', 'scheduledString', 'remainingQuantityType'] as $field) {
            if (array_key_exists($field, $data)) {
                $detail[$field] = $data[$field];
            }
        }
        $kind = CarePlanActivityKind::tryFrom($detail['kind'] ?? '')
            ?? throw new InvalidArgumentException('An activity requires a supported kind.');

        foreach (['productCodeableConcept' => 'device_definition_classification_type', 'statusReason' => null] as $field => $system) {
            if (array_key_exists($field, $data)) {
                $detail[$field] = $this->carePlanActivityConcept($data[$field], $system);
            }
        }
        foreach (['reasonCode' => 'eHealth/ICD10_AM/condition_codes', 'goal' => 'eHealth/care_plan_activity_goals'] as $field => $system) {
            if (array_key_exists($field, $data)) {
                $values = $data[$field] === null || $data[$field] === '' ? [] : (is_array($data[$field]) && array_is_list($data[$field]) ? $data[$field] : [$data[$field]]);
                $detail[$field] = array_map(fn (mixed $value): array => $this->carePlanActivityConcept($value, $system), $values);
            }
        }

        foreach (['productReference' => $options['productType'] ?? match ($kind) {
            CarePlanActivityKind::MEDICATION_REQUEST => 'medication',
            CarePlanActivityKind::DEVICE_REQUEST => 'device_definition',
            CarePlanActivityKind::SERVICE_REQUEST => 'service',
        }, 'location' => 'division', 'performer' => 'employee', 'program' => 'medical_program'] as $field => $type) {
            if (array_key_exists($field, $data)) {
                $detail[$field] = $data[$field] === null || $data[$field] === '' ? null : $this->carePlanActivityReference($data[$field], $type);
            }
        }
        if (isset($detail['productReference'], $detail['productCodeableConcept'])) {
            throw new InvalidArgumentException('An activity cannot contain both product variants.');
        }

        foreach (['quantity', 'dailyAmount', 'remainingQuantity'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                $detail[$field] = is_array($value) || $value === null ? $value : ($value === '' ? null : array_replace($detail[$field] ?? [], ['value' => is_numeric($value) ? $value + 0 : $value]));
                if (is_array($detail[$field])) {
                    foreach (['system', 'code', 'unit', 'comparator'] as $part) {
                        if (array_key_exists($field.ucfirst($part), $data)) {
                            $detail[$field][$part] = $data[$field.ucfirst($part)];
                        }
                    }
                }
            }
        }
        if (array_key_exists('reasonReference', $data)) {
            $existing = $detail['reasonReference'] ?? [];
            $detail['reasonReference'] = array_map(fn (mixed $value): array => $this->carePlanActivityReference($value, existing: $existing), $data['reasonReference'] ?? []);
        }
        if (array_key_exists('outcomeReference', $data)) {
            $resource['outcomeReference'] = array_map(fn (mixed $value): array => $this->carePlanActivityReference($value), $data['outcomeReference'] ?? []);
        }
        if (array_key_exists('outcomeCodeableConcept', $data)) {
            $resource['outcomeCodeableConcept'] = $data['outcomeCodeableConcept'] ?? [];
        }
        if (array_key_exists('scheduledPeriod', $data)) {
            $detail['scheduledPeriod'] = $data['scheduledPeriod'];
        } else {
            foreach (['start', 'end'] as $boundary) {
                $field = 'scheduledPeriod'.ucfirst($boundary);
                if (array_key_exists($field, $data)) {
                    $detail['scheduledPeriod'][$boundary] = $data[$field];
                }
            }
        }
        $scheduled = array_filter(['scheduledPeriod', 'scheduledTiming', 'scheduledString'], static fn (string $field): bool => isset($detail[$field]));
        if (count($scheduled) > 1) {
            throw new InvalidArgumentException('An activity must use a single scheduled variant.');
        }
        $resource['detail'] = $detail;

        return $resource;
    }

    protected function carePlanActivityFieldsFromResource(array $data, mixed ...$context): array
    {
        $detail = $data['detail'];
        $form = array_intersect_key($detail, array_flip(['kind', 'status', 'doNotPerform', 'description', 'reasonCode', 'goal', 'reasonReference', 'scheduledPeriod', 'scheduledTiming', 'scheduledString', 'remainingQuantityType', 'statusReason', 'productCodeableConcept']));
        $form['uuid'] = $data['id'];
        $form['author'] = $data['author'] ?? [];
        $form['carePlan'] = $data['carePlan'] ?? null;
        foreach (['productReference', 'location', 'performer', 'program'] as $field) {
            if (array_key_exists($field, $detail)) {
                $form[$field] = $detail[$field];
            }
        }
        foreach (['quantity', 'dailyAmount', 'remainingQuantity'] as $field) {
            if (array_key_exists($field, $detail)) {
                $form[$field] = $detail[$field];
            }
        }
        foreach (['outcomeReference', 'outcomeCodeableConcept'] as $field) {
            $form[$field] = $data[$field] ?? [];
        }

        return $form;
    }

    protected function carePlanActivityConcept(mixed $value, ?string $system): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if ($system === null) {
            throw new InvalidArgumentException('A status reason requires an explicit coding system.');
        }

        return FhirResource::make()->coding($system, (string) $value)->toCodeableConcept();
    }

    protected function carePlanActivityReference(mixed $value, ?string $type = null, array $existing = []): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && str_contains($value, '/')) {
            [$type, $value] = explode('/', $value, 2);
            $type = match (strtolower($type)) {
                'diagnosticreport' => 'diagnostic_report',
                'clinicalimpression' => 'clinical_impression',
                default => strtolower($type),
            };
        }
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('An activity reference requires its resource type and UUID.');
        }
        $matches = array_values(array_filter(
            $existing,
            static fn (array $reference): bool =>
            data_get($reference, 'identifier.value') === $value
            && ($type === null || data_get($reference, 'identifier.type.coding.0.code') === $type)
        ));
        if (count($matches) === 1) {
            return $matches[0];
        }
        if ($type === null) {
            throw new InvalidArgumentException('An activity reference requires an unambiguous existing reference or an explicit resource type.');
        }

        return FhirResource::make()->coding('eHealth/resources', $type)->toIdentifier($value);
    }

    protected function carePlanFormContext(array $form, ?int $localId = null): array
    {
        unset($form['keyContainerUpload'], $form['key_container_upload'], $form['password'], $form['knedp'], $form['keyContainerFileName']);
        $data = Arr::toCamelCase($form);
        $resource = $localId === null ? [] : Repository::carePlan()->getResource($localId);
        $authorUuid = $data['author'] ?? null;
        if (isset($data['authorId'])) {
            $authorUuid = Employee::whereKey($data['authorId'])->value('uuid');
        }
        $encounterUuid = $data['encounter'] ?? null;
        if (isset($data['encounterId'])) {
            $encounterUuid = Encounter::whereKey($data['encounterId'])->value('uuid');
        }
        $data['uuid'] = $data['uuid'] ?? ($resource['id'] ?? Uuid::uuid1()->toString());
        if ($authorUuid !== null) {
            $data['author'] = $authorUuid;
        }
        if ($encounterUuid !== null) {
            $data['encounter'] = $encounterUuid;
        }
        if (isset($data['supportingInfo']['episodes'])) {
            $data['episodes'] = $data['supportingInfo']['episodes'];
            $data['medicalRecords'] = $data['supportingInfo']['medicalRecords'] ?? [];
            unset($data['supportingInfo']);
        }
        foreach ($data['medicalRecords'] ?? [] as $index => $reference) {
            if (isset($reference['identifier'])) {
                continue;
            }
            $type = $reference['type'] ?? null;
            if (!in_array($type, ['procedure', 'diagnostic_report'], true) || !isset($reference['uuid'])) {
                throw new InvalidArgumentException('A medical record requires its resource type and UUID.');
            }
            $data['medicalRecords'][$index] = FhirResource::make()->coding('eHealth/resources', $type)->toIdentifier($reference['uuid']);
        }

        return ['data' => $data, 'context' => ['resource' => $resource, 'timezone' => config('app.timezone'), 'dateFormat' => config('app.date_format')]];
    }

    protected function prepareCarePlanDraft(array $fields, ?int $localId = null): array
    {
        $input = $this->carePlanFormContext($fields, $localId);

        return $this->carePlanResourceFromFields($input['data'], $input['context']);
    }

    protected function prepareActivityDraft(array $data, CarePlan $plan, ?int $localId = null): array
    {
        $data = Arr::toCamelCase($data);
        if (isset($data['kind'])) {
            $data['kind'] = CarePlanActivityKind::fromInput($data['kind'])->value;
        }
        $original = $localId === null ? [] : Repository::carePlanActivity()->getResource($localId);
        if ((isset($original['detail']['scheduledTiming']) || isset($original['detail']['scheduledString'])) && empty($data['scheduledPeriodStart']) && empty($data['scheduledPeriodEnd'])) {
            unset($data['scheduledPeriodStart'], $data['scheduledPeriodEnd']);
        }
        $data['uuid'] = $data['uuid'] ?? ($original['id'] ?? Uuid::uuid1()->toString());
        if (isset($data['authorId'])) {
            $data['author'] = Employee::whereKey($data['authorId'])->value('uuid');
            if ($data['author'] === data_get($original, 'author.0.identifier.value')) {
                unset($data['author']);
            }
        }
        foreach (['productReference', 'program'] as $field) {
            if (is_string($data[$field] ?? null) && $data[$field] === data_get($original, 'detail.'.$field.'.identifier.value')) {
                $data[$field] = $original['detail'][$field];
            }
        }
        foreach (['reasonCode', 'goal'] as $field) {
            $value = $data[$field] ?? null;
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $index => $item) {
                    if (is_string($item) && $item === data_get($original, 'detail.'.$field.'.'.$index.'.coding.0.code')) {
                        $value[$index] = $original['detail'][$field][$index];
                    }
                }
                $data[$field] = $value;
            }
            $code = is_array($value) && array_is_list($value) ? ($value[0] ?? null) : $value;
            if (is_string($value) && $code !== '' && $code === data_get($original, 'detail.'.$field.'.0.coding.0.code')) {
                unset($data[$field]);
            } elseif (is_string($value) && $code !== '' && count($original['detail'][$field] ?? []) > 1) {
                $system = $field === 'goal' ? 'eHealth/care_plan_activity_goals' : 'eHealth/ICD10_AM/condition_codes';
                $concept = FhirResource::make()->coding($system, $code)->toCodeableConcept();
                $data[$field] = [$concept, ...array_slice($original['detail'][$field], 1)];
            }
        }
        if (is_string($data['productCodeableConcept'] ?? null) && $data['productCodeableConcept'] === data_get($original, 'detail.productCodeableConcept.coding.0.code')) {
            unset($data['productCodeableConcept']);
        }
        if (is_string($data['statusReason'] ?? null)) {
            if ($data['statusReason'] === data_get($original, 'detail.statusReason.coding.0.code')) {
                unset($data['statusReason']);
            } else {
                throw new InvalidArgumentException('A status reason requires a complete CodeableConcept.');
            }
        }
        if (isset($data['scheduledPeriodStart']) || array_key_exists('scheduledPeriodEnd', $data)) {
            $existingPeriod = data_get($original, 'detail.scheduledPeriod', []);
            $planPeriod = Repository::carePlan()->getResource($plan)['period'];
            foreach (['start', 'end'] as $boundary) {
                $field = 'scheduledPeriod'.ucfirst($boundary);
                if (!array_key_exists($field, $data)) {
                    continue;
                }
                $date = $data[$field];
                $existing = $existingPeriod[$boundary] ?? null;
                if ($date === null || $date === '') {
                    $existingPeriod[$boundary] = null;
                } elseif ($existing !== null && CarbonImmutable::parse($existing)->setTimezone(config('app.timezone'))->toDateString() === CarbonImmutable::parse($date)->toDateString()) {
                    $existingPeriod[$boundary] = $existing;
                } else {
                    $parsed = CarbonImmutable::parse($date, config('app.timezone'));
                    $instant = ($boundary === 'end' ? $parsed->setTime(23, 59, 59) : $parsed->startOfDay())->utc();
                    $bound = isset($planPeriod[$boundary]) ? CarbonImmutable::parse($planPeriod[$boundary]) : null;
                    if ($bound && (($boundary === 'start' && $instant->lessThan($bound)) || ($boundary === 'end' && $instant->greaterThan($bound)))) {
                        if ($parsed->toDateString() !== $bound->setTimezone(config('app.timezone'))->toDateString()) {
                            throw new InvalidArgumentException('Activity schedule exceeds its Care Plan period.');
                        }
                        $instant = $bound;
                    }
                    $existingPeriod[$boundary] = $instant->utc()->toIso8601ZuluString();
                }
            }
            $data['scheduledPeriod'] = $existingPeriod;
        }
        foreach (['goal', 'reasonReference'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === null) {
                $data[$field] = [];
            }
        }
        $options = ['resource' => $original, 'carePlanUuid' => $plan->uuid];
        if (!empty($data['productReferenceType'])) {
            if (!in_array($data['productReferenceType'], ['service', 'service_group', 'medication', 'device_definition'], true)) {
                throw new InvalidArgumentException('Unsupported product reference type.');
            }
            $options['productType'] = $data['productReferenceType'];
        }
        $resource = $this->carePlanActivityResourceFromFields($data, $options);

        return $resource;
    }

    protected function displayedCarePlanDetails(CarePlan $plan, array $dictionaries): array
    {
        $resource = Repository::carePlan()->getResource($plan);
        $conceptLabel = static function (?array $concept, string $dictionary) use ($dictionaries): string {
            $labels = collect($concept['coding'] ?? [])->map(
                fn (array $coding): ?string =>
                $dictionaries[$dictionary][$coding['code'] ?? ''] ?? ($coding['display'] ?? null)
            )->filter()->unique()->all();

            return implode(', ', $labels) ?: ($concept['text'] ?? '—');
        };
        $formatDate = static function (?string $date): string {
            if (!$date) {
                return __('care-plan.no_end_date');
            }
            $format = config('app.date_format').(str_contains($date, 'T') ? ' H:i' : '');

            return CarbonImmutable::parse($date, 'UTC')->setTimezone(config('app.timezone'))->format($format);
        };
        $encounterUuid = data_get($resource, 'encounter.identifier.value');
        $encounter = $encounterUuid ? Repository::carePlan()->findEncounter($encounterUuid, $plan->personId) : null;
        $references = collect($resource['supportingInfo'] ?? []);
        $episodeUuids = $references->pluck('identifier.value')->filter()->all();
        $episodes = Episode::wherePersonId($plan->personId)->whereIn('uuid', $episodeUuids)->pluck('name', 'uuid');
        $diagnoses = [];
        $conditions = Repository::carePlan()->addressConditions($plan);
        $diagnosisCodes = collect($resource['addresses'] ?? [])->flatMap(function (array $address) use ($conditions): array {
            $condition = $conditions->get(data_get($address, 'reference.identifier.value'));

            return $condition?->code ? MedicalEventsRepository::codeableConcept()->toResource($condition->code)['coding'] ?? [] : ($address['coding'] ?? []);
        })
            ->where('system', 'eHealth/ICD10_AM/condition_codes')->pluck('code')->all();
        $diagnosisNames = Icd10::whereIn('code', $diagnosisCodes)->pluck('description', 'code');
        foreach ($resource['addresses'] ?? [] as $address) {
            $condition = $conditions->get(data_get($address, 'reference.identifier.value'));
            $concept = $condition?->code ? MedicalEventsRepository::codeableConcept()->toResource($condition->code) : $address;
            foreach ($concept['coding'] ?? [] as $coding) {
                $code = $coding['code'] ?? '';
                $name = $diagnosisNames[$code] ?? ($dictionaries['icpc2_codes'][$code] ?? ($coding['display'] ?? ($concept['text'] ?? '')));
                $diagnoses[] = trim($code.($name && $name !== $code ? ' — '.$name : ''));
            }
        }

        return [
            'requisition' => $resource['requisition'] ?? '—',
            'status' => CarePlanStatus::labelFor($resource['status'] ?? null),
            'category' => $conceptLabel($resource['category'] ?? null, 'care_plan_categories'),
            'title' => $resource['title'] ?? '—',
            'period' => $formatDate(data_get($resource, 'period.start')).' — '.$formatDate(data_get($resource, 'period.end')),
            'encounter' => $encounter ? __('care-plan.encounter').' №'.$encounter->id : __('care-plan.reference_unavailable'),
            'supporting_info' => $references->map(fn (array $reference): string => $episodes->get(data_get($reference, 'identifier.value')) ?: $reference['displayValue'] ?? __('care-plan.reference_unavailable'))->all(),
            'addresses' => array_values(array_unique($diagnoses)),
            'based_on' => (data_get($resource, 'basedOn.displayValue') ?? data_get($resource, 'basedOn.identifier.value')) ?? '—',
            'part_of' => (data_get($resource, 'partOf.displayValue') ?? data_get($resource, 'partOf.identifier.value')) ?? '—',
            'description' => $resource['description'] ?? '—',
            'status_reason' => $conceptLabel($resource['statusReason'] ?? null, match (CarePlanStatus::fromStored($resource['status'] ?? null)) {
                CarePlanStatus::COMPLETED => 'care_plan_complete_reasons',
                default => 'care_plan_cancel_reasons',
            }),
            'note' => $resource['note'] ?? '—',
        ];
    }

    protected function resolveEpisodeLabels(array $references, int $personId): array
    {
        $episodes = Episode::wherePersonId($personId)
            ->whereIn('uuid', array_column($references, 'uuid'))->with('period')->get()->keyBy('uuid');

        return array_map(static function (array $reference) use ($episodes): array {
            $episode = $episodes->get($reference['uuid']);

            return array_replace($reference, [
                'name' => $episode?->name ?: $reference['uuid'],
                'date' => $episode?->period?->start ? CarbonImmutable::parse($episode->period->start)->format(config('app.date_format')) : '—',
            ]);
        }, $references);
    }

    protected function saveCarePlanDraft(array $fields, int $personId, int $legalEntityId, ?int $localId = null): CarePlan
    {
        return DB::transaction(function () use ($fields, $personId, $legalEntityId, $localId): CarePlan {
            if ($localId !== null) {
                CarePlan::whereKey($localId)->lockForUpdate()->firstOrFail();
            }
            // Prepare from the locked snapshot so concurrent edits cannot overwrite untouched fields.
            $resource = $this->prepareCarePlanDraft($fields, $localId);

            return Repository::carePlan()->saveDraft($resource, $personId, $legalEntityId, $localId);
        });
    }

    protected function saveActivityDraft(array $fields, CarePlan $plan, ?int $localId = null): CarePlanActivity
    {
        return DB::transaction(function () use ($fields, $plan, $localId): CarePlanActivity {
            $plan = CarePlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ($localId !== null) {
                CarePlanActivity::whereCarePlanId($plan->id)->whereKey($localId)->lockForUpdate()->firstOrFail();
            }
            $resource = $this->prepareActivityDraft($fields, $plan, $localId);

            return Repository::carePlanActivity()->saveDraft($resource, $plan, $localId);
        });
    }

    /** TV39 quantities are packaging multiples within the inclusive period cap. */
    protected function buildDeviceQuantityOptions(array $packagingCounts, int $maxDailyCount, string $start, string $end): array
    {
        if ($start === '' || $end === '' || $maxDailyCount < 1) {
            return [];
        }
        if (!Validator::make(['start' => $start, 'end' => $end], ['start' => ['required', 'date'], 'end' => ['required', 'date']])->passes()) {
            return [];
        }
        $periodStart = CarbonImmutable::parse($start)->startOfDay();
        $periodEnd = CarbonImmutable::parse($end)->startOfDay();
        if ($periodEnd->lessThan($periodStart)) {
            return [];
        }
        $limit = $maxDailyCount * ((int) $periodStart->diffInDays($periodEnd) + 1);
        $options = [];
        foreach (array_unique($packagingCounts) as $count) {
            $count = (int) $count;
            if ($count < 1) {
                continue;
            }
            for ($quantity = $count; $quantity <= $limit; $quantity += $count) {
                $options[$quantity] = $quantity;
            }
        }
        ksort($options);

        return array_values($options);
    }

    protected function availableGrounds(int $personId): array
    {
        // Fetch patient conditions for outcomeReference selection
        $grounds = Repository::carePlan()->availableGrounds($personId);
        $conditions = $grounds['conditions'];
        $icdDescriptions = Icd10::whereIn('code', $conditions->pluck('code.coding')->flatten()->where('system', 'eHealth/ICD10_AM/condition_codes')->pluck('code'))->pluck('description', 'code');
        $icpcDescriptions = collect();
        if ($conditions->pluck('code.coding')->flatten()->contains('system', 'eHealth/ICPC2/condition_codes')) {
            try {
                $icpcDictionary = dictionary()->basics()->firstWhere('name', 'eHealth/ICPC2/condition_codes');
                $icpcDescriptions = (new BasicDictionaryCollection($icpcDictionary['values'] ?? []))->asCodeDescription();
            } catch (InvalidArgumentException $exception) {
                Log::warning('Care plan diagnosis labels unavailable', ['exceptionType' => $exception::class]);
            }
        }
        $conditions = $conditions->map(function ($condition) use ($icdDescriptions, $icpcDescriptions): array {
            $coding = $condition->code?->coding?->first();
            $description = match ($coding?->system) {
                'eHealth/ICD10_AM/condition_codes' => $icdDescriptions[$coding->code] ?? null,
                'eHealth/ICPC2/condition_codes' => $icpcDescriptions[$coding->code] ?? null,
                default => null,
            };

            return [
                'uuid' => $condition->uuid,
                'name' => $description ?: $condition->code?->text ?: $coding?->code ?: __('care-plan.ground_name_unavailable'),
                'date' => $condition->onsetDate ? Carbon::parse($condition->onsetDate)->format('d.m.Y') : '-',
            ];
        })->toArray();

        // Fetch patient diagnostic reports for justifications (grounds)
        $reports = $grounds['reports']->map(fn ($dr) => [
                'uuid' => $dr->uuid,
                'name' => $dr->code?->displayValue ?: $dr->code?->value ?: 'Diagnostic Report',
                'date' => $dr->issued ? Carbon::parse($dr->issued)->format('d.m.Y') : '-',
            ])->toArray();

        // Fetch patient observations for justifications (grounds)
        $observations = $grounds['observations']->map(fn ($obs) => [
                'uuid' => $obs->uuid,
                'name' => $obs->code?->text ?: 'Observation',
                'date' => $obs->issued ? Carbon::parse($obs->issued)->format('d.m.Y') : '-',
            ])->toArray();

        return compact('conditions', 'reports', 'observations');
    }

    protected function resolveEHealthPeriodBounds(CarePlan $carePlan): array
    {
        $period = Repository::carePlan()->getResource($carePlan->id)['period'] ?? [];

        return ['start' => isset($period['start']) ? CarbonImmutable::parse($period['start'], 'UTC') : null, 'end' => isset($period['end']) ? CarbonImmutable::parse($period['end'], 'UTC') : null];
    }
}
