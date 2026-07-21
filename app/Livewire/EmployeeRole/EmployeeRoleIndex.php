<?php

declare(strict_types=1);

namespace App\Livewire\EmployeeRole;

use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use Throwable;
use App\Models\User;
use Livewire\Component;
use App\Enums\JobStatus;
use App\Traits\FormTrait;
use Illuminate\Bus\Batch;
use Illuminate\View\View;
use App\Models\LegalEntity;
use App\Models\EmployeeRole;
use App\Models\HealthcareService;
use App\Models\Employee\Employee;
use Illuminate\Database\Eloquent\Builder;
use Livewire\WithPagination;
use App\Jobs\EmployeeRoleSync;
use App\Repositories\Repository;
use App\Classes\eHealth\EHealth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Session;
use App\Traits\BatchLegalEntityQueries;
use App\Notifications\SyncNotification;
use Illuminate\Pagination\LengthAwarePaginator;

class EmployeeRoleIndex extends Component
{
    use BatchLegalEntityQueries;
    use WithPagination;
    use FormTrait;

    protected const string BATCH_NAME = 'EmployeeRoleSync';

    /**
     * Selected employee (used as employee_id filter).
     *
     * @var string|null
     */
    #[Url(as: 'employee')]
    public ?string $employeeIdFilter = null;

    /**
     * Selected healthcare service (used as healthcare_service_id filter).
     *
     * @var string|null
     */
    #[Url(as: 'healthcare_service')]
    public ?string $healthcareServiceIdFilter = null;

    /**
     * Statuses by default.
     *
     * @var array|string[]
     */
    #[Url(as: 'status')]
    public array $statusFilter = ['ACTIVE'];

    /**
     * Employees that have roles in the current legal entity (filter options).
     *
     * @var array
     */
    public array $employees = [];

    /**
     * Healthcare services that have roles in the current legal entity (filter options).
     *
     * @var array
     */
    public array $healthcareServices = [];

    protected array $dictionaryNames = ['SPECIALITY_TYPE', 'PROVIDING_CONDITION'];

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
     * Get the synchronization status of the employee roles.
     *
     * @return string The current sync status
     */
    protected function getSyncStatus(): string
    {
        return legalEntity()->getEntityStatus(LegalEntity::ENTITY_EMPLOYEE_ROLE) ?? '';
    }

    /**
     * Determine if a synchronization process is currently running.
     *
     * @return bool True if a sync process is actively processing, false otherwise.
     */
    protected function isSyncProcessing(): bool
    {
        // Get the sync status for whole Legal Entity
        $legalEntitySyncStatus = legalEntity()->getEntityStatus();

        // Set the sync status only for Employee Role
        $this->syncStatus = $this->getSyncStatus();

        // Determine if either the Legal Entity's sync is in progress
        $legalEntitySync = $this->isEntitySyncIsInProgress($legalEntitySyncStatus, true);

        // Determine if either the Employee Role's sync is in progress
        $employeeRoleSync = $this->isEntitySyncIsInProgress($this->syncStatus);

        // Return true if either sync is in progress
        return $legalEntitySync || $employeeRoleSync;
    }

    public function boot(): void
    {
        // This will ensure that the 'isSync' computed property is not cached between requests
        unset($this->isSync);
    }

    public function mount(LegalEntity $legalEntity): void
    {
        $this->getDictionary();

        $roleKeys = EmployeeRole::whereHas(
            'healthcareService',
            static fn (Builder $query) => $query->whereLegalEntityId($legalEntity->id)
        )
            ->get(['employee_id', 'healthcare_service_id']);

        $this->employees = Employee::whereIn('id', $roleKeys->pluck('employee_id')->unique())
            ->with([
                'party:id,first_name,last_name,second_name',
                'specialities:specialityable_id,specialityable_type,speciality,speciality_officio'
            ])
            ->get(['id', 'uuid', 'party_id'])
            ->map(function (Employee $employee): array {
                $officioSpeciality = $employee->specialities->firstWhere('specialityOfficio', true)?->speciality;
                $specialitySuffix = $officioSpeciality
                    ? ' - ' . ($this->dictionaries['SPECIALITY_TYPE'][$officioSpeciality] ?? $officioSpeciality)
                    : '';

                return [
                    'uuid' => $employee->uuid,
                    'label' => $employee->fullName . $specialitySuffix
                ];
            })
            ->toArray();

        $this->healthcareServices = HealthcareService::whereIn('id', $roleKeys->pluck('healthcare_service_id')->unique())
            ->with('division:id,name')
            ->get(['id', 'uuid', 'speciality_type', 'division_id'])
            ->map(function (HealthcareService $healthcareService): array {
                $specialityPrefix = $healthcareService->specialityType
                    ? ($this->dictionaries['SPECIALITY_TYPE'][$healthcareService->specialityType] ?? '') . ' - '
                    : '';

                return [
                    'uuid' => $healthcareService->uuid,
                    'label' => $specialityPrefix . $healthcareService->division->name
                ];
            })
            ->toArray();

        $this->syncStatus = $this->getSyncStatus();
    }

    public function applyFilters(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['employeeIdFilter', 'healthcareServiceIdFilter', 'statusFilter']);

        $this->resetPage();
    }

    public function updatedEmployeeIdFilter(): void
    {
        $this->resetPage();
    }

    public function updatedHealthcareServiceIdFilter(): void
    {
        $this->resetPage();
    }

    public function deactivate(EmployeeRole $employeeRole): void
    {
        $employeeRole->loadMissing('healthcareService:id,legal_entity_id');

        if (Auth::user()->cannot('deactivate', $employeeRole)) {
            Session::flash('error', __('employee-roles.policy.deactivate'));

            return;
        }

        try {
            $response = EHealth::employeeRole()->deactivate($employeeRole->uuid);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle("Error when deactivating $employeeRole->uuid employee role");

            return;
        }

        try {
            Repository::employeeRole()->update($employeeRole, $response->validate());

            $this->dispatch('deactivate-success');
            Session::flash('success', __('employee-roles.success.deactivated'));
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, "Failed to deactivate $employeeRole->uuid employee role");

            return;
        }
    }

    public function sync(): void
    {
        if (Auth::user()->cannot('viewAny', EmployeeRole::class)) {
            Session::flash('error', __('employee-roles.policy.sync'));

            return;
        }

        if ($this->isSyncProcessing()) {
            Session::flash('error', __('forms.errors.sync_already_running'));

            return;
        }

        $user = Auth::user();
        $token = Session::get(config('ehealth.api.oauth.bearer_token'));

        if ($this->syncStatus === JobStatus::PAUSED->value || $this->syncStatus === JobStatus::FAILED->value) {
            $this->resumeSynchronization($user, $token);

            Session::flash('success', __('forms.success.sync_resumed'));

            $user->notify(new SyncNotification('employee_role', 'resumed'));

            return;
        }

        try {
            $response = EHealth::employeeRole()->getMany();
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error connecting when getting a employee role list');

            return;
        }

        try {
            $validated = $response->validate();

            Repository::employeeRole()->sync($response->map($validated));
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors(
                $exception,
                'Error while synchronizing employee roles with eHealth: ',
                __('employee-roles.errors.sync_failed')
            );

            return;
        }

        // If there are more pages, dispatch a job to handle the rest
        if ($response->isNotLast()) {
            try {
                Auth::user()->notify(new SyncNotification('employee_role', 'started'));
                $this->dispatchNextSyncJobs($user, $token);
                Session::flash('success', __('forms.success.sync_started'));
            } catch (Throwable $exception) {
                Log::error('Failed to dispatch EmployeeRole batch', ['exception' => $exception]);

                Auth::user()->notify(new SyncNotification('employee_role', 'failed'));
            }
        } else {
            legalEntity()->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_EMPLOYEE_ROLE);

            Session::flash('success', __('forms.success.updated'));
        }
    }

    #[Computed]
    public function employeeRoles(): LengthAwarePaginator
    {
        return EmployeeRole::forLegalEntity()
            ->filterByEmployeeId($this->employeeIdFilter)
            ->filterByHealthcareServiceId($this->healthcareServiceIdFilter)
            ->filterByStatus($this->statusFilter)
            ->paginate(config('pagination.per_page'));
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

        // Find all the EmployeeRoles failed batches for this legal entity and retry them
        $failedBatches = $this->findFailedBatchesByLegalEntity(legalEntity()->id, 'ASC');

        foreach ($failedBatches as $batch) {
            if ($batch->name === self::BATCH_NAME) {
                Log::info('Resuming Employee sync batch: ' . $batch->name . ' id: ' . $batch->id);

                legalEntity()->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_EMPLOYEE_ROLE);

                $this->restartBatch($batch, $user, $encryptedToken, legalEntity());

                break;
            }
        }
    }

    /**
     * Dispatch next sync jobs for remaining pages.
     *
     * @param  User  $user
     * @param  string  $token
     * @return void
     * @throws Throwable
     */
    protected function dispatchNextSyncJobs(User $user, string $token): void
    {
        Bus::batch([new EmployeeRoleSync(legalEntity(), page: 2)])
            ->withOption('legal_entity_id', legalEntity()->id)
            ->withOption('token', Crypt::encryptString($token))
            ->withOption('user', $user)
            ->then(fn () => $user->notify(new SyncNotification('employee_role', 'completed')))
            ->catch(function (Batch $batch, Throwable $exception) use ($user) {
                Log::error('Employee Role sync batch failed.', [
                    'batch_id' => $batch->id,
                    'exception' => $exception
                ]);

                $user->notify(new SyncNotification('employee_role', 'failed'));
            })
            ->onQueue('sync')
            ->name(self::BATCH_NAME)
            ->dispatch();

        legalEntity()->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_EMPLOYEE_ROLE);
    }

    public function render(): View
    {
        return view('livewire.employee-role.employee-role-index', ['employeeRoles' => $this->employeeRoles]);
    }
}
