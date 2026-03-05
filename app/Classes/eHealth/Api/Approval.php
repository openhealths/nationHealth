<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;

class Approval extends Request
{
    protected const string URL = '/api/approvals';

    /**
     * Get Approvals by search parameters.
     *
     * @param array $query query params: granted_resource_type=care_plan, status, etc.
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getMany(array $query = []): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL, $query);
    }

    /**
     * Create a new Approval request for a Care Plan.
     *
     * @param array $payload
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function create(array $payload): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL, $payload);
    }

    /**
     * Cancel an Approval.
     *
     * @param string $id
     * @param array $payload
     * @return array
     */
    public static function cancelApproval(string $id, array $payload = []): array
    {
        // Typically a PATCH Request to /api/approvals/{id} with status = null depending on API specifics
        // However wait to check official api schema for this endpoint if differing from /actions/cancel
        return (new \App\Classes\eHealth\Request('PATCH', self::URL . "/$id/actions/cancel", $payload))->sendRequest();
    }
}
