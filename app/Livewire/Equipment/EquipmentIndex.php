<?php

declare(strict_types=1);

namespace App\Livewire\Equipment;

use App\Classes\eHealth\EHealth;
use App\Enums\Equipment\AvailabilityStatus;
use App\Enums\Equipment\Status;
use App\Enums\JobStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Jobs\EquipmentSync;
use App\Livewire\Equipment\Traits\StatusTrait;
use App\Models\Equipment;
use App\Models\LegalEntity;
use App\Models\User;
use App\Notifications\SyncNotification;
use App\Repositories\Repository;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\FormTrait;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class EquipmentIndex extends Component
{
    use BatchLegalEntityQueries;
    use WithPagination;
    use StatusTrait;
    use FormTrait;

    protected const string BATCH_NAME = 'EquipmentSync';

    /**
     * Search by equipment name and inventory number.
     *
     * @var string
     */
    public string $searchByName = '';

    /**
     * Search by type from 'device_definition_classification_type' dictionary.
     *
     * @var string|null
     */
    public ?string $typeFilter = null;

    /**
     * List of divisions in the current legal entity.
     *
     * @var array
     */
    public array $divisions;

    /**
     * Search by division ID.
     *
     * @var int|null
     */
    public ?int $divisionFilter = null;

    /**
     * Default values for multiselect filters by statuses.
     *
     * @var array|string[]
     */
    public array $statusFilter;

    /**
     * Default values for multiselect filters by availability statuses.
     *
     * @var array|string[]
     */
    public array $availabilityStatusFilter;

    public bool $isFiltersApplied = false;

    /**
     * Represents the current synchronization status for the component.
     *
     * @var string
     */
    public string $syncStatus = '';

    public array $dictionaryNames = ['device_definition_classification_type', 'equipment_status_reasons'];

    private LegalEntity $legalEntity;

    #[Computed]
    public function isSync(): bool
    {
        return $this->isSyncProcessing();
    }

    /**
     * Get the synchronization status of the equipment entity
     *
     * @return string The current sync status
     */
    protected function getSyncStatus(): string
    {
        return legalEntity()?->getEntityStatus(LegalEntity::ENTITY_EQUIPMENT) ?? '';
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

        // Set the sync status only for Equipment
        $this->syncStatus = $this->getSyncStatus();

        // Determine if either the Legal Entity's sync is in progress
        $legalEntitySync = $this->isEntitySyncIsInProgress($legalEntitySyncStatus, true);

        // Get the sync status only for Division
        $divisionSyncStatus = legalEntity()?->getEntityStatus(LegalEntity::ENTITY_DIVISION);

        // Get the sync status only for HealthCare Service
        $healthCareServiceSyncStatus = legalEntity()?->getEntityStatus(LegalEntity::ENTITY_HEALTHCARE_SERVICE);

        // Get the sync status only for HealthCare Service
        $employeeSyncStatus = legalEntity()?->getEntityStatus(LegalEntity::ENTITY_EMPLOYEE);

        // Determine if either the Equipment's sync is in progress
        $equipmentSync = $this->isEntitySyncIsInProgress($this->syncStatus);

        // Determine if either the Division's sync is in progress
        $divisionSync = $divisionSyncStatus !== JobStatus::COMPLETED->value;

        // Determine if either the HealthCare Service's sync is in progress
        $healthCareServiceSync = $healthCareServiceSyncStatus !== JobStatus::COMPLETED->value;

        // Determine if either the Employee's sync is in progress
        $employeeSync = $employeeSyncStatus !== JobStatus::COMPLETED->value;

        // Return true if either sync is in progress
        return $equipmentSync ||
               $legalEntitySync ||
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

        $this->divisions = $legalEntity->divisions()->select(['id', 'name'])->get()->toArray();
        $this->statusFilter = Status::values();
        $this->availabilityStatusFilter = AvailabilityStatus::values();

        $this->syncStatus = $this->getSyncStatus();
    }

    public function search(): void
    {
        $this->resetPage();
        $this->isFiltersApplied = true;
    }

    public function resetFilters(): void
    {
        $this->reset();
        $this->statusFilter = Status::values();
        $this->availabilityStatusFilter = AvailabilityStatus::values();
    }

    public function sync(): void
    {
        if (Auth::user()->cannot('sync', Equipment::class)) {
            Session::flash('error', 'У вас немає дозволу на синхронізацію обладнань');

            return;
        }

        if ($this->isSyncProcessing()) {
            Session::flash('error', 'Синхронізація вже запущена. Будь ласка, зачекайте її завершення.');

            return;
        }

        $user = Auth::user();
        $token = Session::get(config('ehealth.api.oauth.bearer_token'));

        // Try to resume previous sync if it was paused or failed
        if ($this->syncStatus === JobStatus::PAUSED->value || $this->syncStatus === JobStatus::FAILED->value) {

            $this->resumeSynchronization($user, $token);

            Session::flash('success', __('Відновлення попередньої синхронізації розпочато'));

            $user->notify(new SyncNotification('equipment', 'resumed'));

            return;
        }

        try {
            $response = EHealth::equipment()->getMany();
        } catch (ConnectionException $exception) {
            $this->logConnectionError($exception, 'Error connecting when getting a equipment list');
            Session::flash('error', "Виникла помилка. Відсутній зв'язок із ЕСОЗ");

            return;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            $this->logEHealthException($exception, 'Error connecting when getting a equipment list');

            if ($exception instanceof EHealthValidationException) {
                Session::flash('error', $exception->getFormattedMessage());
            } else {
                Session::flash('error', 'Помилка від ЕСОЗ: ' . $exception->getMessage());
            }

            return;
        }

        try {
            $validated = $this->normalizeDate($response->validate());

            Repository::equipment()->sync($response->map($validated));
        } catch (Throwable $exception) {
            Session::flash('error', 'Виникла помилка. Оновіть список місць надання послуг та співробітників і спробуйте ще раз');
            $this->logDatabaseErrors($exception, 'Error while synchronizing equipments with eHealth: ');

            return;
        }

        // If there are more pages, dispatch a job to handle the rest
        if ($response->isNotLast()) {
            try {
                $user->notify(new SyncNotification('equipment', 'started'));
                $this->dispatchNextSyncJobs($user, $token);
                Session::flash('success', __('forms.success.sync_started'));
            } catch (Throwable $exception) {
                Log::error('Failed to dispatch EquipmentSync batch', ['exception' => $exception]);

                $user->notify(new SyncNotification('equipment', 'failed'));
            }
        } else {
            legalEntity()?->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_EQUIPMENT);

            Session::flash('success', __('forms.success.updated'));
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

        // Find all the Equipment's failed batches for this legal entity and retry them
        $failedBatches = $this->findFailedBatchesByLegalEntity(legalEntity()->id, 'ASC');

        foreach ($failedBatches as $batch) {
            if ($batch->name === self::BATCH_NAME) {
                Log::info('Resuming Equipment sync batch: ' . $batch->name . ' id: ' . $batch->id);

                legalEntity()?->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_EQUIPMENT);

                $this->restartBatch($batch, $user, $encryptedToken, legalEntity());

                break;
            }
        }
    }

    #[Computed]
    public function equipments(): LengthAwarePaginator
    {
        $query = Equipment::filterByLegalEntity(legalEntity()->id);

        // Filters
        if ($this->isFiltersApplied) {
            if (!empty($this->searchByName)) {
                $query->where(function (Builder $searchQuery) {
                    $searchQuery->whereHas('names', function (Builder $nameQuery) {
                        $nameQuery->where('name', 'ILIKE', "%$this->searchByName%");
                    })
                        ->orWhere('inventory_number', 'ILIKE', "%$this->searchByName%");
                });
            }

            if (!empty($this->typeFilter)) {
                $query->whereType($this->typeFilter);
            }

            if (!empty($this->divisionFilter)) {
                $query->whereHas('division', function (Builder $query) {
                    $query->where('id', $this->divisionFilter);
                });
            }

            if (!empty($this->statusFilter)) {
                $query->whereIn('status', $this->statusFilter);
            }

            if (!empty($this->availabilityStatusFilter)) {
                $query->whereIn('availability_status', $this->availabilityStatusFilter);
            }
        }

        return $query->paginate(config('pagination.per_page'));
    }

    /**
     * Dispatch next sync jobs for remaining pages.
     *
     * @return void
     * @throws Throwable
     */
    protected function dispatchNextSyncJobs(User $user, string $token): void
    {
        Bus::batch([new EquipmentSync(legalEntity(), page: 2)])
            ->withOption('legal_entity_id', legalEntity()->id)
            ->withOption('token', Crypt::encryptString($token))
            ->withOption('user', $user)
            ->then(fn () => $user->notify(new SyncNotification('equipment', 'completed')))
            ->catch(function (Batch $batch, Throwable $exception) use ($user) {
                Log::error('Equipment sync batch failed.', [
                    'batch_id' => $batch->id,
                    'exception' => $exception
                ]);

                $user->notify(new SyncNotification('equipment', 'failed'));
            })
            ->onQueue('sync')
            ->name(self::BATCH_NAME)
            ->dispatch();

        legalEntity()?->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_EQUIPMENT);
    }

    public function render(): View
    {
        return view('livewire.equipment.equipment-index', ['equipments' => $this->equipments]);
    }
}
