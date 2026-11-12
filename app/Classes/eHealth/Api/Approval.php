<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\Api\Job;
use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\ValidationRuleBuilder;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class Approval extends Request
{
    protected const string URL = '/api/approvals';

    protected const string APPROVAL_URL = '/api/patients';

    /**
     * Get Approvals by search parameters.
     *
     * Call getPatientApprovals directly whenever the patient UUID is known.
     *
     * @param  array  $query  query params: granted_resource_type=care_plan, etc.
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
     * @param  array  $query  query params: granted_to, is_verified, is_expired, etc.
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
     * @param  array  $query  query params: granted_to, is_verified, is_expired, etc.
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

        if ($payload === []) {
            return $this->withBody('{}', 'application/json')->patch(self::APPROVAL_URL . "/{$patientId}/approvals/{$approvalId}");
        }

        $this->asJson();

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
     * @param  string  $patientId
     * @param  string  $approvalId
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
    */
    public function cancelApproval(string $patientId, string $approvalId): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateCancellation(...));

        return $this->patch(self::APPROVAL_URL . "/{$patientId}/approvals/{$approvalId}/actions/cancel", []);
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

    protected function validateCancellation(EHealthResponse $response): array
    {
        if (!in_array($response->status(), [201, 202], true)) {
            throw new EHealthResponseException($response);
        }

        return $response->status() === 202 ? $this->validatePendingRequest($response) : $this->validateApproval($response);
    }

    private function validatePendingRequest(EHealthResponse $response): array
    {
        $data = Job::validateSubmissionResponse($response);
        Validator::make($data, [
            'status' => ['required', 'string'],
            'links' => ['required', 'array', 'min:1'],
            'links.0.href' => ['required', 'string', 'regex:/\/jobs\/[^\/]+/i'],
        ])->validate();

        return $data;
    }

    protected function validateApprovalList(EHealthResponse $response): array
    {
        $data = $response->json() ?? [];

        Validator::make($data, [
            'data' => ['present', 'array', 'list'],
            'data.*.id' => ['required', 'uuid'],
            'data.*.granted_to.identifier.value' => ['required', 'uuid'],
            'data.*.is_verified' => ['required', 'boolean:strict'],
            'data.*.access_level' => ['nullable', Rule::in(['read', 'write'])],
            'data.*.expires_at' => ['required', 'date'],
            'paging' => ['required', 'array'],
            'paging.page_number' => ['required_without:paging.page', 'integer', 'min:1'],
            'paging.page' => ['required_without:paging.page_number', 'integer', 'min:1'],
            'paging.total_pages' => ['required', 'integer', 'min:0'],
        ])->validate();

        return $data['data'];
    }

    protected function validateApproval(EHealthResponse $response): array
    {
        return $this->validatePatientResource($response, withCreator: true);
    }

    private function validatePatientResource(EHealthResponse $response, bool $withCreator): array
    {
        $data = $this->validateResourceData($response->json() ?? []);
        $rules = [
            'granted_resources' => ['present', 'array', 'list'],
            'granted_resource_types' => ['present', 'array', 'list'],
            'granted_to' => ['required', 'array'],
            'access_level' => ['required', Rule::in(['read', 'write'])],
            'expires_at' => ['required', 'date'],
            'authentication_method_current' => ['required', 'array'],
            'authentication_method_current.type' => ['required', Rule::in(['OTP', 'OFFLINE', 'THIRD_PERSON'])],
        ];
        if ($withCreator) {
            $rules['created_by'] = ['required', 'array'];
        }
        Validator::make($data, $rules)->validate();

        return $data;
    }

    protected function validateCreate(EHealthResponse $response): array
    {
        return $response->status() === 202
            ? $this->validatePendingRequest($response)
            : $this->validatePatientResource($response, withCreator: false);
    }

    protected function validateVerification(EHealthResponse $response): array
    {
        if ($response->status() === 202) {
            return $this->validatePendingRequest($response);
        }

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
            'id' => ['required_without:is_verified', 'uuid'],
        ])->validate();

        return $this->validateResourceData($body, complete: false);
    }

    /** Unwrap documented job envelopes and validate the approval before consumers can persist it. */
    public function validateResourceData(array $data, bool $complete = true): array
    {
        $urgent = null;
        for ($depth = 0; $depth < 6; $depth++) {
            $urgent = $data['urgent'] ?? $urgent;
            $nested = $data['response_data'] ?? ($data['result'] ?? (data_get($data, 'response.body') ?? ($data['data'] ?? null)));
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
                'status' => ['sometimes', 'string'],
                'access_level' => ['nullable', Rule::in(['read', 'write'])],
                'authorize_with' => ['nullable', 'uuid'],
                'expires_at' => [$complete ? 'required' : 'sometimes', 'date'],
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
                "$field.number" => ['nullable', 'string'],
                "$field.reference" => ['nullable', 'array:type,phone_number'],
                "$field.reference.type" => ['nullable', Rule::in(['OTP', 'OFFLINE', 'NA'])],
                "$field.reference.phone_number" => ['nullable', 'string', 'max:255'],
            ];
        }

        return Validator::make($data, $rules)->validate();
    }

    public function errorMessage(Response $response): ?string
    {
        $data = Validator::make($response->json() ?? [], [
            'error' => ['nullable', 'array'],
            'error.message' => ['nullable', 'string'],
        ])->validate();

        return data_get($data, 'error.message');
    }

    public function verifyConfirmed(string $patientUuid, string $approvalId, ?int $code, ?string $bearerToken = null): EHealthResponse
    {
        $api = $this;
        if ($bearerToken !== null) {
            $api->withToken($bearerToken);
        }
        try {
            $response = $this->requireSuccessfulResponse($api->verify($patientUuid, $approvalId, $code === null ? [] : ['code' => $code]));
        } catch (EHealthResponseException $exception) {
            if ($exception->response->status() !== 409 || $this->errorMessage($exception->response) !== 'Approval is already verified') {
                throw $exception;
            }
            // The remote PATCH can succeed before local persistence fails. Reconcile this exact approval.
            $response = $this->requireSuccessfulResponse($api->getApprovalDetails($patientUuid, $approvalId));
            $confirmed = $response->validate();
            if (($confirmed['id'] ?? null) !== $approvalId || !$this->approvalIsGranted($confirmed)) {
                throw $exception;
            }
        }
        try {
            $data = $response->validate();
        } catch (ValidationException $exception) {
            if ($code === null) {
                throw $exception;
            }
            // The code may already be consumed. Re-read this approval instead of sending the code again.
            $confirmedResponse = $this->requireSuccessfulResponse($api->getApprovalDetails($patientUuid, $approvalId));
            $data = $confirmedResponse->validate();
            if (($data['id'] ?? null) !== $approvalId || !$this->approvalIsGranted($data)) {
                throw $exception;
            }
            $response = $confirmedResponse;
        }
        if (array_key_exists('is_verified', $data) && $data['is_verified'] !== true) {
            throw new RuntimeException(__('care-plan.approval_verify_error'));
        }
        // A successful PATCH may contain only an acknowledgement. Read the verified flag and expiry before granting access.
        if ($data === [] || !isset($data['id'], $data['is_verified'], $data['expires_at'])) {
            $response = $this->requireSuccessfulResponse($api->getApprovalDetails($patientUuid, $approvalId));
            $data = $response->validate();
        }
        if (($data['id'] ?? null) !== $approvalId || !$this->approvalIsGranted($data)) {
            throw new RuntimeException(__('care-plan.approval_verify_error'));
        }

        return $response;
    }

    private function approvalIsGranted(array $data): bool
    {
        return ($data['is_verified'] ?? false) === true
            && !empty($data['expires_at'])
            && CarbonImmutable::parse($data['expires_at'])->isFuture();
    }

    protected function requireSuccessfulResponse(EHealthResponse $response): EHealthResponse
    {
        if (!$response->successful()) {
            throw new EHealthResponseException($response);
        }

        return $response;
    }
}
