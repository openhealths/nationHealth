<?php

declare(strict_types=1);

namespace App\Livewire\Declaration;

use App\Enums\User\Role;
use Throwable;
use Exception;
use App\Models\User;
use Livewire\Component;
use App\Enums\JobStatus;
use Illuminate\View\View;
use Illuminate\Bus\Batch;
use App\Traits\FormTrait;
use App\Models\Declaration;
use App\Models\LegalEntity;
use Illuminate\Support\Str;
use Livewire\WithPagination;
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
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Session;
use App\Notifications\SyncNotification;
use App\Traits\BatchLegalEntityQueries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Client\ConnectionException;
use App\Notifications\DeclarationSyncCompleted;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;

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
    public array $statusFilter = ['active', 'CANCELLED'];

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

    public bool $isFiltersApplied = false;

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
        $user = Auth::user();

        $this->employeeIds = $user->party->employees()->filterByLegalEntityId($legalEntity->id)->pluck('id')->all();

        if ($user->hasRole(Role::OWNER)) {
            $this->doctors = $this->getDoctors();
        } else {
            $this->countActive = Declaration::query()->forEmployees($this->employeeIds)->count();
        }

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
        $this->statusFilter = ['active', 'CANCELLED'];
        $this->doctorFilter = [];

        $this->resetPage();
    }

    #[Computed]
    public function declarations(): LengthAwarePaginator
    {
        $user = Auth::user();

        $declarations = collect();
        $declarationRequests = collect();

        if ($user->can('viewAny', Declaration::class)) {
            $declarations = Declaration::with([
                'person:id,first_name,last_name,second_name,birth_date',
                'employee:id,uuid,party_id',
                'employee.party:id,first_name,last_name,second_name'
            ])
                ->when(
                    !$user->hasRole(Role::OWNER),
                    fn (Builder $query) => $query->forEmployees($this->employeeIds)
                )
                ->filterByLegalEntityId(legalEntity()->id)
                ->get(['id', 'person_id', 'employee_id', 'legal_entity_id', 'declaration_number', 'status'])
                ->each->setAttribute('type', 'declaration');
        }

        // Don't show declaration requests for OWNER
        if (!$user->hasRole(Role::OWNER) && $user->can('viewAny', DeclarationRequest::class)) {
            $declarationRequests = DeclarationRequest::with([
                'person:id,first_name,last_name,second_name,birth_date',
                'employee:id,party_id',
                'employee.party:id,first_name,last_name,second_name'
            ])
                ->filterByLegalEntityId(legalEntity()->id)
                ->forEmployees($this->employeeIds)
                ->whereNotIn('status', [Status::SIGNED->value])
                ->get(['id', 'uuid', 'person_id', 'employee_id', 'declaration_number', 'status'])
                ->each->setAttribute('type', 'request');
        }

        $allItems = $declarationRequests->concat($declarations);

        if ($this->isFiltersApplied) {
            // Filter by type
            if (!empty($this->typeFilter)) {
                $allItems = $allItems->filter(
                    fn (DeclarationRequest|Declaration $item) => in_array($item->type, $this->typeFilter, true)
                );
            }

            // Filter by status
            if (!empty($this->statusFilter)) {
                $allItems = $allItems->filter(function (DeclarationRequest|Declaration $item) {
                    if ($item instanceof Declaration) {
                        return in_array($item->status->value, $this->statusFilter, true);
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
                        return in_array($item->employee->uuid, $this->doctorFilter, true);
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
            Session::flash('error', 'Синхронізація вже запущена. Будь ласка, зачекайте її завершення.');

            return;
        }

        $legalEntity = legalEntity();

        $user = Auth::user();
        $token = Session::get(config('ehealth.api.oauth.bearer_token'));
        $user->notify(new SyncNotification('declaration', 'started'));

        // Try to resume previous sync if it was paused or failed
        if ($this->syncStatus === JobStatus::PAUSED->value || $this->syncStatus === JobStatus::FAILED->value) {

            $this->resumeSynchronization($user, $token);

            Session::flash('success', __('Відновлення попередньої синхронізації розпочато'));

            $user->notify(new SyncNotification('declaration', 'resumed'));

            return;
        }

        // Get declarations from eHealth filtered by legal entity
        $query = ['legal_entity_id' => $legalEntity->uuid];

        // If user is doctor, get only his declarations
        if ($user->hasRole(Role::DOCTOR) && !$user->hasRole(Role::OWNER)) {
            $query['employee_id'] = Auth::user()->party
                ->employees()
                ->forParty(Auth::user()->party->id)
                ->first()->uuid;
        }

        try {
            $response = EHealth::declaration()->getMany(query: $query, groupByEntities: true);

            $declarations = $response->validate();

            Repository::declaration()->storeMany($declarations);
        } catch (ConnectionException $exception) {
            $this->logConnectionError($exception, 'Error while syncing declaration requests');
            Session::flash('error', "Виникла помилка. Відсутній зв'язок із ЕСОЗ");

            return;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            $this->logEHealthException($exception, 'Error while syncing declaration requests');
            Session::flash('error', 'Виникла помилка. Зверніться до адміністратора.');

            return;
        } catch (Exception $exception) {
            $this->logDatabaseErrors($exception, 'Error while syncing declaration requests');
            Session::flash('error', 'Виникла помилка. Зверніться до адміністратора.');

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

                session()->flash('success', __('declarations.sync.completed'));
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

    public function approve(int $patientId, int $declarationRequestId): void
    {
        if (!$this->ensureAbility('approve', __('declarations.policy.approve'))) {
            return;
        }

        $declarationRequest = DeclarationRequest::findOrFail($declarationRequestId);

        $this->redirectRoute(
            'declaration.edit',
            [legalEntity(), 'patientId' => $patientId, 'declarationRequest' => $declarationRequest],
            navigate: true
        );
    }

    public function sign(int $patientId, int $declarationRequestId): void
    {
        if (!$this->ensureAbility('sign', __('declarations.policy.sign'))) {
            return;
        }

        Session::flash('showSignModal');
        $declarationRequest = DeclarationRequest::findOrFail($declarationRequestId);

        $this->redirectRoute(
            'declaration.edit',
            [legalEntity(), 'patientId' => $patientId, 'declarationRequest' => $declarationRequest],
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

            ['status' => $status, 'statusReason' => $statusReason] = $response->getData();

            Repository::declarationRequest()->updateStatuses($declarationUuid, $status, $statusReason);
        } catch (ConnectionException $exception) {
            $this->logConnectionError($exception, 'Error while rejecting declaration request');
            Session::flash('error', "Виникла помилка. Відсутній зв'язок із ЕСОЗ");

            return;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            $this->logEHealthException($exception, 'Error while rejecting declaration request');

            if ($exception instanceof EHealthValidationException) {
                Session::flash('error', $exception->getFormattedMessage());
            } else {
                Session::flash('error', 'Помилка від ЕСОЗ: ' . $exception->getMessage());
            }

            return;
        } catch (Exception $exception) {
            $this->logDatabaseErrors($exception, 'Error updating status in declaration request');
            Session::flash('error', 'Виникла помилка. Зверніться до адміністратора.');

            return;
        }
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
            $this->logDatabaseErrors($exception, 'Error while deleting declaration request');
            Session::flash('error', 'Виникла помилка. Зверніться до адміністратора.');

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
            ->get(['id', 'uuid', 'party_id'])
            ->map(fn (Employee $doctor) => [
                'uuid' => $doctor->uuid,
                'full_name' => trim($doctor->party->fullName)
            ]);
    }

    public function render(): View
    {
        return view('livewire.declaration.declaration-index', [
            'declarations' => $this->declarations
        ]);
    }
}
