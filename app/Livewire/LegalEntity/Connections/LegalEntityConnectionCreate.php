<?php
declare(strict_types=1);

namespace App\Livewire\LegalEntity\Connections;

use Throwable;
use App\Models\Connection;
use App\Models\LegalEntity;
use Livewire\WithFileUploads;
use Livewire\Attributes\Title;
use App\Models\LegalEntityType;
use Livewire\Attributes\Locked;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Traits\BatchLegalEntityQueries;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
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

    public const string BATCH_NAME = 'ConnectionSync';

    public const string BATCH_SUBNAME = 'ConnectionClientSync';

    public bool $showSignatureModal = false;

    public bool $showConnectionData = false;

    public string $clientUuid = '';

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

        $this->form->taxId = Auth::user()?->party?->tax_id ?? null;

        $this->redirectUri = config('ehealth.api.connection_redirect_uri');

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

    public function create(string $clientUuid, string $redirectUri, string $clientName): void
    {
        if (Auth::user()->cannot('limitedAction', LegalEntity::class)) {
            session()->flash('error', __('legal-entity-connection.policy.restrict.create'));

            return;
        }

        $this->clientUuid = $clientUuid;
        $this->redirectUri = $redirectUri;
        $this->clientName = $clientName;

        $this->validate($this->form->rulesForCreate());

        $this->dataToSign = [
            'client_id' => $clientUuid,
            'redirect_uri' => $this->redirectUri
        ];

        $this->showSignatureModal = true;
    }

    public function sign()
    {
        $this->form->validate($this->form->rulesForSign());

        try {
            $signedData = signatureService()->signData(
                $this->dataToSign,
                $this->form->password,
                $this->form->knedp,
                $this->form->keyContainerUpload,
                $this->form->taxId
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
            \Log::debug('ConnectionData: ', ['data' => $connectionData]);
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

        $this->legalEntity = LegalEntity::where('uuid', $connectionData['client_uuid'])->first() ?? Repository::legalEntity()->initLegalEntity($connectionData['client_uuid'], $this->clientType, $this->clientName, $connectionData['secret']);

        if (!$this->legalEntity || !Repository::legalEntity()->syncConnections([$connectionData], $this->legalEntity)) {
            return;
        }

        $this->connection = Connection::whereUuid($connectionData['uuid'])->first();

        $this->connection
            ? session()->flash('success', __('legal-entity-connection.connection_established'))
            : session()->flash('error', __('legal-entity-connection.connection_error'));
        // try {
        //     DB::transaction(function () use ($connectionData, $connection) {
        //         Repository::legalEntity()->updateLegalEntitySecret($this->connection->legalEntity, $connectionData['secret']);
        //     });
        // } catch (Throwable $err) {
        //     Log::error('Connection creation: cannot update client secret for Legal Entity: ', ['legalEntity' => $connection->legalEntity->id]);

        //     session()->flash('error', __('Помилка збереження токену (secret) для закладу'));

        //     return;
        // }

        // $this->newConnection = $connectionData;

        // dd($this->newConnection, $connection, $this->legalEntity);
/*
        // This (all below) should be done accordingly TZ (3.31.2.3)
        $connectionsData = $this->getClientConnections();

        if (!$connectionsData) {
            return;
        }

        // If connection was successful, the connection uuid should exist in the eHealth system.
        $isSuccessfulConnect = in_array($connection->uuid, array_column($connectionsData['responseData'], 'uuid'), true);
*/
        // $this->showConnectionData = true;
        // $isSuccessfulConnect = true;

        // return $isSuccessfulConnect
        //    ? redirect()->route('connection.create')->with('success', __('legal-entity-connection.connection_established'))
        //    : redirect()->route('connection.create')->with('error', __('legal-entity-connection.connection_error'));
    }

    #[Title('Зв\'язки МІС та СГуСОЗ')]
    public function render()
    {
        return view('livewire.legal-entity.connection.connection-create', ['connection' => $this->connection]);
    }
}
