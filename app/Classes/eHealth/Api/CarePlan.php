<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\Api\Responses\Collections\CarePlanDetails;
use App\Classes\eHealth\Api\Responses\Collections\CarePlanGetMany;
use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

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
     * Get Care Plans by requisition.
     *
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getMany(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateRequisitionResults(...));

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
    public function getDetails(string $patientId, string $id, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDetails(...));

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

    protected function validateDetails(EHealthResponse $response): CarePlanDetails
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

        $this->validateData($data, $rules);

        // Cancellation signs the complete original resource, including fields outside the projection.
        return new CarePlanDetails($data);
    }

    protected function validateMany(EHealthResponse $response): CarePlanGetMany
    {
        return $this->validateList($response);
    }

    protected function validateRequisitionResults(EHealthResponse $response): CarePlanGetMany
    {
        return $this->validateList($response, [
            'data.*.identity' => 'required|array',
            'data.*.identity.last_name' => 'required|string',
            'data.*.identity.first_name' => 'required|string',
            'data.*.identity.second_name' => 'present|string',
            'data.*.identity.gender' => 'required|in:MALE,FEMALE',
            'data.*.identity.age' => 'required|numeric',
        ]);
    }

    protected function validateList(EHealthResponse $response, array $itemRules = []): CarePlanGetMany
    {
        $data = $response->json(EHealthResponse::DATA_PATH);
        $validated = $this->validateData($data, [
            'data' => 'present|array|list',
            'data.*' => 'required|array',
            'data.*.title' => 'sometimes|nullable|string',
        ] + $this->validationRules('data.*.') + $itemRules);

        return new CarePlanGetMany($validated);
    }

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
