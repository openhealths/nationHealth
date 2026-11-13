<?php

declare(strict_types=1);

namespace App\Livewire\ContractRequest;

use App\Enums\JobStatus;
use App\Jobs\ContractRequestDetailsUpsert;
use App\Jobs\ContractRequestSync;
use App\Models\Contracts\ContractRequest;
use App\Models\LegalEntity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class ContractRequestIndex extends Component
{
    use WithPagination;

    public string $search = '';
    public array $statusFilter = [];
    public array $typeFilter = [];
    public array $pendingStatusFilter = [];
    public array $pendingTypeFilter = [];
    public bool $isFiltersApplied = false;
    public int $filterVersion = 0;

    public function applyFilters(): void
    {
        $this->statusFilter = $this->pendingStatusFilter;
        $this->typeFilter = $this->pendingTypeFilter;
        $this->resetPage();
        $this->isFiltersApplied = true;
    }

    public function resetFilters(): void
    {
        $this->reset([
            'search',
            'statusFilter',
            'typeFilter',
            'pendingStatusFilter',
            'pendingTypeFilter',
            'isFiltersApplied',
        ]);
        $this->filterVersion++;
        $this->resetPage();
    }

    public function mount(): void
    {
        abort_if(!Auth::user() || Auth::user()->cannot('viewAny', ContractRequest::class), 403);
        $this->isSyncing = legalEntity()->getEntityStatus(LegalEntity::ENTITY_CONTRACT_REQUEST) === JobStatus::PROCESSING;
    }

    public bool $isSyncing = false;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function checkSyncStatus(): void
    {
        if (Auth::user()->cannot('sync', ContractRequest::class)) {
            $this->isSyncing = false;
            Session::flash('error', __('contracts.policy.sync_denied'));

            return;
        }

        $wasSyncing = $this->isSyncing;
        $status = legalEntity()->refresh()->getEntityStatus(LegalEntity::ENTITY_CONTRACT_REQUEST);
        $this->isSyncing = $status === JobStatus::PROCESSING;
        if ($wasSyncing && !$this->isSyncing) {
            Session::flash(
                $status === JobStatus::COMPLETED ? 'success' : 'error',
                __($status === JobStatus::COMPLETED ? 'contracts.sync_finished' : 'contracts.sync_failed')
            );
        }
    }

    public function sync(): void
    {
        if (Auth::user()->cannot('sync', ContractRequest::class)) {
            Session::flash('error', __('contracts.policy.sync_denied'));

            return;
        }

        $currentLegalEntity = legalEntity()->refresh();
        if ($currentLegalEntity->getEntityStatus(LegalEntity::ENTITY_CONTRACT_REQUEST) === JobStatus::PROCESSING) {
            Session::flash('info', __('forms.errors.sync_already_running'));

            return;
        }

        $token = session()->get(config('ehealth.api.oauth.bearer_token'));
        if (!is_string($token) || $token === '') {
            Session::flash('error', __('contracts.sync_token_missing'));

            return;
        }

        $lock = Cache::lock('contract-request-sync:' . $currentLegalEntity->id, 120);
        if (!$lock->get()) {
            Session::flash('info', __('forms.errors.sync_already_running'));

            return;
        }

        try {
            // Recheck under the shared lock: another request may have started after the first read.
            if ($currentLegalEntity->refresh()->getEntityStatus(LegalEntity::ENTITY_CONTRACT_REQUEST) === JobStatus::PROCESSING) {
                Session::flash('info', __('forms.errors.sync_already_running'));

                return;
            }
            // One sequential chain preserves type/page context and completes only after validated details.
            $currentLegalEntity->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_CONTRACT_REQUEST);
            Bus::batch([new ContractRequestSync(legalEntity: $currentLegalEntity, standalone: true, contractType: 'CAPITATION')])
                ->withOption('legal_entity_id', $currentLegalEntity->id)
                ->withOption('token', Crypt::encryptString($token))
                ->withOption('user', Auth::user())
                ->withOption('sync_entity', LegalEntity::ENTITY_CONTRACT_REQUEST)
                ->onQueue('sync')
                ->name('ContractRequest Sync')
                ->dispatch();
            $this->isSyncing = true;
            Session::flash('info', __('forms.success.sync_started'));
        } catch (Throwable $exception) {
            $currentLegalEntity->setEntityStatus(JobStatus::FAILED, LegalEntity::ENTITY_CONTRACT_REQUEST);
            Log::warning('ContractRequest sync could not start', ['exception' => $exception::class]);
            Session::flash('error', __('contracts.sync_failed'));
        } finally {
            $lock->release();
        }
    }

    public function syncOne(string $uuid): void
    {
        $contractRequest = ContractRequest::query()->forLegalEntity(legalEntity())->where('uuid', $uuid)->firstOrFail();
        if (Auth::user()->cannot('view', $contractRequest) || Auth::user()->cannot('sync', ContractRequest::class)) {
            Session::flash('error', __('contracts.policy.sync_denied'));

            return;
        }
        if ($contractRequest->syncStatus === JobStatus::PROCESSING) {
            Session::flash('info', __('forms.errors.sync_already_running'));

            return;
        }
        $token = session()->get(config('ehealth.api.oauth.bearer_token'));
        if (!is_string($token) || $token === '') {
            Session::flash('error', __('contracts.sync_token_missing'));

            return;
        }
        $currentLegalEntity = legalEntity();
        $lock = Cache::lock('contract-request-sync:' . $currentLegalEntity->id, 120);
        if (!$lock->get()) {
            Session::flash('info', __('forms.errors.sync_already_running'));

            return;
        }
        try {
            if ($currentLegalEntity->refresh()->getEntityStatus(LegalEntity::ENTITY_CONTRACT_REQUEST) === JobStatus::PROCESSING
                || $contractRequest->refresh()->syncStatus === JobStatus::PROCESSING) {
                Session::flash('info', __('forms.errors.sync_already_running'));

                return;
            }
            $currentLegalEntity->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_CONTRACT_REQUEST);
            $contractRequest->update(['sync_status' => JobStatus::PROCESSING]);
            Bus::batch([new ContractRequestDetailsUpsert($contractRequest, legalEntity(), standalone: true)])
                ->withOption('legal_entity_id', legalEntity()->id)
                ->withOption('token', Crypt::encryptString($token))
                ->withOption('user', Auth::user())
                ->onQueue('sync')
                ->withOption('sync_entity', LegalEntity::ENTITY_CONTRACT_REQUEST)
                ->name('Single Contract Request Sync')
                ->dispatch();
            $this->isSyncing = true;
            Session::flash('info', __('forms.synchronisation_started'));
        } catch (Throwable $exception) {
            $contractRequest->update(['sync_status' => JobStatus::FAILED]);
            $currentLegalEntity->setEntityStatus(JobStatus::FAILED, LegalEntity::ENTITY_CONTRACT_REQUEST);
            Session::flash('error', __('contracts.sync_failed'));
        } finally {
            $lock->release();
        }
    }

    public function render(): View
    {
        $canView = Auth::user()->can('viewAny', ContractRequest::class);
        if (!$canView && !Session::has('error')) {
            Session::flash('error', __('contracts.policy.read_denied'));
        }

        $contracts = ContractRequest::query()
            ->forLegalEntity(legalEntity())
            ->when(!$canView, fn ($query) => $query->whereIn('id', []))
            ->when($this->statusFilter, function ($query) {
                $query->whereIn('status', $this->statusFilter);
            })
            ->when($this->typeFilter, function ($query) {
                $query->whereIn('type', $this->typeFilter);
            })
            ->when($this->search, function ($query) {
                $query->where('contract_number', 'like', '%' . $this->search . '%');
            })
            ->orderByRaw("CASE WHEN status = 'NEW' THEN 0 ELSE 1 END")
            ->orderByDesc('inserted_at')
            ->orderByDesc('id')
            ->paginate(10);

        return view('livewire.contract-request.contract-request-index', [
            'contracts' => $contracts
        ]);
    }
}
