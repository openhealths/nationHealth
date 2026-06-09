<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Core\Arr;

class CarePlan extends Request
{
    protected const string URL = '/api/care_plans';

    /**
     * Create a new Care Plan in eHealth.
     *
     * @param  string  $patientId
     * @param  array  $payload
     *
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|ConnectionException
     */
    public function create(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->post("/api/patients/{$patientId}/care_plans", $payload);
    }

    /**
     * Get Care Plans by search parameters.
     *
     * @param  array  $query
     *
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|ConnectionException
     */
    public function getMany(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateMany(...));

        return $this->get(self::URL, $query);
    }

    /**
     * Get a specific Care Plan by Patient ID and Care Plan ID.
     *
     * @param  string  $patientId
     * @param  string  $id
     * @param  array  $query
     *
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|ConnectionException
     */
    public function getDetails(string $patientId, string $id, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDetails(...));

        return $this->get("/api/patients/{$patientId}/care_plans/{$id}", $query);
    }

    /**
     * Cancel a Care Plan.
     *
     * @param string $personId
     * @param string $id
     * @param array  $payload requires status_reason
     *
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException
     */
    public function cancel(string $personId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch("/api/patients/$personId/care_plans/$id/actions/cancel", $payload);
    }

    /**
     * Complete a Care Plan.
     *
     * @param string $personId
     * @param string $id
     * @param array  $payload requires status_reason
     *
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException
     */
    public function complete(string $personId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->patch("/api/patients/$personId/care_plans/$id/actions/complete", $payload);
    }

    /**
     * Get Care Plans by search parameters.
     *
     * @param string $patientId
     * @param array  $query
     *
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateMany(...));

        return $this->get("/api/patients/$patientId/care_plans", $query);
    }

    protected function validateDetails(EHealthResponse $response): array
    {
        $data = $this->replaceEHealthPropNames($response->getData());

        $validator = Validator::make($data, [
            'uuid' => 'required|uuid',
            'status' => 'required|string',
            'title' => 'required|string',
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'CarePlan details validation failed: ' . implode(', ', $validator->errors()->all())
            );
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        return $data;
    }

    protected function validateMany(EHealthResponse $response): array
    {
        $transformedData = [];
        foreach ($response->getData() as $item) {
            $transformedData[] = $this->replaceEHealthPropNames($item);
        }

        $validator = Validator::make($transformedData, [
            '*' => 'array',
            '*.uuid' => 'required|uuid',
            '*.status' => 'required|string',
            '*.title' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'CarePlan validation failed: ' . implode(', ', $validator->errors()->all())
            );
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        return $transformedData;
    }

    protected function replaceEHealthPropNames(array $properties): array
    {
        // Only replace for associative arrays (objects)
        if (!Arr::isAssoc($properties)) {
            $result = [];
            foreach ($properties as $item) {
                $result[] = is_array($item) ? $this->replaceEHealthPropNames($item) : $item;
            }

            return $result;
        }

        $mapping = [
            'id' => 'uuid',
            'inserted_at' => 'ehealth_inserted_at',
            'inserted_by' => 'ehealth_inserted_by',
            'updated_at' => 'ehealth_updated_at'
        ];

        $replaced = [];
        foreach ($properties as $name => $value) {
            $newName = $mapping[$name] ?? $name;
            $replaced[$newName] = is_array($value) ? $this->replaceEHealthPropNames($value) : $value;
        }

        return $replaced;
    }
}
