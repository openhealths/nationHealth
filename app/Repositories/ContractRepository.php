<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Classes\eHealth\Api\Contract as ContractMapper;
use App\Models\Contracts\Contract;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use Illuminate\Validation\ValidationException;

class ContractRepository
{
    /**
     * Saves or updates a contract based on data received from E-Health API.
     */
    public function saveFromEHealth(array $eHealthData, ?LegalEntity $legalEntity = null, bool $details = false): Contract
    {
        $legalEntity ??= legalEntity();
        $remoteEntityId = data_get($eHealthData, 'contractor_legal_entity.id')
            ?? (data_get($eHealthData, 'contractor_legal_entity.uuid') ?? ($eHealthData['contractor_legal_entity_id'] ?? null));
        $uuid = $eHealthData['id'] ?? $eHealthData['uuid'];
        $existing = Contract::query()->where('uuid', $uuid)->first();

        // A response must never reassign a record owned by another facility.
        if (($remoteEntityId !== null && $remoteEntityId !== $legalEntity->uuid)
            || ($existing !== null && $existing->legalEntityId !== $legalEntity->id)) {
            throw ValidationException::withMessages(['ehealth_error' => __('contracts.response_context_mismatch')]);
        }

        $attributes = app(ContractMapper::class)->mapCreate($eHealthData);

        // List summaries omit full details. Only supplied fields may replace persisted values.
        $providedKeys = array_fill_keys(array_keys($eHealthData), true);
        $providedKeys['uuid'] = true;
        $providedKeys['data'] = true;
        foreach (['contractor_legal_entity', 'contractor_owner', 'nhs_signer', 'nhs_legal_entity'] as $relation) {
            if (array_key_exists($relation, $eHealthData)) {
                $providedKeys[$relation . '_id'] = true;
            }
        }
        if (isset($eHealthData['contract_type'])) {
            $providedKeys['type'] = true;
        }
        if (!$details) {
            $attributes = array_intersect_key($attributes, $providedKeys);
        }
        $attributes['data'] = $details ? $eHealthData : array_replace($existing?->data ?? [], $eHealthData);
        unset($attributes['data']['sync_status'], $attributes['data']['uuid'], $attributes['data']['type']);

        // A status transition without a reason clears the previous status's explanation.
        if (array_key_exists('status', $eHealthData) && !array_key_exists('status_reason', $eHealthData)
            && ($details || ($existing !== null && $existing->getRawOriginal('status') !== $eHealthData['status']))) {
            $attributes['status_reason'] = null;
            unset($attributes['data']['status_reason']);
        }

        if (isset($eHealthData['sync_status'])) {
            $attributes['sync_status'] = $eHealthData['sync_status'];
        }

        $attributes['legal_entity_id'] = $legalEntity->id;

        $attributes['contractor_legal_entity_id'] = $legalEntity->uuid;
        if (empty($attributes['contractor_owner_id']) && $existing === null) {
            $attributes['contractor_owner_id'] = Employee::query()->activeOwners($legalEntity->id)->value('uuid');
        }

        return Contract::updateOrCreate(['uuid' => $uuid], $attributes);
    }
}
