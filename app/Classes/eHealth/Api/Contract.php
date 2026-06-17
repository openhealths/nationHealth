<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use GuzzleHttp\Promise\PromiseInterface;
use App\Exceptions\EHealth\EHealthConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Contract extends EHealthRequest
{
    /**
     * Base URL of the resource
     */
    protected const string URL = '/api/contracts';

    /**
     * Maps eHealth API contract response fields to local database columns.
     *
     * @param  array  $data  Raw data from eHealth API
     * @return array
     */
    public function mapCreate(array $data): array
    {
        return [
            'uuid' => $data['id'] ?? $data['uuid'],
            'contract_number' => $data['contract_number'] ?? null,
            'status' => $data['status'] ?? 'ACTIVE',
            'type' => $data['type'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'contractor_base' => $data['contractor_base'] ?? null,
            'contractor_payment_details' => $data['contractor_payment_details'] ?? [],
            'contractor_rmsp_amount' => $data['contractor_rmsp_amount'] ?? null,
            'contractor_divisions' => $data['contractor_divisions'] ?? [],
            'external_contractor_flag' => $data['external_contractor_flag'] ?? false,
            'external_contractors' => $data['external_contractors'] ?? [],
            'nhs_signer_id' => $data['nhs_signer']['id'] ?? $data['nhs_signer_id'] ?? null,
            'nhs_legal_entity_id' => $data['nhs_legal_entity']['id'] ?? $data['nhs_legal_entity_id'] ?? null,
            'nhs_signer_base' => $data['nhs_signer_base'] ?? null,
            'nhs_payment_method' => $data['nhs_payment_method'] ?? null,
            'nhs_contract_price' => $data['nhs_contract_price'] ?? null,
            'nhs_signed_date' => $data['nhs_signed_date'] ?? null,
            'id_form' => $data['id_form'] ?? null,
            'issue_city' => $data['issue_city'] ?? null,
            'medical_programs' => $data['medical_programs'] ?? [],
            'inserted_at' => $data['inserted_at'] ?? now(),
            'data' => $data,
        ];
    }

    /**
     * Get the details of the signed contract by ID
     *
     * @param  string  $id  contract UUID
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException
     */
    public function getDetails(string $id): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL . '/' . $id);
    }

    /**
     * Get a list of contracts with validation logic.
     *
     * @param  array  $queryParams
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException
     */
    public function getMany(array $queryParams = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateMany(...));
        $this->setDefaultPageSize();

        return $this->get(self::URL, $queryParams);
    }

    /**
     * Helper to map API properties to Database columns (e.g. id -> uuid)
     */
    public static function replaceEHealthPropNames(array $data): array
    {
        $replaced = [];
        foreach ($data as $name => $value) {
            if ($name === 'id') {
                $replaced['uuid'] = $value;
            } else {
                $replaced[$name] = $value;
            }
        }

        return $replaced;
    }

    /**
     * Internal validator for contract list response.
     *
     * @param  EHealthResponse  $response
     * @return array
     * @throws ValidationException
     * @throws EHealthResponseException
     */
    protected function validateMany(EHealthResponse $response): array
    {
        // 1. Check for API-level errors
        if ($response->failed()) {
            $error = $response->getError();
            Log::channel('e_health_errors')->error('eHealth Contract API Failure', ['error' => $error]);
            throw new EHealthResponseException($error['message'] ?? 'Contract API Error');
        }

        // 2. Transform Data (id -> uuid)
        $transformedData = [];
        foreach ($response->getData() as $item) {
            $transformedData[] = self::replaceEHealthPropNames($item);
        }

        // 3. Validate Structure
        $validator = Validator::make($transformedData, [
            '*.uuid' => 'required|uuid',
            '*.status' => 'required|string',
            '*.contract_number' => 'required|string',
            '*.contractor_legal_entity_id' => 'sometimes|uuid',
            '*.contractor_owner_id' => 'sometimes|uuid',
            '*.nhs_signer_id' => 'sometimes|uuid',
            '*.edrpou' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            $errorMsg = 'EHealth Contract (getMany) validation failed: ' . implode(', ', $validator->errors()->all());
            Log::channel('e_health_errors')->error($errorMsg);
            throw ValidationException::withMessages(['ehealth_error' => $errorMsg]);
        }

        return $validator->validated();
    }
}
