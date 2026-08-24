<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Core\Arr;
use App\Enums\CarePlanStatus;
use App\Enums\MedicalProgram\Type;
use App\Enums\Person\ServiceRequestStatus;
use App\Enums\User\Role;
use App\Models\Employee\Employee;
use App\Traits\InteractsWithApprovals;
use App\Classes\eHealth\EHealth;
use App\Models\CarePlan;
use App\Services\Dictionary\DictionaryManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

abstract class CarePlanComponent extends Component
{
    use WithFileUploads;
    use InteractsWithApprovals;

    // Everything the server derives is #[Locked]: it is sent to the browser on every request
    // and would otherwise come back whatever the client made of it. Only properties bound with
    // wire:model or entangled with Alpine stay writable.

    #[Locked]
    public CarePlan $carePlan;

    public bool $showSignatureModal = false;
    public string $actionType = ''; // 'cancel', 'complete', 'sign_activity', 'complete_activity', 'cancel_activity'
    public string $statusReason = ''; // Used when cancelling or completing

    #[Locked]
    public ?int $activityToSign = null;

    #[Locked]
    public array $dictionaries = [];

    /** Entangled with Alpine in the method selection modal, so it cannot be locked. */
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
    public array $ePrescriptionForm = [];

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
    public array $referralForm = [];

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
    public array $activityForm = [
        'id' => null,
        'kind' => 'service_request',
        'program' => '',
        'quantity' => '',
        'quantity_system' => '',
        'quantity_code' => '',
        'daily_amount' => '',
        'reason_code' => '',
        'reason_reference' => '',
        'goal' => '',
        'description' => '',
        'scheduled_period_start' => '',
        'scheduled_period_end' => '',
        'product_reference' => '',
        'product_codeable_concept' => '',
    ];

    public array $form = [
        'knedp' => '',
        'keyContainerUpload' => null,
        'keyContainerFileName' => '',
        'password' => '',
    ];

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
            ->map(fn (array $item): array => $item + ['type' => 'Діагноз/Стан'])
            ->concat(collect($this->availableObservations)->map(fn (array $item): array => $item + ['type' => 'Спостереження']))
            ->concat(collect($this->availableReports)->map(fn (array $item): array => $item + ['type' => 'Діагностичний звіт']))
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

    public function hydrate(): void
    {
        if (isset($this->carePlan)) {
            $this->authorize('view', $this->carePlan);
        }
    }

    protected function authorizeCarePlanWrite(): void
    {
        $this->authorize('manage', $this->carePlan);
    }

    protected function ownedActivity(int $activityId): \App\Models\CarePlanActivity
    {
        $activity = $this->carePlan->activities()->whereKey($activityId)->first();

        if ($activity === null) {
            abort(404);
        }

        return $activity;
    }

    protected function bootCarePlan(CarePlan $carePlan): void
    {
        $this->authorize('view', $carePlan);

        $this->carePlan = $carePlan;
        $this->carePlan->load(['person', 'author.party', 'categoryConcept.coding', 'activities.kindConcept.coding']);

        $personId = $this->carePlan->personId;

        // Fetch patient conditions for outcomeReference selection
        $this->availableConditions = \App\Models\MedicalEvents\Sql\Condition::where('person_id', $personId)
            ->with('code.coding')->get()->map(fn ($c) => [
                'uuid' => $c->uuid,
                'name' => ($c->code?->text ?: null) ?? ($c->code?->coding?->first()?->code ?: null) ?? 'Unknown Condition',
                'date' => $c->onset_date ? \Carbon\Carbon::parse($c->onset_date)->format('d.m.Y') : '-',
            ])->toArray();

        // Fetch patient diagnostic reports for justifications (grounds)
        $this->availableReports = \App\Models\MedicalEvents\Sql\DiagnosticReport::where('person_id', $personId)
            ->get()->map(fn ($dr) => [
                'uuid' => $dr->uuid,
                'name' => $dr->code?->text ?: 'Diagnostic Report',
                'date' => $dr->issued ? \Carbon\Carbon::parse($dr->issued)->format('d.m.Y') : '-',
            ])->toArray();

        // Fetch patient observations for justifications (grounds)
        $this->availableObservations = \App\Models\MedicalEvents\Sql\Observation::where('person_id', $personId)
            ->get()->map(fn ($obs) => [
                'uuid' => $obs->uuid,
                'name' => $obs->code?->text ?: 'Observation',
                'date' => $obs->issued ? \Carbon\Carbon::parse($obs->issued)->format('d.m.Y') : '-',
            ])->toArray();

        // Basic dictionaries and medical programs come from different eHealth endpoints.
        // They are loaded separately so a missing optional dictionary cannot leave the
        // programme dropdowns silently empty.
        try {
            $basics = app(\App\Services\Dictionary\DictionaryManager::class)->basics();
            $this->dictionaries['care_plan_categories'] = $basics->byName('eHealth/care_plan_categories')
                ?->asCodeDescription()
                ?->toArray() ?? [];

            $this->dictionaries['care_plan_activity_outcomes'] = $basics->byName('eHealth/care_plan_activity_outcomes')
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
                ?? $basics->byName('eHealth/MEDICATION_REQUEST_REJECT_REASON')
                    ?->asCodeDescription()
                    ?->toArray()
                ?? [];

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
        } catch (\Exception $exception) {
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
        } catch (\Exception $exception) {
            Log::warning('CarePlanShow: failed to load medical programs: ' . $exception->getMessage());
        }

        $this->carePlanUuid = $this->carePlan->uuid;
        $this->patientId = $this->carePlan->person->uuid;


        $medicationRequestClass = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::class;
        $this->activePrescriptions = class_exists($medicationRequestClass)
            ? $medicationRequestClass::with('basedOn')->whereHas('basedOn', fn($q) => $q->whereIn('value', $this->carePlan->activities->pluck('uuid')))->get()->toArray()
            : [];
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
        return !filled($this->carePlan->uuid)
            && in_array($this->carePlanStatusCode(), [
                CarePlanStatus::DRAFT->value,
                CarePlanStatus::PENDING->value,
                'pending',
            ], true);
    }

    /**
     * Signed in eHealth, still `new`, and the current doctor has no confirmed approval yet.
     */
    public function getCanRequestPatientApprovalProperty(): bool
    {
        return filled($this->carePlan->uuid)
            && !$this->isTerminalCarePlan
            && $this->carePlanStatusCode() === CarePlanStatus::PENDING->value
            && !$this->hasGrantedApprovalForCurrentDoctor;
    }

    /**
     * Cancel / complete: plan is active in eHealth, or the current doctor already has a granted approval
     * while the plan is still `new` (no activities signed yet).
     */
    public function getCanChangePlanLifecycleProperty(): bool
    {
        if (!filled($this->carePlan->uuid) || $this->isTerminalCarePlan) {
            return false;
        }

        return $this->carePlanStatusCode() === CarePlanStatus::ACTIVE->value
            || $this->hasGrantedApprovalForCurrentDoctor;
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
        $this->carePlan->unsetRelation('approvals');
        $this->refreshCarePlan();
    }

    /**
     * Livewire AJAX does not remount the layout toast, so session flash alone is invisible.
     * Keep the session value for the next full page load and also push it to Alpine.
     */
    protected function flashOutcome(string $type, string $message): void
    {
        session()->flash($type, $message);
        $this->dispatch('flashMessage', ['message' => $message, 'type' => $type]);
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
            $rules['form.knedp'] = 'required|string';
            $rules['form.keyContainerUpload'] = 'required|file|max:1024';
            $rules['form.password'] = 'required|string';
        }

        if ($this->actionType === 'recall_referral') {
            $rules['referralExplanatoryLetter'] = 'required|string|min:3';
        }

        return $rules;
    }

    /**
     * Completing an activity is the one action here eHealth accepts unsigned
     * (API-007-006-0006), so it must not ask the doctor for a KEP.
     */
    protected function requiresDigitalSignature(): bool
    {
        return $this->actionType !== 'complete_activity';
    }

    public function getStatusReasonsProperty(): array
    {
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
        $upload = $this->form['keyContainerUpload'] ?? null;

        if ($upload && method_exists($upload, 'getClientOriginalName')) {
            $this->form['keyContainerFileName'] = $upload->getClientOriginalName();
        } elseif ($upload === null) {
            $this->form['keyContainerFileName'] = '';
        }
    }

    public function updatedSelectedProgram(): void
    {
        $this->activityForm['program'] = $this->selectedProgram;

        if (method_exists($this, 'refreshDeviceSelectionWarning')) {
            $this->refreshDeviceSelectionWarning();
        }
    }

    public function openSignatureModal(string $actionType, ?int $activityId = null, ?string $requestUuid = null): void
    {
        $this->authorizeCarePlanWrite();
        if ($this->guardTerminalCarePlanMutation()) {
            return;
        }

        if ($activityId) {
            $this->ownedActivity($activityId);
        }

        if (in_array($actionType, ['cancel_activity', 'complete_activity'], true) && $activityId) {
            $activity = $this->ownedActivity($activityId);

            if ($activity) {
                $blockReason = app(\App\Services\MedicalEvents\CarePlanLifecycleGateService::class)
                    ->activityStatusChangeBlockReason($activity, $actionType);

                if ($blockReason !== null) {
                    $this->flashOutcome('error', $blockReason);

                    return;
                }
            }
        }

        if ($actionType === 'cancel') {
            $blockReason = app(\App\Services\MedicalEvents\CarePlanLifecycleGateService::class)
                ->planCancelBlockReason($this->carePlan);

            if ($blockReason !== null) {
                $this->flashOutcome('error', $blockReason);

                return;
            }
        }

        if ($actionType === 'complete') {
            $blockReason = app(\App\Services\MedicalEvents\CarePlanLifecycleGateService::class)
                ->planCompleteBlockReason($this->carePlan);

            if ($blockReason !== null) {
                $this->flashOutcome('error', $blockReason);

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

        $termsOfService = $this->carePlan->termsOfService;
        if (is_array($termsOfService)) {
            $termsOfService = $termsOfService['coding'][0]['code'] ?? null;
        }

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

        $this->flashOutcome('error', __('care-plan.cannot_mutate_terminal_care_plan', [
            'status' => CarePlanStatus::labelFor($this->carePlan->status),
        ]));

        return true;
    }

    protected function refreshCarePlan(): void
    {
        $this->carePlan->refresh();
        $this->carePlan->unsetRelation('approvals');
        $this->carePlan->load(['person', 'author.party', 'categoryConcept', 'activities.kindConcept.coding']);

        if (property_exists($this, 'activity') && $this->activity instanceof \App\Models\CarePlanActivity) {
            $this->activity->refresh()->load(['kindConcept.coding', 'reasonReferences', 'author.party']);
        }

        $medicationRequestClass = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::class;
        $this->activePrescriptions = class_exists($medicationRequestClass)
            ? $medicationRequestClass::with('basedOn')->whereHas('basedOn', fn($q) => $q->whereIn('value', $this->carePlan->activities->pluck('uuid')))->get()->toArray()
            : [];

        $this->loadActiveReferrals();
    }

    protected function loadActiveReferrals(): void
    {
        $serviceReferrals = [];
        $deviceReferrals = [];

        $serviceRequestClass = \App\Models\MedicalEvents\Sql\ServiceRequestRequest::class;
        $deviceRequestClass = \App\Models\MedicalEvents\Sql\DeviceRequestRequest::class;

        if (class_exists($serviceRequestClass)) {
            $activityUuids = $this->carePlan->activities->pluck('uuid')->toArray();

            $serviceReferrals = $serviceRequestClass::query()
                ->with(['employee', 'basedOn'])
                ->whereHas('basedOn', fn($q) => $q->whereIn('value', $activityUuids))
                ->get()
                ->map(fn (Model $record): array => $this->normalizeReferralForView($record, 'service_request'))
                ->toArray();
        }

        if (class_exists($deviceRequestClass)) {
            $activityUuids = $activityUuids ?? $this->carePlan->activities->pluck('uuid')->toArray();
            $deviceReferrals = $deviceRequestClass::query()
                ->with(['employee', 'basedOn'])
                ->whereHas('basedOn', fn($q) => $q->whereIn('value', $activityUuids))
                ->get()
                ->map(fn (Model $record): array => $this->normalizeReferralForView($record, 'device_request'))
                ->toArray();
        }

        $this->activeReferrals = array_values(array_merge($serviceReferrals, $deviceReferrals));
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeReferralForView(Model $record, string $kind): array
    {
        return [
            'uuid' => $record->getAttribute('uuid'),
            'kind' => $kind,
            'based_on_uuid' => $record->basedOn?->value,
            'status' => $record->getAttribute('status'),
            'status_label' => $this->resolveReferralStatusLabel((string) $record->getAttribute('status')),
            'request_number' => $record->getAttribute('request_number'),
            'quantity' => $record->getAttribute('quantity'),
            'started_at' => $record->getAttribute('started_at'),
            'ended_at' => $record->getAttribute('ended_at'),
            'service_id' => $kind === 'service_request' ? $record->getAttribute('service_id') : null,
            'device_id' => $kind === 'device_request' ? $record->getAttribute('device_id') : null,
            'product_code' => $kind === 'service_request'
                ? $record->getAttribute('service_id')
                : $record->getAttribute('device_id'),
            'category' => $record->getAttribute('category'),
            'category_label' => $this->referralCategoryLabel($record->getAttribute('category')),
            'priority' => $record->getAttribute('priority'),
            'priority_label' => $this->referralPriorityLabel($record->getAttribute('priority')),
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

    protected function cleanCarePlanPayload(array $payload): array
    {
        $excludeKeys = [
            'remaining_quantity',
            'remaining_quantity_type',
            'inserted_at',
            'inserted_by',
            'updated_at',
            'updated_by',
            'ehealth_inserted_at',
            'ehealth_updated_at',
            'ehealth_inserted_by',
            'status_history',
            'database_id',
            'urgent',
            'links',
        ];

        $cleaned = $this->cleanPayloadKeys($payload, $excludeKeys);

        if (isset($cleaned['uuid']) && empty($cleaned['id'])) {
            $cleaned['id'] = $cleaned['uuid'];
        }
        unset($cleaned['uuid']);

        return $cleaned;
    }

    protected function cleanActivityPayload(array $payload): array
    {
        $excludeKeys = [
            'remaining_quantity',
            'remaining_quantity_type',
            'inserted_at',
            'inserted_by',
            'updated_at',
            'updated_by',
            'status_history',
            'database_id',
        ];

        $cleaned = [];
        foreach ($payload as $key => $value) {
            $snakeKey = \Illuminate\Support\Str::snake($key);
            if (in_array($snakeKey, $excludeKeys, true)) {
                continue;
            }

            if ($snakeKey === 'author' && is_array($value)) {
                if (isset($value[0])) {
                    $value = $value[0];
                }
            }

            if (is_array($value)) {
                $cleaned[$key] = $this->cleanActivityPayload($value);
            } else {
                $cleaned[$key] = $value;
            }
        }

        return $cleaned;
    }

    protected function cleanPayloadKeys(array $payload, array $excludeKeys): array
    {
        $cleaned = [];
        foreach ($payload as $key => $value) {
            $snakeKey = \Illuminate\Support\Str::snake($key);
            if (in_array($snakeKey, $excludeKeys, true)) {
                continue;
            }

            if ($snakeKey === 'author' && is_array($value)) {
                // eHealth getDetails returns author as a list [ {identifier...} ], but creation / expected schema is a single object
                if (isset($value[0])) {
                    $value = $value[0];
                }
            }

            if (is_array($value)) {
                $cleaned[$key] = $this->cleanPayloadKeys($value, $excludeKeys);
            } else {
                $cleaned[$key] = $value;
            }
        }

        return $cleaned;
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
        if (!$user) {
            return $programs->where('is_active', '=', true);
        }

        $roles = $user->allowedRoles;
        $mainSpeciality = $user->getMainSpeciality(legalEntity());

        $filtered = $programs
            ->where('is_active', '=', true)
            ->filter(function (array $program) use ($roles, $user, $mainSpeciality): bool {
                $allowedEmployeeTypes = Arr::get($program, 'medical_program_settings.employee_types_to_create_request', []);
                if (!empty($allowedEmployeeTypes) && $roles->intersect($allowedEmployeeTypes)->isEmpty()) {
                    return false;
                }

                if ($user->hasAllowedRole(Role::SPECIALIST->value) || $user->hasAllowedRole(Role::DOCTOR->value)) {
                    $allowedSpecialities = Arr::get($program, 'medical_program_settings.speciality_types_care_plan_activity_allowed')
                        ?? Arr::get($program, 'medical_program_settings.speciality_types_request_allowed')
                        ?? Arr::get($program, 'medical_program_settings.speciality_types_allowed', []);

                    if (!empty($allowedSpecialities)) {
                        return $mainSpeciality->intersect($allowedSpecialities)->isNotEmpty();
                    }
                }

                return true;
            });

        return $filtered;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $programs
     * @return Collection<int, array<string, mixed>>
     */
    protected function filterMedicationPrograms(Collection $programs): Collection
    {
        $user = Auth::user();
        if (!$user) {
            return $programs->where('is_active', '=', true);
        }

        $mainSpeciality = $user->getMainSpeciality(legalEntity());

        $filtered = $programs->where('is_active', '=', true);

        if ($user->hasAllowedRole(Role::SPECIALIST) || $user->hasAllowedRole(Role::DOCTOR)) {
            $filtered = $filtered->filter(function (array $program) use ($mainSpeciality): bool {
                $allowedSpecialities = Arr::get($program, 'medical_program_settings.speciality_types_allowed', []);

                return $mainSpeciality->intersect($allowedSpecialities)->isNotEmpty();
            });
        }

        return $filtered;
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
                        ?? Arr::get($program, 'medical_program_settings.speciality_types_request_allowed')
                        ?? Arr::get($program, 'medical_program_settings.speciality_types_allowed', []);

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
}
