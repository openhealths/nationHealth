<?php

declare(strict_types=1);

namespace App\Livewire\Division\HealthcareService;

use Exception;
use Throwable;
use App\Classes\eHealth\EHealth;
use App\Enums\JobStatus;
use App\Enums\Status;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
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
use Illuminate\Http\Client\ConnectionException;
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
        return legalEntity()?->getEntityStatus(LegalEntity::ENTITY_HEALTHCARE_SERVICE) ?? '';
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

    public function boot(): void
    {
        // This will ensure that the 'isSync' computed property is not cached between requests
        unset($this->isSync);
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
    }

    public function activate(HealthcareService $healthcareService): void
    {
        if (Auth::user()->cannot('activate', $healthcareService)) {
            Session::flash('error', 'У вас немає дозволу на активування послуги');

            return;
        }

        try {
            $response = EHealth::healthcareService()->activate($healthcareService->uuid);
        } catch (ConnectionException $exception) {
            $this->logConnectionError($exception, "Error connecting when activate $healthcareService->uuid a healthcare service");
            Session::flash('error', "Виникла помилка. Відсутній зв'язок із ЕСОЗ");

            return;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            $this->logEHealthException($exception, "Error when activate $healthcareService->uuid a healthcare service");

            if ($exception instanceof EHealthValidationException) {
                Session::flash('error', $exception->getFormattedMessage());
            } else {
                Session::flash('error', 'Помилка від ЕСОЗ: ' . $exception->getMessage());
            }

            return;
        }

        try {
            Repository::healthcareService()->updateStatus($healthcareService->uuid, $response->validate());

            Session::flash('success', 'Послугу успішно активовано');
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, "Failed to activate $healthcareService->uuid healthcare service");
            Session::flash('error', 'Виникла помилка. Зверніться до адміністратора.');

            return;
        }
    }

    public function deactivate(HealthcareService $healthcareService): void
    {
        if (Auth::user()->cannot('deactivate', $healthcareService)) {
            Session::flash('error', 'У вас немає дозволу на деактивування послуги');

            return;
        }

        try {
            $response = EHealth::healthcareService()->deactivate($healthcareService->uuid);
        } catch (ConnectionException $exception) {
            $this->logConnectionError($exception, "Error connecting when deactivating $healthcareService->uuid a healthcare service");
            Session::flash('error', "Виникла помилка. Відсутній зв'язок із ЕСОЗ");

            return;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            $this->logEHealthException($exception, "Error when deactivating $healthcareService->uuid a healthcare service");

            if ($exception instanceof EHealthValidationException) {
                Session::flash('error', $exception->getFormattedMessage());
            } else {
                Session::flash('error', 'Помилка від ЕСОЗ: ' . $exception->getMessage());
            }

            return;
        }

        try {
            Repository::healthcareService()->updateStatus($healthcareService->uuid, $response->validate());

            Session::flash('success', 'Послугу успішно деактивовано');
        } catch (Throwable $exception) {
            $this->logDatabaseErrors($exception, "Failed to deactivate $healthcareService->uuid healthcare service");
            Session::flash('error', 'Виникла помилка. Зверніться до адміністратора.');

            return;
        }
    }

    public function delete(HealthcareService $healthcareService): void
    {
        if (Auth::user()->cannot('delete', $healthcareService)) {
            Session::flash('error', 'У вас немає дозволу на видалення заявки на створення послуги');

            return;
        }

        try {
            HealthcareService::destroy($healthcareService->id);

            Session::flash('success', 'Чернетку послуги успішно видалено');
        } catch (Exception $exception) {
            $this->logDatabaseErrors($exception, 'Error while deleting healthcare service: ');
            Session::flash('error', 'Виникла помилка. Зверніться до адміністратора.');

            return;
        }
    }

    public function sync(): void
    {
        if ($this->isSyncProcessing()) {
            Session::flash('error', 'Синхронізація вже запущена. Будь ласка, зачекайте її завершення.');

            return;
        }

        if (Auth::user()->cannot('sync', HealthcareService::class)) {
            Session::flash('error', 'У вас немає дозволу на синхронізацію послуг');

            return;
        }

        $token = Session::get(config('ehealth.api.oauth.bearer_token'));
        $user = Auth::user();

        // Try to resume previous sync if it was paused or failed
        if ($this->syncStatus === JobStatus::PAUSED->value || $this->syncStatus === JobStatus::FAILED->value) {

            $this->resumeSynchronization($user, $token);

            Session::flash('success', __('Відновлення попередньої синхронізації розпочато'));

            $user->notify(new SyncNotification('healthcare_service', 'resumed'));

            return;
        }

        try {
            $query = $this->divisionUuid ? ['division_id' => $this->divisionUuid] : [];

            $response = EHealth::healthcareService()->getMany($query);
        } catch (ConnectionException $exception) {
            $this->logConnectionError($exception, 'Error connecting when getting a healthcare service list');
            Session::flash('error', "Виникла помилка. Відсутній зв'язок із ЕСОЗ");

            return;
        } catch (EHealthValidationException|EHealthResponseException $exception) {
            $this->logEHealthException($exception, 'Error connecting when getting a healthcare service list');
            Session::flash('error', 'Виникла помилка. Зверніться до адміністратора.');

            return;
        }

        try {
            $validated = $response->validate();
            Repository::healthcareService()->sync($response->map($validated));
        } catch (Throwable $exception) {
            Session::flash('error', 'Виникла помилка. Оновіть список місць надання послуг та спробуйте ще раз');
            $this->logDatabaseErrors($exception, 'Error while synchronizing healthcare services with eHealth: ');

            return;
        }

        // If there are more pages, dispatch a job to handle the rest
        if ($response->isNotLast()) {
            try {
                $user->notify(new SyncNotification('healthcare_service', 'started'));
                $this->dispatchNextSyncJobs($user, $token);
                Session::flash('success', __('Синхронізацію успішно розпочато.'));
            } catch (Throwable $exception) {
                Log::error('Failed to dispatch HealthcareServiceSync batch', ['exception' => $exception]);

                $user->notify(new SyncNotification('healthcare_service', 'failed'));
            }
        } else {
            legalEntity()?->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_HEALTHCARE_SERVICE);

            Session::flash('success', __('Інформацію успішно оновлено'));
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

                legalEntity()?->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_HEALTHCARE_SERVICE);

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
        if ($this->isFiltersApplied) {
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

        legalEntity()?->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_HEALTHCARE_SERVICE);
    }

    public function render(): View
    {
        return view('livewire.division.healthcare-service.healthcare-service-index', [
            'healthcareServices' => $this->healthcareServices
        ]);
    }
}
