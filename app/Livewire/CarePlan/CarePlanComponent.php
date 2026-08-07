<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Core\Arr;
use App\Enums\MedicalProgram\Type;
use App\Enums\User\Role;
use App\Traits\InteractsWithApprovals;
use App\Classes\eHealth\EHealth;
use App\Models\CarePlan;
use App\Services\Dictionary\DictionaryManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithFileUploads;

abstract class CarePlanComponent extends Component
{
    use WithFileUploads;
    use InteractsWithApprovals;

    public CarePlan $carePlan;

    public bool $showSignatureModal = false;
    public string $actionType = ''; // 'cancel', 'complete', 'sign_activity', 'complete_activity', 'cancel_activity'
    public string $statusReason = ''; // Used when cancelling or completing
    public ?int $activityToSign = null;
    public array $dictionaries = [];
    public array $authMethods = [];
    public bool $showMethodSelectionModal = false;
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
    public array $participatingDeviceProgramIds = [];

    public string $deviceParticipationWarning = '';
    public bool $showEPrescriptionDrawer = false;
    public array $ePrescriptionForm = [];
    public ?array $ePrescriptionSelectedActivity = null;
    public ?array $ePrescriptionSelectedProduct = null;
    public ?array $ePrescriptionSelectedProgram = null;
    public float $ePrescriptionRemainingQty = 0.0;
    public bool $ePrescriptionSkipTreatmentPeriod = true;
    public bool $ePrescriptionShowDailyDoseWarning = false;
    public bool $ePrescriptionShowRemainingQtyWarning = false;
    public string $ePrescriptionWarningMessage = '';
    public array $ePrescriptionMultiples = [];
    public array $ePrescriptionPackages = [];
    public array $ePrescriptionAuthMethods = [];
    public ?string $ePrescriptionRequestIdToSign = null;
    public string $printableContent = '';
    public array $activePrescriptions = [];

    // Outgoing Referral State Variables
    public bool $showReferralDrawer = false;
    public array $referralForm = [];
    public ?array $referralSelectedActivity = null;
    public float $referralRemainingQty = 0.0;
    public bool $referralShowRemainingQtyWarning = false;
    public string $referralWarningMessage = '';
    public ?string $referralRequestIdToSign = null;
    public array $activeReferrals = [];
    public string $referralServiceCategory = '';

    // Search and selection parameters
    public string $searchQuery = '';
    public array $searchResults = [];
    public int $searchPage = 1;
    public ?array $selectedProduct = null;
    public string $selectedProgram = '';

    // Linked justification references (grounds)
    public array $linkedGrounds = [];
    public array $availableReports = [];
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
    public array $outcomeReferences = []; // For outcomeReference (IDs of identifiers)

    public ?string $selectedOutcomeReference = null;

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

    protected function bootCarePlan(CarePlan $carePlan): void
    {
        $this->carePlan = $carePlan;
        $this->carePlan->load(['person', 'author.party', 'categoryConcept.coding', 'activities.kindConcept.coding']);

        // Fetch patient conditions for outcomeReference selection
        $this->availableConditions = \App\Models\MedicalEvents\Sql\Condition::where('person_id', $this->carePlan->person_id)
            ->with('code.coding')->get()->map(fn ($c) => [
                'uuid' => $c->uuid,
                'name' => ($c->code?->text ?: null) ?? ($c->code?->coding?->first()?->code ?: null) ?? 'Unknown Condition',
                'date' => $c->onset_date ? \Carbon\Carbon::parse($c->onset_date)->format('d.m.Y') : '-',
            ])->toArray();

        // Fetch patient diagnostic reports for justifications (grounds)
        $this->availableReports = \App\Models\MedicalEvents\Sql\DiagnosticReport::where('person_id', $this->carePlan->person_id)
            ->get()->map(fn ($dr) => [
                'uuid' => $dr->uuid,
                'name' => $dr->code?->text ?: 'Diagnostic Report',
                'date' => $dr->issued ? \Carbon\Carbon::parse($dr->issued)->format('d.m.Y') : '-',
            ])->toArray();

        // Fetch patient observations for justifications (grounds)
        $this->availableObservations = \App\Models\MedicalEvents\Sql\Observation::where('person_id', $this->carePlan->person_id)
            ->get()->map(fn ($obs) => [
                'uuid' => $obs->uuid,
                'name' => $obs->code?->text ?: 'Observation',
                'date' => $obs->issued ? \Carbon\Carbon::parse($obs->issued)->format('d.m.Y') : '-',
            ])->toArray();

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

            // Load medical programs (split by type; device/medication lists are role-filtered)
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
            Log::warning('CarePlanShow: failed to load dictionaries: ' . $exception->getMessage());
        }

        $this->carePlanUuid = $this->carePlan->uuid;
        $this->patientId = $this->carePlan->person->uuid;
        $this->loadDeviceProgramParticipationState();

        $medicationRequestClass = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::class;
        $this->activePrescriptions = class_exists($medicationRequestClass)
            ? $medicationRequestClass::whereIn('based_on_id', $this->carePlan->activities->pluck('id'))->get()->toArray()
            : [];
        $this->loadActiveReferrals();

        $action = request()->query('action');
        if (in_array($action, ['cancel', 'complete'])) {
            $statusStr = is_array($this->carePlan->status)
                ? ($this->carePlan->status['coding'][0]['code'] ?? ($this->carePlan->status['text'] ?? ''))
                : $this->carePlan->status;

            if (strtolower((string) $statusStr) === 'active') {
                $this->openSignatureModal($action);
            }
        }
    }

    protected function rulesForSigning(): array
    {
        $statusReasonOptional = in_array($this->actionType, [
            'sign_activity',
            'sign_plan',
            'sign_eprescription',
            'sign_servicerequest',
            'sign_devicerequest',
        ], true);

        return [
            'statusReason' => $statusReasonOptional ? 'nullable|string' : 'required|string',
            'form.knedp' => 'required|string',
            'form.keyContainerUpload' => 'required|file|max:1024',
            'form.password' => 'required|string',
        ];
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
        $this->actionType = $actionType;
        $this->activityToSign = $activityId;
        if ($requestUuid !== null && $requestUuid !== '') {
            if (in_array($actionType, ['cancel_prescription', 'sign_eprescription', 'reject_prescription'], true)) {
                $this->ePrescriptionRequestIdToSign = $requestUuid;
            } elseif (in_array($actionType, ['cancel_referral', 'sign_servicerequest', 'sign_devicerequest'], true)) {
                $this->referralRequestIdToSign = $requestUuid;
            }
        }
        $this->statusReason = ''; // Reset reason
        $this->outcomeCode = ''; // Reset outcome
        $this->outcomeReferences = []; // Reset references
        $this->showSignatureModal = true;
    }

    protected function refreshCarePlan(): void
    {
        $this->carePlan->refresh();
        $this->carePlan->load(['person', 'author.party', 'categoryConcept', 'activities.kindConcept.coding']);

        $medicationRequestClass = \App\Models\MedicalEvents\Sql\Medications\MedicationRequestRequest::class;
        $this->activePrescriptions = class_exists($medicationRequestClass)
            ? $medicationRequestClass::whereIn('based_on_id', $this->carePlan->activities->pluck('id'))->get()->toArray()
            : [];

        $this->loadActiveReferrals();
    }

    protected function loadActiveReferrals(): void
    {
        $activityIds = $this->carePlan->activities->pluck('id');
        $serviceReferrals = collect();
        $deviceReferrals = collect();

        $serviceRequestClass = \App\Models\MedicalEvents\Sql\ServiceRequestRequest::class;
        if (class_exists($serviceRequestClass)) {
            $serviceReferrals = $serviceRequestClass::query()
                ->with('employee')
                ->whereIn('based_on_id', $activityIds)
                ->get()
                ->map(fn (Model $record): array => $this->normalizeReferralForView($record, 'service_request'));
        }

        $deviceRequestClass = \App\Models\MedicalEvents\Sql\DeviceRequestRequest::class;
        if (class_exists($deviceRequestClass)) {
            $deviceReferrals = $deviceRequestClass::query()
                ->with('employee')
                ->whereIn('based_on_id', $activityIds)
                ->get()
                ->map(fn (Model $record): array => $this->normalizeReferralForView($record, 'device_request'));
        }

        $this->activeReferrals = $serviceReferrals->merge($deviceReferrals)->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeReferralForView(Model $record, string $kind): array
    {
        return [
            'uuid' => $record->getAttribute('uuid'),
            'kind' => $kind,
            'based_on_id' => $record->getAttribute('based_on_id'),
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
        $normalized = strtolower($status);
        $referralKey = 'care-plan.referral_status.' . $normalized;
        if (Lang::has($referralKey)) {
            return __($referralKey);
        }

        $statusKey = 'care-plan.status.' . $normalized;
        if (Lang::has($statusKey)) {
            return __($statusKey);
        }

        return $status;
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

    protected function scopeDocumentsToActivity(int $activityId): void
    {
        $this->activePrescriptions = array_values(array_filter(
            $this->activePrescriptions,
            static fn (array $prescription): bool => (int) ($prescription['based_on_id'] ?? $prescription['basedOnId'] ?? 0) === $activityId
        ));

        $this->activeReferrals = array_values(array_filter(
            $this->activeReferrals,
            static fn (array $referral): bool => (int) ($referral['based_on_id'] ?? $referral['basedOnId'] ?? 0) === $activityId
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

        if ($this->participatingDeviceProgramIds !== []) {
            $filtered = app(\App\Services\MedicalEvents\DeviceProgramParticipationGuard::class)
                ->filterProgramsForParticipation($filtered, $this->participatingDeviceProgramIds);
        }

        return $filtered;
    }

    protected function loadDeviceProgramParticipationState(): void
    {
        $guard = app(\App\Services\MedicalEvents\DeviceProgramParticipationGuard::class);
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
