<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;

class CarePlanActivity extends Request
{
    protected const string URL = '/api/care_plans';

    /**
     * Create a new Care Plan Activity in eHealth.
     *
     * @param string $carePlanId
     * @param array $payload
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function create(string $carePlanId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/$carePlanId/activities", $payload);
    }

    /**
     * Cancel a Care Plan Activity.
     *
     * @param string $carePlanId
     * @param string $activityId
     * @param array $payload
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function cancel(string $carePlanId, string $activityId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::URL . "/$carePlanId/activities/$activityId/actions/cancel", $payload);
    }

    /**
     * Complete a Care Plan Activity.
     *
     * @param string $carePlanId
     * @param string $activityId
     * @param array $payload
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function complete(string $carePlanId, string $activityId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::URL . "/$carePlanId/activities/$activityId/actions/complete", $payload);
    }
}
