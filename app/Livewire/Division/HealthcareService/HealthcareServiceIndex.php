<?php

declare(strict_types=1);

namespace App\Livewire\Division\HealthcareService;

use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthException;
use Exception;
use Throwable;
use App\Classes\eHealth\EHealth;
use App\Enums\JobStatus;
use App\Enums\Status;
use App\Jobs\HealthcareServiceSync;
use App\Models\Division;
use App\Models\HealthcareService;
use App\Models\LegalEntity;
use App\Models\User;
use App\Notifications\SyncNotification;
use App\Repositories\Repository;
use App\Traits\BatchLegalEntityQueries;
use App\Traits\FormTrait;
use Illuminate\Bus\Batch;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class HealthcareServiceIndex extends Component
{
    use BatchLegalEntityQueries;
    use WithPagination;
    use FormTrait;

    protected const string BATCH_NAME = 'HealthcareServiceSync';

    public ?int $divisionId = null;

    public ?string $divisionUuid = null;

    public ?Status $divisionStatus;

    /**
     * Represents the current synchronization status for the component.
     *
     * @var string
     */
    public string $syncStatus = '';

    #[Url(as: 'type')]
    public ?string $typeFilter = null;

    /**
     * List of divisions in the current legal entity.
     *
     * @var array
     */
    public array $divisions;

    #[Url(as: 'division')]
    public ?int $divisionFilter = null;

    #[Url(as: 'status')]
    public array $status = [];

    public bool $isFiltersApplied = false;

    public array $dictionaryNames = ['DIVISION_TYPE', 'SPECIALITY_TYPE', 'PROVIDING_CONDITION'];

    #[Computed]
    public function isSync(): bool
    {
        return $this->isSyncProcessing();
    }

    /**
     * Get the current synchronization status
     *
     * @return string The synchronization status
     */
    protected function getSyncStatus(): string
    {
        return legalEntity()->getEntityStatus(LegalEntity::ENTITY_HEALTHCARE_SERVICE) ?? '';
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

        // Get the sync status only for Healthcare Service
        $this->syncStatus = $this->getSyncStatus();

        // Get the sync status only for Division
        $divisionSyncStatus = legalEntity()?->getEntityStatus(LegalEntity::ENTITY_DIVISION);

        // Determine if either the Legal Entity's sync is in progress
        $legalEntitySync = $this->isEntitySyncIsInProgress($legalEntitySyncStatus, true);

        // Determine if either the Division's sync is in progress
        $divisionSync = $divisionSyncStatus !== JobStatus::COMPLETED->value;

        // Determine if either the Healthcare Service's sync is in progress
        $hcsSync = $this->isEntitySyncIsInProgress($this->syncStatus);

        // Return true if either sync is in progress
        return $legalEntitySync || $divisionSync || $hcsSync;
    }

    public function mount(LegalEntity $legalEntity, Division $division): void
    {
        if ($this->divisionFilter) {
            $this->isFiltersApplied = true;
        }

        $this->divisionUuid = $division->uuid;
        $this->divisions = $legalEntity->divisions()->get(['id', 'name', 'status'])->toArray();

        $this->getDictionary();

        // Get the sync status only for Healthcare Service
        $this->syncStatus = $this->getSyncStatus();
    }

    public function search(): void
    {
        $this->resetPage();
        $this->isFiltersApplied = true;
    }

    public function resetFilters(): void
    {
        $this->divisionFilter = null;
        $this->typeFilter = null;
        $this->divisionId = null;
        $this->status = [];
    }

    public function activate(HealthcareService $healthcareService): void
    {
        if (Auth::user()->cannot('activate', $healthcareService)) {
            Session::flash('error', __('healthcare-services.policy.activate'));

            return;
        }

        try {
            $response = EHealth::healthcareService()->activate($healthcareService->uuid);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle("Error when activate $healthcareService->uuid a healthcare service");

            return;
        }

        try {
            Repository::healthcareService()->updateStatus($healthcareService->uuid, $response->validate());

            Session::flash('success', __('healthcare-services.success.activated'));
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, "Failed to activate $healthcareService->uuid healthcare service");

            return;
        }
    }

    public function deactivate(HealthcareService $healthcareService): void
    {
        if (Auth::user()->cannot('deactivate', $healthcareService)) {
            Session::flash('error', __('healthcare-services.policy.deactivate'));

            return;
        }

        try {
            $response = EHealth::healthcareService()->deactivate($healthcareService->uuid);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle("Error when deactivating $healthcareService->uuid a healthcare service");

            return;
        }

        try {
            Repository::healthcareService()->updateStatus($healthcareService->uuid, $response->validate());

            Session::flash('success', __('healthcare-services.success.deactivated'));
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors($exception, "Failed to deactivate $healthcareService->uuid healthcare service");

            return;
        }
    }

    public function delete(HealthcareService $healthcareService): void
    {
        if (Auth::user()->cannot('delete', $healthcareService)) {
            Session::flash('error', __('healthcare-services.policy.delete'));

            return;
        }

        try {
            HealthcareService::destroy($healthcareService->id);

            Session::flash('success', __('healthcare-services.success.draft_deleted'));
        } catch (Exception $exception) {
            $this->handleDatabaseErrors($exception, 'Error while deleting healthcare service: ');

            return;
        }
    }

    public function sync(): void
    {
        if ($this->isSyncProcessing()) {
            Session::flash('error', __('forms.errors.sync_already_running'));

            return;
        }

        if (Auth::user()->cannot('sync', HealthcareService::class)) {
            Session::flash('error', __('healthcare-services.policy.sync'));

            return;
        }

        $token = Session::get(config('ehealth.api.oauth.bearer_token'));
        $user = Auth::user();

        // Try to resume previous sync if it was paused or failed
        if ($this->syncStatus === JobStatus::PAUSED->value || $this->syncStatus === JobStatus::FAILED->value) {
            $this->resumeSynchronization($user, $token);

            Session::flash('success', __('forms.success.sync_resumed'));

            $user->notify(new SyncNotification('healthcare_service', 'resumed'));

            return;
        }

        try {
            $query = $this->divisionUuid ? ['division_id' => $this->divisionUuid] : [];

            $response = EHealth::healthcareService()->getMany($query);
        } catch (EHealthException|EHealthConnectionException $exception) {
            $exception->handle('Error connecting when getting a healthcare service list');

            return;
        }

        try {
            $validated = $response->validate();
            Repository::healthcareService()->sync($response->map($validated));
        } catch (Throwable $exception) {
            $this->handleDatabaseErrors(
                $exception,
                'Error while synchronizing healthcare services with eHealth: ',
                __('healthcare-services.error.sync_failed')
            );

            return;
        }

        // If there are more pages, dispatch a job to handle the rest
        if ($response->isNotLast()) {
            try {
                $user->notify(new SyncNotification('healthcare_service', 'started'));
                $this->dispatchNextSyncJobs($user, $token);
                Session::flash('success', __('healthcare-services.success.sync_started'));
            } catch (Throwable $exception) {
                Log::error('Failed to dispatch HealthcareServiceSync batch', ['exception' => $exception]);

                $user->notify(new SyncNotification('healthcare_service', 'failed'));
            }
        } else {
            legalEntity()?->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_HEALTHCARE_SERVICE);

            Session::flash('success', __('healthcare-services.success.sync_updated'));
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

        // Find all the Divisions failed batches for this legal entity and retry them
        $failedBatches = $this->findFailedBatchesByLegalEntity(legalEntity()->id, 'ASC');

        foreach ($failedBatches as $batch) {
            if ($batch->name === self::BATCH_NAME) {
                Log::info('Resuming Division sync batch: ' . $batch->name . ' id: ' . $batch->id);

                legalEntity()->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_HEALTHCARE_SERVICE);

                $this->restartBatch($batch, $user, $encryptedToken, legalEntity());

                break;
            }
        }
    }

    #[Computed]
    public function healthcareServices(): LengthAwarePaginator
    {
        $query = HealthcareService::filterByLegalEntity(legalEntity()->id);

        // Filters
        if ($this->divisionFilter) {
            $this->divisionId = $this->divisionFilter;
            $this->divisionUuid = Division::whereId($this->divisionId)->value('uuid');
            $query->whereDivisionId($this->divisionFilter);
        } else {
            $this->divisionUuid = null;
        }

        if (!empty($this->typeFilter)) {
            $query->whereSpecialityType($this->typeFilter);
        }

        if (!empty($this->status)) {
            $query->whereIn('status', $this->status);
        }

        return $query->paginate(config('pagination.per_page'));
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
        Bus::batch([new HealthcareServiceSync(legalEntity(), page: 2)])
            ->withOption('legal_entity_id', legalEntity()->id)
            ->withOption('token', Crypt::encryptString($token))
            ->withOption('user', $user)
            ->then(fn () => $user->notify(new SyncNotification('healthcare_service', 'completed')))
            ->catch(function (Batch $batch, Throwable $exception) use ($user) {
                Log::error('Healthcare Service sync batch failed.', [
                    'batch_id' => $batch->id,
                    'exception' => $exception
                ]);

                $user->notify(new SyncNotification('healthcare_service', 'failed'));
            })
            ->onQueue('sync')
            ->name(self::BATCH_NAME)
            ->dispatch();

        legalEntity()->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_HEALTHCARE_SERVICE);
    }

    public function render(): View
    {
        return view('livewire.division.healthcare-service.healthcare-service-index', [
            'healthcareServices' => $this->healthcareServices
        ]);
    }
}
