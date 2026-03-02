<?php

declare(strict_types=1);

namespace App\Livewire\Contract;

use App\Classes\eHealth\EHealth;
use App\Enums\Contract\Type;
use App\Enums\JobStatus;
use App\Jobs\ContractSync;
use App\Models\Contracts\Contract;
use App\Models\LegalEntity;
use App\Notifications\SyncNotification;
use App\Repositories\Repository;
use App\Traits\FormTrait;
use Auth;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use Livewire\WithPagination;
use Log;

class ContractIndex extends Component
{
    use FormTrait;
    use WithPagination;

    public array $typeFilter = [];
    public bool $isFiltersApplied = false;

    public function mount(): void
    {
        $this->typeFilter = Type::values();
    }

    public function search(): void
    {
        $this->resetPage();
        $this->isFiltersApplied = true;
    }

    public function resetFilters(): void
    {
        $this->reset(['typeFilter', 'isFiltersApplied']);
        $this->typeFilter = Type::values();
    }

    public function sync(): void
    {
        $currentLegalEntity = legalEntity();

        if ($currentLegalEntity->getEntityStatus(LegalEntity::ENTITY_CONTRACT) === JobStatus::PROCESSING) {
            Session::flash('error', 'Синхронізація вже триває.');

            return;
        }

        $user = Auth::user();
        $token = session()->get(config('ehealth.api.oauth.bearer_token'));

        $this->dispatch('flashMessage', ['message' => 'Синхронізацію контрактів розпочато...', 'type' => 'success']);

        try {
            // Request first page
            $response = EHealth::contract()
                ->withToken($token)
                ->getMany([
                    // Using the correct filter key according to the Apiary documentation
                    'contractor_legal_entity_id' => $currentLegalEntity->uuid,
                ]);

            $contractsData = $response->validate();

            foreach ($contractsData as $item) {
                Repository::contract()->saveFromEHealth($item);
            }

            if ($response->isNotLast()) {
                Bus::batch([
                    new ContractSync(
                        legalEntity: $currentLegalEntity,
                        page: 2,
                        standalone: false
                    )
                ])
                    ->withOption('legal_entity_id', $currentLegalEntity->id)
                    ->withOption('token', Crypt::encryptString($token))
                    ->withOption('user', $user)
                    ->then(function (Batch $batch) use ($user) {
                        $user->notify(new SyncNotification('contract', 'completed'));
                    })
                    ->catch(function (Batch $batch, \Throwable $e) use ($user) {
                        Log::error('Contract sync batch failed', ['error' => $e->getMessage()]);
                        $user->notify(new SyncNotification('contract', 'failed'));
                    })
                    ->onQueue('sync')
                    ->name('Contract Hybrid Sync')
                    ->dispatch();

                $currentLegalEntity->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_CONTRACT);
            } else {
                $currentLegalEntity->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_CONTRACT);
            }

        } catch (\Exception $e) {
            Log::error('Manual contract sync error', ['message' => $e->getMessage()]);
            $this->dispatch('flashMessage', ['message' => 'Error: ' . $e->getMessage(), 'type' => 'error']);
        }
    }

    public function render(): \Illuminate\View\View
    {
        $contracts = Contract::query()
            ->where('legal_entity_id', legalEntity()->id)
            ->when($this->isFiltersApplied, function ($query) {
                $query->whereIn('type', $this->typeFilter);
            })
            ->orderByDesc('start_date')
            ->paginate(config('app.per_page', 15));

        return view('livewire.contract.contract-index', ['contracts' => $contracts]);
    }
}
