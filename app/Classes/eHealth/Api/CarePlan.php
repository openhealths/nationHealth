<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Core\Arr;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CarePlan extends Request
{
    protected const string URL = '/api/care_plans';

    /**
     * Create a new Care Plan in eHealth.
     *
     * @param  string  $patientId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function create(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator(\App\Classes\eHealth\Api\Job::validateSubmissionResponse(...));

        return $this->post("/api/patients/{$patientId}/care_plans", $payload);
    }

    /**
     * Get Care Plans by search parameters.
     *
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
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
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getDetails(string $patientId, string $id, array $query = [], bool $preserveApiKeys = false): PromiseInterface|EHealthResponse
    {
        $this->setValidator(fn (EHealthResponse $response): array => $this->validateDetails($response, $preserveApiKeys));

        return $this->get("/api/patients/{$patientId}/care_plans/{$id}", $query);
    }

    /**
     * Cancel a Care Plan.
     * Note: This requires a Digital Signature (DS).
     * The payload must contain 'signed_content' which is the Care Plan object (without activities) + status_reason, signed by KEП.
     *
     * @param  string  $personId
     * @param  string  $id
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function cancel(string $personId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator(\App\Classes\eHealth\Api\Job::validateSubmissionResponse(...));

        return $this->patch("/api/patients/$personId/care_plans/$id/actions/cancel", $payload);
    }

    /**
     * Complete a Care Plan.
     * Note: This does NOT require a Digital Signature (DS).
     * The payload just needs to contain 'status_reason'.
     *
     * @param  string  $personId
     * @param  string  $id
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function complete(string $personId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator(\App\Classes\eHealth\Api\Job::validateSubmissionResponse(...));

        return $this->patch("/api/patients/$personId/care_plans/$id/actions/complete", $payload);
    }

    /**
     * Get Care Plans by search parameters.
     *
     * @param  string  $patientId
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateMany(...));

        return $this->get("/api/patients/$patientId/care_plans", $query);
    }

    /**
     * Get a list of summary info about care plans.
     *
     * @param  string  $patientId
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/patient-summary/get-care-plans
     */
    public function getSummary(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateMany(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query);

        return $this->get("/api/patients/$patientId/summary/care_plans", $mergedQuery);
    }

    protected function validateDetails(EHealthResponse $response, bool $preserveApiKeys = false): array
    {
        $native = $response->getData();
        if (array_is_list($native) && count($native) === 1) {
            $native = $native[0];
        }
        $data = Arr::toCamelCase($native);

        $validator = Validator::make($data, [
            'id' => 'required|uuid',
            'status' => 'required|string',
            'title' => 'required|string',
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'CarePlan details validation failed: ' . implode(', ', $validator->errors()->all())
            );
            throw new ValidationException($validator);
        }

        // Signed snapshots retain the API's keys; both forms pass the same validator.
        return $preserveApiKeys ? $native : $data;
    }

    protected function validateMany(EHealthResponse $response): array
    {
        $transformedData = [];
        foreach ($response->getData() as $item) {
            $transformedData[] = Arr::toCamelCase($item);
        }

        $validator = Validator::make($transformedData, [
            '*' => 'array',
            '*.id' => 'required|uuid',
            '*.status' => 'required|string',
            '*.title' => 'nullable|string',
            '*.managingOrganization' => ['required', 'array'],
            '*.managingOrganization.identifier.value' => ['required', 'uuid'],
            '*.managingOrganization.displayValue' => ['required', 'string']
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'CarePlan validation failed: ' . implode(', ', $validator->errors()->all())
            );
            throw new ValidationException($validator);
        }

        return $transformedData;
    }

}
