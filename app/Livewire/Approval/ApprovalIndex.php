<?php

declare(strict_types=1);

namespace App\Livewire\Approval;

use App\Classes\eHealth\EHealth;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use App\Exceptions\EHealth\EHealthJobTimeoutException;
use App\Models\CarePlan;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\MedicalEvents\Sql\Approval;
use App\Models\Person\Person;
use App\Enums\JobStatus;
use App\Notifications\SyncNotification;
use App\Repositories\MedicalEvents\Repository;
use App\Traits\LogsExceptions;
use App\Traits\BatchLegalEntityQueries;
use App\Jobs\ApprovalSync;
use App\Services\MedicalEvents\PatientApprovalService;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class ApprovalIndex extends Component
{
    use LogsExceptions;
    use WithPagination;
    use BatchLegalEntityQueries;

    public array $patients = [];
    public array $employees = [];
    public array $resources = [];
    public array $resourceTypes = [];
    public array $reasons = [];
    public array $booleanOptions = [];
    public array $accessLevels = [];

    public string $filterPatientId = '';
    public string $filterGrantedTo = '';
    public string $filterGrantedResources = '';
    public string $filterGrantedResourceType = '';
    public string $filterIsVerified = '';
    public string $filterIsExpired = '';
    public string $filterReason = '';
    public string $filterAccessLevel = '';

    public bool $isSearching = false;
    public bool $showCancelApprovalModal = false;
    public ?int $approvalToCancel = null;

    public function mount(): void
    {
        $this->loadFilterOptions();
    }

    #[Computed]
    public function paginatedApprovals(): LengthAwarePaginator
    {
        return $this->isSearching ? $this->searchApprovalsFromEHealth() : $this->paginateLocalApprovals();
    }

    #[Computed]
    public function searchPatient(): ?Person
    {
        return $this->isSearching ? Person::query()->with('names')->where('uuid', $this->filterPatientId)->firstOrFail() : null;
    }

    #[Computed]
    public function localApprovalsByUuid(): Collection
    {
        return Approval::query()
            ->with([
                'createdBy',
                'approvable' => static fn (MorphTo $morphTo) => $morphTo->morphWith([
                    CarePlan::class => ['person'],
                ]),
            ])
            ->whereIn('uuid', collect($this->paginatedApprovals->items())->pluck('id'))
            ->get(['id', 'uuid', 'status', 'expires_at', 'created_by_id', 'approvable_id', 'approvable_type'])
            ->keyBy('uuid');
    }

    public function search(): void
    {
        $this->validate($this->filterValidationRules());
        $this->isSearching = true;
        $this->resetPage();
    }

    public function sync(): void
    {
        if (!Auth::user()->can('sync', Approval::class)) {
            return;
        }

        $this->validateOnly('filterPatientId', $this->filterValidationRules());

        $patient = Person::query()
            ->where('uuid', $this->filterPatientId)
            ->firstOrFail();

        $employeeUuid = Auth::user()->activeDoctorEmployee()->uuid;
        $canViewAll = Auth::user()->can('viewAllForPatient', [Approval::class, $patient]);

        $batchName = ApprovalSync::BATCH_NAME . '_' . $patient->uuid;

        $running = $this->findRunningBatchesByLegalEntity(legalEntity()->id)
            ->contains('name', $batchName);

        if ($running) {
            Session::flash('error', __('approval.messages.sync_already_running'));

            return;
        }

        try {
            $query = [];

            if (!$canViewAll) {
                $query['granted_to'] = $employeeUuid;
            }

            $response = EHealth::approval()->getPatientApprovals($patient->uuid, $query);

            $approvals = [];

            foreach ($response->validate() as $approval) {
                if (!$canViewAll && data_get($approval, 'granted_to.identifier.value') !== $employeeUuid) {
                    continue;
                }

                $approvals[] = EHealth::approval()
                    ->getApprovalDetails($patient->uuid, $approval['id'])
                    ->validate();
            }

            Repository::approval()->syncMany($patient, $approvals);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while synchronizing approvals');

            return;
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while synchronizing approvals');

            return;
        }

        if ($response->isNotLast()) {
            $user = Auth::user();
            $userId = $user->id;
            $legalEntityId = legalEntity()->id;
            $patientUuid = $patient->uuid;

            try {
                Bus::batch([new ApprovalSync(legalEntity(), page: 2)])
                    ->withOption('legal_entity_id', $legalEntityId)
                    ->withOption('token', Crypt::encryptString(
                        Session::get(config('ehealth.api.oauth.bearer_token'))
                    ))
                    ->withOption('user', $user)
                    ->withOption('patient_uuid', $patientUuid)
                    ->withOption('person_id', $patient->id)
                    ->withOption('employee_uuid', $canViewAll ? null : $employeeUuid)
                    ->withOption('sync_entity', LegalEntity::ENTITY_APPROVAL)
                    ->catch(static function (Batch $batch, Throwable $exception) use (
                        $userId,
                        $patientUuid
                    ): void {
                        Log::error('Approval sync batch failed', [
                            'batch_id' => $batch->id,
                            'patient_uuid' => $patientUuid,
                            'exception' => $exception,
                        ]);

                        User::find($userId)?->notify(
                            new SyncNotification('approval', 'failed')
                        );
                    })
                    ->onQueue('sync')
                    ->name(ApprovalSync::BATCH_NAME . '_' . $patientUuid)
                    ->dispatch();

                legalEntity()->setEntityStatus(
                    JobStatus::PROCESSING,
                    LegalEntity::ENTITY_APPROVAL
                );

                $user->notify(new SyncNotification('approval', 'started'));

                Session::flash('success', __('approval.sync_started'));
            } catch (Throwable $exception) {
                $this->handleDatabaseErrors(
                    $exception,
                    'Error while dispatching ApprovalSync'
                );

                return;
            }
        } else {
            legalEntity()->setEntityStatus(
                JobStatus::COMPLETED,
                LegalEntity::ENTITY_APPROVAL
            );

            Session::flash('success', __('patients.sync_success'));
        }

        $this->loadFilterOptions();

        $this->reset([
            'filterGrantedTo',
            'filterGrantedResources',
            'filterGrantedResourceType',
            'filterIsVerified',
            'filterIsExpired',
            'filterReason',
            'filterAccessLevel',
        ]);

        $this->search();
    }

    public function confirmCancelApproval(int $approvalId): void
    {
        $this->approvalToCancel = $approvalId;
        $this->showCancelApprovalModal = true;
    }

    public function cancelApproval(int $approvalId, PatientApprovalService $service): void
    {
        $this->showCancelApprovalModal = false;

        $approval = Approval::query()
            ->with([
                'createdBy',
                'approvable' => static fn (MorphTo $morphTo) => $morphTo->morphWith([
                    CarePlan::class => ['person'],
                ]),
            ])
            ->findOrFail($approvalId);

        if (Auth::user()->cannot('cancel', $approval)) {
            Session::flash('error', $approval->expiresAt !== null && !$approval->expiresAt->isFuture() ? __('approval.messages.cancel_expired') : __('approval.messages.cancel_forbidden'));

            return;
        }

        try {
            $service->cancel($approval);
        } catch (EHealthJobTimeoutException $exception) {
            $exception->handle('Approval cancellation job did not finish');

            return;
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while cancelling approval', __('approval.messages.cancel_failed'));

            return;
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, 'Error while storing cancelled approval');

            return;
        }

        Session::flash('success', __('approval.messages.cancelled'));
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterPatientId',
            'filterGrantedTo',
            'filterGrantedResources',
            'filterGrantedResourceType',
            'filterIsVerified',
            'filterIsExpired',
            'filterReason',
            'filterAccessLevel',
            'isSearching'
        ]);
        $this->resetValidation();
        $this->resetPage();
    }

    protected function paginateLocalApprovals(): LengthAwarePaginator
    {
        return Approval::query()->withAllRelations()->with([
            'approvable' => static fn (MorphTo $morphTo) => $morphTo->morphWith([
                Person::class => ['names'],
                CarePlan::class => ['person.names']
            ]),
        ])->where(static function (Builder $query): void {
            $employeeUuid = Auth::user()->activeDoctorEmployee()->uuid;

            $query->whereHas('grantedTo', static fn (Builder $identifier): Builder => $identifier->where('value', $employeeUuid))
                ->orWhereHas('createdBy', static fn (Builder $identifier): Builder => $identifier->where('value', $employeeUuid));
        })->latest()->paginate(config('pagination.per_page'));
    }

    protected function searchApprovalsFromEHealth(): LengthAwarePaginator
    {
        $perPage = config('pagination.per_page');
        $page = $this->getPage();

        try {
            $response = EHealth::approval()->getPatientApprovals($this->filterPatientId, $this->buildSearchParams());
            $approvals = Arr::toCamelCase($response->validate());
            $total = $response->getPaging()['total_entries'];
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while loading approvals');
            $approvals = [];
            $total = 0;
        }

        return new LengthAwarePaginator(collect($approvals), $total, $perPage, $page, ['path' => LengthAwarePaginator::resolveCurrentPath()]);
    }

    private function loadFilterOptions(): void
    {
        $employee = Auth::user()->activeDoctorEmployee();
        $approvals = Approval::query()->with([
            'reason', 'grantedResources.grantedTo',
            'approvable' => static fn (MorphTo $morphTo) => $morphTo->morphWith([Person::class => ['names'], CarePlan::class => ['person.names']]),
        ])->whereHas('grantedTo', static fn ($query) => $query->where('value', $employee->uuid))->get();

        $this->patients = Person::query()->with('names')->get(['id', 'uuid'])
            ->map(static fn (Person $patient): array => ['uuid' => $patient->uuid, 'name' => $patient->fullName.' | '.$patient->uuid])
            ->sortBy('name')->values()->all();
        $this->employees = [['uuid' => $employee->uuid, 'name' => $employee->fullName]];
        $this->resources = $approvals->flatMap(static fn (Approval $approval) => $approval->grantedResources)->pluck('grantedTo')->filter()
            ->map(static fn ($identifier): array => ['uuid' => $identifier->value, 'name' => ($identifier->displayValue ?: $identifier->value).' | '.$identifier->value])
            ->unique('uuid')->sortBy('name')->values()->all();
        $this->reasons = $approvals->pluck('reason')->filter()->map(static fn ($reason): array => ['uuid' => $reason->value, 'name' => $reason->displayValue ?: $reason->value])
            ->unique('uuid')->sortBy('name')->values()->all();
        $this->resourceTypes = collect(__('approval.resource_types'))->map(static fn ($name, $code): array => ['code' => $code, 'name' => $name])->values()->all();
        $this->booleanOptions = [['code' => 'true', 'name' => __('forms.yes')], ['code' => 'false', 'name' => __('forms.no')]];
        $this->accessLevels = [['code' => 'read', 'name' => __('patients.read')], ['code' => 'write', 'name' => __('patients.write')]];
    }

    private function buildSearchParams(): array
    {
        $canViewAll = Auth::user()->can('viewAllForPatient', [Approval::class, $this->searchPatient]);

        return array_filter([
            'granted_to' => $this->filterGrantedTo ?: ($canViewAll ? '' : Auth::user()->activeDoctorEmployee()->uuid),
            'granted_resources' => $this->filterGrantedResources,
            'granted_resource_type' => $this->filterGrantedResourceType,
            'is_verified' => $this->filterIsVerified,
            'is_expired' => $this->filterIsExpired,
            'reason' => $this->filterReason,
            'access_level' => $this->filterAccessLevel,
            'page' => $this->getPage(),
            'page_size' => config('pagination.per_page'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function filterValidationRules(): array
    {
        return [
            'filterPatientId' => ['required', 'uuid', Rule::in(array_column($this->patients, 'uuid'))],
            'filterGrantedTo' => ['nullable', 'uuid', Rule::in(array_column($this->employees, 'uuid'))],
            'filterGrantedResources' => ['nullable', 'uuid', Rule::in(array_column($this->resources, 'uuid'))],
            'filterGrantedResourceType' => ['nullable', Rule::in(array_column($this->resourceTypes, 'code'))],
            'filterIsVerified' => ['nullable', Rule::in(['true', 'false'])],
            'filterIsExpired' => ['nullable', Rule::in(['true', 'false'])],
            'filterReason' => ['nullable', 'uuid', Rule::in(array_column($this->reasons, 'uuid'))],
            'filterAccessLevel' => ['nullable', Rule::in(['read', 'write'])],
        ];
    }

    public function render(): View
    {
        return view('livewire.approval.approval-index');
    }
}
