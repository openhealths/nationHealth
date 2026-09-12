<?php

declare(strict_types=1);

namespace App\Livewire\LegalEntity\Connections;

use Livewire\Component;
use App\Models\Connection;
use App\Models\LegalEntity;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Log;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;

class LegalEntityConnectionComponent extends Component
{
    /**
     * The ID of the legal entity.
     *
     * @var ?int
     */
    public ?int $legalEntityId = null;

    /**
     * The ID of the connection.
     *
     * @var ?int
     */
    public ?int $connectionId = null;

    /**
     * Controls the visibility of the signature modal.
     *
     * @var bool
     */
    public bool $showSignatureModal = false;

    /**
     * Form data for the connection.
     *
     * @var array
     */
    public array $form = [];

    /**
     * The legal entity model instance.
     *
     * @var ?LegalEntity
     */
    protected ?LegalEntity $legalEntity = null;

    /**
     * The connection model instance.
     *
     * @var ?Connection
     */
    protected ?Connection $connection = null;

    public function mount(LegalEntity $legalEntity, ?Connection $connection=null)
    {
        $this->legalEntity = $legalEntity ?? request()->route('legalEntity');

        $this->legalEntityId = $this->legalEntity?->id;

        if ($connection) {
            $this->connection = $connection?->load([
                'legalEntity',
                'client',
            ]);

            $this->connectionId = $connection->id;
        }
    }

    /**
     * Sets the connection model instance if not already set.
     *
     * @return void
     */
    protected function setConnection(): void
    {
        if (!isset($this->connection)) {
            $this->connection ??= Connection::find($this->connectionId);
        }
    }

    /**
     * Sets the legal entity model instance if not already set.
     *
     * @return void
     */
    protected function setLegalEntity(): void
    {
        if (!isset($this->legalEntity)) {
            $this->legalEntity ??= LegalEntity::find($this->legalEntityId);
        }
    }

    public function sign()
    {
        $this->showSignatureModal = false;

        session()->flash('success', 'Зв\'язок успішно встановлений!');

        return redirect()->route('legal-entity-connection.show', [
            'legalEntity' => $this->legalEntity ?? 1,
            'id' => 'conn-13-1312qe11'
        ]);
    }

     /**
     * Synchronize a single client's details from eHealth.
     *
     * @param  string  $clientUuid  The UUID of the client to synchronize
     *
     * @return bool  Returns true if synchronization was successful, false otherwise
     *
     * @throws EHealthResponseException  If eHealth API returns a server error
     * @throws EHealthValidationException  If eHealth API returns a validation error
     */
    protected function syncSingleClient(string $clientUuid): bool
    {
        try {
            $response = EHealth::connection()->getClientDetails(clientId: $clientUuid);

            $clientData = $response->validate();
        } catch (EHealthResponseException $err) {
            Log::channel('e_health_errors')->error(self::class . ':syncConnections', ['error' => $err->getDetails()]);
            session()->flash('error', __('errors.ehealth.messages.server_error'));

            return false;
        } catch (EHealthValidationException $err) {
            Log::channel('e_health_errors')->error(self::class . ':syncConnections', ['error' => $err->getDetails()]);

            session()->flash('error', __('errors.ehealth.messages.validation_error'));

            return false;
        }

        $client = Repository::legalEntity()->syncClient($clientData);

        if (!$client) {
            return false;
        }

        $client->refresh();

        return true;
    }
}
