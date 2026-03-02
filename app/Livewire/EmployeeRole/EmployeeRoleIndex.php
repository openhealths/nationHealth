<?php

declare(strict_types=1);

namespace App\Livewire\EmployeeRole;

use Throwable;
use App\Models\User;
use Livewire\Component;
use App\Enums\JobStatus;
use App\Traits\FormTrait;
use Illuminate\Bus\Batch;
use Illuminate\View\View;
use App\Models\LegalEntity;
use App\Models\EmployeeRole;
use Livewire\WithPagination;
use App\Jobs\EmployeeRoleSync;
use App\Repositories\Repository;
use App\Classes\eHealth\EHealth;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Session;
use App\Traits\BatchLegalEntityQueries;
use App\Notifications\SyncNotification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;

class EmployeeRoleIndex extends Component
{
    use BatchLegalEntityQueries;
    use WithPagination;
    use FormTrait;

    protected const string BATCH_NAME = 'EmployeeRoleSync';

    /**
     * Full name of employee.
     *
     * @var string
     */
    public string $employeeSearch = '';

    /**
     * Chosen speciality type for filter.
     *
     * @var string|null
     */
    public ?string $specialityTypeFilter = null;

    /**
     * Statuses by default.
     *
     * @var array|string[]
     */
    public array $statusFilter = ['ACTIVE'];

    /**
     * List of all speciality types.
     *
     * @var array
     */
    public array $healthcareServiceSpecialityTypes;

    protected array $dictionaryNames = ['SPECIALITY_TYPE', 'PROVIDING_CONDITION'];

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
     * Get the synchronization status of the employee roles.
     *
     * @return string The current sync status
     */
    protected function getSyncStatus(): string
    {
        return legalEntity()?->getEntityStatus(LegalEntity::ENTITY_EMPLOYEE_ROLE) ?? '';
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

        $this->healthcareServiceSpecialityTypes = array_keys($this->dictionaries['SPECIALITY_TYPE']);

        $this->syncStatus = $this->getSyncStatus();
    }

    public function applyFilters(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->employeeSearch = '';
        $this->specialityTypeFilter = null;
        $this->statusFilter = ['ACTIVE', 'INACTIVE'];
    }

    public function deactivate(EmployeeRole $employeeRole): void
    {
        $employeeRole->loadMissing('healthcareService:id,legal_entity_id');

        if (Auth::user()->cannot('deactivate', $employeeRole)) {
            Session::flash('error', 'У вас немає дозволу на деактивування ролі');

            return;
        }

        try {
            $response = EHealth::employeeRole()->deactivate($employeeRole->uuid);
        } catch (ConnectionException $exception) {
            $this->logConnectionError($exception, "Error connecting when deactivating $employeeRole->uuid employee role");
            Session::flash('error', "Виникла помилка. Відсутній зв'язок із ЕСОЗ");

            return;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            $this->logEHealthException($exception, "Error when deactivating $employeeRole->uuid employee role");

            if ($exception instanceof EHealthValidationException) {
                Session::flash('error', $exception->getFormattedMessage());
            } else {
                Session::flash('error', 'Помилка від ЕСОЗ: ' . $exception->getMessage());
            }

            return;
        }

        try {
            Repository::employeeRole()->update($employeeRole->uuid, $response->validate());

            $this->dispatch('deactivate-success');
            Session::flash('success', 'Роль успішно деактивовано');
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, "Failed to deactivate $employeeRole->uuid employee role");
            Session::flash('error', 'Виникла помилка. Зверніться до адміністратора.');

            return;
        }
    }

    public function sync(): void
    {
        if (Auth::user()->cannot('viewAny', EmployeeRole::class)) {
            Session::flash('error', 'У вас немає дозволу на синхронізацію ролей співробітників');

            return;
        }

        if ($this->isSyncProcessing()) {
            Session::flash('error', 'Синхронізація вже запущена. Будь ласка, зачекайте її завершення.');

            return;
        }

        $user = Auth::user();
        $token = Session::get(config('ehealth.api.oauth.bearer_token'));

        if ($this->syncStatus === JobStatus::PAUSED->value || $this->syncStatus === JobStatus::FAILED->value) {

            $this->resumeSynchronization($user, $token);

            Session::flash('success', __('Відновлення попередньої синхронізації розпочато'));

            $user->notify(new SyncNotification('employee_role', 'resumed'));

            return;
        }

        try {
            $response = EHealth::employeeRole()->getMany();
        } catch (ConnectionException $exception) {
            $this->logConnectionError($exception, 'Error connecting when getting a employee role list');
            Session::flash('error', "Виникла помилка. Відсутній зв'язок із ЕСОЗ");

            return;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            $this->logEHealthException($exception, 'Error connecting when getting a employee role list');
            Session::flash('error', 'Виникла помилка. Зверніться до адміністратора.');

            return;
        }

        try {
            $validated = $this->normalizeDate($response->validate());

            Repository::employeeRole()->sync($response->map($validated));
        } catch (Throwable $exception) {
            Session::flash('error', 'Виникла помилка. Оновіть список співробітників і послуги та спробуйте ще раз');
            $this->logDatabaseErrors($exception, 'Error while synchronizing employee roles with eHealth: ');

            return;
        }

        // If there are more pages, dispatch a job to handle the rest
        if ($response->isNotLast()) {
            try {
                Auth::user()->notify(new SyncNotification('employee_role', 'started'));
                $this->dispatchNextSyncJobs($user, $token);
                Session::flash('success', __('Синхронізацію успішно розпочато.'));
            } catch (Throwable $exception) {
                Log::error('Failed to dispatch EmployeeRole batch', ['exception' => $exception]);

                Auth::user()->notify(new SyncNotification('employee_role', 'failed'));
            }
        } else {
            legalEntity()?->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_EMPLOYEE_ROLE);

            Session::flash('success', __('Інформацію успішно оновлено'));
        }
    }

    #[Computed]
    public function employeeRoles(): LengthAwarePaginator
    {
        return EmployeeRole::forLegalEntity()
            ->filterByEmployeeSearch($this->employeeSearch)
            ->filterBySpecialityType($this->specialityTypeFilter)
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

                legalEntity()?->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_EMPLOYEE_ROLE);

                $this->restartBatch($batch, $user, $encryptedToken, legalEntity());

                break;
            }
        }
    }

    /**
     * Dispatch next sync jobs for remaining pages.
     *
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

        legalEntity()?->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_EMPLOYEE_ROLE);
    }

    public function render(): View
    {
        return view('livewire.employee-role.employee-role-index', ['employeeRoles' => $this->employeeRoles]);
    }
}
