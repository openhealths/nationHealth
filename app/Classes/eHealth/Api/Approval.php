<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use App\Classes\eHealth\ValidationRuleBuilder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class Approval extends Request
{
    protected const string URL = '/api/approvals';

    protected const string APPROVAL_URL = '/api/patients';

    /**
     * Get Approvals by search parameters.
     *
     * Call getPatientApprovals directly whenever the patient UUID is known.
     *
     * @param  array  $query  query params: granted_resource_type=care_plan, status, etc.
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getMany(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateApprovalList(...));

        return $this->get(self::URL, $query);
    }

    /**
     * Get approvals for a specific patient.
     *
     * @param  string  $patientId
     * @param  array  $query  query params: status (e.g. active), limit, etc.
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getPatientApprovals(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $this->setValidator($this->validateApprovalList(...));

        return $this->get(self::APPROVAL_URL . "/{$patientId}/approvals", $query);
    }

    /**
     * Get approvals for a specific patient.
     *
     * @param  string  $patientId
     * @param  array  $query  query params: status (e.g. active), limit, etc.
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getApprovalDetails(string $patientId, string $approvalUuid, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateApproval(...));

        return $this->get(self::APPROVAL_URL . "/{$patientId}/approvals/{$approvalUuid}", $query);
    }

    /**
     * Create a new Approval request for a patient entity.
     *
     * @param  string  $patientId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function createApproval(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateCreate(...));

        return $this->post(self::APPROVAL_URL . "/{$patientId}/approvals", $payload);
    }

    /**
     * Create a new Approval request for a Care Plan.
     *
     * @deprecated Use createApproval instead.
     *
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function create(array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateCreate(...));

        return $this->post(self::URL, $payload);
    }

    /**
     * Verify Approval via OTP.
     *
     * @param  string  $patientId
     * @param  string  $approvalId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function verify(string $patientId, string $approvalId, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateVerification(...));

        return $this->patch(self::APPROVAL_URL . "/{$patientId}/approvals/{$approvalId}", $payload);
    }

    /**
     * Resend SMS code for Approval.
     *
     * eHealth documents this as PATCH (not POST). Using POST yields ACL 403:
     * "No matching rule was found for path /api/patients".
     *
     * @see https://e-health-ua.atlassian.net/wiki/spaces/EH/pages/583403110/Resend+SMS+on+Approval
     *
     * @param  string  $patientId
     * @param  string  $approvalId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function resendSms(string $patientId, string $approvalId): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateCreate(...));

        return $this->patch(self::APPROVAL_URL . "/{$patientId}/approvals/{$approvalId}/actions/resend", []);
    }

    /**
     * Cancel an Approval.
     *
     * A 202 response is a job receipt, not confirmation that access was revoked.
     *
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function cancelApproval(string $patientId, string $approvalId): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateCancellation(...));

        return $this->patch(self::APPROVAL_URL . "/{$patientId}/approvals/{$approvalId}/actions/cancel", []);
    }

    protected function validateCancellation(EHealthResponse $response): array
    {
        Validator::make(['status' => $response->status()], [
            'status' => ['required', Rule::in([201, 202])],
        ])->validate();

        return $this->validateCreate($response);
    }

    /**
     * Build the request payload for an approval request for a person data.
     *
     * @param  array  $payloadData  Expected keys: employee_id (string), person_id (string), authorize_with (string|null)
     * @return array
     */
    public function getPayloadForPersonDataApproval(array $payloadData): array
    {
        $payload = [
           'granted_to' => [
               'identifier' => [
                   'type' => [
                       'coding' => [
                           [
                               'system' => 'eHealth/resources',
                               'code' => 'employee'
                           ]
                       ]
                   ],
                   'value' => $payloadData['employee_id'],
               ]
           ],
           'created_by' => [
               'identifier' => [
                   'type' => [
                       'coding' => [
                           [
                               'system' => 'eHealth/resources',
                               'code' => 'employee'
                           ]
                       ]
                   ],
                   'value' => $payloadData['employee_id'],
               ]
           ],
           'person' => [
               'identifier' => [
                   'type' => [
                       'coding' => [
                           [
                               'system' => 'eHealth/resources',
                               'code' => 'person'
                           ]
                       ]
                   ],
                   'value' => $payloadData['person_id'],
               ]
           ],
           'access_level' => 'read',
           'authorize_with' => $payloadData['authorize_with'] ?: null,
        ];

        return $payload;
    }

    protected function validateApprovalList(EHealthResponse $response): array
    {
        $data = $response->getData();

        Validator::make(['items' => $data], [
            'items' => ['present', 'array'],
            'items.*.id' => ['required', 'uuid'],
            'items.*.granted_to.identifier.value' => ['required', 'uuid'],
            'items.*.is_verified' => ['sometimes', 'boolean:strict'],
            'items.*.access_level' => ['nullable', Rule::in(['read', 'write'])],
            'items.*.expires_at' => ['nullable', 'date'],
            'items.*.status' => ['nullable', 'string'],
        ])->validate();

        return $data;
    }

    protected function validateApproval(EHealthResponse $response): array
    {
        $data = $response->getData();

        Validator::make($data, [
            'id' => ['required', 'uuid'],
            'granted_to.identifier.value' => ['required', 'uuid'],
            'access_level' => ['required', 'string'],
            'is_verified' => ['sometimes', 'boolean:strict'],
            'expires_at' => ['nullable', 'date'],
            'status' => ['nullable', 'string'],
        ])->validate();

        return $data;
    }

    protected function validateCreate(EHealthResponse $response): array
    {
        return $response->status() === 202
            ? Job::validateSubmissionResponse($response)
            : self::validateResourceData($response->json() ?? []);
    }

    protected function validateVerification(EHealthResponse $response): array
    {
        // Verification acknowledges the PATCH; it need not return an Approval resource.
        Validator::make(['status' => $response->status()], [
            'status' => ['required', Rule::in([200, 204])],
        ])->validate();
        if (trim($response->body()) === '') {
            return [];
        }
        $body = Validator::make(['body' => $response->json()], [
            'body' => ['present', 'array'],
        ])->validate()['body'];
        if (array_key_exists('data', $body) || array_key_exists('meta', $body)) {
            $body = Validator::make(['body' => $body], [
                'body' => ['array:data,meta'],
                'body.data' => ['nullable', 'array'],
                'body.meta' => ['nullable', 'array'],
                'body.meta.code' => ['nullable', 'integer', Rule::in([200, 204])],
                'body.meta.type' => ['nullable', 'string'],
                'body.meta.url' => ['nullable', 'string'],
                'body.meta.request_id' => ['nullable', 'string'],
            ])->validate()['body'];
            $body = $body['data'] ?? [];
        }
        if ($body === []) {
            return [];
        }
        Validator::make($body, [
            'id' => ['required_without_all:is_verified,status', 'uuid'],
        ])->validate();

        return self::validateResourceData($body, complete: false);
    }

    /** Unwrap documented job envelopes and validate the approval before consumers can persist it. */
    public static function validateResourceData(array $data, bool $complete = true): array
    {
        $urgent = null;
        for ($depth = 0; $depth < 6; $depth++) {
            $urgent = $data['urgent'] ?? $urgent;
            $nested = $data['response_data'] ?? $data['result'] ?? data_get($data, 'response.body') ?? $data['data'] ?? null;
            if (!is_array($nested)) {
                break;
            }
            $data = array_is_list($nested) && count($nested) === 1 ? $nested[0] : $nested;
        }
        if ($urgent !== null && !array_key_exists('urgent', $data)) {
            $data['urgent'] = $urgent;
        }

        $rules = ValidationRuleBuilder::merge(
            [
                'id' => [$complete ? 'required' : 'sometimes', 'uuid'],
                'is_verified' => [$complete ? 'required' : 'sometimes', 'boolean:strict'],
                'status' => ['nullable', 'string'],
                'access_level' => ['nullable', Rule::in(['read', 'write'])],
                'authorize_with' => ['nullable', 'uuid'],
                'expires_at' => ['nullable', 'date'],
                'inserted_at' => ['nullable', 'date'],
                'updated_at' => ['nullable', 'date'],
                'urgent' => ['nullable', 'array'],
            ],
            ValidationRuleBuilder::identifierRules('granted_to'),
            ValidationRuleBuilder::identifierRules('created_by'),
            ValidationRuleBuilder::identifierRules('person'),
            ValidationRuleBuilder::identifierRules('reason'),
            ValidationRuleBuilder::identifierCollectionRules('granted_resources'),
            ValidationRuleBuilder::codeableConceptCollectionRules('granted_resource_types'),
        );
        foreach (['authentication_method_current', 'urgent.authentication_method_current'] as $field) {
            $rules += [
                $field => ['nullable', 'array'],
                "$field.type" => ["required_with:$field", 'string'],
                "$field.id" => ['nullable', 'uuid'],
                "$field.uuid" => ['nullable', 'uuid'],
                "$field.phone_number" => ['nullable', 'string'],
                "$field.phoneNumber" => ['nullable', 'string'],
                "$field.number" => ['nullable', 'string'],
            ];
        }

        return Validator::make($data, $rules)->validate();
    }

    public static function errorMessage(\Illuminate\Http\Client\Response $response): ?string
    {
        $data = Validator::make($response->json() ?? [], [
            'error' => ['nullable', 'array'],
            'error.message' => ['nullable', 'string'],
        ])->validate();

        return data_get($data, 'error.message');
    }

}
