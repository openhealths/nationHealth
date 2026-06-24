<?php

declare(strict_types=1);

namespace App\Livewire\Declaration;

use Throwable;
use Exception;
use App\Core\Arr;
use App\Models\User;
use Livewire\Component;
use App\Enums\User\Role;
use App\Enums\JobStatus;
use Illuminate\View\View;
use Illuminate\Bus\Batch;
use App\Traits\FormTrait;
use App\Models\Declaration;
use App\Models\LegalEntity;
use Illuminate\Support\Str;
use Livewire\WithPagination;
use App\Models\Person\Person;
use App\Jobs\DeclarationsSync;
use App\Repositories\Repository;
use App\Classes\eHealth\EHealth;
use App\Enums\Declaration\Status;
use App\Models\Employee\Employee;
use Livewire\Attributes\Computed;
use App\Models\DeclarationRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Enums\Status as EntityStatus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Session;
use App\Notifications\SyncNotification;
use App\Traits\BatchLegalEntityQueries;
use Illuminate\Database\Eloquent\Builder;
use App\Exceptions\EHealth\EHealthException;
use App\Enums\Declaration\ReorganizedStatus;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Notifications\DeclarationSyncCompleted;
use App\Exceptions\EHealth\EHealthConnectionException;

class DeclarationIndex extends Component
{
    use BatchLegalEntityQueries;
    use WithPagination;
    use FormTrait;

    protected const string BATCH_NAME = 'DeclarationsSync';
    protected const string SUB_BATCH_NAME = 'DeclarationDetailsSync';
    protected const string DEPENDENT_BATCH_NAME = 'DeclarationRequestDetailsSync';

    /**
     * Search by patient first and last names.
     *
     * @var string
     */
    public string $searchByName = '';

    /**
     * Represents the current synchronization status for the component.
     *
     * @var string
     */
    public string $syncStatus = '';

    /**
     * Search by declaration and declaration request number
     *
     * @var string
     */
    public string $searchByNumber = '';

    /**
     * Default types for multiselect filter
     *
     * @var array|string[]
     */
    public array $typeFilter = ['request', 'declaration'];

    /**
     * Default status for multiselect filter
     *
     * @var array|string[]
     */
    public array $statusFilter = [Status::ACTIVE->value];

    public array $reorganizationFilter = [];

    /**
     * Filter for multiselect doctors
     *
     * @var array|string[]
     */
    public array $doctorFilter = [];

    /**
     * Available doctors list
     *
     * @var Collection
     */
    public Collection $doctors;

    /**
     * Count of active declarations.
     *
     * @var int
     */
    public int $countActive;

    public array $employeeIds;

    /**
     * eHealth uuids of the current user's own employees in this legal entity.
     *
     * @var array|string[]
     */
    public array $ownEmployeeUuids = [];

    public bool $isFiltersApplied = false;

    protected array $dictionaryNames = ['POSITION'];

    /**
     * Determine if the declaration is synchronized.
     *
     * @return bool True if the declaration is synchronized, false otherwise.
     */
    #[Computed]
    public function isSync(): bool
    {
        return $this->isSyncProcessing();
    }

    /**
     * Get the synchronization status of the declarations
     *
     * @return string The current sync status
     */
    protected function getSyncStatus(): string
    {
        return legalEntity()?->getEntityStatus(LegalEntity::ENTITY_DECLARATION) ?? '';
    }

    /**
     * Determine if a synchronization process is currently running.
     *
     * @return bool True if a sync process is actively processing, false otherwise.
     */
    protected function isSyncProcessing(): bool
    {
        // Get the sync status for whole Legal Entity
        $legalEntitySyncStatus = legalEntity()?->getEntityStatus();

        // Get the sync status only for Division
        $divisionSyncStatus = legalEntity()?->getEntityStatus(LegalEntity::ENTITY_DIVISION);

        // Get the sync status only for HealthCare Service
        $healthCareServiceSyncStatus = legalEntity()?->getEntityStatus(LegalEntity::ENTITY_HEALTHCARE_SERVICE);

        // Get the sync status only for HealthCare Service
        $employeeSyncStatus = legalEntity()?->getEntityStatus(LegalEntity::ENTITY_EMPLOYEE);

        // Set the sync status only for Declaration
        $this->syncStatus = $this->getSyncStatus();

        // Determine if either the Legal Entity's sync is in progress
        $legalEntitySync = $this->isEntitySyncIsInProgress($legalEntitySyncStatus, true);

        // Determine if either the Division's sync is in progress
        $divisionSync = $divisionSyncStatus !== JobStatus::COMPLETED->value;

        // Determine if either the HealthCare Service's sync is in progress
        $healthCareServiceSync = $healthCareServiceSyncStatus !== JobStatus::COMPLETED->value;

        // Determine if either the Employee's sync is in progress
        $employeeSync = $employeeSyncStatus !== JobStatus::COMPLETED->value;

        // Determine if either the Declaration's sync is in progress
        $declarationSync = $this->isEntitySyncIsInProgress($this->syncStatus);

        // Return true if either sync is in progress
        return $legalEntitySync ||
            $declarationSync ||
            $divisionSync ||
            $healthCareServiceSync ||
            $employeeSync;
    }

    public function boot(): void
    {
        // This will ensure that the 'isSync' computed property is not cached between requests
        unset($this->isSync);
    }

    public function mount(LegalEntity $legalEntity): void
    {
        $this->getDictionary();

        $user = Auth::user();

        $ownEmployees = $user->party->employees()
            ->filterByLegalEntityId($legalEntity->id)
            ->get(['id', 'uuid', 'employee_type']);

        // Use only the current user's doctor employee UUIDs for the default doctor filter.
        $this->ownEmployeeUuids = $ownEmployees
            ->where('employee_type', Role::DOCTOR->value)
            ->pluck('uuid')
            ->filter()
            ->values()
            ->all();

        // Select employee_ids from reorganization_employee_declarations where legal_entity_uuid is in legators of current legal entity
        $reorganizedEmployeeIds = $user->party->reorganizedEmployeeDeclarations()
            ->hasConnectionTo($legalEntity)
            ->pluck('employee_id')
            ->all();

        $this->employeeIds = array_values(array_unique(array_merge($ownEmployees->pluck('id')->all(), $reorganizedEmployeeIds)));

        $this->doctors = $this->getDoctors();

        // A user with a doctor employee defaults to viewing that doctor's declarations.
        if (!empty($this->ownEmployeeUuids)) {
            $this->doctorFilter = $this->ownEmployeeUuids;
        }

        $this->countActive = Declaration::query()
            ->forEmployees($this->employeeIds)
            ->where('status', Status::ACTIVE)
            ->filterByLegalEntityId(legalEntity()->id)
            ->count();

        $this->syncStatus = $this->getSyncStatus();
    }

    public function search(): void
    {
        $this->resetPage();
        $this->isFiltersApplied = true;
    }

    public function resetFilters(): void
    {
        $this->searchByName = '';
        $this->searchByNumber = '';
        $this->typeFilter = ['request', 'declaration'];
        $this->statusFilter = [Status::ACTIVE->value];
        $this->reorganizationFilter = [];
        $this->doctorFilter = $this->ownEmployeeUuids;

        $this->isFiltersApplied = false;

        $this->resetPage();
    }

    #[Computed]
    public function declarations(): LengthAwarePaginator
    {
        $user = Auth::user();

        $declarations = collect();
        $declarationRequests = collect();

        if ($user->can('viewAny', Declaration::class)) {
            $selectedEmployeeIds = $this->doctors
                ->whereIn('uuid', $this->doctorFilter)
                ->pluck('id')
                ->all();

            $employeePool = array_values(array_unique(array_merge($this->employeeIds, $selectedEmployeeIds)));

            $declarations = Declaration::with([
                'reorganizedEmployeeDeclaration',
                'person:id,first_name,last_name,second_name,birth_date',
                'employee:id,uuid,party_id',
                'employee.party:id,first_name,last_name,second_name'
            ])
                ->when(
                    !$user->hasAllowedRole(Role::OWNER),
                    fn (Builder $query) => $query->forEmployees($employeePool),
                    fn (Builder $query) => $query->filterByLegalEntityId(legalEntity()->id)
                        ->when(
                            !empty($selectedEmployeeIds),
                            fn (Builder $ownerQuery) => $ownerQuery->forEmployees($selectedEmployeeIds)
                        )
                )
                ->get(['id', 'person_id', 'employee_id', 'legal_entity_id', 'declaration_number', 'declaration_request_id', 'status'])
                ->each->setAttribute('type', 'declaration');
        }

        // Don't show declaration requests for OWNER
        if (!$user->hasAllowedRole(Role::OWNER) && $user->can('viewAny', DeclarationRequest::class)) {
            $declarationRequests = DeclarationRequest::with([
                'person:id,first_name,last_name,second_name,birth_date',
                'employee:id,party_id',
                'employee.party:id,first_name,last_name,second_name'
            ])
                ->forEmployees($this->employeeIds)
                ->whereNotIn('status', [Status::SIGNED->value])
                ->get(['id', 'uuid', 'person_id', 'employee_id', 'declaration_number', 'status', 'parent_declaration_uuid'])
                ->each->setAttribute('type', 'request');
        }

        $allItems = $declarationRequests->concat($declarations);

        if ($this->isFiltersApplied) {
            // Filter by type
            if (!empty($this->typeFilter)) {
                $allItems = $allItems->filter(
                    fn (DeclarationRequest|Declaration $item) => \in_array($item->type, $this->typeFilter, true)
                );
            }

            // Filter by status
            if (!empty($this->statusFilter)) {
                $allItems = $allItems->filter(function (DeclarationRequest|Declaration $item) {
                    if ($item instanceof Declaration) {
                        return \in_array($item->status->value, $this->statusFilter, true);
                    }

                    return true;
                });
            }

            // Search by first and last name
            if (!empty($this->searchByName)) {
                $searchTerm = Str::lower(trim($this->searchByName));

                $allItems = $allItems->filter(function (DeclarationRequest|Declaration $item) use ($searchTerm) {
                    $last = Str::lower(data_get($item, 'person.last_name', ''));
                    $first = Str::lower(data_get($item, 'person.first_name', ''));

                    return Str::contains($last, $searchTerm) || Str::contains($first, $searchTerm);
                });
            }

            // Search by declaration number
            if (!empty($this->searchByNumber)) {
                $searchTerm = Str::lower(trim($this->searchByNumber));

                $allItems = $allItems->filter(function (DeclarationRequest|Declaration $item) use ($searchTerm) {
                    $number = Str::lower($item->declaration_number ?? '');

                    return Str::contains($number, $searchTerm);
                });
            }

            // Filter by doctors
            if (!empty($this->doctorFilter)) {
                $allItems = $allItems->filter(function (DeclarationRequest|Declaration $item) {
                    if ($item instanceof Declaration) {
                        return \in_array($item->employee->uuid, $this->doctorFilter, true);
                    }

                    return false;
                });
            }

            // Filter by reorganization type
            if (!empty($this->reorganizationFilter)) {
                $allItems = $allItems->filter(function (DeclarationRequest|Declaration $item) {
                    if ($item instanceof DeclarationRequest) {
                        return false;
                    }

                    if ($item->reorganizedEmployeeDeclaration) {
                        if ($item->hasParentDeclaration()) {
                            return \in_array(ReorganizedStatus::RESIGNED->value, $this->reorganizationFilter, true);
                        }

                        return \in_array(ReorganizedStatus::TO_BE_RESIGNED->value, $this->reorganizationFilter, true);
                    }

                    return false;
                });
            }
        }

        // Pagination
        $perPage = config('pagination.per_page');
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $currentItems = $allItems->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $currentItems,
            $allItems->count(),
            $perPage,
            $currentPage,
            ['path' => request()->url()]
        );
    }

    public function sync(): void
    {
        if (Auth::user()->cannot('sync', Declaration::class)) {
            Session::flash('error', __('declarations.policy.sync'));

            return;
        }

        if ($this->isSyncProcessing()) {
            Session::flash('error', __('forms.errors.sync_already_running'));

            return;
        }

        $legalEntity = legalEntity();

        if (
            $legalEntity->status === EntityStatus::REORGANIZED->value &&
            !$legalEntity->employees()->where('status', EntityStatus::REORGANIZED->value)->exists()
        ) {
            Session::flash('error', __('forms.errors.sync_reorg_declarations_cannot_start'));

            return;
        }

        $user = Auth::user();
        $token = Session::get(config('ehealth.api.oauth.bearer_token'));
        $user->notify(new SyncNotification('declaration', 'started'));

        // Try to resume previous sync if it was paused or failed
        if ($this->syncStatus === JobStatus::PAUSED->value || $this->syncStatus === JobStatus::FAILED->value) {
            $this->resumeSynchronization($user, $token);

            Session::flash('success', __('forms.success.sync_resumed'));

            $user->notify(new SyncNotification('declaration', 'resumed'));

            return;
        }

        // Get declarations from eHealth filtered by legal entity
        $query = ['legal_entity_id' => $legalEntity->uuid];

        // If user is doctor, get only his declarations
        if ($user->hasAllowedRole(Role::DOCTOR) && !$user->hasAllowedRole(Role::OWNER)) {
            $query['employee_id'] = Auth::user()->party
                ->employees()
                ->whereLegalEntityId($legalEntity->id)
                ->forParty(Auth::user()->party->id)
                ->first()->uuid;
        }

        try {
            $response = EHealth::declaration()->getMany(query: $query, groupByEntities: true);

            $declarations = $response->validate();

            Repository::declaration()->storeMany($declarations);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while syncing declaration requests');

            return;
        } catch (Exception $exception) {
            $this->handleDatabaseErrors($exception, 'Error while syncing declaration requests');

            return;
        }

        // Check if there are more pages to process
        if ($response->isNotLast()) {
            Bus::batch([
                new DeclarationsSync(
                    legalEntity: $legalEntity,
                    page: 2,
                    nextEntity: null
                )
            ])
                ->withOption('legal_entity_id', $legalEntity->id)
                ->withOption('token', Crypt::encryptString($token))
                ->withOption('user', $user)
                ->then(function (Batch $batch) use ($user) {
                    $message = __('declarations.sync.completed', [
                        'processed' => $batch->processedJobs,
                        'total' => $batch->totalJobs,
                    ]);

                    $user->notify(new DeclarationSyncCompleted($message, 'success'));
                })->catch(callback: function (Batch $batch, Throwable $err) use ($user) {
                    $message = __('declarations.sync.failed');

                    Log::error('Declaration sync batch failed.', [
                        'batch_id' => $batch->id,
                        'exception' => $err
                    ]);

                    $user->notify(new DeclarationSyncCompleted($message, 'error'));
                })
                ->onQueue('sync')
                ->name(self::BATCH_NAME)
                ->dispatch();

            legalEntity()?->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_DECLARATION);

            Session::flash('success', __('declarations.sync.started'));
        } else {
            if (!empty($declarations['declarations'])) {
                Bus::batch($this->getDeclarationRequestsStartJob($legalEntity, null))
                    ->withOption('legal_entity_id', $legalEntity->id)
                    ->withOption('token', Crypt::encryptString($token))
                    ->withOption('user', $user)
                    ->then(function (Batch $batch) use ($user) {
                        $message = __('declarations.sync.completed', [
                            'processed' => $batch->processedJobs,
                            'total' => $batch->totalJobs,
                        ]);

                        $user->notify(new DeclarationSyncCompleted($message, 'success'));
                    })->catch(callback: function (Batch $batch, Throwable $err) use ($user) {
                        $message = __('declarations.sync.failed');

                        Log::error('DeclarationRequest sync batch failed.', [
                            'batch_id' => $batch->id,
                            'exception' => $err
                        ]);

                        $user->notify(new DeclarationSyncCompleted($message, 'error'));
                    })
                    ->onQueue('sync')
                    ->name(self::DEPENDENT_BATCH_NAME)
                    ->dispatch();

                legalEntity()?->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_DECLARATION);

                Session::flash('success', __('declarations.sync.started'));
            } else {
                // If there were no declarations to sync, mark the status as completed
                legalEntity()?->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_DECLARATION);

                Session::flash('success', __('declarations.sync.completed'));
            }
        }
    }

    /**
     * Resume the synchronization process for a user with the provided token.
     *
     * This method handles the continuation of a previously initiated synchronization
     * operation for a specific user using an authentication or session token.
     *
     * @param  User  $user  The user instance for whom synchronization should be resumed
     * @param  string  $token  The authentication or session token used to resume the sync process
     * @return void
     */
    protected function resumeSynchronization(User $user, string $token): void
    {
        $encryptedToken = Crypt::encryptString($token);

        // Find all the EmployeeRequests failed batches for this legal entity and retry them
        $failedBatches = $this->findFailedBatchesByLegalEntity(legalEntity()->id, 'ASC');

        foreach ($failedBatches as $batch) {
            if ($batch->name === self::BATCH_NAME || $batch->name === self::SUB_BATCH_NAME || $batch->name === self::DEPENDENT_BATCH_NAME) {
                Log::info('Resuming Declaration sync batch: ' . $batch->name . ' id: ' . $batch->id);

                legalEntity()?->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_DECLARATION);

                $this->restartBatch($batch, $user, $encryptedToken, legalEntity());

                break;
            }
        }
    }

    public function approve(int $personId, int $declarationRequestId): void
    {
        if (!$this->ensureAbility('approve', __('declarations.policy.approve'))) {
            return;
        }

        $declarationRequest = DeclarationRequest::findOrFail($declarationRequestId);

        $this->redirectRoute(
            'declaration.edit',
            [legalEntity(), 'personId' => $personId, 'declarationRequest' => $declarationRequest],
            navigate: true
        );
    }

    public function sign(int $personId, int $declarationRequestId): void
    {
        if (!$this->ensureAbility('sign', __('declarations.policy.sign'))) {
            return;
        }

        Session::flash('showSignModal');
        $declarationRequest = DeclarationRequest::findOrFail($declarationRequestId);

        $this->redirectRoute(
            'declaration.edit',
            [legalEntity(), 'personId' => $personId, 'declarationRequest' => $declarationRequest],
            navigate: true
        );
    }

    /**
     * Create a new resignation declaration request for a declaration from a reorganized legal entity.
     *
     * Finds the reorganized employee declaration linked to the given declaration ID,
     * builds a new declaration request payload using the current authenticated doctor
     * and the reorganized person's data, stores it locally, sends it to eHealth,
     * updates the local record with the eHealth response, then redirects to the
     * declaration edit page to continue the approval and sign flow.
     *
     * @param  int  $declarationId  The local ID of the declaration to resign
     * @return void
     */
    public function resign(int $declarationId): void
    {
        if (Auth::user()->cannot('resign', Declaration::class)) {
            Session::flash('error', __('declarations.policy.resign'));

            return;
        }

        $reorganizedDeclaration = Declaration::find($declarationId)->reorganizedEmployeeDeclaration()->first();
        $currentEmployee = Employee::where('user_id', Auth::id())->where('employee_type', 'DOCTOR')->whereNot('status', EntityStatus::REORGANIZED)->first();
        $reorganizedPerson = Person::find($reorganizedDeclaration->personId);

        $resignRequestData = [
                "person_id" => $reorganizedPerson->uuid,
                "employee_id" => $currentEmployee->uuid,
                "division_id" => $currentEmployee->divisionUuid,
                "authorize_with" => $reorganizedDeclaration->authorizeWith,
                "parent_declaration_id" => $reorganizedDeclaration->declarationUuid
        ];

        $declarationRequest = Repository::declarationRequest()->store(array_merge($resignRequestData, ['status' => EntityStatus::DRAFT->value]));

        try {
            $response = EHealth::declarationRequest()->create(removeEmptyKeys(Arr::toSnakeCase($resignRequestData)));

            $responseData = $response->getData();

            $responseData['sync_status'] = JobStatus::PARTIAL->value;

            try {
                Repository::declarationRequest()->update($declarationRequest->id, $responseData);
            } catch (Exception $exception) {
                $this->handleDatabaseErrors($exception, 'Error updating declaration request after response');
                Session::flash('error', __('messages.database_error'));

                return;
            }
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error when resending create resign declaration request');

            return;
        }

        $this->redirectRoute(
            'declaration.edit',
            [legalEntity(), 'personId' => $reorganizedPerson->id, 'declarationRequest' => $declarationRequest],
            navigate: true
        );
    }

    public function reject(string $declarationUuid): void
    {
        if (!$this->ensureAbility('reject', __('declarations.policy.reject'))) {
            return;
        }

        try {
            $response = EHealth::declarationRequest()->reject($declarationUuid);

            ['status' => $status, 'status_reason' => $statusReason] = $response->getData();

            Repository::declarationRequest()->updateStatuses($declarationUuid, $status, $statusReason);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error while rejecting declaration request');

            return;
        } catch (Exception $exception) {
            $this->handleDatabaseErrors($exception, 'Error updating status in declaration request');

            return;
        }

        Session::flash('success', __('declarations.rejected_declaration_request'));
    }

    /**
     * Delete declaration request with status DRAFT from DB.
     *
     * @param  DeclarationRequest  $declarationRequest
     * @return void
     */
    public function delete(DeclarationRequest $declarationRequest): void
    {
        if (Auth::user()->cannot('delete', $declarationRequest)) {
            Session::flash('error', __('declarations.policy.delete'));

            return;
        }

        try {
            DeclarationRequest::destroy($declarationRequest->id);
        } catch (Exception $exception) {
            $this->handleDatabaseErrors($exception, 'Error while deleting declaration request');

            return;
        }
    }

    /**
     * Ensure that the authenticated user has the given ability; if not, flash an error message.
     *
     * @param  string  $ability
     * @param  string  $errorMessage
     * @return bool
     */
    protected function ensureAbility(string $ability, string $errorMessage): bool
    {
        if (Auth::user()->cannot($ability, DeclarationRequest::class)) {
            Session::flash('error', $errorMessage);

            return false;
        }

        return true;
    }

    /**
     * Get list of doctors in current legal entity.
     *
     * @return Collection
     */
    protected function getDoctors(): Collection
    {
        return Employee::with('party:id,last_name,first_name')
            ->doctor()
            ->filterByLegalEntityId(legalEntity()->id)
            ->whereHas('declarations')
            ->get(['id', 'uuid', 'party_id', 'position'])
            ->map(fn (Employee $doctor) => [
                'id' => $doctor->id,
                'uuid' => $doctor->uuid,
                'fullName' => trim($doctor->party->fullName . ' - ' . ($this->dictionaries['POSITION'][$doctor->position]))
            ]);
    }

    public function render(): View
    {
        return view('livewire.declaration.declaration-index', [
            'declarations' => $this->declarations
        ]);
    }
}
