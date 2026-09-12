<?php
declare(strict_types=1);

namespace App\Livewire\LegalEntity\Connections;

use Throwable;
use Exception;
use App\Models\User;
use App\Enums\JobStatus;
use Illuminate\Bus\Batch;
use App\Models\Connection;
use App\Models\LegalEntity;
use App\Jobs\ConnectionSync;
use Livewire\Attributes\Title;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Session;
use App\Notifications\SyncNotification;
use App\Traits\BatchLegalEntityQueries;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;

class LegalEntityConnectionIndex extends LegalEntityConnectionComponent
{
    use BatchLegalEntityQueries;

    public const string BATCH_NAME = 'ConnectionSync';

    public const string BATCH_SUBNAME = 'ConnectionClientSync';

    /**
     * Retrieves paginated connections for the current legal entity.
     *
     * @return LengthAwarePaginator
     */
    #[Computed]
    public function connections(): LengthAwarePaginator
    {
        $connections = Connection::with(['legalEntity', 'client'])
            ->where('legal_entity_id', $this->legalEntityId)
            ->get();

        // Pagination
        $perPage = config('pagination.per_page');
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $currentItems = $connections->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $currentItems,
            $connections->count(),
            $perPage,
            $currentPage,
            ['path' => request()->url()]
        );
    }

    /**
     * Synchronize all the Connections with stored ones on the eHealths side
     *
     * @return void
     *
     * @throws Exception|EHealthResponseException|EHealthValidationException
     */
    public function sync(): void
    {
        $user = Auth::user();

        if ($user->cannot('sync', Connection::class)) {
            Session::flash('error', __('legal-entity.policy.deny.sync'));

            return;
        }

        /*
         * This is need by Livewire behavior.
         * On the first render, mount() runs and assigns $this->legalEntity.
         * On subsequent requests (e.g., when clicking synchronize button), Livewire does NOT run mount() again
         * and does NOT rehydrate protected typed properties.
         * Code below allows to ensure that property is set before use.
         */
        $this->setLegalEntity();

        $token = Session::get(config('ehealth.api.oauth.bearer_token'));

        $syncQuery = [
            'page' => 1,
            'page_size' => config('ehealth.api.page_size_le_connections_max')
        ];

        try {
            $response = EHealth::connection()->getClientConnections(clientId: $this->legalEntity->uuid, query: $syncQuery);

            $connections = $response->validate();
        } catch (EHealthResponseException $err) {
            Log::channel('e_health_errors')->error(self::class . ':syncConnections', ['error' => $err->getDetails()]);
            session()->flash('error', __('errors.ehealth.messages.server_error'));

            return;
        } catch (EHealthValidationException $err) {
            Log::channel('e_health_errors')->error(self::class . ':syncConnections', ['error' => $err->getDetails()]);

            session()->flash('error', __('errors.ehealth.messages.validation_error'));

            return;
        }

        if (!Repository::legalEntity()->syncConnections($connections, $this->legalEntity)) {
            return;
        }

        $isJobsStarted = false;

        if ($response->isNotLast()) {
            Bus::batch([new ConnectionSync($this->legalEntity, page: 2)])
                ->withOption('legal_entity_id', $this->legalEntityId)
                ->withOption('token', Crypt::encryptString($token))
                ->withOption('user', $user)
                ->withOption('sync_entity', LegalEntity::ENTITY_CONNECTION)
                ->catch(function (Batch $batch, Throwable $exception) use ($user) {
                    Log::error('Connection Client sync batch failed.', [
                        'batch_id' => $batch->id,
                        'exception' => $exception
                    ]);

                    $user->notify(new SyncNotification('connection', 'failed'));
                })
                ->onQueue('sync')
                ->name(self::BATCH_NAME)
                ->dispatch();

            $isJobsStarted = true;

            $user->notify(new SyncNotification('connection', 'started'));

            $this->legalEntity->setEntityStatus(JobStatus::PROCESSING);
        } else {
            // Client synchronization
            if (count($connections) === 1) {
                $clientUuid = $connections[0]['client_uuid'] ?? null;

                if (!$this->syncSingleClient($clientUuid)) {
                    return;
                }
            } else {
                try {
                    $this->dispatchNextSyncJobs($user, $token, $this->legalEntity);
                } catch (Throwable $exception) {
                    Log::error('Failed to dispatch Connection Client sync batch', ['exception' => $exception]);

                    $user->notify(new SyncNotification('connection_client', 'failed'));

                    session()->flash('error', __('legal-entity-connection.sync.error.client_fail'));

                    return;
                }

                $isJobsStarted = true;
            }
        }

        $isJobsStarted
            ? Session::flash('success', __('legal-entity-connection.sync.started'))
            : session()->flash('success', __('legal-entity-connection.sync.success_many'));
    }

    /**
     * Dispatch next sync jobs for connection clients.
     *
     * @param  User  $user
     * @param  string  $token
     * @param  LegalEntity $legalEntity
     *
     * @return void
     *
     * @throws Throwable
     */
    protected function dispatchNextSyncJobs(User $user, string $token, LegalEntity $legalEntity): void
    {
        Bus::batch($this->getConnectionClientsDataJob($legalEntity, null))
            ->withOption('legal_entity_id', $legalEntity->id)
            ->withOption('token', Crypt::encryptString($token))
            ->withOption('user', $user)
            ->withOption('sync_entity', LegalEntity::ENTITY_CONNECTION_CLIENT)
            ->catch(function (Batch $batch, Throwable $exception) use ($user) {
                Log::error('Connection Client sync batch failed.', [
                    'batch_id' => $batch->id,
                    'exception' => $exception
                ]);

                $user->notify(new SyncNotification('connection_client', 'failed'));
            })
            ->onQueue('sync')
            ->name(self::BATCH_SUBNAME)
            ->dispatch();

        $user->notify(new SyncNotification('connection_client', 'started'));

        $legalEntity->setEntityStatus(JobStatus::PROCESSING);
    }

    #[Title('Зв\'язки МІС та СГуСОЗ')]
    public function render()
    {
        return view('livewire.legal-entity.connection.connection-index', [
            'connections' => $this->connections(),
        ]);
    }
}
