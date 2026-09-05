<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Arr;
use App\Models\Client;
use App\Models\Connection;
use App\Models\LegalEntity;

class ConnectionRepository
{
    /**
     * @param  object  $model
     * @param  array  $connections
     * @return void
     */
    public function syncConnection(array $connections): void
    {
        if (empty($connections)) {
            return;
        }

        foreach ($connections as $connectionData) {
            $legalEntityUuid = Arr::pull($connectionData, 'client_uuid')[0] ?? null;
            $secret = Arr::pull($connectionData, 'secret')[0] ?? null;
            $legalEntity = LegalEntity::where('uuid', $legalEntityUuid)->first();

            if (!$legalEntity) {
                continue;
            }

            $connection = Connection::updateOrCreate(
                [
                'consumer_uuid' => $connectionData['consumer_uuid'],
                'legal_entity_id' => $legalEntity->id,
                'uuid' => $connectionData['uuid']
            ],
                $connectionData
            );

            $connection->legalEntity()->associate($legalEntity);
            $connection->save($connectionData);

            if ($secret) {
                $connection->legalEntity->update(['client_secret' => $secret]);
            }
        }
    }

    /**
     * @param  object  $model
     * @param  array  $clientData
     * @return void
     */
    public function syncClient(array $clientData): void
    {
        if (empty($clientData)) {
            return;
        }


            $client = Client::updateOrCreate(
                [
                'connectionable_type' => get_class($model),
                'connectionable_id' => $model->id,
                'uuid' => $clientData['uuid']
            ],
                $clientData
            );

            $client->save($clientData);

    }
}
