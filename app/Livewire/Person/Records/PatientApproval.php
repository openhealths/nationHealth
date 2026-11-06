<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Enums\Person\AuthenticationMethod;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Models\Icd10;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\MedicalEvents\Sql\ServiceRequestRequest;
use App\Models\Person\Person;
use App\Models\Employee\Employee;
use App\Services\MedicalEvents\Fhir;
use App\Services\MedicalEvents\PatientApprovalService;
use App\Livewire\Person\Forms\PatientApprovalForm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class PatientApproval extends Component
{
    #[Locked]
    public int $personId;

    public bool $open = false;
    public string $step = 'form';
    public string $mode = '';
    public string $resourceType = 'episode_of_care';
    public string $search = '';
    public array $resourceIds = [];

    public PatientApprovalForm $form;

    #[Locked]
    public array $remoteReferrals = [];

    public string $childType = 'encounter';
    public string $childUuid = '';
    public string $groupId = '';
    public string $previewSection = '';
    public string $previewTitle = '';
    public string $previewCodingSystem = 'all';
    public array $previewRows = [];
    public bool $includeDetectedIssue = false;
    public bool $grantToLegalEntity = false;
    public string $accessLevel = 'read';
    public array $authMethods = [];
    public string $selectedAuthUuid = '';
    public bool $leafletConfirmed = false;
    public string $verificationCode = '';

    #[Locked]
    public bool $smsResent = false;

    #[Locked]
    public ?string $approvalId = null;

    #[Locked]
    public ?int $linkId = null;

    #[Locked]
    public string $selectedAuthType = '';

    #[Locked]
    public ?string $smsPhone = null;

    #[Locked]
    public ?int $smsResendAvailableAt = null;

    public function showGroupDetails(string $section, string $groupId): void
    {
        if (!in_array($section, ['codes', 'services'], true)) {
            return;
        }

        if (!in_array($this->mode, ['forbidden_group', 'diagnoses_group', 'service_group'], true)) {
            return;
        }

        if ($this->mode === 'diagnoses_group' && $section !== 'codes') {
            return;
        }

        if ($this->mode === 'service_group' && $section !== 'services') {
            return;
        }

        $group = collect($this->selectableGroups)->firstWhere('id', $groupId);

        if ($group === null) {
            return;
        }

        try {
            $rows = match ($this->mode) {
                'diagnoses_group' => $this->diagnosisCodeRows(
                    EHealth::diagnoseGroup()
                        ->getDetails($groupId)
                        ->getData()['diagnoses_group_codes']
                ),
                'forbidden_group' => $this->forbiddenGroupRows(
                    $section,
                    EHealth::forbiddenGroup()->getDetails($groupId)->getData()
                ),
                'service_group' => $this->serviceRows($group),
            };

            $this->previewRows = $rows;
            $this->previewCodingSystem = 'all';

            $this->previewTitle = trim(($group['code'] ?? '').' '.$group['name']).' — '.(
                $section === 'codes' ? __('patients.list_of_diagnosis_codes') : __('patients.list_of_services')
            );

            $this->previewSection = $section;
        } catch (Throwable) {
            $this->addError('groupId', __('approval.create.group_details_failed'));
        }
    }

    public function updatedGroupId(): void
    {
        $this->resetValidation('groupId');
    }

    public function closePreview(): void
    {
        $this->reset('previewSection', 'previewRows');
    }

    public function showReferralDetails(string $uuid): void
    {
        if ($this->mode !== 'service_request') {
            return;
        }

        $referral = ServiceRequestRequest::query()
            ->where('person_id', $this->personId)
            ->where('uuid', $uuid)
            ->first();

        if ($referral === null && !isset($this->remoteReferrals[$uuid])) {
            return;
        }

        try {
            $referralData = app(PatientApprovalService::class)
                ->referralDetails($this->person(), $uuid);
            $resources = $referralData['permitted_resources'] ?? [];

            $this->previewRows = collect($resources)
                ->map(static fn (array $resource): array => [
                    'code' => data_get($resource, 'identifier.type.coding.0.code'),
                    'name' => data_get($resource, 'identifier.value'),
                ])
                ->all();

            $this->previewTitle = __('approval.create.referral_prefix').' '.(
                $referral?->requestNumber ?? $this->remoteReferrals[$uuid]['requisition']
            );
            $this->previewSection = 'referral';
            $this->previewCodingSystem = 'all';
        } catch (Throwable) {
            $this->addError('resourceIds', __('approval.create.referral_check_failed'));
        }
    }

    private function forbiddenGroupRows(string $section, array $details): array
    {
        if ($section === 'codes') {
            return $this->diagnosisCodeRows($details['forbidden_group_codes']);
        }

        $services = dictionary()
            ->services()
            ->flattened()
            ->keyBy('id');

        return collect($details['forbidden_group_services'])
            ->map(static function (array $item) use ($services): array {
                $service = $services->get($item['service_id']);

                return [
                    'code' => $service['code'] ?? $item['service_id'],
                    'name' => $service['name'] ?? '',
                ];
            })
            ->all();
    }

    private function diagnosisCodeRows(array $codes): array
    {
        $icdSystem = 'eHealth/ICD10_AM/condition_codes';
        $icpcSystem = 'eHealth/ICPC2/condition_codes';

        $items = collect($codes)
            ->map(static fn (array $item): array => [
                ...$item,
                'system' => $item['system'] ?? $icdSystem,
            ]);

        $icdDescriptions = Icd10::query()
            ->whereIn('code', $items->where('system', $icdSystem)->pluck('code'))
            ->pluck('description', 'code');

        $icpcDescriptions = dictionary()
            ->basics()
            ->byName($icpcSystem)
            ->whereIn('code', $items->where('system', $icpcSystem)->pluck('code'))
            ->asCodeDescription();

        return $items
            ->map(static fn (array $item): array => [
                'code' => $item['code'],
                'name' => ($item['system'] === $icdSystem ? $icdDescriptions : $icpcDescriptions)->get($item['code'], ''),
                'system' => $item['system'],
            ])
            ->all();
    }

    private function serviceRows(array $node): array
    {
        $rows = [];

        foreach ($node['services'] ?? [] as $service) {
            if (($service['is_active'] ?? true) === false) {
                continue;
            }

            $rows[] = [
                'code' => $service['code'],
                'name' => $service['name'],
            ];

            array_push($rows, ...$this->serviceRows($service));
        }

        foreach ($node['groups'] ?? [] as $group) {
            if (($group['is_active'] ?? true) === false) {
                continue;
            }

            array_push($rows, ...$this->serviceRows($group));
        }

        return $rows;
    }

    public function openDrawer(): void
    {
        if (!Auth::user()->can('create', Approval::class)) {
            return;
        }

        if ($this->linkId !== null) {
            $this->step = $this->approvalId === null ? 'processing' : 'verifying';
        } elseif ($this->approvalId !== null) {
            $this->step = $this->selectedAuthType === AuthenticationMethod::OFFLINE->value ? 'confirming' : 'code';
        } else {
            $this->step = 'form';
        }

        $this->open = true;
    }

    public function closeDrawer(): void
    {
        $this->open = false;
        $this->closePreview();
    }

    public function updatedMode(): void
    {
        $this->reset(
            'resourceIds',
            'groupId',
            'childUuid',
            'search',
            'includeDetectedIssue',
            'grantToLegalEntity',
            'accessLevel'
        );

        $this->closePreview();
        $this->resetValidation();
        $this->remoteReferrals = [];
        $this->accessLevel = $this->mode === 'resource' && in_array($this->resourceType, ['encounter', 'procedure'], true) ? 'write' : 'read';

        if ($this->mode === 'service_request') {
            $this->loadReferrals();
        }
    }

    private function loadReferrals(): void
    {
        try {
            $this->remoteReferrals = app(PatientApprovalService::class)
                ->patientReferrals($this->person());
        } catch (Throwable) {
            $this->addError('resourceIds', __('approval.create.referral_check_failed'));
        }
    }

    public function updatedResourceType(): void
    {
        $this->resourceIds = [];
        $this->search = '';
        $this->accessLevel = in_array($this->resourceType, ['encounter', 'procedure'], true) ? 'write' : 'read';
    }

    public function updatedChildType(): void
    {
        $this->childUuid = '';
        $this->search = '';
    }

    public function selectRecord(string $uuid): void
    {
        if (!in_array($this->mode, ['resource', 'child', 'service_request'], true)) {
            return;
        }

        if ($this->mode === 'service_request') {
            if (!array_key_exists($uuid, $this->remoteReferrals)) {
                return;
            }

            if (!$this->remoteReferrals[$uuid]['selectable']) {
                $this->addError('resourceIds', __('approval.create.referral_no_resources'));

                return;
            }

            $this->resourceIds = ($this->resourceIds[0] ?? null) === $uuid ? [] : [$uuid];
            $this->resetValidation();

            return;
        }

        $type = $this->recordType();
        $query = $this->recordsQuery();

        if ($query === null) {
            return;
        }

        $record = $query->with($this->recordRelations($type))->where('uuid', $uuid)->first();

        if ($record === null) {
            return;
        }

        if ($this->mode === 'child') {
            $reason = $this->childUnavailableReason($record, $type, $this->person());

            if ($reason !== null) {
                $this->addError('childUuid', $reason);

                return;
            }
        } elseif (!PatientApprovalService::approvalStatusAllowed($type, $record->getRawOriginal('status'))) {
            $this->addError('resourceIds', $type === 'specimen' ? __('approval.create.specimen_not_available') : __('approval.create.invalid_record_status'));

            return;
        }

        switch ($this->mode) {
            case 'resource':
                $this->resourceIds = in_array($uuid, $this->resourceIds, true) ? array_values(array_diff($this->resourceIds, [$uuid])) : [...$this->resourceIds, $uuid];
                break;

            case 'child':
                $this->childUuid = $this->childUuid === $uuid ? '' : $uuid;
                break;
        }

        $this->resetValidation();
    }

    #[Computed]
    public function availableRecords(): array
    {
        if ($this->mode === 'service_request') {
            return $this->remoteReferralOptions();
        }

        $type = $this->recordType();
        $query = $this->recordsQuery();

        if ($query === null) {
            return [];
        }

        $query->whereNotNull('uuid');

        if ($this->mode !== 'child' && isset(PatientApprovalService::ALLOWED_STATUSES[$type])) {
            $query->whereIn('status', PatientApprovalService::ALLOWED_STATUSES[$type]);
        }

        $this->applySearchFilter($query, $type);

        $query->with($this->recordRelations($type))->latest('id');

        $person = $this->mode === 'child' ? $this->person() : null;

        return $query->get()
            ->map(function ($record) use ($type, $person): array {
                $option = $this->recordOption($record, $type);

                if ($person !== null) {
                    $reason = $this->childUnavailableReason($record, $type, $person);
                    $option['selectable'] = $reason === null;
                    $option['unavailable_reason'] = $reason;
                }

                return $option;
            })
            ->sortByDesc('selectable')
            ->values()
            ->all();
    }

    private function childUnavailableReason(Model $record, string $type, Person $person): ?string
    {
        if (!PatientApprovalService::approvalStatusAllowed($type, $record->getRawOriginal('status')) || ($type === 'condition' && $record->getRawOriginal('verification_status') === 'entered_in_error')) {
            return __('approval.create.invalid_record_status');
        }

        return $this->form->childContext($person, $record, $type) === null ? __('approval.create.missing_context_episode') : null;
    }

    private function applySearchFilter(Builder $query, string $type): void
    {
        $search = trim($this->search);

        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';

        $matchingCodes = in_array($type, ['encounter', 'specimen'], true)
            ? collect($this->recordTypeNames)
                ->filter(
                    static fn (string $name, string $code): bool =>
                    str_contains(mb_strtolower($name), mb_strtolower($search)) || str_contains(mb_strtolower($code), mb_strtolower($search))
                )
                ->keys()
                ->all()
            : [];

        $query->where(function (Builder $query) use ($type, $like, $matchingCodes): void {
            $query->whereLike('uuid', $like);

            switch ($type) {
                case 'episode_of_care':
                    $query->orWhereLike('name', $like);
                    break;

                case 'diagnostic_report':
                case 'procedure':
                    $query->orWhereHas('code', static function (Builder $code) use ($like): void {
                        $code->whereLike('display_value', $like)
                            ->orWhereLike('value', $like)
                            ->orWhereHas('type.coding', static fn (Builder $coding) => $coding->whereLike('code', $like));
                    });
                    break;

                case 'condition':
                case 'observation':
                case 'clinical_impression':
                    $query->orWhereHas('code', static function (Builder $code) use ($like): void {
                        $code->whereLike('text', $like)
                            ->orWhereHas('coding', static fn (Builder $coding) => $coding->whereLike('code', $like));
                    });
                    break;

                case 'immunization':
                    $query->orWhereHas('vaccineCode', static function (Builder $code) use ($like): void {
                        $code->whereLike('text', $like)
                            ->orWhereHas('coding', static fn (Builder $coding) => $coding->whereLike('code', $like));
                    });
                    break;

                case 'device':
                    $query->orWhereHas('type', static function (Builder $type) use ($like): void {
                        $type->whereLike('text', $like)
                            ->orWhereHas('coding', static fn (Builder $coding) => $coding->whereLike('code', $like));
                    });
                    break;

                case 'activity':
                    $query->orWhereLike('description', $like)
                        ->orWhereLike('kind', $like);
                    break;

                case 'encounter':
                case 'specimen':
                    $query->orWhereHas('type', static function (Builder $concept) use ($like, $matchingCodes): void {
                        $concept->whereLike('text', $like)
                            ->orWhereHas('coding', static function (Builder $coding) use ($like, $matchingCodes): void {
                                $coding->whereLike('code', $like);

                                if ($matchingCodes !== []) {
                                    $coding->orWhereIn('code', $matchingCodes);
                                }
                            });
                    });
                    break;

                case 'care_plan':
                    $query->orWhereLike('title', $like);
                    break;

                case 'service_request':
                    $query->orWhereLike('request_number', $like);
                    break;
            }
        });
    }

    #[Computed]
    public function selectedRecords(): array
    {
        if ($this->mode === 'service_request') {
            $uuid = $this->resourceIds[0] ?? null;

            return $uuid !== null && isset($this->remoteReferrals[$uuid]) ? [$this->remoteReferralOption($this->remoteReferrals[$uuid])] : [];
        }

        $type = $this->recordType();

        $ids = match ($this->mode) {
            'resource' => $this->resourceIds,
            'child' => $this->childUuid !== '' ? [$this->childUuid] : [],
            'service_request' => array_slice($this->resourceIds, 0, 1),
            default => [],
        };

        $query = $this->recordsQuery();

        if ($ids === [] || $query === null) {
            return [];
        }

        return $query
            ->whereIn('uuid', $ids)
            ->with($this->recordRelations($type))
            ->get()
            ->map(fn ($record): array => $this->recordOption($record, $type))
            ->all();
    }

    private function recordType(): string
    {
        return match ($this->mode) {
            'child' => $this->childType,
            'service_request' => 'service_request',
            default => $this->resourceType,
        };
    }

    private function recordsQuery(): ?Builder
    {
        $type = $this->recordType();

        $model = $this->mode === 'child' ? PatientApprovalService::CHILD_MODELS[$type] ?? null : PatientApprovalService::RESOURCE_MODELS[$type] ?? null;

        if ($model === null) {
            return null;
        }

        $query = $model::query();

        return $this->mode === 'child' && $type === 'activity' ? $query->whereHas('carePlan', fn (Builder $plan) => $plan->where('person_id', $this->personId)) : $query->where('person_id', $this->personId);
    }

    private function recordRelations(string $type): array
    {
        return match ($type) {
            'episode_of_care' => ['period'],
            'diagnostic_report' => ['code', 'effectivePeriod', 'encounter', 'originEpisode'],
            'encounter' => ['type.coding', 'period', 'episode'],
            'procedure' => ['code', 'performedPeriod', 'encounter', 'originEpisode'],
            'specimen' => ['type.coding', 'collection.collectedPeriod'],
            'service_request' => ['category.coding'],
            'condition' => ['code.coding', 'context'],
            'observation' => ['code.coding', 'context', 'diagnosticReport'],
            'activity' => ['carePlan'],
            'clinical_impression' => ['code.coding', 'encounter'],
            'immunization' => ['vaccineCode.coding', 'context'],
            'device' => ['type.coding', 'context'],
            default => [],
        };
    }

    #[Computed]
    public function recordTypeNames(): array
    {
        $dictionary = match ($this->recordType()) {
            'encounter' => 'eHealth/encounter_types',
            'specimen' => 'specimen_types',
            default => null,
        };

        return $dictionary !== null
            ? dictionary()
                ->basics()
                ->byName($dictionary, false)
                ->asCodeDescription()
                ->all()
            : [];
    }

    private function recordOption($record, string $type): array
    {
        $name = match ($type) {
            'condition', 'observation', 'clinical_impression' => $record->code?->text ?: $record->code?->coding?->first()?->code,
            'activity' => $record->description,
            'immunization' => $record->vaccineCode?->text ?: $record->vaccineCode?->coding?->first()?->code,
            'device' => $record->type?->text ?: $record->type?->coding?->first()?->code,
            'episode_of_care' => $record->name,
            'diagnostic_report', 'procedure' => $record->code?->displayValue ?: $record->code?->value,
            'encounter', 'specimen' => $this->recordTypeNames[$record->type?->coding?->first()?->code] ?? $record->type?->text ?? $record->type?->coding?->first()?->code,
            'care_plan' => $record->title,
            'service_request' => $record->requestNumber,
        };

        $date = match ($type) {
            'episode_of_care', 'encounter' => collect([
                $record->period?->start, $record->period?->end
            ])->filter()->map(fn ($date) => formatDisplayDate($date))->implode(' — '),
            'diagnostic_report' => $record->effectiveDateTime
                ? formatDisplayDate($record->effectiveDateTime)
                : (collect([
                    $record->effectivePeriod?->start,
                    $record->effectivePeriod?->end,
                ])->filter()->map(fn ($date) => formatDisplayDate($date))->implode(' — ') ?: formatDisplayDate($record->issued)),
            'procedure' => $record->performedDateTime
                ? formatDisplayDate($record->performedDateTime)
                : collect([
                    $record->performedPeriod?->start,
                    $record->performedPeriod?->end,
                ])->filter()->map(fn ($date) => formatDisplayDate($date))->implode(' — '),
            'care_plan' => collect([
                $record->periodStart,
                $record->periodEnd,
            ])->filter()->map(fn ($date) => formatDisplayDate($date))->implode(' — '),
            'specimen' => formatDisplayDate($record->collection?->collectedDateTime ?? $record->collection?->collectedPeriod?->start ?? $record->receivedTime ?? $record->ehealthInsertedAt),
            'service_request' => formatDisplayDate($record->startedAt),
            'condition' => formatDisplayDate($record->assertedDate ?? $record->onsetDate),
            'observation' => formatDisplayDate($record->effectiveDateTime ?? $record->issued),
            'activity' => formatDisplayDate($record->scheduledPeriodStart),
            'clinical_impression' => formatDisplayDate($record->effectiveDateTime),
            'immunization' => formatDisplayDate($record->date),
            'device' => formatDisplayDate($record->ehealthInsertedAt),
        };

        $service = $type === 'service_request' ? dictionary()->services()->flattened()->firstWhere('id', $record->serviceId) : null;

        return [
            'uuid' => $record->uuid,
            'name' => $name ?: __('approval.create.'.$type),
            'date' => $date,
            'status' => $type === 'service_request' ? $record->status : null,
            'processing_status' => $type === 'service_request' ? $record->programProcessingStatus : null,
            'category' => $type === 'service_request' ? $record->category?->coding?->first()?->code : null,
            'service_code' => $service['code'] ?? null,
            'service_name' => $service['name'] ?? null,
            'selectable' => $type !== 'service_request' || ($this->remoteReferrals[$record->uuid]['selectable'] ?? false),
            'availability_known' => $type !== 'service_request' || array_key_exists($record->uuid, $this->remoteReferrals),
        ];
    }

    private function remoteReferralOptions(): array
    {
        $search = mb_strtolower(trim($this->search));

        return collect($this->remoteReferrals)
            ->filter(static function (array $referral) use ($search): bool {
                return $search === '' || str_contains(mb_strtolower($referral['requisition']), $search) || str_contains(mb_strtolower($referral['id']), $search);
            })
            ->sortByDesc(static fn (array $referral): bool => $referral['status'] === 'active')
            ->take(5)
            ->map(fn (array $referral): array => $this->remoteReferralOption($referral))
            ->values()
            ->all();
    }

    private function remoteReferralOption(array $referral): array
    {
        $service = $referral['service_id'] ? dictionary()->services()->flattened()->firstWhere('id', $referral['service_id']) : null;

        return [
            'uuid' => $referral['id'],
            'name' => $referral['requisition'],
            'date' => $referral['authored_on'] ? formatDisplayDate($referral['authored_on']) : '',
            'status' => $referral['status'],
            'processing_status' => $referral['program_processing_status'],
            'category' => $referral['category'],
            'service_code' => $service['code'] ?? null,
            'service_name' => $service['name'] ?? null,
            'selectable' => $referral['selectable'],
            'availability_known' => true,
        ];
    }

    #[Computed]
    public function selectableGroups(): array
    {
        return match ($this->mode) {
            'diagnoses_group' => dictionary()
                ->diagnoseGroups()
                ->toArray(),

            'forbidden_group' => dictionary()
                ->forbiddenGroups()
                ->toArray(),

            'service_group' => dictionary()
                ->services()
                ->flatMap(static fn (array $service): array => $service['groups'] ?? [])
                ->filter(static fn (array $group): bool => $group['is_active'] ?? false)
                ->values()
                ->toArray(),

            default => [],
        };
    }

    public function chooseAuthentication(): void
    {
        if (Auth::user()->cannot('create', Approval::class)) {
            return;
        }
        $this->validatedSelection();

        $this->authMethods = app(PatientApprovalService::class)
            ->availableAuthenticationMethods($this->person());

        $this->reset('selectedAuthUuid', 'leafletConfirmed');
        $this->step = 'methods';
    }

    public function create(): void
    {
        if (Auth::user()->cannot('create', Approval::class)) {
            return;
        }

        if ($this->approvalId !== null || $this->linkId !== null) {
            return;
        }

        $this->validate(['selectedAuthUuid' => ['required', 'uuid']]);

        $service = app(PatientApprovalService::class);
        $method = collect($service->availableAuthenticationMethods($this->person()))->firstWhere('uuid', $this->selectedAuthUuid);

        if (!$method) {
            throw ValidationException::withMessages([
                'selectedAuthUuid' => __('approval.create.select_auth_method'),
            ]);
        }

        if ($this->mode === 'person' && $method['type'] === 'OFFLINE') {
            throw ValidationException::withMessages([
                'selectedAuthUuid' => __('approval.create.person_requires_otp'),
            ]);
        }

        if ($this->mode === 'person' && $method['type'] === AuthenticationMethod::THIRD_PERSON->value) {
            try {
                $authMethods = EHealth::person()
                    ->getAuthMethods($method['value'])
                    ->validate();
            } catch (EHealthException|EHealthConnectionException $exception) {
                $exception->handle('Error when checking confidant authentication methods');

                return;
            }

            if (!Person::hasActiveOtpAuthenticationMethod($authMethods)) {
                throw ValidationException::withMessages([
                    'selectedAuthUuid' => __('approval.create.confidant_requires_otp'),
                ]);
            }
        }

        if ($method['type'] === 'OFFLINE' && !$this->leafletConfirmed) {
            throw ValidationException::withMessages([
                'leafletConfirmed' => __('approval.create.leaflet_required'),
            ]);
        }

        $employee = $this->employeeForApproval();
        $payload = $this->buildPayload($employee->uuid);

        if ($employee->employee_type === 'ASSISTANT' && $payload['access_level'] === 'write') {
            throw ValidationException::withMessages([
                'accessLevel' => __('approval.create.assistant_read_only'),
            ]);
        }

        $payload['authorize_with'] = $this->selectedAuthUuid;

        try {
            $result = $service->create(
                $this->person(),
                $payload
            );

            $this->selectedAuthType = $method['type'];
            $this->smsPhone = $method['phoneNumber'] ?? null;
            $this->approvalId = $result['approval_id'];
            $this->linkId = $result['link_id'];
            $this->reset('smsResent', 'smsResendAvailableAt');
            $this->step = $this->linkId !== null ? 'processing' : 'confirming';

            if ($this->step === 'confirming') {
                $this->continueAfterCreation();
            }
        } catch (EHealthValidationException|EHealthResponseException|EHealthConnectionException) {
            $this->open = false;
            $this->step = 'form';

            Session::flash('error', __('approval.create.create_ehealth_failed'));

            $this->redirectRoute('persons.patient-data', [
                legalEntity(),
                'person' => $this->personId,
            ]);
        } catch (Throwable) {
            $this->addError('approvalCreation', __('approval.create.create_failed'));
        }
    }

    public function poll(): void
    {
        if ($this->linkId === null || !in_array($this->step, ['processing', 'verifying'], true)) {
            return;
        }

        try {
            $status = app(PatientApprovalService::class)->jobStatus(
                $this->linkId,
                $this->person()
            );

            if ($status['status'] === 'pending') {
                return;
            }

            if ($status['status'] === 'failed') {
                $this->handlePollFailure($status['message']);

                return;
            }

            $this->handlePollSuccess($status);
        } catch (Throwable $exception) {
            $this->addError('approvalCreation', __('approval.create.job_failed'));
        }
    }

    private function handlePollFailure(string $message): void
    {
        $wasVerifying = $this->step === 'verifying';
        $this->linkId = null;

        if (!$wasVerifying) {
            $this->approvalId = null;
            $this->step = 'form';
            $this->open = false;

            Session::flash('error', __('approval.create.create_ehealth_failed'));

            $this->redirectRoute('persons.patient-data', [
                legalEntity(),
                'person' => $this->personId,
            ]);

            return;
        }

        $this->step = $this->selectedAuthType === 'OFFLINE' ? 'confirming' : 'code';
        $this->addError('approvalCreation', $message);
    }

    private function handlePollSuccess(array $status): void
    {
        if ($this->step === 'verifying') {
            app(PatientApprovalService::class)->syncVerified($this->person(), $this->approvalId);

            $this->linkId = null;
            $this->finish();

            return;
        }

        $this->approvalId = $status['approval_id'];
        $this->linkId = null;
        $this->step = 'confirming';

        $this->continueAfterCreation();
    }

    public function continueAfterCreation(): void
    {
        if (Auth::user()->cannot('create', Approval::class)) {
            Session::flash('error', __('approval.create.role_forbidden'));

            return;
        }

        if ($this->step !== 'confirming' || $this->approvalId === null) {
            return;
        }

        try {
            $service = app(PatientApprovalService::class);

            if ($this->selectedAuthType !== 'OFFLINE') {
                $details = $service->details($this->person(), $this->approvalId);
                $method = $service->resolveConfirmationMethod($details['authentication_method_current'] ?? [], $this->person()->uuid, $this->selectedAuthUuid);
                if (AuthenticationMethod::confirmationMethod($method) !== AuthenticationMethod::OFFLINE) {
                    $this->smsPhone = AuthenticationMethod::confirmationPhone($method) ?? $this->smsPhone;
                    $this->smsResendAvailableAt = $service->smsResendAvailableAt($this->person(), $this->approvalId);
                    $this->step = 'code';

                    return;
                }
                $this->selectedAuthType = AuthenticationMethod::OFFLINE->value;
            }
            if (!$this->leafletConfirmed) {
                $this->addError('leafletConfirmed', __('approval.create.leaflet_required'));

                return;
            }

            $this->linkId = $service->verify($this->person(), $this->approvalId, null);
            if ($this->linkId !== null) {
                $this->step = 'verifying';
            } else {
                $this->finish();
            }
        } catch (Throwable) {
            $this->addError('approvalCreation', __('approval.create.confirmation_failed'));
        }
    }

    public function verifyCode(): void
    {
        if (Auth::user()->cannot('create', Approval::class)) {
            return;
        }

        if ($this->step !== 'code' || $this->approvalId === null || !in_array($this->selectedAuthType, ['OTP', 'THIRD_PERSON'], true)) {
            return;
        }

        $this->validate(['verificationCode' => ['required', 'regex:/^[0-9]{4}$/']]);

        try {
            $this->linkId = app(PatientApprovalService::class)->verify(
                $this->person(),
                $this->approvalId,
                (int) $this->verificationCode
            );

            if ($this->linkId !== null) {
                $this->step = 'verifying';

                return;
            }

            $this->finish();
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            $message = data_get($exception->getDetails(), 'error.message');

            $this->addError(
                'verificationCode',
                $message === 'Invalid verification code' ? __('errors.ehealth.messages.invalid_verification_code') : __('approval.create.verify_ehealth_failed')
            );
        } catch (Throwable) {
            $this->addError('verificationCode', __('approval.create.verify_processing_failed'));
        }
    }

    public function resendSms(): void
    {
        if (Auth::user()->cannot('create', Approval::class)) {
            return;
        }

        if ($this->step !== 'code' || $this->approvalId === null || $this->smsResent) {
            return;
        }

        $this->resetValidation('smsResend');

        try {
            app(PatientApprovalService::class)->resendSms($this->person(), $this->approvalId);

            $this->smsResent = true;
        } catch (ValidationException $exception) {
            $this->addError('smsResend', $exception->errors()['smsResend'][0] ?? __('approval.create.resend_failed'));
        } catch (Throwable) {
            $this->addError('smsResend', __('approval.create.resend_failed'));
        }
    }

    protected function finish(): void
    {
        $this->open = false;
        $this->step = 'form';
        $this->approvalId = null;
        $this->linkId = null;
        $this->smsResent = false;
        $this->smsResendAvailableAt = null;
        $this->verificationCode = '';

        Session::flash('success', __('approval.create.verified'));

        $this->redirectRoute('persons.patient-data', [
            legalEntity(),
            'person' => $this->personId,
        ]);
    }

    private function person(): Person
    {
        return Person::findOrFail($this->personId);
    }

    private function employeeForApproval(): Employee
    {
        $allowed = [Role::DOCTOR->value, Role::SPECIALIST->value, Role::ASSISTANT->value];

        if ($this->mode !== 'person') {
            $allowed[] = Role::MED_COORDINATOR->value;
        }

        $employee = Auth::user()->activeDoctorEmployee();

        if (!$employee || $employee->legalEntityId !== legalEntity()->id || !$employee->isActive || $employee->status !== Status::APPROVED || !in_array($employee->employeeType, $allowed, true)) {
            throw ValidationException::withMessages(['mode' => __('approval.create.role_forbidden')]);
        }

        return $employee;
    }

    private function validatedSelection(): array
    {
        return $this->form->validateSelection(
            $this->person(),
            [
                'mode' => $this->mode,
                'resourceType' => $this->resourceType,
                'resourceIds' => $this->resourceIds,
                'childType' => $this->childType,
                'childUuid' => $this->childUuid,
                'groupId' => $this->groupId,
                'selectableGroups' => in_array($this->mode, ['diagnoses_group', 'forbidden_group', 'service_group'], true) ? $this->selectableGroups : [],
                'includeDetectedIssue' => $this->includeDetectedIssue,
                'grantToLegalEntity' => $this->grantToLegalEntity,
                'accessLevel' => $this->accessLevel,
            ]
        );
    }

    private function buildPayload(string $employeeUuid): array
    {
        $selection = $this->validatedSelection();

        return Fhir::approval()->toFhir(
            $selection,
            $employeeUuid,
            legalEntity()->uuid
        );
    }

    public function render(): View
    {
        return view('livewire.person.records.parts.patient-approval');
    }
}
