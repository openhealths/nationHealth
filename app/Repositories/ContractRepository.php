<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Classes\eHealth\Api\Contract as ContractMapper;
use App\Models\Contracts\Contract;
use App\Models\Employee\Employee;

class ContractRepository
{
    /**
     * Saves or updates a contract based on data received from E-Health API.
     */
    public function saveFromEHealth(array $eHealthData): Contract
    {
        $mapper = app(ContractMapper::class);
        $attributes = $mapper->mapCreate($eHealthData);

        unset($attributes['id']);

        $attributes['legal_entity_id'] = legalEntity()->id;

        $attributes['contractor_legal_entity_id'] = $eHealthData['contractor_legal_entity']['id']
            ?? $eHealthData['contractor_legal_entity']['uuid']
            ?? $eHealthData['contractor_legal_entity_id']
            ?? legalEntity()->uuid;

        $attributes['contractor_owner_id'] = $eHealthData['contractor_owner']['id']
            ?? $eHealthData['contractor_owner']['uuid']
            ?? $eHealthData['contractor_owner_id']
            ?? Employee::activeOwners(legalEntity()->id)->value('uuid');

        return Contract::updateOrCreate(
            ['uuid' => $attributes['uuid']],
            $attributes
        );
    }
}
