<?php
declare(strict_types=1);

namespace App\Livewire\LegalEntity\Connections;

use Throwable;
use RuntimeException;
use App\Enums\Status;
use App\Models\Connection;
use App\Models\LegalEntity;
use Livewire\WithFileUploads;
use Livewire\Attributes\Title;
use App\Models\LegalEntityType;
use Livewire\Attributes\Locked;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use App\Exceptions\Signature\SignatureException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Traits\BatchLegalEntityQueries;
use Illuminate\Support\Facades\Validator;
use App\Enums\LegalEntity\ConnectionStatus;
use App\Auth\EHealth\Services\TokenStorage;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Classes\eHealth\Api\Connection as ConnectionApi;
use App\Livewire\LegalEntity\Connections\Form\ConnectionForm;

class LegalEntityConnectionCreate extends LegalEntityConnectionComponent
{
    use BatchLegalEntityQueries,
        WithFileUploads;

    protected const array LEGAL_ENTITY_TYPES = [
        LegalEntity::TYPE_PRIMARY_CARE,
        LegalEntity::TYPE_OUTPATIENT,
        LegalEntity::TYPE_EMERGENCY,
        LegalEntity::TYPE_PHARMACY
    ];

    /**
     * @var object|null
     */
    public ?object $file = null;

    #[Locked]
    public ?int $legalEntityId = null;

    #[Locked]
    public ?int $connectionId = null;

    public const string BATCH_NAME = 'ConnectionSync';

    public const string BATCH_SUBNAME = 'ConnectionClientSync';

    public bool $showSignatureModal = false;

    public bool $showConnectionData = false;

    public string $clientUuid = '';

    #[Locked]
    public string $redirectUri = '';

    public string $clientName = '';

    public ?int $clientType = null;

    public ConnectionForm $form;

    public array $legalEntityTypes = [];

    #[Locked]
    public array $dataToSign = [
        'client_id' => '',
        'redirect_uri' => ''
    ];

    public function mount(LegalEntity $legalEntity, ?Connection $connection=null)
    {
        $legalEntity ??= null;

        parent::mount($legalEntity, $connection);

        $this->form->taxId = Auth::user()?->party?->taxId ?? null;

        $this->redirectUri = ConnectionApi::redirectUri();

        $this->clientUuid = $this->legalEntity?->clientId ?? '';

        $edrData = \is_string($legalEntity->edr)
            ? json_decode($legalEntity->edr, true)
            : (\is_array($legalEntity->edr) ? $legalEntity->edr : []);

        $this->clientName = $edrData['name'] ?? '';

        $this->setLegalEntityTypes();
    }

    /**
     * Populates `legalEntityTypes` with allowed types keyed by id, localized and reverse-ordered for display.
     *
     * @return void
     */
    protected function setLegalEntityTypes(): void
    {
        $types = LegalEntityType::whereIn('name', self::LEGAL_ENTITY_TYPES)
            ->pluck('localized_name', 'id')
            ->map(fn ($label) => __($label))
            ->reverse()
            ->toArray();

        foreach ($types as $id => $localizedName) {
            $this->legalEntityTypes[$id] = $localizedName;
        }
    }

    public function create(string $clientUuid, string $clientName): void
    {
        if (Auth::user()->cannot('create', Connection::class)) {
            session()->flash('error', __('legal-entity-connection.policy.restrict.create'));

            return;
        }

        $this->clientUuid = $clientUuid;
        $this->redirectUri = ConnectionApi::redirectUri();
        $this->clientName = $clientName;

        $this->validate($this->form->rulesForCreate(self::LEGAL_ENTITY_TYPES));

        $this->dataToSign = [
            'client_id' => $clientUuid,
            'redirect_uri' => $this->redirectUri
        ];

        $this->showSignatureModal = true;
    }

    public function sign()
    {
        if (Auth::user()->cannot('create', Connection::class)) {
            session()->flash('error', __('legal-entity-connection.policy.restrict.create'));

            return;
        }

        $this->form->validate($this->form->rulesForSign());

        try {
            $signedData = signatureService()->signData(
                $this->dataToSign,
                $this->form->knedp,
                $this->form->keyContainerUpload,
                $this->form->password,
                $this->form->taxId
            );
        } catch (SignatureException $exception) {
            $exception->handle('Error when signing connection data');

            return;
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

        // Revalidate mutable creation fields before creating the connection in eHealth.
        $this->validate($this->form->rulesForCreate(self::LEGAL_ENTITY_TYPES));

        $this->showSignatureModal = false;

        try {
            $response = EHealth::connection()->createConnection($signedData);

            if (!$response->successful()) {
                throw new EHealthResponseException($response);
            }
        } catch (EHealthResponseException $err) {
            $code = $err->getCode();
            $errorMessage = $err->response->json('error.message')
                ?? $err->response->json('details.errorMessage')
                ?? $err->response->json('description')
                ?? $err->response->json('message');

            Log::channel('e_health_errors')->error(self::class . ':syncConnections', ['error' => $errorMessage ?? $err->getMessage()]);

            if ($code < 400 || $code === 408 || $code >= 500 || !\is_string($errorMessage) || trim($errorMessage) === '') {
                $errorMessage = __('legal-entity-connection.creation_result_unknown');
            }

            $code === 409
                ? session()->flash('error', __('legal-entity-connection.sync.error.already_exist'))
                : session()->flash('error', $errorMessage);

            return;
        } catch (EHealthValidationException $err) {
            Log::channel('e_health_errors')->error(self::class . ':syncConnections', ['error' => $err->getDetails()]);

            session()->flash('error', __('errors.ehealth.messages.validation_error'));

            return;
        } catch (Throwable $err) {
            Log::channel('e_health_errors')->error(self::class . ':createConnection', ['error' => $err->getMessage()]);

            session()->flash('error', __('legal-entity-connection.creation_result_unknown'));

            return;
        }

        try {
            // Save the secret before validating or synchronizing the remaining connection data. This is important to do so.
            // This done to ensure that the client_secret is saved before any further validation or synchronization
            // of the connection data when possible errors occurs, preventing the saving of the client secret from being bypassed.
            $saveStep = 'validate_client_credentials';
            $credentials = Validator::make($response->getData(), $this->form->rulesForCredentials())->validate();

            $saveStep = 'find_legal_entity';
            $legalEntity = LegalEntity::where('uuid', $credentials['client_id'])->first();

            if ($legalEntity) {
                // Refresh legal entity details on the next eHealth login after reconnecting.
                $saveStep = 'save_client_secret_and_mark_for_refresh';
                if (!$legalEntity->update([
                    'client_secret' => $credentials['secret'],
                    'status' => Status::NEW->value
                ])) {
                    throw new RuntimeException('Unable to save the client secret and mark the facility for a details refresh.');
                }
            } else {
                $saveStep = 'create_legal_entity_with_client_secret';
                $legalEntity = Repository::legalEntity()->initLegalEntity($credentials['client_id'], $this->clientType, $this->clientName, $credentials['secret']);
            }

            if (!$legalEntity) {
                throw new RuntimeException('Unable to save the client secret locally.');
            }

            $saveStep = 'validate_connection_response';
            $connectionData = $response->validate();

            $saveStep = 'save_connection';
            if (!Repository::legalEntity()->syncConnections([$connectionData], $legalEntity, ConnectionStatus::PENDING)) {
                throw new RuntimeException('Unable to save the created connection locally.');
            }

            $saveStep = 'check_saved_connection';
            $connection = Connection::whereUuid($connectionData['uuid'])->first();

            if (!$connection) {
                throw new RuntimeException('Created connection was not found locally.');
            }
        } catch (Throwable $err) {
            Log::error('Connection creation: failed to save the eHealth connection data to the local database.', [
                'failed_step' => $saveStep,
                'legal_entity_uuid' => $credentials['client_id'] ?? $this->dataToSign['client_id'],
                'connection_uuid' => $connectionData['uuid'] ?? null,
                'exception_type' => $err::class
            ]);

            session()->flash('error', __('legal-entity-connection.local_save_failed'));

            return;
        }

        $this->legalEntity = $legalEntity;
        $this->legalEntityId = $legalEntity->id;
        $this->connection = $connection;
        $this->connectionId = $connection->id;

        $token = app(TokenStorage::class)->getBearerToken();

        $isSuccessfulConnect = false;

        // Verify the connection for case when user is logined by the eHealth system
        // Otherwise, verification will be done via the VerifyCreatedConnection listener
        if ($token) {
            try {
                $response = EHealth::connection()->getClientConnections(
                    clientId: $this->legalEntity->uuid,
                    query: [
                        'page' => 1,
                        'page_size' => config('ehealth.api.page_size_le_connections_max')
                    ]
                );

                $connections = $response->validate();

                $isSuccessfulConnect = \in_array($this->connection->uuid, array_column($connections, 'uuid'), true);
            } catch (Throwable $err) {
                Log::channel('e_health_errors')->error(self::class . ':verifyConnection', ['error' => $err->getMessage()]);

                session()->flash('error', __('errors.ehealth.messages.server_error'));

                return;
            }

            if (!$isSuccessfulConnect) {
                session()->flash('error', __('legal-entity-connection.connection_error'));

                return;
            }

            $this->connection->update(['status' => ConnectionStatus::ACTIVE->value]);
        }

        session()->flash('success', __('legal-entity-connection.connection_established'));
    }

    #[Title('Зв\'язки МІС та СГуСОЗ')]
    public function render()
    {
        return view('livewire.legal-entity.connection.connection-create', [
            'connection' => $this->connection
        ]);
    }
}
