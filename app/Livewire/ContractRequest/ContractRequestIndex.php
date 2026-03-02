<?php

declare(strict_types=1);

namespace App\Livewire\ContractRequest;

use App\Classes\eHealth\EHealth;
use App\Enums\JobStatus;
use App\Jobs\ContractRequestDetailsUpsert;
use App\Jobs\ContractRequestSync;
use App\Models\Contracts\ContractRequest;
use App\Models\LegalEntity;
use App\Notifications\SyncNotification;
use App\Repositories\Repository;
use Auth;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Livewire\Component;
use Livewire\WithPagination;
use Log;
use Session;

class ContractRequestIndex extends Component
{
    use WithPagination;

    /**
     * Force reset sync status if stuck
     */
    public function forceReset(): void
    {
        $entity = legalEntity();
        $entity->setEntityStatus(JobStatus::FAILED, LegalEntity::ENTITY_CONTRACT_REQUEST);

        $this->dispatch('flashMessage', [
            'message' => 'Contract Request sync status has been reset.',
            'type' => 'info'
        ]);
    }

    /**
     * Synchronization of applications with EHealth
     */
    public function sync(): void
    {
        $currentLegalEntity = legalEntity();

        if ($currentLegalEntity->getEntityStatus(LegalEntity::ENTITY_CONTRACT_REQUEST) === JobStatus::PROCESSING) {
            Session::flash('error', 'Synchronization is already in progress.');

            return;
        }

        $user = Auth::user();
        $user?->notify(new SyncNotification('contract_request', 'started'));
        $this->dispatch('flashMessage', ['message' => 'Starting synchronization...', 'type' => 'success']);

        $token = session()?->get(config('ehealth.api.oauth.bearer_token'));
        $encryptedToken = Crypt::encryptString($token);

        $types = ['capitation', 'reimbursement'];
        $batchJobs = [];
        $syncedCount = 0;

        foreach ($types as $type) {
            try {
                $response = EHealth::contractRequest()
                    ->withToken($token)
                    ->getMany($type, [
                        'contractor_legal_entity_id' => $currentLegalEntity->uuid
                    ]);

                $data = $response->validate();

                if (!empty($data)) {
                    foreach ($data as $item) {
                        Repository::contractRequest()->saveFromEHealth($item, strtoupper($type));
                        $syncedCount++;
                    }
                }

                if ($response->isNotLast()) {
                    $batchJobs[] = new ContractRequestSync(
                        legalEntity: $currentLegalEntity,
                        nextEntity: null,
                        isFirstLogin: false,
                        user: $user,
                        contractType: strtoupper($type),
                    );
                }

            } catch (\Exception $e) {
                Log::error("ContractRequest sync ($type) error.", ['error' => $e->getMessage()]);
            }
        }

        if (!empty($batchJobs)) {
            Bus::batch($batchJobs)
                ->withOption('legal_entity_id', $currentLegalEntity->id)
                ->withOption('token', $encryptedToken)
                ->withOption('user', $user)
                ->then(fn (Batch $batch) => $user->notify(new SyncNotification('contract_request', 'completed')))
                ->catch(function (Batch $batch, \Throwable $e) use ($user) {
                    Log::error('ContractRequest batch failed.', ['err' => $e->getMessage()]);
                    $user->notify(new SyncNotification('contract_request', 'failed'));
                })
                ->onQueue('sync')
                ->name('Contract Request Hybrid Sync')
                ->dispatch();

            $currentLegalEntity->setEntityStatus(JobStatus::PROCESSING, LegalEntity::ENTITY_CONTRACT_REQUEST);
            $msg = "First pages synced ($syncedCount items). Processing the rest in background.";
        } else {
            $currentLegalEntity->setEntityStatus(JobStatus::COMPLETED, LegalEntity::ENTITY_CONTRACT_REQUEST);
            $msg = "Synchronization completed. Items received: $syncedCount.";
        }

        $this->dispatch('flashMessage', ['message' => $msg, 'type' => 'success']);
    }

    /**
     * Synchronize a single contract request by its UUID
     */
    public function syncOne(string $uuid): void
    {
        $contractRequestModel = ContractRequest::where('uuid', $uuid)->firstOrFail();

        // Optimistic UI update
        $contractRequestModel->update(['sync_status' => JobStatus::PROCESSING->value]);

        $userAuth = Auth::user();
        $bearerToken = session()->get(config('ehealth.api.oauth.bearer_token'));

        Bus::batch([
            new ContractRequestDetailsUpsert(
                contractRequestModel: $contractRequestModel,
                legalEntity: legalEntity(),
                standalone: true
            )
        ])
            ->withOption('legal_entity_id', legalEntity()->id)
            ->withOption('token', Crypt::encryptString($bearerToken))
            ->withOption('user', $userAuth)
            ->then(function (Batch $batch) use ($userAuth) {
                $userAuth->notify(new SyncNotification('contract_request', 'completed'));
            })
            ->catch(function (Batch $batch, \Throwable $e) use ($userAuth) {
                Log::error('Single ContractRequest sync failed.', ['error' => $e->getMessage()]);
                $userAuth->notify(new SyncNotification('contract_request', 'failed'));
            })
            ->onQueue('sync')
            ->name('Sync Single Contract Request')
            ->dispatch();

        $this->dispatch('flashMessage', [
            'message' => __('forms.synchronisation_started'),
            'type' => 'success'
        ]);
    }

    public function render(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\View\View
    {
        $contracts = ContractRequest::query()
            ->where('contractor_legal_entity_id', legalEntity()->uuid)
            ->orderByDesc('created_at')
            ->paginate(10);

        return view('livewire.contract-request.contract-request-index', [
            'contracts' => $contracts
        ]);
    }
}
