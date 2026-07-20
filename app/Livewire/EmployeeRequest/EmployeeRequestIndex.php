<?php

declare(strict_types=1);

namespace App\Livewire\EmployeeRequest;

use App\Exceptions\EHealth\EHealthConnectionException;
use Auth;
use App\Models\User;
use App\Enums\JobStatus;
use Illuminate\Bus\Batch;
use App\Models\LegalEntity;
use Livewire\WithPagination;
use App\Classes\eHealth\EHealth;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;
use App\Enums\Employee\RequestStatus;
use App\Jobs\EmployeeRequestsSyncAll;
use Illuminate\Support\Facades\Session;
use App\Traits\BatchLegalEntityQueries;
use App\Models\Employee\EmployeeRequest;
use App\Livewire\Employee\EmployeeComponent;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Notifications\EmployeeRequestSyncCompleted;
use App\Services\Employee\EmployeeRequestProcessor;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Notifications\SyncNotification;

class EmployeeRequestIndex extends EmployeeComponent
{
    use WithPagination;
    use BatchLegalEntityQueries;

    protected const string BATCH_NAME = 'EmployeeRequestsSyncAll';
    protected const string DEPENDENT_BATCH_NAME = 'EmployeeRequestDetailsSync';

    public string $search = '';
    public string $status = '';

    /**
     * Represents the current synchronization status for the component.
     *
     * @var string
     */
    public string $syncStatus = '';

    private LegalEntity $legalEntity;

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
        return legalEntity()?->getEntityStatus(LegalEntity::ENTITY_EMPLOYEE_REQUEST) ?? '';
    }

    /**
     * Determine if a synchronization process is currently running.
     *
     * @return bool True if a sync process is actively processing, false otherwise.
     */
    protected function isSyncProcessing(): bool
    {
        // Set the sync status only for EmployeeRequest
        $this->syncStatus = $this->getSyncStatus();

        // Determine if either the EmployeeRequest's sync is in progress
        $employeeRequestSync = $this->isEntitySyncIsInProgress($this->syncStatus);

        // Return true if either sync is in progress
        return $employeeRequestSync;
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

        $this->loadDictionaries();

        $this->syncStatus = $this->getSyncStatus();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function syncOne(int $requestId, EmployeeRequestProcessor $processor): void
    {
        Log::info("[SyncOne] Started for Request ID: {$requestId}");

        $localRequest = EmployeeRequest::with(['revision', 'employee', 'party', 'division'])
            ->where('legal_entity_id', legalEntity()->id)
            ->find($requestId);

        if (!$localRequest) {
            $this->dispatch('flashMessage', [
                'message' => __('employees.sync.employee_request_not_found'),
                'type' => 'error',
            ]);

            return;
        }

        if (Auth::user()->cannot('viewAny', EmployeeRequest::class)) {
            $this->dispatch('flashMessage', [
                'message' => __('employees.sync.employee_request_forbidden'),
                'type' => 'error',
            ]);

            return;
        }

        if (!$localRequest->isPendingEhealth()) {
            $this->dispatch('flashMessage', [
                'message' => __('employees.sync.employee_request_not_pending'),
                'type' => 'warning',
            ]);

            return;
        }

        try {
            $result = $processor->syncSinglePendingRequest($localRequest, legalEntity());

            $type = match ($result['outcome']) {
                EmployeeRequestProcessor::OUTCOME_APPROVED => 'success',
                EmployeeRequestProcessor::OUTCOME_PENDING,
                EmployeeRequestProcessor::OUTCOME_REJECTED,
                EmployeeRequestProcessor::OUTCOME_EXPIRED => 'info',
                default => 'error',
            };

            $this->dispatch('flashMessage', [
                'message' => $result['message'],
                'type' => $type,
            ]);

            unset($this->requests);
        } catch (\Exception $e) {
            Log::error('[SyncOne] ERROR: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $this->dispatch('flashMessage', [
                'message' => __('employees.sync.employee_request_failed', ['error' => $e->getMessage()]),
                'type' => 'error',
            ]);
        }
    }

    /**
     * Mass synchronization.
     * Process 1st page synchronously, dispatch Job for the rest.
     */
    public function sync(EmployeeRequestProcessor $processor): void
    {
        if (Auth::user()->cannot('viewAny', EmployeeRequest::class)) {
            session()->flash('error', __('У вас немає дозволу на синхронізацію заявок'));

            return;
        }

        if ($this->isSyncProcessing()) {
            Session::flash('error', __('forms.errors.sync_already_running'));

            return;
        }

        $user = Auth::user();
        $token = session()->get(config('ehealth.api.oauth.bearer_token'));

        // Try to resume previous sync if it was paused or failed
        if ($this->syncStatus === JobStatus::PAUSED->value || $this->syncStatus === JobStatus::FAILED->value) {

            $this->resumeSynchronization($user, $token);

            $user->notify(new SyncNotification('employee_request', 'resumed'));

            return;
        }

        // Notify start
        $this->dispatch('flashMessage', [
            'message' => __('employees.sync.started'),
            'type' => 'success'
        ]);

        try {
            // 1. Synchronous request for Page 1
            $response = EHealth::employeeRequest()->getMany(['edrpou' => legalEntity()->edrpou]); // Page 1 is default
        } catch (EHealthConnectionException $e) {
            Log::error('Employee Request sync failed: No connection.', ['error' => $e->getMessage()]);
            $this->dispatch('flashMessage', ['message' => 'Немає зв\'язку з ЕСОЗ', 'type' => 'error']);

            return;
        } catch (EHealthResponseException $e) {
            Log::error('Employee Request sync failed: API error.', ['error' => $e->getMessage()]);
            $this->dispatch('flashMessage', ['message' => 'Помилка API ЕСОЗ: ' . $e->getMessage(), 'type' => 'error']);

            return;
        } catch (\Exception $e) {
            Log::error('Employee Request sync failed: Unexpected error.', ['error' => $e->getMessage()]);
            $this->dispatch('flashMessage', ['message' => 'Виникла помилка при ініціалізації синхронізації', 'type' => 'error']);

            return;
        }

        // 2. Process Page 1 immediately
        $validatedData = $response->validate();
        $processor->processBatch($validatedData, legalEntity());
        // Store result for dispatched jobs
        $batch = null;

        // 3. Check if there are more pages
        if ($response->isNotLast()) {
            $batch = Bus::batch([
                new EmployeeRequestsSyncAll(
                    legalEntity: $this->legalEntity,
                    page: 2,
                    nextEntity: null
                ),
            ])
                ->withOption('legal_entity_id', $this->legalEntity->id)
                ->withOption('token', Crypt::encryptString($token))
                ->withOption('user', $user)
                ->then(function (Batch $batch) use ($user) {
                    // app(PermissionRegistrar::class)->forgetCachedPermissions();
                    $message = __('employees.sync.completed_successfully', [
                        'processed' => $batch->processedJobs,
                        'total' => $batch->totalJobs,
                    ]);
                    $user->notify(new EmployeeRequestSyncCompleted($message, 'success'));
                })->catch(callback: function (Batch $batch, \Throwable $e) use ($user) {
                    $message = __('employees.sync.failed');
                    Log::error('EmployeeRequest sync batch failed.', ['batch_id' => $batch->id, 'exception' => $e]);
                    $user->notify(new EmployeeRequestSyncCompleted($message, 'error'));
                })
                ->onQueue('sync')
                ->name(self::BATCH_NAME)
                ->dispatch();
        } else {
            $batch = Bus::batch($this->getEmployeeRequestDetailsStartJob($this->legalEntity, null))
                ->withOption('legal_entity_id', $this->legalEntity->id)
                ->withOption('token', Crypt::encryptString($token))
                ->withOption('user', $user)
                ->then(function (Batch $batch) use ($user) {
                    $message = __('employees.sync.completed_successfully', [
                        'processed' => $batch->processedJobs,
                        'total' => $batch->totalJobs,
                    ]);
                    $user->notify(new EmployeeRequestSyncCompleted($message, 'success'));
                })->catch(callback: function (Batch $batch, \Throwable $e) use ($user) {
                    $message = __('employees.sync.failed');
                    Log::error('Employee sync batch failed.', ['batch_id' => $batch->id, 'exception' => $e]);
                    $user->notify(new EmployeeRequestSyncCompleted($message, 'error'));
                })
                ->onQueue('sync')
                ->name(self::DEPENDENT_BATCH_NAME)
                ->dispatch();
        }

        if ($batch?->totalJobs > 0) {
            legalEntity()?->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_EMPLOYEE_REQUEST);

            $message = __('Сторінка 1 оброблена. Решта завантажується фоново.');

            // Force refresh of the table
            $this->resetPage();
        } else {
            $message = __('Немає нових заявок для синхронізації');
        }

        $this->dispatch('flashMessage', [
            'message' => $message,
            'type' => 'success'
        ]);
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

        // Define the daily sync batch name (created by EmployeeRequestsSyncAll listener)
        $dailySyncName = 'Full Employee Requests Sync for LE: ' . legalEntity()->id;

        // Find all the EmployeeRequests failed batches for this legal entity and retry them
        $failedBatches = $this->findFailedBatchesByLegalEntity(legalEntity()->id, 'ASC');

        foreach ($failedBatches as $batch) {
            if ($batch->name === self::BATCH_NAME || $batch->name === self::DEPENDENT_BATCH_NAME || $batch->name === $dailySyncName) {
                Log::info('Resuming EmployeeRequest sync batch: ' . $batch->name . ' id: ' . $batch->id);

                legalEntity()?->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_EMPLOYEE_REQUEST);

                $this->restartBatch($batch, $user, $encryptedToken, legalEntity());

                $this->dispatch('flashMessage', [
                    'message' => __('forms.success.sync_resumed'),
                    'type' => 'success'
                ]);

                break;
            }
        }
    }

    /**
     * Fetches the paginated list of all requests.
     * English annotations used as requested.
     */
    #[Computed]
    public function requests(): LengthAwarePaginator
    {
        return EmployeeRequest::query()
            ->with(['party', 'division', 'revision'])
            ->where('legal_entity_id', legalEntity()->id)
            ->whereHas('revision')
            ->when($this->search, fn ($query) => $query->searchByFullName($this->search))
            ->when($this->status, function ($query) {
                $query->where('status', $this->status);
            })
            ->orderByDesc('created_at')
            ->paginate(20);
    }

    public function render(): object
    {
        return view('livewire.employee-request.employee-request-index', [
            'requests' => $this->requests,
            'statuses' => RequestStatus::filterChoices(),
            'dictionaries' => $this->dictionaries,
        ]);
    }
}
