<?php

declare(strict_types=1);

namespace App\Livewire\LegalEntity\Connections;

use Livewire\Component;
use App\Models\Connection;
use App\Models\LegalEntity;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Livewire\Features\SupportRedirects\Redirector;
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
     * Rehydrates the protected legalEntity/connection properties on every request.
     *
     * Livewire does not rehydrate protected typed properties on subsequent requests
     * (e.g. action calls), only on the initial mount().
     *
     * @return void
     */
    public function boot(): void
    {
        // Sets the connection model instance if not already set.
        if (!isset($this->connection) && $this->connectionId !== null) {
            $this->connection ??= Connection::find($this->connectionId);
        }

        // Sets the legal entity model instance if not already set.
        if (!isset($this->legalEntity) && $this->legalEntityId !== null) {
            $this->legalEntity ??= LegalEntity::find($this->legalEntityId);
        }
    }

    /**
     * Updates the secret for a connection in eHealth and synchronizes it with the local database.
     *
     * @param  Connection  $connection  The connection model instance to update The new redirect URI to set for the connection
     *
     * @return void
     *
     * @throws EHealthResponseException  If eHealth API returns a server error
     * @throws EHealthValidationException  If eHealth API returns a validation error
     */
    public function refreshSecret(Connection $connection): void
    {
        try {
            $response = EHealth::connection()->refreshConnectionToken($connection->legalEntity->uuid, $connection->uuid);

            $connectionData = $response->validate();
        } catch (EHealthResponseException $err) {
            Log::channel('e_health_errors')->error(self::class . ':syncConnections', ['error' => $err->getDetails()]);
            session()->flash('error', __('errors.ehealth.messages.server_error'));

            return;
        } catch (EHealthValidationException $err) {
            Log::channel('e_health_errors')->error(self::class . ':syncConnections', ['error' => $err->getDetails()]);

            session()->flash('error', __('errors.ehealth.messages.validation_error'));

            return;
        }

        if (!Repository::legalEntity()->syncConnectionSecret($connection, $connectionData['secret'])) {
            return;
        }

        $connection->refresh();

        session()->flash('success', __('legal-entity-connection.secret_updated_title'));
    }

    /**
     * Updates the redirect URI for a connection in eHealth and synchronizes it with the local database.
     *
     * @param  Connection  $connection  The connection model instance to update
     * @param  string  $redirectUri  The new redirect URI to set for the connection
     *
     * @return void
     *
     * @throws EHealthResponseException  If eHealth API returns a server error
     * @throws EHealthValidationException  If eHealth API returns a validation error
     */
    public function update(Connection $connection, string $redirectUri): void
    {
        try {
            $response = EHealth::connection()->updateConnectionRedirectUri($connection->legalEntity->uuid, $connection->uuid, $redirectUri);

            $connectionData = $response->validate();
        } catch (EHealthResponseException $err) {
            Log::channel('e_health_errors')->error(self::class . ':syncConnections', ['error' => $err->getDetails()]);
            session()->flash('error', __('errors.ehealth.messages.server_error'));

            return;
        } catch (EHealthValidationException $err) {
            Log::channel('e_health_errors')->error(self::class . ':syncConnections', ['error' => $err->getDetails()]);

            session()->flash('error', __('errors.ehealth.messages.validation_error'));

            return;
        }

        if (!Repository::legalEntity()->syncRedirectUri($connection, $connectionData['redirect_uri'])) {
            return;
        }

        $connection->refresh();

        session()->flash('success', __('legal-entity-connection.callback_updated_title'));
    }

     /**
     * Deletes a connection in eHealth and synchronizes it with the local database.
     *
     * @param  Connection  $connection  The connection model instance to delete
     *
     * @return RedirectResponse|Redirector|null
     *
     * @throws EHealthResponseException  If eHealth API returns a server error
     * @throws EHealthValidationException  If eHealth API returns a validation error
     */
    public function deleteConnection(Connection $connection): RedirectResponse|Redirector|null
    {
        $clientUuid = $connection->legalEntity->uuid;

        try {
            $response = EHealth::connection()->deleteConnection($clientUuid, $connection->uuid);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() > 299) {
                throw new EHealthResponseException($response);
            }
        } catch (EHealthResponseException $err) {
            Log::channel('e_health_errors')->error(self::class . ':syncConnections', ['error' => $err->getDetails()]);
            session()->flash('error', __('errors.ehealth.messages.server_error'));

            return null;
        } catch (EHealthValidationException $err) {
            Log::channel('e_health_errors')->error(self::class . ':syncConnections', ['error' => $err->getDetails()]);

            session()->flash('error', __('errors.ehealth.messages.validation_error'));

            return null;
        }

        $syncQuery = [
            'page' => 1,
            'page_size' => config('ehealth.api.page_size_le_connections_max')
        ];

        try {
             $response = EHealth::connection()->getClientConnections(clientId: $clientUuid, query: $syncQuery);

             $connectionsData = $response->validate();
        } catch (EHealthResponseException $err) {
            Log::channel('e_health_errors')->error(self::class . ':syncConnections', ['error' => $err->getDetails()]);
            session()->flash('error', __('errors.ehealth.messages.server_error'));

            return null;
        } catch (EHealthValidationException $err) {
            Log::channel('e_health_errors')->error(self::class . ':syncConnections', ['error' => $err->getDetails()]);

            session()->flash('error', __('errors.ehealth.messages.validation_error'));

            return null;
        }

        // If termination was successful, the connection uuid should no longer exist in the eHealth system.
        $isSuccessfulTermination = !in_array($connection->uuid, array_column($connectionsData, 'uuid'), true);

        if (!$isSuccessfulTermination || !Repository::legalEntity()->syncConnectionDelete($connection)) {
            return null;
        } else {
            $connection->refresh();
        }

        return $isSuccessfulTermination
            ? Redirect::route('connection.index', [legalEntity()])->with('success', __('legal-entity-connection.terminate_success')) ?? null
            : Redirect::route('connection.index', [legalEntity()])->with('error', __('legal-entity-connection.sync.error.terminate')) ?? null;
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
