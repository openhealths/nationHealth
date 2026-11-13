<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Classes\eHealth\Api\ContractRequest as ApiMapper;
use App\Models\Contracts\ContractRequest;
use App\Models\Employee\Employee;
use App\Models\LegalEntity;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ContractRequestRepository
{
    /**
     * Saves or updates a contract request from E-Health data (or local form data).
     *
     * @param  array  $eHealthData  The raw data payload.
     * @param  string  $type  The contract type (e.g., REIMBURSEMENT).
     * @return ContractRequest
     */
    public function saveFromEHealth(array $eHealthData, string $type, ?LegalEntity $legalEntity = null, bool $details = false): ContractRequest
    {
        $legalEntity ??= legalEntity();
        $remoteEntityId = data_get($eHealthData, 'contractor_legal_entity.id')
            ?? (data_get($eHealthData, 'contractor_legal_entity.uuid') ?? ($eHealthData['contractor_legal_entity_id'] ?? null));
        $uuid = $eHealthData['id'] ?? $eHealthData['uuid'];
        $existing = ContractRequest::query()->where('uuid', $uuid)->first();

        // A response must never reassign a record owned by another facility.
        if (($remoteEntityId !== null && $remoteEntityId !== $legalEntity->uuid)
            || ($existing !== null && $existing->contractorLegalEntityId !== $legalEntity->uuid)) {
            throw ValidationException::withMessages(['ehealth_error' => __('contracts.response_context_mismatch')]);
        }

        $attributes = app(ApiMapper::class)->mapCreate($eHealthData);

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
        // Local drafts may contain upload objects; remote checksum strings remain part of the snapshot.
        foreach (['statute_md5', 'additional_document_md5'] as $field) {
            if (($attributes['data'][$field] ?? null) instanceof UploadedFile) {
                unset($attributes['data'][$field]);
            }
        }

        // A status transition without a reason clears the previous status's explanation.
        if (array_key_exists('status', $eHealthData) && !array_key_exists('status_reason', $eHealthData)
            && ($details || ($existing !== null && $existing->getRawOriginal('status') !== $eHealthData['status']))) {
            $attributes['status_reason'] = null;
            unset($attributes['data']['status_reason']);
        }

        $attributes['type'] = strtoupper($type);
        if (isset($eHealthData['sync_status'])) {
            $attributes['sync_status'] = $eHealthData['sync_status'];
        }

        $attributes['contractor_legal_entity_id'] = $legalEntity->uuid;
        if (empty($attributes['contractor_owner_id']) && $existing === null) {
            $attributes['contractor_owner_id'] = Employee::query()->activeOwners($legalEntity->id)->value('uuid');
        }

        return ContractRequest::updateOrCreate(['uuid' => $uuid], $attributes);
    }
}
