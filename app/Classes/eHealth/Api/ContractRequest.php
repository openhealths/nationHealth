<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContractRequest extends EHealthRequest
{
    /**
     * RETURN the api.
     * We use the relative path without the initial slash so that Guzzle correctly glues it to the Base URI.
     */
    protected const string URL = '/api/contract_requests';

    /**
     * Gets a list of contract requests from E-Health.
     *
     * ESOZ API-005-012-0007: GET /api/contract_requests/{contract_type}
     */
    public function getMany(string $contractType, $query = null): PromiseInterface|EHealthResponse
    {
        $this->setValidator(fn (EHealthResponse $response): array => $this->validateMany($response, $contractType, $query['contractor_legal_entity_id'] ?? null, (int) ($query['page'] ?? 1)));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'] ?? [], $query ?? []);
        $url = self::URL . '/' . strtolower($contractType);

        return $this->get($url, $mergedQuery);
    }

    /**
     * Maps the API response data to the Database Model structure.
     *
     * @param  array  $data  Raw data from eHealth API
     * @return array
     */
    public function mapCreate(array $data): array
    {
        // Helper function to safely extract ID/UUID from array or string
        $extractId = static function ($value) {
            if (is_array($value)) {
                return $value['id'] ?? ($value['uuid'] ?? null);
            }

            return $value;
        };

        return [
            // Primary identifiers (API returns 'id', we store as 'uuid')
            'uuid' => $data['id'] ?? $data['uuid'],

            // Contractor Relations (can be object or ID)
            'contractor_legal_entity_id' => $extractId($data['contractor_legal_entity'] ?? null)
                ?? ($data['contractor_legal_entity_id'] ?? null),

            'contractor_owner_id' => $extractId($data['contractor_owner'] ?? null)
                ?? ($data['contractor_owner_id'] ?? null),

            // Core Data
            'contract_number' => $data['contract_number'] ?? null,
            'status' => $data['status'],
            'status_reason' => $data['status_reason'] ?? null,
            'type' => isset($data['type'])
                ? strtoupper((string) $data['type'])
                : (isset($data['contract_type']) ? strtoupper((string) $data['contract_type']) : null),
            'id_form' => $data['id_form'] ?? null,

            // Dates — never invent "today" when eHealth omits inserted_at
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'inserted_at' => !empty($data['inserted_at']) ? $data['inserted_at'] : null,
            'updated_at' => !empty($data['updated_at']) ? $data['updated_at'] : null,

            // Signer details and addresses
            'contractor_base' => $data['contractor_base'] ?? null,
            'contractor_payment_details' => $data['contractor_payment_details'] ?? [],
            'contractor_rmsp_amount' => $data['contractor_rmsp_amount'] ?? null,

            // NHS fields (nullable for NEW requests)
            'nhs_signer_id' => $extractId($data['nhs_signer'] ?? null) ?? ($data['nhs_signer_id'] ?? null),
            'nhs_legal_entity_id' => $extractId($data['nhs_legal_entity'] ?? null) ?? ($data['nhs_legal_entity_id'] ?? null),
            'nhs_signer_base' => $data['nhs_signer_base'] ?? null,
            'nhs_payment_method' => $data['nhs_payment_method'] ?? null,
            'nhs_contract_price' => $data['nhs_contract_price'] ?? null,
            'issue_city' => $data['issue_city'] ?? null,

            // Relations & Meta
            'contract_id' => $data['contract_id'] ?? null, // Link to active contract
            'previous_request_id' => $data['previous_request_id'] ?? null,
            'medical_programs' => $data['medical_programs'] ?? [],
            'contractor_divisions' => $data['contractor_divisions'] ?? [],
            'contractor_employee_divisions' => $data['contractor_employee_divisions'] ?? [],
            'external_contractors' => $data['external_contractors'] ?? [],
            'external_contractor_flag' => $data['external_contractor_flag'] ?? false,
            'nhs_signed_date' => $data['nhs_signed_date'] ?? null,
            'printout_content' => $data['printout_content'] ?? null,
            'parent_contract_id' => $data['parent_contract_id'] ?? null,
            'assignee_id' => $data['assignee_id'] ?? null,

            // Store full dump for debugging or extra fields
            'data' => $data,
        ];
    }

    /**
     * Get details of a contract request
     *
     * @throws EHealthConnectionException
     */
    public function getDetails(string $contractType, string $uuid): PromiseInterface|EHealthResponse
    {
        $this->setValidator(fn (EHealthResponse $response): array => $this->validateDetails($response, $contractType, $uuid));

        return $this->get(self::URL . '/' . strtolower($contractType) . '/' . $uuid);
    }

    /**
     * Initializes a contract request (Step 1).
     * Corresponds to: POST /api/contract_requests/{contract_type}
     */
    public function initialize(string $contractType): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateInitialize(...));

        $url = self::URL . '/' . $contractType;

        return $this->post($url);
    }

    /**
     * Sends the signed contract request (Step 2).
     * Corresponds to: POST /api/contract_requests/{contract_type}/{id}
     */
    public function create(string $uuid, string $contractType, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateCreate(...));

        try {
            // Primary route for contract request creation in MIS docs.
            return $this->post(self::URL . '/' . $contractType . '/' . $uuid, $payload);
        } catch (EHealthResponseException $exception) {
            // If typed route is missing in a specific environment, fallback to id-only route.
            if ($exception->response->status() !== 404) {
                throw $exception;
            }

            try {
                return $this->post(self::URL . '/' . $uuid, $payload);
            } catch (EHealthResponseException $fallbackException) {
                // Last fallback for legacy deployments expecting PUT.
                if ($fallbackException->response->status() !== 404) {
                    throw $fallbackException;
                }

                return $this->put(self::URL . '/' . $contractType . '/' . $uuid, $payload);
            }
        }
    }

    /**
     * Assigns a contract request to an NHS employee.
     * Corresponds to: PATCH /api/contract_requests/{id}/actions/assign
     *
     * @param  string  $uuid  The UUID of the contract request.
     * @param  array  $payload  Payload with 'assignee_id'.
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException
     */
    public function assign(string $uuid, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateAssign(...));

        return $this->patch(self::URL . '/' . $uuid . '/actions/assign', $payload);
    }

    /**
     * Approves a contract request (NHS action).
     * Corresponds to: PATCH /api/contract_requests/{id}/actions/approve
     *
     * @param  string  $uuid  The UUID of the contract request.
     * @param  array  $payload  Payload with 'nhs_signer_id', etc.
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException
     */
    public function approve(string $uuid, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateApprove(...));

        return $this->patch(self::URL . '/' . $uuid . '/actions/approve', $payload);
    }

    /**
     * Declines a contract request (NHS action).
     * Corresponds to: PATCH /api/contract_requests/{id}/actions/decline
     *
     * @param  string  $uuid  The UUID of the contract request.
     * @param  array  $payload  Payload with 'status_reason'.
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function decline(string $uuid, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDecline(...));

        return $this->patch(self::URL . '/' . $uuid . '/actions/decline', $payload);
    }

    /**
     * Approves a contract request (MSP action).
     * Corresponds to: PATCH /api/contract_requests/{id}/actions/approve_msp
     *
     * @param  string  $uuid  The UUID of the contract request.
     * @param  string  $contractType  'capitation' or 'reimbursement'.
     * @param  array  $payload  Signed payload.
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function approveMsp(string $uuid, string $contractType, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateApproveMsp(...));

        try {
            return $this->patch(self::URL . '/' . $uuid . '/actions/approve_msp', $payload);
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() !== 404) {
                throw $exception;
            }

            return $this->patch(self::URL . '/' . $contractType . '/' . $uuid . '/actions/approve_msp', $payload);
        }
    }

    /**
     * Terminates a contract.
     * Corresponds to: PATCH /api/contract_requests/{id}/actions/terminate
     *
     * @param  string  $uuid  The UUID of the contract request.
     * @param  string  $contractType  'capitation' or 'reimbursement'.
     * @param  array  $payload  Signed payload with reason.
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function terminate(string $uuid, string $contractType, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateTerminate(...));

        try {
            return $this->patch(self::URL . '/' . $uuid . '/actions/terminate', $payload);
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() !== 404) {
                throw $exception;
            }

            return $this->patch(self::URL . '/' . $contractType . '/' . $uuid . '/actions/terminate', $payload);
        }
    }

    /**
     * Gets the printable HTML content of a contract.
     * Corresponds to: GET /api/contract_requests/{id}/printout_content
     *
     * @param  string  $uuid  The UUID of the contract request.
     * @param  string  $contractType  'capitation' or 'reimbursement'.
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    /**
     * Gets partially signed contract request content (PKCS7) for MSP co-signing.
     * Corresponds to: GET /api/contract_requests/{id}/signed_content
     *
     * @throws EHealthConnectionException|EHealthResponseException
     */
    public function getSignedContent(string $uuid, string $contractType): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateSignedContent(...));

        try {
            return $this->get(self::URL . '/' . $uuid . '/signed_content');
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() !== 404) {
                throw $exception;
            }

            return $this->get(self::URL . '/' . $contractType . '/' . $uuid . '/signed_content');
        }
    }

    protected function validateSignedContent(EHealthResponse $response): array
    {
        return Validator::make($response->json() ?? [], [
            'data' => 'required|array',
            'data.content' => 'required_without:data.signed_content|string',
            'data.signed_content' => 'required_without:data.content|string',
        ])->validate()['data'];
    }

    public function getPrintoutContent(string $uuid, string $contractType): PromiseInterface|EHealthResponse
    {
        $this->setValidator(fn (EHealthResponse $response): array => $this->validatePrintoutContent($response, $uuid));

        return $this->get(self::URL . '/' . strtolower($contractType) . '/' . $uuid . '/printout_content');
    }

    /**
     * Signs a contract (NHS action).
     * Corresponds to: PATCH /api/contract_requests/{id}/actions/sign_nhs
     *
     * @param  string  $uuid  The UUID of the contract request.
     * @param  array  $payload  Signed payload.
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function signNhs(string $uuid, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateSignNhs(...));

        return $this->patch(self::URL . '/' . $uuid . '/actions/sign_nhs', $payload);
    }

    /**
     * Signs a contract (MSP action).
     * Corresponds to: PATCH /api/contract_requests/{id}/actions/sign_msp
     *
     * @param  string  $uuid  The UUID of the contract request.
     * @param  string  $contractType  'capitation' or 'reimbursement'.
     * @param  array  $payload  Signed payload.
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function signMsp(string $uuid, string $contractType, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateSignMsp(...));

        try {
            return $this->patch(self::URL . '/' . $uuid . '/actions/sign_msp', $payload);
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() !== 404) {
                throw $exception;
            }

            return $this->patch(self::URL . '/' . $contractType . '/' . $uuid . '/actions/sign_msp', $payload);
        }
    }

    /**
     * Validates the response for getMany().
     */
    /** Validate the envelope before treating an empty collection as a successful sync. */
    protected function validateMany(EHealthResponse $response, ?string $contractType = null, ?string $legalEntityId = null, ?int $page = null): array
    {
        Validator::make(['http_status' => $response->status(), 'body' => $response->json()], [
            'http_status' => 'required|in:200',
            'body' => 'required|array',
        ])->validate();

        Validator::make($response->json() ?? [], [
            'data' => 'present|array|list',
            'data.*' => 'required|array',
            'paging' => 'required|array',
            'paging.page_number' => 'required|integer:strict|min:1',
            'paging.page_size' => 'required|integer:strict|min:1|max:300',
            'paging.total_entries' => 'required|integer:strict|min:0',
            'paging.total_pages' => 'required|integer:strict|min:0',
        ])->validate();

        $data = $response->getData();
        foreach ($data as $item) {
            $this->validateContext($item, $contractType, null, $legalEntityId);
        }
        if ($page !== null) {
            Validator::make($response->getPaging(), ['page_number' => [Rule::in([$page])]])->validate();
        }

        return array_map(fn (array $item): array => $this->validateRecord($item, false), $data);
    }

    protected function validateDetails(EHealthResponse $response, ?string $contractType = null, ?string $id = null): array
    {
        Validator::make(['http_status' => $response->status(), 'body' => $response->json()], [
            'http_status' => 'required|in:200',
            'body' => 'required|array',
        ])->validate();

        Validator::make($response->json() ?? [], [
            'data' => 'required|array',
            'urgent' => 'present|array',
            'urgent.documents' => 'sometimes|array|list',
            'urgent.documents.*' => 'required|array',
            'urgent.documents.*.type' => 'required|string',
            'urgent.documents.*.url' => 'required|string|url:https',
        ])->validate();

        $this->validateContext($response->getData(), $contractType, $id);

        $data = $this->validateRecord($response->getData(), true);

        // The document links are an envelope sibling; keep them separate from signed resource fields.
        return array_replace($data, ['urgent' => $response->getUrgent()]);
    }

    protected function validateContext(array $data, ?string $contractType, ?string $id = null, ?string $legalEntityId = null): void
    {
        $rules = [];
        if ($contractType !== null) {
            $rules['contract_type'] = [Rule::in([strtoupper($contractType)])];
        }
        if ($id !== null) {
            $rules['id'] = [Rule::in([$id])];
        }
        if ($legalEntityId !== null) {
            $rules['contractor_legal_entity_id'] = [Rule::in([$legalEntityId])];
        }
        Validator::make($data, $rules)->validate();
    }

    /** PREPROD General MIS 9.15.9: list summaries and full records have different required fields. */
    protected function validateRecord(array $data, bool $details): array
    {
        $rules = [
            'id' => 'required|uuid',
            'contract_type' => 'required|string|in:CAPITATION,REIMBURSEMENT',
            'status' => 'required|string|in:NEW,IN_PROCESS,APPROVED,DECLINED,TERMINATED,PENDING_NHS_SIGN,NHS_SIGNED,SIGNED',
            'status_reason' => 'sometimes|string',
            'assignee_id' => 'sometimes|string|uuid',
            'reason' => 'sometimes|string',
            'printout_content' => 'sometimes|string',
            'contract_number' => 'sometimes|string',
            'contractor_base' => 'required|string',
            'contractor_owner' => ($details ? 'required' : 'sometimes') . '|array',
            'contractor_owner.id' => 'required_with:contractor_owner|uuid',
            'contractor_owner.party' => 'sometimes|array',
            'contractor_owner.party.first_name' => 'required_with:contractor_owner.party|string',
            'contractor_owner.party.last_name' => 'required_with:contractor_owner.party|string',
            'contractor_owner.party.second_name' => 'sometimes|string',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d',
            'inserted_at' => 'required|string|date',
            'updated_at' => 'required|string|date',
            'id_form' => 'required|string',
            'contractor_divisions' => 'sometimes|array|list',
            'contractor_divisions.*' => 'required|array',
            'contractor_divisions.*.id' => 'required|uuid',
            'contractor_divisions.*.name' => 'required|string',
            'medical_programs' => 'present_if:contract_type,REIMBURSEMENT|array|list',
            'medical_programs.*' => 'required|string|uuid',
            'external_contractor_flag' => 'sometimes|boolean:strict',
            'external_contractors' => 'sometimes|array|list',
            'external_contractors.*' => 'required|array',
            'contractor_employee_divisions' => 'sometimes|array|list',
            'contractor_employee_divisions.*' => 'required|array',
            'nhs_signer' => 'sometimes|required|array',
            'nhs_signer.id' => 'required_with:nhs_signer|uuid',
            'nhs_legal_entity' => 'sometimes|required|array',
            'nhs_legal_entity.id' => 'required_with:nhs_legal_entity|uuid',
            'nhs_signer_id' => 'sometimes|uuid',
            'nhs_legal_entity_id' => 'sometimes|uuid',
            'nhs_signer_base' => 'sometimes|string',
            'nhs_payment_method' => 'sometimes|string|in:BACKWARD',
            'nhs_contract_price' => 'sometimes|numeric:strict',
            'nhs_signed_date' => 'sometimes|string|date',
            'issue_city' => 'sometimes|string',
            'parent_contract_id' => 'sometimes|uuid',
            'contract_id' => 'sometimes|uuid',
            'previous_request_id' => 'sometimes|uuid',
            'contractor_owner_id' => ($details ? 'sometimes' : 'required') . '|uuid',
            'contractor_legal_entity_id' => ($details ? 'sometimes' : 'required') . '|uuid',
        ];

        if ($details) {
            $rules += [
                'contractor_legal_entity' => 'required|array',
                'contractor_legal_entity.id' => 'required|uuid',
                'contractor_legal_entity.name' => 'required|string',
                'contractor_legal_entity.edrpou' => 'required|string',
                'contractor_legal_entity.addresses' => 'present|array|list',
                'contractor_legal_entity.addresses.*' => 'required|array',
                'contractor_payment_details' => 'required|array',
                'contractor_payment_details.bank_name' => 'required|string',
                'contractor_payment_details.payer_account' => 'required|string',
                'contractor_payment_details.MFO' => 'sometimes|string',
                'contractor_rmsp_amount' => 'required_if:contract_type,CAPITATION|numeric:strict',
            ];
            $rules['contractor_divisions'] = 'present_if:contract_type,CAPITATION|array|list';
            $rules['contractor_employee_divisions'] = 'present_if:contract_type,CAPITATION|array|list';
        }

        if ($details) {
            // Validate nested objects used by the detail partials before preserving the full wire payload.
            $rules += [
                'contractor_legal_entity.addresses.*.type' => 'sometimes|string',
                'contractor_legal_entity.addresses.*.country' => 'required_with:contractor_legal_entity.addresses.*|string',
                'contractor_legal_entity.addresses.*.area' => 'sometimes|string',
                'contractor_legal_entity.addresses.*.region' => 'sometimes|string',
                'contractor_legal_entity.addresses.*.settlement' => 'sometimes|string',
                'contractor_legal_entity.addresses.*.settlement_type' => 'sometimes|string',
                'contractor_legal_entity.addresses.*.settlement_id' => 'sometimes|string|uuid',
                'contractor_legal_entity.addresses.*.street_type' => 'sometimes|string',
                'contractor_legal_entity.addresses.*.street' => 'sometimes|string',
                'contractor_legal_entity.addresses.*.building' => 'sometimes|string',
                'contractor_legal_entity.addresses.*.apartment' => 'sometimes|string',
                'contractor_legal_entity.addresses.*.zip' => 'sometimes|string',
                'contractor_divisions.*.addresses' => 'present_with:contractor_divisions.*|array|list',
                'contractor_divisions.*.addresses.*' => 'array',
                'contractor_divisions.*.addresses.*.type' => 'sometimes|string',
                'contractor_divisions.*.addresses.*.country' => 'required_with:contractor_divisions.*.addresses.*|string',
                'contractor_divisions.*.addresses.*.area' => 'sometimes|string',
                'contractor_divisions.*.addresses.*.region' => 'sometimes|string',
                'contractor_divisions.*.addresses.*.settlement' => 'sometimes|string',
                'contractor_divisions.*.addresses.*.settlement_type' => 'sometimes|string',
                'contractor_divisions.*.addresses.*.settlement_id' => 'sometimes|string|uuid',
                'contractor_divisions.*.addresses.*.street_type' => 'sometimes|string',
                'contractor_divisions.*.addresses.*.street' => 'sometimes|string',
                'contractor_divisions.*.addresses.*.building' => 'sometimes|string',
                'contractor_divisions.*.addresses.*.apartment' => 'sometimes|string',
                'contractor_divisions.*.addresses.*.zip' => 'sometimes|string',
                'contractor_divisions.*.phones' => 'present_with:contractor_divisions.*|array|list',
                'contractor_divisions.*.phones.*' => 'array',
                'contractor_divisions.*.phones.*.type' => 'required_with:contractor_divisions.*.phones.*|string',
                'contractor_divisions.*.phones.*.number' => 'required_with:contractor_divisions.*.phones.*|string',
                'contractor_divisions.*.email' => 'required_with:contractor_divisions.*|string',
                'contractor_divisions.*.working_hours' => 'present_with:contractor_divisions.*|array',
                'contractor_divisions.*.mountain_group' => 'sometimes|boolean:strict',
                'contractor_employee_divisions.*.employee' => 'required_with:contractor_employee_divisions.*|array',
                'contractor_employee_divisions.*.employee.id' => 'required_with:contractor_employee_divisions.*.employee|string|uuid',
                'contractor_employee_divisions.*.employee.party' => 'required_with:contractor_employee_divisions.*.employee|array',
                'contractor_employee_divisions.*.employee.party.first_name' => 'required_with:contractor_employee_divisions.*.employee.party|string',
                'contractor_employee_divisions.*.employee.party.last_name' => 'required_with:contractor_employee_divisions.*.employee.party|string',
                'contractor_employee_divisions.*.employee.party.second_name' => 'sometimes|string',
                'contractor_employee_divisions.*.employee.speciality' => 'required_with:contractor_employee_divisions.*.employee|array',
                'contractor_employee_divisions.*.employee.speciality.speciality' => 'required_with:contractor_employee_divisions.*.employee.speciality|string',
                'contractor_employee_divisions.*.employee.speciality.speciality_officio' => 'required_with:contractor_employee_divisions.*.employee.speciality|boolean:strict',
                'contractor_employee_divisions.*.employee.speciality.level' => 'required_with:contractor_employee_divisions.*.employee.speciality|string',
                'contractor_employee_divisions.*.employee.speciality.qualification_type' => 'required_with:contractor_employee_divisions.*.employee.speciality|string',
                'contractor_employee_divisions.*.employee.speciality.attestation_name' => 'required_with:contractor_employee_divisions.*.employee.speciality|string',
                'contractor_employee_divisions.*.employee.speciality.attestation_date' => 'required_with:contractor_employee_divisions.*.employee.speciality|string',
                'contractor_employee_divisions.*.employee.speciality.valid_to_date' => 'sometimes|string',
                'contractor_employee_divisions.*.employee.speciality.certificate_number' => 'required_with:contractor_employee_divisions.*.employee.speciality|string',
                'contractor_employee_divisions.*.staff_units' => 'required_with:contractor_employee_divisions.*|numeric:strict',
                'contractor_employee_divisions.*.declaration_limit' => 'required_with:contractor_employee_divisions.*|numeric:strict',
                'contractor_employee_divisions.*.division_id' => 'required_with:contractor_employee_divisions.*|string|uuid',
                // Detail examples expand legal_entity_id into a legal_entity object.
                'external_contractors.*.legal_entity_id' => 'required_without:external_contractors.*.legal_entity|uuid',
                'external_contractors.*.legal_entity' => 'required_without:external_contractors.*.legal_entity_id|array',
                'external_contractors.*.legal_entity.id' => 'required_with:external_contractors.*.legal_entity|uuid',
                'external_contractors.*.legal_entity.name' => 'sometimes|string',
                'external_contractors.*.contract' => 'required_with:external_contractors.*|array',
                'external_contractors.*.contract.number' => 'required_with:external_contractors.*.contract|string',
                'external_contractors.*.contract.issued_at' => 'required_with:external_contractors.*.contract|string',
                'external_contractors.*.contract.expires_at' => 'required_with:external_contractors.*.contract|string',
                'external_contractors.*.divisions' => 'present_with:external_contractors.*|array|list',
                'external_contractors.*.divisions.*' => 'array',
                'external_contractors.*.divisions.*.id' => 'required_with:external_contractors.*.divisions.*|string|uuid',
                'external_contractors.*.divisions.*.medical_service' => 'required_with:external_contractors.*.divisions.*|string',
                'nhs_signer.party' => 'sometimes|array',
                'nhs_signer.party.first_name' => 'required_with:nhs_signer.party|string',
                'nhs_signer.party.last_name' => 'required_with:nhs_signer.party|string',
                'nhs_signer.party.second_name' => 'sometimes|string',
                'nhs_legal_entity.name' => 'required_with:nhs_legal_entity|string',
                'nhs_legal_entity.edrpou' => 'required_with:nhs_legal_entity|string',
                'nhs_legal_entity.addresses' => 'present_with:nhs_legal_entity|array|list',
                'nhs_legal_entity.addresses.*' => 'array',
                'nhs_legal_entity.addresses.*.type' => 'sometimes|string',
                'nhs_legal_entity.addresses.*.country' => 'required_with:nhs_legal_entity.addresses.*|string',
                'nhs_legal_entity.addresses.*.area' => 'sometimes|string',
                'nhs_legal_entity.addresses.*.region' => 'sometimes|string',
                'nhs_legal_entity.addresses.*.settlement' => 'sometimes|string',
                'nhs_legal_entity.addresses.*.settlement_type' => 'sometimes|string',
                'nhs_legal_entity.addresses.*.settlement_id' => 'sometimes|string|uuid',
                'nhs_legal_entity.addresses.*.street_type' => 'sometimes|string',
                'nhs_legal_entity.addresses.*.street' => 'sometimes|string',
                'nhs_legal_entity.addresses.*.building' => 'sometimes|string',
                'nhs_legal_entity.addresses.*.apartment' => 'sometimes|string',
                'nhs_legal_entity.addresses.*.zip' => 'sometimes|string',
            ];
        }

        if ($details) {
            foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
                $prefix = 'contractor_divisions.*.working_hours.' . $day;
                $rules[$prefix] = 'sometimes|array|list';
                $rules[$prefix . '.*'] = 'present|array|list';
                $rules[$prefix . '.*.*'] = [function (string $attribute, mixed $value, $fail) use ($day): void {
                    if (!is_string($value) && (!in_array($day, ['mon', 'tue', 'wed', 'thu', 'fri'], true)
                        || (!is_int($value) && !is_float($value)))) {
                        $fail(__('validation.string', ['attribute' => $attribute]));
                    }
                }];
            }
        }

        Validator::make($data, $rules)->validate();

        // Preserve wire keys in nested objects for the existing read-only views.
        return $data + ['uuid' => $data['id'], 'type' => $data['contract_type']];
    }

    /**
     * Validates the response for initializeRequest().
     */
    protected function validateInitialize(EHealthResponse $response): array
    {
        $transformedData = self::replaceEHealthPropNames($response->getData());

        $validator = Validator::make($transformedData, [
            'additional_document_url' => ['required', 'url'],
            'id' => ['required', 'uuid'],
            'statute_url' => ['required', 'url']
        ]);

        if ($validator->fails()) {
            $error = 'EHealth Contract (initializeRequest) validation failed: ' . implode(', ', $validator->errors()->all());
            Log::channel('e_health_errors')->error($error);
            throw ValidationException::withMessages(['ehealth_error' => $error]);
        }

        return $validator->validated();
    }

    /**
     * Validates the response for createSignedRequest().
     */
    protected function validateCreate(EHealthResponse $response): array
    {
        $transformedData = self::replaceEHealthPropNames($response->getData());

        $validator = Validator::make($transformedData, [
            'uuid' => 'required|uuid',
            'status' => 'required|string',
            'contract_number' => 'required|string',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d',
            'contractor_legal_entity' => 'required|array',
            'contractor_legal_entity.uuid' => 'required|uuid',
            'contractor_owner' => 'required|array',
            'contractor_owner.uuid' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            $error = 'EHealth Contract (createSignedRequest) validation failed: ' . implode(', ', $validator->errors()->all());
            Log::channel('e_health_errors')->error($error);
            throw ValidationException::withMessages(['ehealth_error' => $error]);
        }

        return $validator->validated();
    }

    /**
     * Validates the response for assign().
     */
    protected function validateAssign(EHealthResponse $response): array
    {
        $transformedData = self::replaceEHealthPropNames($response->getData());
        $validator = Validator::make($transformedData, [
            'uuid' => 'required|uuid',
            'status' => 'required|string|in:ASSIGNED',
            'assignee_id' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            $error = 'EHealth Contract (assign) validation failed: ' . implode(', ', $validator->errors()->all());
            Log::channel('e_health_errors')->error($error);
            throw ValidationException::withMessages(['ehealth_error' => $error]);
        }

        return $validator->validated();
    }

    /**
     * Validates the response for approve().
     */
    protected function validateApprove(EHealthResponse $response): array
    {
        $transformedData = self::replaceEHealthPropNames($response->getData());
        $validator = Validator::make($transformedData, [
            'uuid' => 'required|uuid',
            'status' => 'required|string|in:APPROVED',
            'nhs_signer_id' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            $error = 'EHealth Contract (approve) validation failed: ' . implode(', ', $validator->errors()->all());
            Log::channel('e_health_errors')->error($error);
            throw ValidationException::withMessages(['ehealth_error' => $error]);
        }

        return $validator->validated();
    }

    /**
     * Validates the response for decline().
     */
    protected function validateDecline(EHealthResponse $response): array
    {
        $transformedData = self::replaceEHealthPropNames($response->getData());
        $validator = Validator::make($transformedData, [
            'uuid' => 'required|uuid',
            'status' => 'required|string|in:DECLINED',
            'status_reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            $error = 'EHealth Contract (decline) validation failed: ' . implode(', ', $validator->errors()->all());
            Log::channel('e_health_errors')->error($error);
            throw ValidationException::withMessages(['ehealth_error' => $error]);
        }

        return $validator->validated();
    }

    /**
     * Validates the response for approveMsp().
     */
    protected function validateApproveMsp(EHealthResponse $response): array
    {
        $transformedData = self::replaceEHealthPropNames($response->getData());
        $validator = Validator::make($transformedData, [
            'uuid' => 'required|uuid',
            'status' => 'required|string|in:MSP_APPROVED',
        ]);

        if ($validator->fails()) {
            $error = 'EHealth Contract (approveMsp) validation failed: ' . implode(', ', $validator->errors()->all());
            Log::channel('e_health_errors')->error($error);
            throw ValidationException::withMessages(['ehealth_error' => $error]);
        }

        return $validator->validated();
    }

    /**
     * Validates the response for terminate().
     */
    protected function validateTerminate(EHealthResponse $response): array
    {
        $transformedData = self::replaceEHealthPropNames($response->getData());
        $validator = Validator::make($transformedData, [
            'uuid' => 'required|uuid',
            'status' => 'required|string|in:TERMINATED',
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            $error = 'EHealth Contract (terminate) validation failed: ' . implode(', ', $validator->errors()->all());
            Log::channel('e_health_errors')->error($error);
            throw ValidationException::withMessages(['ehealth_error' => $error]);
        }

        return $validator->validated();
    }

    /**
     * Validates the response for getPrintoutContent().
     */
    protected function validatePrintoutContent(EHealthResponse $response, ?string $id = null): array
    {
        Validator::make(['http_status' => $response->status(), 'body' => $response->json()], [
            'http_status' => 'required|in:200',
            'body' => 'required|array',
        ])->validate();

        return Validator::make($response->json() ?? [], [
            'data' => 'required|array',
            'data.id' => ['required', 'uuid', ...($id !== null ? [Rule::in([$id])] : [])],
            'data.printout_content' => 'required|string',
        ])->validate()['data'];
    }

    /**
     * Validates the response for signNhs().
     */
    protected function validateSignNhs(EHealthResponse $response): array
    {
        $transformedData = self::replaceEHealthPropNames($response->getData());
        $validator = Validator::make($transformedData, [
            'uuid' => 'required|uuid',
            'status' => 'required|string|in:SIGNED_NHS',
        ]);

        if ($validator->fails()) {
            $error = 'EHealth Contract (signNhs) validation failed: ' . implode(', ', $validator->errors()->all());
            Log::channel('e_health_errors')->error($error);
            throw ValidationException::withMessages(['ehealth_error' => $error]);
        }

        return $validator->validated();
    }

    /**
     * Validates the response for signMsp().
     */
    protected function validateSignMsp(EHealthResponse $response): array
    {
        $transformedData = self::replaceEHealthPropNames($response->getData());
        $validator = Validator::make($transformedData, [
            'uuid' => 'required|uuid',
            'status' => 'required|string|in:SIGNED',
        ]);

        if ($validator->fails()) {
            $error = 'EHealth Contract (signMsp) validation failed: ' . implode(', ', $validator->errors()->all());
            Log::channel('e_health_errors')->error($error);
            throw ValidationException::withMessages(['ehealth_error' => $error]);
        }

        return $validator->validated();
    }

    /**
     * Replaces eHealth property names with application-specific names (e.g., id -> uuid).
     * This logic is copied from Employee.php for robust nested replacement.
     *
     * @param  array  $properties  Raw properties from a single API item.
     * @return array Properties with application-friendly names.
     */
    protected static function replaceEHealthPropNames(array $properties): array
    {
        $replaced = [];
        foreach ($properties as $name => $value) {
            switch ($name) {
                case 'id':
                    $replaced['uuid'] = $value;
                    break;
                case 'contract_type':
                    $replaced['type'] = is_string($value) ? strtoupper($value) : $value;
                    break;
                case 'party':
                case 'contractor_legal_entity':
                case 'contractor_owner':
                case 'legal_entity':
                case 'division':
                    if (is_array($value) && isset($value['id'])) {
                        $value['uuid'] = $value['id'];
                        unset($value['id']);
                    }
                    $replaced[$name] = $value;
                    break;
                case 'external_contractors':
                    if (is_array($value)) {
                        $replaced[$name] = array_map(function ($item) {
                            if (is_array($item) && isset($item['id'])) {
                                $item['uuid'] = $item['id'];
                                unset($item['id']);
                            }
                            // Recurse for nested objects like 'divisions' inside
                            if (is_array($item) && isset($item['divisions'])) {
                                $item['divisions'] = array_map(function ($div) {
                                    if (is_array($div) && isset($div['id'])) {
                                        $div['uuid'] = $div['id'];
                                        unset($div['id']);
                                    }

                                    return $div;
                                }, $item['divisions']);
                            }

                            return $item;
                        }, $value);
                    } else {
                        $replaced[$name] = $value;
                    }
                    break;
                default:
                    $replaced[$name] = $value;
                    break;
            }
        }

        return $replaced;
    }

    /**
     * Normalize optional list fields: eHealth may send "" instead of null for absent UUIDs.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected static function normalizeListItem(array $item): array
    {
        foreach (['contract_number', 'contractor_legal_entity_id', 'contractor_owner_id', 'nhs_signer_id', 'edrpou'] as $field) {
            if (array_key_exists($field, $item) && $item[$field] === '') {
                $item[$field] = null;
            }
        }

        return $item;
    }
}
