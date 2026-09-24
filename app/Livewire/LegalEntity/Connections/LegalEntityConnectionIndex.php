<?php
declare(strict_types=1);

namespace App\Livewire\LegalEntity\Connections;

use Throwable;
use Exception;
use App\Models\User;
use App\Core\Arr;
use App\Enums\JobStatus;
use Illuminate\Bus\Batch;
use App\Models\Connection;
use App\Models\LegalEntity;
use App\Jobs\ConnectionSync;
use Livewire\WithFileUploads;
use Livewire\Attributes\Title;
use Livewire\Attributes\Locked;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
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
use App\Livewire\LegalEntity\Connections\Form\ConnectionForm;

class LegalEntityConnectionIndex extends LegalEntityConnectionComponent
{
    use BatchLegalEntityQueries,
        WithFileUploads;

    /**
     * @var object|null
     */
    public ?object $file = null;

    public const string BATCH_NAME = 'ConnectionSync';

    public const string BATCH_SUBNAME = 'ConnectionClientSync';

    public bool $showSignatureModal = false;

    public string $clientUuid = '';

    public ConnectionForm $form;

    #[Locked]
    public array $dataToSign = [
        'client_id' => '',
        'redirect_uri' => ''
    ];

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

    public function create(string $clientUuid): void
    {
        if (Auth::user()->cannot('create', Connection::class)) {
            session()->flash('error', __('legal-entity-connection.policy.restrict.create'));

            return;
        }

        $this->dataToSign = [
            'client_id' => $clientUuid,
            'redirect_uri' => config('ehealth.api.connection_redirect_uri')
        ];

        $this->showSignatureModal = true;
    }

    public function sign()
    {
        $this->form->validate($this->form->rulesForSign(skipTaxIdValidation: true));

        try {
            $signedData = signatureService()->signData(
                $this->dataToSign,
                $this->form->password,
                $this->form->knedp,
                $this->form->keyContainerUpload,
                Auth::user()->party->tax_id
            );
        } catch (Throwable $err) {
            Log::error('Failed to sign connection data.', ['exception' => $err]);
            session()->flash('error', $err->getMessage());

            return;
        }

        // Handle errors from encrypted data
        if (isset($signedData['errors'])) {
            $this->dispatchErrorMessage($signedData['errors']);

            Log::channel('e_health_errors')->error(self::class . ':createConnection', ['error' => $signedData['errors']]);

            session()->flash('error', __('forms.signature_validation_error'));

            return;
        }

        $this->showSignatureModal = false;

        try {
            $response = EHealth::connection()->createConnection($signedData);

            $connectionData = $response->validate();
        } catch (EHealthResponseException $err) {
            $code = $err->getCode();
            $errorMessage = $err->getDetails()['error']['message'] ?? ($err->getMessage() ?? __('errors.ehealth.messages.server_error'));

            Log::channel('e_health_errors')->error(self::class . ':syncConnections', ['error' => $errorMessage]);

            $code === 409
                ? session()->flash('error', __('legal-entity-connection.sync.error.already_exist'))
                : session()->flash('error', $errorMessage);

            return;
        } catch (EHealthValidationException $err) {
            Log::channel('e_health_errors')->error(self::class . ':syncConnections', ['error' => $err->getDetails()]);

            session()->flash('error', __('errors.ehealth.messages.validation_error'));

            return;
        }

        if (!Repository::legalEntity()->syncConnections([$connectionData], $this->legalEntity)) {
            return;
        }

        $connection = Connection::whereUuid($connectionData['uuid'])->first();

        try {
            DB::transaction(function () use ($connectionData, $connection) {
                Repository::legalEntity()->updateLegalEntitySecret($connection->legalEntity, $connectionData['secret']);
            });
        } catch (Throwable $err) {
            Log::error('Connection creation: cannot update client secret for Legal Entity: ', ['legalEntity' => $connection->legalEntity->id]);

            session()->flash('error', __('Помилка збереження токену (secret) для закладу'));

            return;
        }

        // This (all below) should be done accordingly TZ (3.31.2.3)
        $connectionsData = $this->getClientConnections();

        if (!$connectionsData) {
            return;
        }

        // If connection was successful, the connection uuid should exist in the eHealth system.
        $isSuccessfulConnect = in_array($connection->uuid, array_column($connectionsData['responseData'], 'uuid'), true);

        return $isSuccessfulConnect
           ? redirect()->route('connection.show', [$this->legalEntity ?? legalEntity(), $connection->id])->with('success', __('legal-entity-connection.connection_established'))
           : redirect()->route('connection.show', [$this->legalEntity ?? legalEntity(), $connection->id])->with('error', __('legal-entity-connection.connection_error'));
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
            Session::flash('error', __('legal-entity-connection.policy.restrict.sync'));

            return;
        }

        $token = Session::get(config('ehealth.api.oauth.bearer_token'));

        $connections = $this->getClientConnections();

        if (!$connections) {
            return;
        }

        $response = $connections['response'];

        if (!Repository::legalEntity()->syncConnections($connections['responseData'], $this->legalEntity)) {
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
            if (count($connections['responseData']) === 1) {
                $clientUuid = Arr::get($connections, 'responseData.0.client_uuid', null);

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
