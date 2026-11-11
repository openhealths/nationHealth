<?php

declare(strict_types=1);

namespace App\Livewire\Person\Records;

use App\Classes\eHealth\EHealth;
use App\Enums\CarePlanCompletenessFilter;
use App\Enums\CarePlanStatusFilter;
use App\Jobs\CarePlanFullSync;
use App\Models\CarePlan;
use App\Repositories\Repository;
use App\Services\Dictionary\DictionaryManager;
use App\Traits\HandlesCarePlanSync;
use App\Traits\SynchronizesCarePlans;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Throwable;

class PatientCarePlans extends BasePatientComponent
{
    use HandlesCarePlanSync;
    use SynchronizesCarePlans;

    #[Locked]
    public ?string $syncBatchId = null;

    #[Locked]
    public array $carePlans = [];

    public string $filterName = '';
    public string $filterEncounterId = '';
    public string $filterStatus = CarePlanStatusFilter::CURRENT->value;
    public string $filterCompleteness = CarePlanCompletenessFilter::COMPLETE->value;
    public string $filterStartDateRange = '';
    public string $filterEndDateRange = '';
    public string $filterBasedOn = '';
    public string $filterPartOf = '';
    public string $filterPeriodDate = '';
    public string $filterManagingOrganization = '';
    public bool $showAdditionalParams = false;

    /**
     * Initialize component with care plans for the specific patient.
     */
    protected function initializeComponent(): void
    {
        $this->loadCarePlans();
        $this->syncBatchId = $this->runningSyncBatchId();
        if ($this->syncBatchId === null && Cache::has($this->accessSyncKey())) {
            $this->sync();
        }

        try {
            $basics = app(DictionaryManager::class)->basics();
            $this->dictionaries['care_plan_categories'] = $basics->byName('eHealth/care_plan_categories')
                ?->asCodeDescription()
                ?->toArray() ?? [];
        } catch (Exception $exception) {
            Log::warning('PersonCarePlans: failed to load dictionaries: ' . $exception->getMessage());
        }
    }

    /**
     * Load care plans from the database.
     */
    public function loadCarePlans(): void
    {
        $this->carePlans = Repository::carePlan()->patientListing($this->personId, [
            'name' => $this->filterName,
            'status' => $this->filterStatus,
            'completeness' => $this->filterCompleteness,
            'encounter_id' => $this->filterEncounterId,
            'start_date' => $this->filterStartDateRange,
            'end_date' => $this->filterEndDateRange,
            'based_on' => $this->filterBasedOn,
            'part_of' => $this->filterPartOf,
            'period_date' => $this->filterPeriodDate,
            'managing_organization_id' => $this->filterManagingOrganization,
        ], (int) legalEntity()->id);
    }

    private function runningSyncBatchId(): ?string
    {
        return $this->findRunningBatchesByLegalEntity(legalEntity()->id)
            ->firstWhere('name', CarePlanFullSync::BATCH_NAME.'_'.$this->uuid)?->id;
    }

    public function sync(): void
    {
        if (Auth::user()->cannot('care_plan:read')) {
            Session::flash('error', __('care-plan.sync_access_denied'));

            return;
        }
        $token = Session::get(config('ehealth.api.oauth.bearer_token'));
        if (!is_string($token) || $token === '') {
            Session::flash('error', __('care-plan.sync_session_expired'));

            return;
        }
        if ($this->syncBatchId !== null && $this->carePlanSyncOutcome([$this->syncBatchId]) === null) {
            Session::flash('info', __('care-plan.sync_running'));

            return;
        }
        try {
            $this->syncBatchId = $this->startCarePlanPatientSync($this->patient(), firstPageSynchronously: true);
            // Keep the intent while a concurrent caller returns the pre-grant batch.
            if (Cache::get($this->accessSyncKey()) !== $this->syncBatchId) {
                Cache::forget($this->accessSyncKey());
            }
            $this->loadCarePlans();
            Session::flash($this->syncBatchId === null ? 'success' : 'info', __($this->syncBatchId === null ? 'care-plan.sync_success' : 'care-plan.sync_first_page'));
        } catch (Throwable $exception) {
            Log::error('Could not start patient CarePlan synchronization', ['exceptionType' => $exception::class]);
            Session::flash('error', __('care-plan.sync_failed'));
        }
    }

    public function checkSyncStatus(): void
    {
        if ($this->syncBatchId === null) {
            return;
        }
        $outcome = $this->carePlanSyncOutcome([$this->syncBatchId]);
        if ($outcome === null) {
            $this->skipRender();

            return;
        }
        $this->syncBatchId = null;
        if (Cache::has($this->accessSyncKey())) {
            $this->sync();

            return;
        }
        $this->loadCarePlans();
        $this->flashCarePlanSyncOutcome($outcome);
    }

    public function updatedFilterCompleteness(): void
    {
        $this->loadCarePlans();
    }

    #[On('care-plan-read-access-granted')]
    public function synchronizeGrantedPlans(): void
    {
        if (Auth::user()->cannot('care_plan:read')) {
            Session::flash('error', __('care-plan.sync_access_denied'));

            return;
        }
        $runningBatch = $this->runningSyncBatchId();
        if ($runningBatch !== null) {
            // The old batch may already have exhausted detail reads before consent was granted.
            // Persist the retry across reloads; never interrupt jobs already in flight.
            Cache::put($this->accessSyncKey(), $runningBatch, now()->addDay());
            $this->syncBatchId = $runningBatch;
        }
        $this->sync();
    }

    private function accessSyncKey(): string
    {
        return 'care-plan-sync-after-access:'.legalEntity()->id.':'.$this->uuid;
    }

    #[Computed]
    public function encounterChoices(): array
    {
        return Repository::carePlan()->encounterChoices($this->personId);
    }

    #[Computed]
    public function relatedCarePlanChoices(): array
    {
        return Repository::carePlan()->relatedPlanChoices($this->personId);
    }

    public function search(): void
    {
        if (Auth::user()->cannot('search', CarePlan::class)) {
            Session::flash('error', __('care-plan.sync_access_denied'));

            return;
        }
        $this->validate([
            'filterEncounterId' => ['nullable', 'uuid'],
            'filterBasedOn' => ['nullable', 'uuid'],
            'filterPartOf' => ['nullable', 'uuid'],
            'filterManagingOrganization' => ['nullable', 'uuid'],
            'filterPeriodDate' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $query = array_filter([
            'encounter_id' => $this->filterEncounterId,
            'based_on' => $this->filterBasedOn,
            'part_of' => $this->filterPartOf,
            'period_date' => $this->filterPeriodDate,
            'managing_organization_id' => $this->filterManagingOrganization,
            'status' => in_array($this->filterStatus, [CarePlanStatusFilter::ALL->value, CarePlanStatusFilter::CURRENT->value], true) ? '' : $this->filterStatus,
        ], static fn (string $value): bool => $value !== '');
        try {
            // Search returns the registry even where patient consent still blocks detail reads.
            $summaries = $this->collectSummaries(fn (int $page) => EHealth::carePlan()->getBySearchParams($this->uuid, array_replace($query, ['page' => $page])));
            Repository::carePlan()->storeSummaries($summaries, $this->personId, (int) legalEntity()->id);
            $this->filterCompleteness = CarePlanCompletenessFilter::ALL->value;
            $this->loadCarePlans();
        } catch (Throwable $exception) {
            Log::error('Care Plan patient search failed', ['exceptionType' => $exception::class]);
            Session::flash('error', __('care-plan.search_error'));
        }
    }

    public function resetFilters(): void
    {
        $this->reset([
            'filterName',
            'filterEncounterId',
            'filterStatus',
            'filterCompleteness',
            'filterStartDateRange',
            'filterEndDateRange',
            'filterBasedOn',
            'filterPartOf',
            'filterPeriodDate',
            'filterManagingOrganization',
        ]);
        $this->loadCarePlans();
    }

    public function render(): View
    {
        return view('livewire.person.records.care-plans');
    }
}
