<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Contract extends EHealthRequest
{
    /**
     * Base URL of the resource
     */
    protected const string URL = '/api/contracts';

    /**
     * Maps eHealth API contract response fields to local database columns.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mapCreate(array $data): array
    {
        $extractId = static function ($value): ?string {
            if (is_array($value)) {
                return $value['id'] ?? ($value['uuid'] ?? null);
            }

            return is_string($value) ? $value : null;
        };

        return [
            'uuid' => $data['id'] ?? $data['uuid'],
            'contractor_legal_entity_id' => $extractId($data['contractor_legal_entity'] ?? null)
                ?? ($data['contractor_legal_entity_id'] ?? null),
            'contractor_owner_id' => $extractId($data['contractor_owner'] ?? null)
                ?? ($data['contractor_owner_id'] ?? null),
            'contract_number' => $data['contract_number'] ?? null,
            'status' => $data['status'] ?? null,
            'status_reason' => $data['status_reason'] ?? null,
            'type' => $data['contract_type'] ?? ($data['type'] ?? null),
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'contractor_base' => $data['contractor_base'] ?? null,
            'contractor_payment_details' => $data['contractor_payment_details'] ?? [],
            'contractor_rmsp_amount' => $data['contractor_rmsp_amount'] ?? null,
            'contractor_divisions' => $data['contractor_divisions'] ?? [],
            'external_contractor_flag' => $data['external_contractor_flag'] ?? false,
            'external_contractors' => $data['external_contractors'] ?? [],
            'nhs_signer_id' => $extractId($data['nhs_signer'] ?? null) ?? ($data['nhs_signer_id'] ?? null),
            'nhs_legal_entity_id' => $extractId($data['nhs_legal_entity'] ?? null) ?? ($data['nhs_legal_entity_id'] ?? null),
            'nhs_signer_base' => $data['nhs_signer_base'] ?? null,
            'nhs_payment_method' => $data['nhs_payment_method'] ?? null,
            'nhs_contract_price' => $data['nhs_contract_price'] ?? null,
            'nhs_signed_date' => $data['nhs_signed_date'] ?? null,
            'id_form' => $data['id_form'] ?? null,
            'issue_city' => $data['issue_city'] ?? null,
            'medical_programs' => $data['medical_programs'] ?? [],
            // Never invent "today" when eHealth omits inserted_at — that caused false creation dates in UI.
            'inserted_at' => !empty($data['inserted_at']) ? $data['inserted_at'] : null,
            'updated_at' => $data['updated_at'] ?? null,
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
    public function getDetails(string $id, string $contractType = 'capitation'): PromiseInterface|EHealthResponse
    {
        $this->setValidator(fn (EHealthResponse $response): array => $this->validateDetails($response, $contractType, $id));

        return $this->get(self::URL . '/' . strtolower($contractType) . '/' . $id);
    }

    /**
     * Get a list of contracts with validation logic.
     *
     * @param  array  $queryParams
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException
     */
    public function getMany(array $queryParams = [], string $contractType = 'capitation'): PromiseInterface|EHealthResponse
    {
        $this->setValidator(fn (EHealthResponse $response): array => $this->validateMany($response, $contractType, $queryParams['contractor_legal_entity_id'] ?? null, (int) ($queryParams['page'] ?? 1)));
        $this->setDefaultPageSize();

        return $this->get(self::URL . '/' . strtolower($contractType), array_merge($this->options['query'] ?? [], $queryParams));
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
            'status' => 'required|string|in:VERIFIED,TERMINATED',
            'status_reason' => 'sometimes|string',
            'assignee_id' => 'sometimes|string|uuid',
            'reason' => 'sometimes|string',
            'contract_number' => 'sometimes|string',
            'contractor_base' => 'required|string',
            'contractor_owner' => 'required|array',
            'contractor_owner.id' => 'required|uuid',
            'contractor_owner.party' => 'sometimes|array',
            'contractor_owner.party.first_name' => 'required_with:contractor_owner.party|string',
            'contractor_owner.party.last_name' => 'required_with:contractor_owner.party|string',
            'contractor_owner.party.second_name' => 'sometimes|string',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d',
            'inserted_at' => 'required|string|date',
            'updated_at' => 'required|string|date',
            'id_form' => 'required|string',
            'contractor_divisions' => 'present|array|list',
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
            'contract_request_id' => ($details ? 'required' : 'sometimes') . '|uuid',
            'contractor_legal_entity_id' => ($details ? 'sometimes' : 'required') . '|uuid',
            'is_suspended' => $details ? 'required|boolean:strict' : 'required|string|in:true,false',
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
        return $data + ['uuid' => $data['id']];
    }

    public function getPrintoutContent(string $id, string $contractType = 'capitation'): PromiseInterface|EHealthResponse
    {
        $this->setValidator(fn (EHealthResponse $response): array => $this->validatePrintoutContent($response, $id));

        return $this->get(self::URL . '/' . strtolower($contractType) . '/' . $id . '/printout_content');
    }

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
}
