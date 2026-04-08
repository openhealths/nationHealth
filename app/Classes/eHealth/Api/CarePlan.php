<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;

class CarePlan extends Request
{
    protected const string URL = '/api/care_plans';

    /**
     * Create a new Care Plan in eHealth.
     *
     * @param string $patientId
     * @param array $payload
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function create(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->post("/api/patients/{$patientId}/care_plans", $payload);
    }

    /**
     * Get Care Plans by search parameters.
     *
     * @param array $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getMany(array $query = []): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL, $query);
    }

    /**
     * Get a specific Care Plan by ID.
     *
     * @param string $id
     * @param array $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getDetails(string $id, array $query = []): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL . "/$id", $query);
    }

    /**
     * Cancel a Care Plan.
     *
     * @param string $id
     * @param array $payload requires status_reason
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function cancel(string $personId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch("/api/patients/$personId/care_plans/$id/actions/cancel", $payload);
    }

    /**
     * Complete a Care Plan.
     *
     * @param string $id
     * @param array $payload requires status_reason
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function complete(string $personId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch("/api/patients/$personId/care_plans/$id/actions/complete", $payload);
    }

    /**
     * Fetch a summary of Care Plans for a specific patient.
     *
     * @param string $personId
     * @param array $query
     * @return PromiseInterface|EHealthResponse
     */
    public function getSummary(string $personId, array $query = []): PromiseInterface|EHealthResponse
    {
        return $this->get("/api/patients/$personId/care_plans", $query);
    }
}
