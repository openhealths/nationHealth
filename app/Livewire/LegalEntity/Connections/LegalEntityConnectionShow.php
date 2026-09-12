<?php

declare(strict_types=1);

namespace App\Livewire\LegalEntity\Connections;

use Exception;
use App\Models\Connection;
use Livewire\Attributes\Title;
use App\Classes\eHealth\EHealth;
use App\Repositories\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;

class LegalEntityConnectionShow extends LegalEntityConnectionComponent
{
    /**
     * Synchronize current connection with stored one on the eHealths side
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
         * On the first render, mount() runs and assigns $this->legalEntity or $this->connection.
         * On subsequent requests (e.g., when clicking synchronize button), Livewire does NOT run mount() again
         * and does NOT rehydrate protected typed properties.
         * Code below allows to ensure that property is set before use.
         */
        $this->setLegalEntity();
        $this->setConnection();

        $clientUuid = $this->connection->clientUuid ?? null;

        // Connection synchronization
        try {
            $response = EHealth::connection()->getConnectionDetails(clientId: $clientUuid, connectionId: $this->connection->uuid);

            $connection = $response->validate();
        } catch (EHealthResponseException $err) {
            Log::channel('e_health_errors')->error(self::class . ':syncConnections', ['error' => $err->getDetails()]);
            session()->flash('error', __('errors.ehealth.messages.server_error'));

            return;
        } catch (EHealthValidationException $err) {
            Log::channel('e_health_errors')->error(self::class . ':syncConnections', ['error' => $err->getDetails()]);

            session()->flash('error', __('errors.ehealth.messages.validation_error'));

            return;
        }

        if (!Repository::legalEntity()->syncConnections([$connection], $this->legalEntity)) {
            return;
        }

        $this->connection->refresh();

        if (!$this->syncSingleClient($clientUuid)) {
            return;
        }

        session()->flash('success', __('legal-entity-connection.sync.success_one'));

        return;
    }

    #[Title('Деталі зв\'язку')]
    public function render()
    {
        return view('livewire.legal-entity.connection.connection-show', [
            'connection' => $this->connection,
        ]);
    }
}
