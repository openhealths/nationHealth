<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\Api\Responses\Collections\CarePlanDetails;
use App\Classes\eHealth\Api\Responses\Collections\CarePlanJob;
use App\Core\Arr;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Errors\MedicalEventsErrorTranslator;
use App\Contracts\ApiErrorTranslator;
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
     * The response is validated as CarePlanDetails when the care plan is created at once (201),
     * or as CarePlanJob when eHealth has created a job to process the request (202).
     *
     * @param  string  $patientId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function create(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateSubmission(...));

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

    /**
     * Get the translator of the errors the medical events resources return.
     *
     * @return ApiErrorTranslator
     */
    protected function errorTranslator(): ApiErrorTranslator
    {
        return new MedicalEventsErrorTranslator();
    }

    /**
     * Validate the care plan eHealth returns when it has created it at once (201).
     *
     * @param  EHealthResponse  $response
     * @return CarePlanDetails
     */
    protected function validateResponse(EHealthResponse $response): CarePlanDetails
    {
        $data = $response->json(EHealthResponse::DATA_PATH);
        if (is_array($data) && array_is_list($data) && count($data) === 1) {
            $data = $data[0];
        }

        $rules = $this->validationRules('data.') + [
            'data' => 'required|array',
            'data.title' => 'required|string',
            'data.category' => 'required|array',
            'data.description' => 'sometimes|nullable|string',
            'data.note' => 'sometimes|nullable|string',
            'data.context' => 'sometimes|nullable|string',
            'data.addresses' => 'present|array|list',
            'data.addresses.*' => 'required|array',
            'data.supporting_info' => 'sometimes|array|list',
            'data.supporting_info.*' => 'required|array',
            'data.contributor' => 'sometimes|array|list',
            'data.contributor.*' => 'required|array',
            'data.status_history' => 'present|array|list',
            'data.status_history.*' => 'required|array',
            'data.status_history.*.status' => 'required|string',
            'data.status_history.*.inserted_at' => 'required|date',
            'data.status_history.*.inserted_by' => 'required|uuid',
            'data.status_history.*.status_reason' => 'sometimes|nullable|array',
            'data.status_reason' => 'sometimes|nullable|array',
            'data.care_manager' => 'sometimes|nullable|array',
            'data.inserted_at' => 'required|date',
            'data.inserted_by' => 'required|uuid',
            'data.updated_at' => 'required|date',
            'data.updated_by' => 'required|uuid',
        ];

        foreach (['category', 'addresses.*', 'status_history.*.status_reason', 'status_reason'] as $field) {
            $rules += $this->conceptRules('data.' . $field);
        }

        foreach (['supporting_info.*', 'contributor.*', 'care_manager'] as $field) {
            $rules += $this->referenceRules('data.' . $field);
        }

        $validated = $this->validateData($data, $rules);

        return new CarePlanDetails($validated);
    }

    /**
     * Validate the response to the create request. The flow splits by the status of the response:
     * - 201: eHealth has processed the request at once and returns the care plan, validated as CarePlanDetails;
     * - any other status (202): eHealth has created a job to process the request, validated as CarePlanJob.
     *
     * @param  EHealthResponse  $response
     * @return CarePlanDetails|CarePlanJob
     */
    protected function validateSubmission(EHealthResponse $response): CarePlanDetails|CarePlanJob
    {
        // TODO: the 201 branch is typed, but its result is not mapped to the Model DTO yet: it is handled
        //  as an array by the legacy submitOperation(). It is finished in the next PR, see CarePlanDetails::toArray().
        return $response->status() === 201 ? $this->validateResponse($response) : $this->validateJob($response);
    }

    /**
     * Validate the job eHealth has created to process the request asynchronously.
     * The care plan is not in the response: it is available only after the job has been completed successfully.
     *
     * @param  EHealthResponse  $response
     * @return CarePlanJob
     */
    protected function validateJob(EHealthResponse $response): CarePlanJob
    {
        $data = Job::validateSubmissionResponse($response);

        if ($response->status() === 202) {
            $this->validateData($data, [
                'data.status' => 'required|string',
            ]);
        }

        return new CarePlanJob($data);
    }

    /**
     * Validation rules of the care plan resource.
     *
     * @param  string  $prefix  Path of the care plan in the validated data, e.g. `data.` or `data.*.`
     * @return array
     */
    protected function validationRules(string $prefix): array
    {
        $rules = [
            $prefix . 'id' => 'required|uuid',
            $prefix . 'status' => 'required|in:new,active,completed,cancelled,cencelled,terminated',
            $prefix . 'requisition' => 'required|string',
            $prefix . 'intent' => 'required|in:order',
            $prefix . 'period' => 'required|array',
            $prefix . 'period.start' => 'required|date',
            $prefix . 'period.end' => 'sometimes|nullable|date',
            $prefix . 'terms_of_service' => 'required|array',
            $prefix . 'terms_of_service.coding.*.code' => 'required|in:INPATIENT,OUTPATIENT',
            $prefix . 'managing_organization.display_value' => 'required|string',
            $prefix . 'inform_with' => 'sometimes|nullable|array',
            $prefix . 'inform_with.auth_method_id' => 'sometimes|uuid',
        ] + $this->conceptRules($prefix . 'terms_of_service');

        foreach (['subject', 'encounter', 'author', 'managing_organization'] as $field) {
            $rules[$prefix . $field] = 'required|array';
            $rules += $this->referenceRules($prefix . $field);
        }

        foreach (['based_on', 'part_of'] as $field) {
            $rules[$prefix . $field] = 'sometimes|nullable|array';
            $rules += $this->referenceRules($prefix . $field);
        }

        return $rules;
    }

    /**
     * Validation rules of a reference to another resource (identifier with a type and a display value).
     *
     * @param  string  $field
     * @return array
     */
    protected function referenceRules(string $field): array
    {
        return [
            $field . '.identifier' => 'required_with:' . $field . '|array',
            $field . '.identifier.value' => 'required_with:' . $field . '|uuid',
            $field . '.identifier.type' => 'required_with:' . $field . '|array',
            $field . '.identifier.type.coding' => 'sometimes|array|list',
            $field . '.identifier.type.coding.*' => 'required|array',
            $field . '.identifier.type.coding.*.system' => 'required|string',
            $field . '.identifier.type.coding.*.code' => 'required|string',
            $field . '.identifier.type.text' => 'sometimes|nullable|string',
            $field . '.display_value' => 'sometimes|nullable|string',
        ];
    }

    /**
     * Validation rules of a codeable concept.
     *
     * @param  string  $field
     * @return array
     */
    protected function conceptRules(string $field): array
    {
        return [
            $field . '.coding' => 'required_with:' . $field . '|array|list|min:1',
            $field . '.coding.*' => 'required|array',
            $field . '.coding.*.system' => 'required|string',
            $field . '.coding.*.code' => 'required|string',
            $field . '.text' => 'sometimes|nullable|string',
        ];
    }

    /**
     * Validate the response data, logging every failure before the validation exception is thrown.
     *
     * @param  mixed  $data
     * @param  array  $rules
     * @return array
     */
    protected function validateData(mixed $data, array $rules): array
    {
        $validator = Validator::make(['data' => $data], $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'CarePlan validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validate()['data'];
    }
}
