<?php

declare(strict_types=1);

namespace App\Livewire\Division;

use Throwable;
use Exception;
use App\Models\User;
use App\Enums\JobStatus;
use App\Models\Division;
use Illuminate\Bus\Batch;
use App\Jobs\DivisionSync;
use Illuminate\Support\Str;
use App\Models\LegalEntity;
use Livewire\WithPagination;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Session;
use App\Notifications\SyncNotification;
use App\Traits\BatchLegalEntityQueries;
use App\Livewire\Division\Trait\HasAction;
use Illuminate\Pagination\LengthAwarePaginator;
use \App\Enums\Division\Status as DivisionStatus;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;

class DivisionIndex extends DivisionComponent
{
    use BatchLegalEntityQueries;
    use WithPagination;
    use HasAction;

    protected const string BATCH_NAME = 'DivisionSync';

    /**
     * Represents the current synchronization status for the component.
     *
     * @var string
     */
    public string $syncStatus = '';

    /**
     * Search by division name.
     *
     * @var string
     */
    public string $searchByName = '';

    /**
     * Search by division uuid(s)
     *
     * @var array
     */
    public array $searchByUuid = [];

    /**
     * Default status for multiselect filter
     *
     * @var array|string[]
     */
    public array $statusFilter = [DivisionStatus::ACTIVE->value];

    /**
     * Default status for multiselect filter
     *
     * @var array|string[]
     */
    public array $typeFilter = [];

    public bool $isFiltersApplied = false;

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
        return legalEntity()?->getEntityStatus(LegalEntity::ENTITY_DIVISION) ?? '';
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
        $this->syncStatus = $this->getSyncStatus();

        // Determine if either the Legal Entity's sync is in progress
        $legalEntitySync = $this->isEntitySyncIsInProgress($legalEntitySyncStatus, true);

        // Determine if either the Division's sync is in progress
        $divisionSync = $this->isEntitySyncIsInProgress($this->syncStatus);

        // Return true if either sync is in progress
        return $legalEntitySync || $divisionSync;
    }

    public function boot(): void
    {
        // This will ensure that the 'isSync' computed property is not cached between requests
        unset($this->isSync);
    }

    public function mount(?LegalEntity $legalEntity = null): void
    {
        $this->setDictionary();

        $this->syncStatus = $this->getSyncStatus();
        $this->typeFilter = Division::getValidDivisionTypes();
    }

     public function search(): void
    {
        $this->resetPage();
        $this->isFiltersApplied = true;
    }

    public function resetFilters(): void
    {
        $this->searchByName = '';
        $this->searchByUuid = [];
        $this->typeFilter = Division::getValidDivisionTypes();
        $this->statusFilter = [DivisionStatus::ACTIVE->value];

        $this->isFiltersApplied = false;

        $this->resetPage();
    }

    #[computed]
    public function divisions(): LengthAwarePaginator
    {
        $divisions = collect();

        $divisions = legalEntity()
            ?->divisions()
            ->orderBy('uuid')
            ->get();

        if ($this->isFiltersApplied) {
            // Filter by type
            if (!empty($this->typeFilter)) {
                $divisions = $divisions->filter(
                    fn (Division $item) => \in_array($item->type, $this->typeFilter, true)
                );
            }

            // Filter by status
            if (!empty($this->statusFilter)) {
                $divisions = $divisions->filter(function (Division $item) {
                    return \in_array($item->status->value, $this->statusFilter, true);
                });
            }

            // Search name
            if (!empty($this->searchByName)) {
                $searchTerm = Str::lower(trim($this->searchByName));

                $divisions = $divisions->filter(function (Division $item) use ($searchTerm) {
                    $divisionName = Str::lower($item->name);

                    return Str::contains($divisionName, $searchTerm);
                });
            }

            // Search by division's uuid
            if (!empty($this->searchByUuid)) {

                $divisions = $divisions->filter(function (Division $item) {
                    return in_array($item->uuid, $this->searchByUuid, true);
                });
            }
        }

        // Pagination
        $perPage = config('pagination.per_page');
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $currentItems = $divisions->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $currentItems,
            $divisions->count(),
            $perPage,
            $currentPage,
            ['path' => request()->url()]
        );
    }

    /**
     * Resets the pagination when the search term is updated.
     *
     * It ensures that when a user starts searching, the pagination
     * is reset to the first page to show the most relevant results.
     *
     * @return void
     */
    public function updatingDivisionFormSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Synchronize all the Divisions with stored on the eHealths side
     *
     * @return void
     * @throws Exception|EHealthResponseException|EHealthValidationException
     */
    public function sync(): void
    {
        if (Auth::user()->cannot('viewAny', Division::class)) {
            Session::flash('error', __('divisions.policy.deny.sync'));

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

            Session::flash('success', __('forms.success.sync_resumed'));

            $user->notify(new SyncNotification('division', 'resumed'));

            return;
        }

        // Try to resume if previous batch failed or was paused
        if ($this->syncStatus === JobStatus::FAILED->value || $this->syncStatus === JobStatus::PAUSED->value) {
            $this->resumeSynchronization($user, $token);

            return;
        }

        $syncQuery = [
            'page' => 1,
            'per_page' => config('ehealth.api.max_per_page')
        ];

        try {
            $response = EHealth::division()->getMany(query: $syncQuery);

            $divisions = $response->validate();

            Repository::division()->saveDivisionsList($divisions);
        } catch (EHealthResponseException $err) {
            Log::channel('e_health_errors')->error(self::class . ':createDivision', ['error' => $err->getDetails()]);
            session()->flash('error', __('errors.ehealth.messages.server_error'));

            return;
        } catch (EHealthValidationException $err) {
            Log::channel('e_health_errors')->error(self::class . ':createDivision', ['error' => $err->getDetails()]);

            session()->flash('error', __('errors.ehealth.messages.server_error'));

            return;
        } catch (Throwable $err) {
            Log::channel('db_errors')->error(static::class . ': [syncDivisions]: ', ['error' => $err->getMessage()]);

            session()->flash('error', __('divisions.request.sync.errors.fail'));

            return;
        }

        // If there are more pages, dispatch a job to handle the rest
        if ($response->isNotLast()) {
            Bus::batch([
                new DivisionSync(
                    legalEntity: legalEntity(),
                    page: 2,
                    standalone: true, // Sync only divisions (without healthcare services)
                    nextEntity: null
                )
            ])
                ->withOption('legal_entity_id', legalEntity()->id)
                ->withOption('token', Crypt::encryptString($token))
                ->withOption('user', $user)
                ->then(function (Batch $batch) use ($user) {
                    $user->notify(new SyncNotification('division', 'complete'));
                })->catch(callback: function (Batch $batch, Throwable $e) use ($user) {
                    Log::error('Division sync batch failed.', [
                        'batch_id' => $batch->id,
                        'exception' => $e
                    ]);

                    $user->notify(new SyncNotification('division', 'failed'));
                })
                ->onQueue('sync')
                ->name(self::BATCH_NAME)
                ->dispatch();

            legalEntity()?->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_DIVISION);

            session()->flash('success', __('Синхронізація запущена у фоновому режимі'));
        } else {
            legalEntity()?->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_DIVISION);

            session()->flash('success', __('Інформацію успішно оновлено'));
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

                legalEntity()?->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_DIVISION);

                $this->restartBatch($batch, $user, $encryptedToken, legalEntity());

                break;
            }
        }
    }

    public function render(): View
    {
        return view('livewire.division.division-index', ['divisions' => $this->divisions]);
    }
}
