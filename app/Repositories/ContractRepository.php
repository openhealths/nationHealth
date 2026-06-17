<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Classes\eHealth\Api\Contract as ContractMapper; // ФІКС: Правильний мапер
use App\Models\Contracts\Contract;

class ContractRepository
{
    /**
     * Saves or updates a contract based on data received from E-Health API.
     */
    public function saveFromEHealth(array $eHealthData): Contract
    {
        // 1. Map API attributes using the Contract mapper
        $mapper = app(ContractMapper::class);
        $attributes = $mapper->mapCreate($eHealthData);

        // 2. API returns 'id'; mapCreate already sets 'uuid', ensure no stray 'id' key
        unset($attributes['id']);

        // 3. Local context — always set legal_entity_id and contractor references
        $attributes['legal_entity_id'] = legalEntity()->id;

        $attributes['contractor_legal_entity_id'] = $eHealthData['contractor_legal_entity']['id']
            ?? $eHealthData['contractor_legal_entity_id']
            ?? legalEntity()->uuid;

        $attributes['contractor_owner_id'] = $eHealthData['contractor_owner']['id']
            ?? $eHealthData['contractor_owner_id']
            ?? null;

        // 4. Persist and return
        return Contract::updateOrCreate(
            ['uuid' => $attributes['uuid']],
            $attributes
        );
    }
}
