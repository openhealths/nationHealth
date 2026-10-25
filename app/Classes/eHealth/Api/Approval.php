<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Request as LegacyRequest;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;

class Approval extends Request
{
    protected const string URL = '/api/approvals';

    protected const string APPROVAL_URL = '/api/patients';

    public function confirmWithoutOtp(string $patientUuid, string $approvalId): EHealthResponse
    {
        return $this->requireSuccessfulResponse($this->verify($patientUuid, $approvalId, []));
    }

    public function deactivate(string $patientUuid, string $approvalId): EHealthResponse
    {
        return $this->requireSuccessfulResponse($this->verify($patientUuid, $approvalId, [
            'status' => 'inactive',
        ]));
    }

    protected function requireSuccessfulResponse(EHealthResponse $response): EHealthResponse
    {
        if (!$response->successful()) {
            throw new EHealthResponseException($response);
        }

        return $response;
    }

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
        return $this->patch(self::APPROVAL_URL . "/{$patientId}/approvals/{$approvalId}/actions/resend", []);
    }

    /**
     * Cancel an Approval.
     *
     * @param  string  $id
     * @param  array  $payload
     * @return array
     */
    public static function cancelApproval(string $id, array $payload = []): array
    {
        // Typically a PATCH Request to /api/approvals/{id} with status = null depending on API specifics
        // However wait to check official api schema for this endpoint if differing from /actions/cancel
        return (new LegacyRequest('PATCH', self::URL . "/$id/actions/cancel", $payload))->sendRequest();
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
}
