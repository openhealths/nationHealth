<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Classes\eHealth\Api\ContractRequest as ApiMapper;
use App\Models\Contracts\ContractRequest;

class ContractRequestRepository
{
    /**
     * Saves or updates a contract request from E-Health data (or local form data).
     *
     * @param  array  $eHealthData  The raw data payload.
     * @param  string  $type  The contract type (e.g., REIMBURSEMENT).
     * @return ContractRequest
     */
    public function saveFromEHealth(array $eHealthData, string $type): ContractRequest
    {
        /** @var ApiMapper $mapper */
        $mapper = app(ApiMapper::class);

        // 1. Map API attributes (basic mapping)
        $attributes = $mapper->mapCreate($eHealthData);

        // 2. Fix UUID: API returns 'id', local DB expects 'uuid'
        if (isset($eHealthData['id'])) {
            $attributes['uuid'] = $eHealthData['id'];
            unset($attributes['id']);
        }

        // 3. Set System/Local fields
        $attributes['contractor_legal_entity_id'] = legalEntity()->uuid;
        $attributes['type'] = strtoupper($type);

        // Fallback to legalEntity owner ONLY if input is missing. Never use auth()->id() (int).
        if (!isset($attributes['contractor_owner_id']) || empty($attributes['contractor_owner_id'])) {
            $attributes['contractor_owner_id'] = $eHealthData['contractor_owner_id']
                ?? $eHealthData['contractor_owner']['id']
                ?? legalEntity()->owner_id;
        }

        // 4. Handle JSON Data Column
        // We must sanitize 'data' to remove PHP Objects (like UploadedFile) that cannot be JSON encoded.
        $jsonSafeData = $eHealthData;

        // Remove file objects from the JSON payload to prevent "Array to string conversion" error
        if (isset($jsonSafeData['statute_md5'])) {
            unset($jsonSafeData['statute_md5']);
        }
        if (isset($jsonSafeData['additional_document_md5'])) {
            unset($jsonSafeData['additional_document_md5']);
        }

        $attributes['data'] = $jsonSafeData;

        // 5. Handle Payment Details (if present)
        if (isset($eHealthData['contractor_payment_details'])) {
            $attributes['contractor_payment_details'] = $eHealthData['contractor_payment_details'];
        }

        // 6. Handle Medical Programs
        if (isset($eHealthData['medical_programs'])) {
            $attributes['medical_programs'] = $eHealthData['medical_programs'];
        }

        // 7. Update or Create record
        return ContractRequest::updateOrCreate(
            ['uuid' => $attributes['uuid']],
            $attributes
        );
    }
}
