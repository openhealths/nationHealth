<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use App\Exceptions\EHealth\EHealthConnectionException;

class Approval extends Request
{
    protected const string URL = '/api/approvals';

    /**
     * Get Approvals by search parameters.
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
        return $this->get("/api/patients/{$patientId}/approvals", $query);
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
        return $this->post("/api/patients/{$patientId}/approvals", $payload);
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
        return $this->patch("/api/patients/{$patientId}/approvals/{$approvalId}", $payload);
    }

    /**
     * Resend SMS code for Approval.
     *
     * This is the only endpoint documented by eHealth for this action (requires the
     * `approval:create` scope); there is no patient-prefix-less variant. A previous version of
     * this method tried an undocumented endpoint first, which eHealth confirmed doesn't exist
     * (404) and only muddied real failures from this endpoint behind a doomed fallback.
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
        return $this->post("/api/patients/{$patientId}/approvals/{$approvalId}/actions/resend", []);
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
        return (new \App\Classes\eHealth\Request('PATCH', self::URL . "/$id/actions/cancel", $payload))->sendRequest();
    }
}
