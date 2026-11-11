<?php

declare(strict_types=1);

namespace App\Livewire\CarePlan;

use App\Classes\eHealth\EHealth;
use App\Enums\CarePlanCompletenessFilter;
use App\Enums\CarePlanStatusFilter;
use App\Jobs\CarePlanFullSync;
use App\Models\CarePlan;

use App\Repositories\Repository;
use App\Traits\HandlesCarePlanSync;
use App\Traits\SynchronizesCarePlans;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class CarePlanIndex extends Component
{
    use HandlesCarePlanSync;
    use SynchronizesCarePlans;

    #[Locked]
    public array $syncBatchIds = [];

    #[Locked]
    public bool $syncInitiationFailed = false;

    #[Locked]
    public array $requisitionResults = [];

    public string $searchRequisition = '';

    public string $filterName = '';
    public string $filterEncounterId = '';
    public string $filterStatus = CarePlanStatusFilter::CURRENT->value;
    public string $filterCompleteness = CarePlanCompletenessFilter::COMPLETE->value;
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
            'requisitionResults',
            'filterName',
            'filterEncounterId',
            'filterStatus',
            'filterCompleteness',
            'filterStartDateRange',
            'filterEndDateRange',
        ]);
    }

    /**
     * Search eHealth by public requisition number (per TZ 3.10.3.2.1).
     */
    public function searchByRequisition(): void
    {
        if (Auth::user()->cannot('search', CarePlan::class)) {
            session()->flash('error', __('care-plan.sync_access_denied'));

            return;
        }
        $this->validate(['searchRequisition' => ['nullable', 'string', 'max:255']]);
        if (empty($this->searchRequisition)) {
            $this->requisitionResults = [];

            return;
        }

        try {
            // The requisition endpoint exposes only the permitted identity projection.
            // Do not require a local patient or fetch protected clinical details here.
            $this->requisitionResults = $this->collectSummaries(fn (int $page) => EHealth::carePlan()->getMany(['requisition' => $this->searchRequisition, 'page' => $page]));
            $this->filterCompleteness = CarePlanCompletenessFilter::ALL->value;
            if ($this->filterStatus === CarePlanStatusFilter::CURRENT->value) {
                $this->filterStatus = CarePlanStatusFilter::ALL->value;
            }
        } catch (Throwable $e) {
            $this->requisitionResults = [];
            Log::error('Care Plan requisition search failed', ['exceptionType' => $e::class]);
            session()->flash('error', __('care-plan.search_error'));
        }
    }

    public function mount(): void
    {
        $this->syncBatchIds = $this->findRunningBatchesByLegalEntity(legalEntity()->id)
            ->filter(static fn ($batch): bool => str_starts_with($batch->name, CarePlanFullSync::BATCH_NAME.'_'))
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
                } catch (Throwable $exception) {
                    $this->syncInitiationFailed = true;
                    Log::error('Could not start patient CarePlan synchronization', ['exceptionType' => $exception::class]);
                }
            }
        } catch (Throwable $exception) {
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

    #[Computed]
    public function carePlans(): Collection
    {
        return Repository::carePlan()->listing(legalEntity()->id, ['status' => $this->filterStatus, 'completeness' => $this->filterCompleteness, 'name' => $this->filterName, 'startDate' => $this->filterStartDateRange, 'endDate' => $this->filterEndDateRange, 'encounterId' => $this->filterEncounterId, 'requisition' => $this->searchRequisition]);
    }

    public function render()
    {
        return view('livewire.care-plan.care-plan-index');
    }
}
