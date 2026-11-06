<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Traits\SynchronizesCarePlans;

use App\Models\CarePlan;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class CarePlanIndex extends Component
{
    use SynchronizesCarePlans;
    use \App\Traits\HandlesCarePlanSync;

    #[\Livewire\Attributes\Locked]
    public array $syncBatchIds = [];

    #[\Livewire\Attributes\Locked]
    public bool $syncInitiationFailed = false;

    public string $searchRequisition = '';

    public string $filterName = '';
    public string $filterEncounterId = '';
    public string $filterStatus = '';
    public string $filterStartDateRange = '';
    public string $filterEndDateRange = '';
    public bool $showAdditionalParams = false;

    public function search(): void
    {
        $this->searchByRequisition();
    }

    public function resetFilters(): void
    {
        $this->reset([
            'searchRequisition',
            'filterName',
            'filterEncounterId',
            'filterStatus',
            'filterStartDateRange',
            'filterEndDateRange',
        ]);
    }

    /**
     * Search eHealth by public requisition number (per TZ 3.10.3.2.1).
     */
    public function searchByRequisition(): void
    {
        if (empty($this->searchRequisition)) {
            return;
        }

        try {
            $this->synchronizeSearch(['requisition' => $this->searchRequisition], (int) legalEntity()->id);
        } catch (\Throwable $e) {
            Log::error('CarePlan search error: ' . $e->getMessage());
            session()->flash('error', __('care-plan.search_error') . ': ' . $e->getMessage());
        }
    }

    public function mount(): void
    {
        $this->syncBatchIds = $this->findRunningBatchesByLegalEntity(legalEntity()->id)
            ->filter(static fn ($batch): bool => str_starts_with($batch->name, \App\Jobs\CarePlanFullSync::BATCH_NAME.'_'))
            ->pluck('id')->all();
    }

    public function sync(): void
    {
        if (Auth::user()->cannot('sync', CarePlan::class)) {
            session()->flash('error', __('care-plan.sync_access_denied'));

            return;
        }
        if ($this->syncBatchIds !== [] && $this->carePlanSyncOutcome($this->syncBatchIds) === null) {
            session()->flash('info', __('care-plan.sync_running'));

            return;
        }
        $this->syncBatchIds = [];
        $this->syncInitiationFailed = false;
        try {
            $persons = Repository::carePlan()->patientsForSync(legalEntity()->id, auth()->user());
            foreach ($persons->values() as $index => $person) {
                try {
                    $batchId = $this->startCarePlanPatientSync($person, firstPageSynchronously: $index === 0);
                    if ($batchId !== null) {
                        $this->syncBatchIds[] = $batchId;
                    }
                } catch (\Throwable $exception) {
                    $this->syncInitiationFailed = true;
                    Log::error('Could not start patient CarePlan synchronization', ['exceptionType' => $exception::class]);
                }
            }
        } catch (\Throwable $exception) {
            $this->syncInitiationFailed = true;
            Log::error('Could not start CarePlan index synchronization', ['exceptionType' => $exception::class]);
        }
        if ($this->syncInitiationFailed) {
            session()->flash('error', __('care-plan.sync_failed'));

            return;
        }
        session()->flash($this->syncBatchIds === [] ? 'success' : 'info', __($this->syncBatchIds === [] ? 'care-plan.sync_success' : 'care-plan.sync_first_page'));
    }

    public function checkSyncStatus(): void
    {
        if ($this->syncBatchIds === []) {
            return;
        }
        $outcome = $this->carePlanSyncOutcome($this->syncBatchIds);
        if ($outcome === null) {
            $this->skipRender();

            return;
        }
        $outcome['success'] = $outcome['success'] && !$this->syncInitiationFailed;
        $this->syncBatchIds = [];
        $this->flashCarePlanSyncOutcome($outcome);
    }

    public function render()
    {
        $carePlans = Repository::carePlan()->listing(legalEntity()->id, ['status' => $this->filterStatus, 'name' => $this->filterName, 'startDate' => $this->filterStartDateRange, 'endDate' => $this->filterEndDateRange, 'encounterId' => $this->filterEncounterId, 'requisition' => $this->searchRequisition]);

        return view('livewire.care-plan.care-plan-index', ['carePlans' => $carePlans]);
    }
}
