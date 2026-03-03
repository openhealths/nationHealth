<?php

declare(strict_types=1);

namespace App\Livewire\Employee;

use AllowDynamicProperties;
use App\Classes\eHealth\EHealth;
use App\Enums\JobStatus;
use App\Enums\Status;
use App\Enums\User\Role;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Jobs\EmployeeSync;
use App\Models\Employee\Employee;
use App\Models\Employee\EmployeeRequest;
use App\Models\LegalEntity;
use App\Models\User;
use App\Notifications\EmployeeSyncCompleted;
use App\Notifications\SyncNotification;
use App\Repositories\Repository;
use App\Traits\BatchLegalEntityQueries;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use JsonException;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

#[AllowDynamicProperties]
class EmployeeIndex extends EmployeeComponent
{
    use WithPagination,
        BatchLegalEntityQueries;

    protected const string BATCH_NAME = 'EmployeeFullSync';
    protected const string DEPENDENT_BATCH_NAME = 'EmployeeDetailsSync';

    // --- Component State for Filters ---
    public string $search = '';
    public array $status = ['APPROVED', 'NEW', 'SIGNED'];
    public array $filter = [
        'phone' => '',
        'email' => '',
        'role' => '',
        'position' => '',
        'division_id' => '',
    ];

    // --- State for Modals ---
    public bool $showDeactivateModal = false;
    public ?int $employeeIdToDeactivate = null;
    public ?string $employeeToDeactivateName = null;
    public bool $isDoctorToDeactivate = false;

    public ?int $employeeToDismissId = null;
    public ?string $employeeToDismissName = null;

    public bool $showDeleteModal = false;
    public ?int $requestToDeleteId = null;
    public ?string $deleteRequestName = null;

    public ?string $batchId = null;
    public string $dismissalMessageType = 'default';

    public int $refreshTrigger = 0;

    private LegalEntity $legalEntity;

    /**
     * Represents the current synchronization status for the component.
     *
     * @var string
     */
    public string $syncStatus = '';

   #[Computed]
    public function isSync(): bool
    {
        return $this->isSyncProcessing();
    }

    /**
     * Get the synchronization status of the employee request.
     *
     * @return string The current sync status
     */
    protected function getSyncStatus(): string
    {
        return legalEntity()?->getEntityStatus(LegalEntity::ENTITY_EMPLOYEE) ?? '';
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

        // Set the sync status only for Employee
        $this->syncStatus = $this->getSyncStatus();

        // Determine if either the Legal Entity's sync is in progress
        $legalEntitySync = $this->isEntitySyncIsInProgress($legalEntitySyncStatus, true);

        // Determine if either the Division's sync is in progress
        $employeeSync = $this->isEntitySyncIsInProgress($this->syncStatus);

        // Return true if either sync is in progress
        return $legalEntitySync || $employeeSync;
    }

    public function boot(): void
    {
        $this->legalEntity = legalEntity();

        // This will ensure that the 'isSync' computed property is not cached between requests
        unset($this->isSync);
    }

    public function mount(LegalEntity $legalEntity): void
    {
        $this->legalEntity = $legalEntity;

        $this->loadDivisions($legalEntity);

        $this->loadDictionaries();

        // Set the sync status for Employee
        $this->syncStatus = $this->getSyncStatus();
    }

    public function applyFilters(): void
    {
        $this->resetPage();
    }

    /**
     * Main computed property to fetch and filter parties.
     */
    #[Computed]
    public function parties(): LengthAwarePaginator
    {
        // 1. We get the basic query from the repository (all the complex SQL is hidden there)
        $query = Repository::employee()->getPartiesWithLatestActivityQuery($this->legalEntity->id);

        // 2. Apply dynamic filters (Search, Email, Phone, etc.)
        // This method (applyDatabaseFilters) remains in the component because it is responsible for UI filtering
        $this->applyDatabaseFilters($query);

        // 3. Return the paginated result
        return $query->paginate(10);
    }

    /**
     * Applies UI filters (Search, Email, Phone, Status) to the query builder.
     */
    private function applyDatabaseFilters(Builder $query): void
    {
        // 1. Filter: Ensure Party is linked to this Legal Entity via Employee or Request
        $query->where(function (Builder $q) {
            $q->whereHas('employees', function ($sub) {
                $sub->where('legal_entity_id', $this->legalEntity->id);
                $this->applyChildFilters($sub);
            })
                ->orWhereHas('employeeRequests', function ($sub) {
                    $sub->where('legal_entity_id', $this->legalEntity->id)
                        ->whereIn('status', [Status::NEW->value, Status::SIGNED->value]);
                    $this->applyChildFilters($sub);
                });
        });

        // 2. Filter: Search Text (Full Name, Case-Insensitive)
        if (!empty($this->search)) {
            $searchTerm = '%' . $this->search . '%';
            // PostgreSQL specific: ILIKE is case-insensitive
            $query->whereRaw("CONCAT(last_name, ' ', first_name, ' ', second_name) ILIKE ?", [$searchTerm]);
        }

        // 3. Filter: Email (via Users)
        if (!empty($this->filter['email'])) {
            // ILIKE for emails too
            $query->whereHas('party.users', fn ($q) => $q->where('email', 'ILIKE', '%' . $this->filter['email'] . '%'));
        }

        // 4. Filter: Phone
        if (!empty($this->filter['phone'])) {
            $query->whereHas('phones', fn ($q) => $q->where('number', 'like', '%' . $this->filter['phone'] . '%'));
        }
    }

    /**
     * Helper to apply role, division, position, and status filters to relationship subqueries.
     */
    private function applyChildFilters(Builder $subQuery): void
    {
        // Status Filter
        if (!empty($this->status)) {
            // Map 'DISMISSED' -> 'STOPPED' for DB query
            $dbStatuses = array_map(fn ($s) => $s === 'DISMISSED' ? 'STOPPED' : $s, $this->status);

            // Remove non-DB statuses (like 'VERIFIED'/'NOT_VERIFIED' which apply to Party)
            $dbStatuses = array_diff($dbStatuses, ['VERIFIED', 'NOT_VERIFIED']);

            if (!empty($dbStatuses)) {
                $subQuery->whereIn('status', $dbStatuses);
            }
        }

        // Division Filter
        if (!empty($this->filter['division_id'])) {
            $subQuery->where('division_id', $this->filter['division_id']);
        }

        // Role Filter
        if (!empty($this->filter['role'])) {
            $subQuery->where('employee_type', $this->filter['role']);
        }

        // Position Filter
        if (!empty($this->filter['position'])) {
            $subQuery->where('position', $this->filter['position']);
        }
    }

    public function showModalDeactivate(int $id): void
    {
        $employee = Employee::find($id);

        if ($employee) {
            $this->employeeIdToDeactivate = $id;

            $this->employeeToDeactivateName = $employee->full_name
                ?? ($employee->last_name . ' ' . $employee->first_name);

            // Logic to determine if the employee is a doctor
            // Checks both the property/accessor and the position code
            $type = $employee->employeeType ?? $employee->employee_type ?? '';

            $this->isDoctorToDeactivate = ($type === Role::DOCTOR->value);
        }

        $this->showDeactivateModal = true;
    }

    public function closeModal(): void
    {
        $this->showDeactivateModal = false;
        $this->reset(['employeeToDeactivateId', 'employeeToDeactivateName', 'dismissalMessageType']);
    }

    public function resetFilters(): void
    {
        $this->reset(['filter', 'status', 'search']);
        $this->status = ['APPROVED', 'NEW'];
        $this->resetPage();
    }

    public function tryEdit(int $employeeId): void
    {
        $this->dispatch('flashMessage', ['message' => 'Користувач має підтвердити вхід', 'type' => 'error']);
    }

    public function deactivate(): void
    {
        // 1. Get the employee record from the database
        $employee = Employee::find($this->employeeIdToDeactivate);

        if (!$employee) {
            // If the employee is not found, just close and clean UI state
            $this->resetDeactivateState();

            return;
        }

        // eHealth requires: end_date >= start_date.
        $startDateStr = $employee->start_date; // 'Y-m-d' string from DB
        $endDateStr = \Illuminate\Support\Carbon::now('Europe/Kiev')->format('Y-m-d');

        // If 'today' in Kiev is lexicographically earlier than 'start_date', use 'start_date' as the dismissal date
        if ($startDateStr && $endDateStr < $startDateStr) {
            $formattedEndDate = $startDateStr;
        } else {
            $formattedEndDate = $endDateStr;
        }

        try {
            // 2. eHealth API Call using formatted string
            $response = EHealth::employee()->deactivate($employee->uuid, $formattedEndDate);

            if (!empty($response)) {
                // 3. Updates in the local database
                $employee->update([
                    'status' => Status::STOPPED->value,
                    'end_date' => $formattedEndDate,
                ]);

                // 4. Safe User Cleanup: Remove a role from a user (if binding exists)
                // This handles cases where email might be 'N/A' or user doesn't exist locally
                $users = $employee->party->users;

                foreach ($users as $user) {
                    $roleToRemove = $employee->employee_type;

                    if ($user->hasRole($roleToRemove)) {
                        $user->removeRole($roleToRemove);
                    }
                }

                $this->dispatch('flashMessage', ['message' => __('employees.dismissalSuccess'), 'type' => 'success']);
            } else {
                $this->dispatch(
                    'flashMessage',
                    ['message' => __('employees.dismissalEhealthError'), 'type' => 'error']
                );
            }
        } catch (\Exception $e) {
            // Log the error for debugging purposes
            Log::error('Employee deactivation failed', [
                'employee_id' => $employee->id,
                'error' => $e->getMessage()
            ]);

            $this->dispatch(
                'flashMessage',
                ['message' => __('employees.requestError', ['error' => $e->getMessage()]), 'type' => 'error']
            );
        }

        // 5. Reset UI State to ensure modal is clean for the next action
        $this->resetDeactivateState();
    }

    // Auxiliary method to avoid duplicating the reset code (can be pasted directly into deactivate if you want)
    private function resetDeactivateState(): void
    {
        $this->showDeactivateModal = false;
        $this->employeeIdToDeactivate = null;
        $this->employeeToDeactivateName = null;
        $this->isDoctorToDeactivate = false;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws Throwable
     * @throws NotFoundExceptionInterface
     */
    public function sync(): void
    {
        if ($this->isSyncProcessing()) {
            Session::flash('error', 'Синхронізація вже запущена. Будь ласка, зачекайте її завершення.');

            return;
        }

        $user = Auth::user();
        $token = session()->get(config('ehealth.api.oauth.bearer_token'));

        // Try to resume previous sync if it was paused or failed
        if ($this->syncStatus === JobStatus::PAUSED->value || $this->syncStatus === JobStatus::FAILED->value) {

            $this->resumeSynchronization($user, $token);

            $user->notify(new SyncNotification('employee', 'resumed'));

            return;
        }

        $user->notify(new SyncNotification('employee', 'started'));

        $this->dispatch('flashMessage', [
            'message' => __('employees.sync.started'),
            'type' => 'success',
        ]);

        try {
            $response = EHealth::employee()->getMany(['legal_entity_id' => legalEntity()->uuid]);
        } catch (ConnectionException $e) {
            Log::error('Employee sync failed: No connection to E-Health.', ['error' => $e->getMessage()]);
            $this->dispatch(
                'flashMessage',
                ['message' => __('errors.ehealth.messages.no_connection'), 'type' => 'error']
            );

            return;
        } catch (EHealthResponseException $e) {
            Log::error(
                'Employee sync failed: E-Health API error.',
                ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
            );
            $this->dispatch(
                'flashMessage',
                ['message' => __('employees.requestError', ['error' => $e->getMessage()]), 'type' => 'error']
            );

            return;
        } catch (\Exception $e) {
            Log::error(
                'Employee sync failed: An unexpected error occurred during initiation.',
                ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
            );
            $this->dispatch('flashMessage', ['message' => __('employees.sync.error'), 'type' => 'error']);

            return;
        }

        $employees = $response->validate();
        data_forget($employees, '*.party');
        data_forget($employees, '*.doctor');
        data_fill($employees, '*.legal_entity_id', legalEntity()->id);
        data_fill($employees, '*.sync_status', JobStatus::PARTIAL->value);

        Employee::upsert($employees, uniqueBy: ['uuid']);

        if ($response->isNotLast()) {
            Bus::batch([
                new EmployeeSync(
                    legalEntity: $this->legalEntity,
                    page: 2,
                    nextEntity: null
                ),
            ])
                ->withOption('legal_entity_id', $this->legalEntity->id)
                ->withOption('token', Crypt::encryptString($token))
                ->withOption('user', $user)
                ->then(function (Batch $batch) use ($user) {
                    app(PermissionRegistrar::class)->forgetCachedPermissions();
                    $message = __('employees.sync.completed_successfully', [
                        'processed' => $batch->processedJobs,
                        'total' => $batch->totalJobs,
                    ]);
                    $user->notify(new EmployeeSyncCompleted($message, 'success'));
                })->catch(callback: function (Batch $batch, \Throwable $e) use ($user) {
                    $message = __('employees.sync.failed');
                    Log::error('Employee sync batch failed.', ['batch_id' => $batch->id, 'exception' => $e]);
                    $user->notify(new EmployeeSyncCompleted($message, 'error'));
                })
                ->onQueue('sync')
                ->name(self::BATCH_NAME)
                ->dispatch();
        } else {
            Bus::batch($this->getEmployeeDetailsStartJob($this->legalEntity, null))
                ->withOption('legal_entity_id', $this->legalEntity->id)
                ->withOption('token', Crypt::encryptString($token))
                ->withOption('user', $user)
                ->then(function (Batch $batch) use ($user) {
                    $message = __('employees.sync.completed_successfully', [
                        'processed' => $batch->processedJobs,
                        'total' => $batch->totalJobs,
                    ]);
                    $user->notify(new EmployeeSyncCompleted($message, 'success'));
                })->catch(callback: function (Batch $batch, \Throwable $e) use ($user) {
                    $message = __('employees.sync.failed');
                    Log::error('Employee sync batch failed.', ['batch_id' => $batch->id, 'exception' => $e]);
                    $user->notify(new EmployeeSyncCompleted($message, 'error'));
                })
                ->onQueue('sync')
                ->name(self::DEPENDENT_BATCH_NAME)
                ->dispatch();
        }

        legalEntity()?->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_EMPLOYEE);

        $this->dispatch('flashMessage', [
            'message' => "Сторінка 1 оброблена. Решта завантажується фоново.",
            'type' => 'success'
        ]);
    }

    /**
     * Resume the synchronization process for a user with the provided token.
     *
     * This method handles the continuation of a previously initiated synchronization
     * operation for a specific user using an authentication or session token.
     *
     * @param User $user The user instance for whom synchronization should be resumed
     * @param string $token The authentication or session token used to resume the sync process
     * @return void
     */
    protected function resumeSynchronization(User $user, string $token): void
    {
        $encryptedToken = Crypt::encryptString($token);

        // Find all the EmployeeRequests failed batches for this legal entity and retry them
        $failedBatches = $this->findFailedBatchesByLegalEntity(legalEntity()->id, 'ASC');

        foreach ($failedBatches as $batch) {
            if ($batch->name === self::BATCH_NAME || $batch->name === self::DEPENDENT_BATCH_NAME) {
                Log::info('Resuming Employee sync batch: ' . $batch->name . ' id: ' . $batch->id);

                legalEntity()?->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_EMPLOYEE);

                $this->restartBatch($batch, $user, $encryptedToken, legalEntity());

                $this->dispatch('flashMessage', [
                    'message' => __('Відновлення попередньої синхронізації розпочато'),
                    'type' => 'success'
                ]);

                break;
            }
        }
    }

    /**
     * Synchronize a specific employee.
     * Uses the parent syncEmployeeData method.
     */
    public function syncOne(int $employeeId): void
    {
        $employee = Employee::with(['party'])->find($employeeId);

        if (!$employee) {
            $this->dispatch('flashMessage', ['message' => 'Співробітника не знайдено', 'type' => 'error']);

            return;
        }

        // Call the core logic from EmployeeComponent
        $success = $this->syncEmployeeData($employee);

        if ($success) {
            $this->refreshTrigger++;
        }
    }

    public function confirmRequestDeletion(int $id): void
    {
        $request = EmployeeRequest::with('party')->find($id);

        if (!$request) {
            return;
        }

        $this->requestToDeleteId = $id;
        $this->deleteRequestName = $request->party?->fullName ?? __('employees.modals.delete_draft.default_name');

        $this->showDeleteModal = true;
    }

    /**
     * This method is triggered by the "Delete" button in the modal window.
     * It retrieves the stored ID and executes the deletion logic.
     */
    public function deleteRequest(): void
    {
        if ($this->requestToDeleteId) {
            $request = EmployeeRequest::with('revision')->find($this->requestToDeleteId);

            // Make sure the request exists and it's a draft (without UUID)
            if ($request && !$request->uuid) {

                // 1. Delete the related revision if it exists
                if ($request->revision) {
                    // Since Revision model uses SoftDeletes, standard delete() only hides the record.
                    // We use forceDelete() to physically remove the draft data from the database.
                    $request->revision->forceDelete();
                }

                // 2. Delete the request itself
                $request->delete();

                $this->dispatch(
                    'flashMessage',
                    ['message' => __('employees.draft.delete_success'), 'type' => 'success']
                );
            }

            // Close the modal and clear the ID
            $this->showDeleteModal = false;
            $this->requestToDeleteId = null;
        }
    }

    /**
     * Renders the component view.
     *
     * @throws JsonException
     */
    public function render(): object
    {
        $filterKey = md5(
            $this->search .
            implode(',', $this->status) .
            json_encode($this->filter, JSON_THROW_ON_ERROR) .
            $this->getPage() .
            $this->refreshTrigger
        );

        return view('livewire.employee.employee-index', [
            'parties' => $this->parties,
            'dictionaries' => $this->dictionaries,
            'filterKey' => $filterKey,
        ]);
    }
}
