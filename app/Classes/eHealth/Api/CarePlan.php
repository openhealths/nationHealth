<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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
        $this->setValidator(function (EHealthResponse $response) use ($patientId): array {
            if ($response->getStatusCode() === 201) {
                return $this->validate($response->getData(), $patientId);
            }

            $data = Job::validateSubmissionResponse($response);
            Validator::make($data, ['status' => ['required', 'string']])->validate();

            return $data;
        });

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
        $this->setValidator(Job::validateSubmissionResponse(...));

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
        $this->setValidator(Job::validateSubmissionResponse(...));

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

    /**
     * Validate the registered plan returned by creation or by its completed job.
     */
    public function validate(array $resource, string $patientUuid, ?string $planUuid = null): array
    {
        $data = Arr::toCamelCase($resource);
        $statuses = ['new', 'active', 'completed', 'cancelled', 'terminated'];
        $rules = [
            'id' => ['required', 'uuid'],
            'status' => ['required', Rule::in($statuses)],
            'title' => ['required', 'string'],
            'intent' => ['required', 'in:order'],
            'period.start' => ['required', 'date'],
            'period.end' => ['sometimes', 'nullable', 'date'],
            'subject.identifier.value' => [Rule::in([$patientUuid])],
            'requisition' => ['required', 'string'],
            'addresses' => ['required', 'array', 'min:1'],
            'statusHistory' => ['present', 'array'],
            'statusHistory.*.status' => ['required', Rule::in($statuses)],
            'statusHistory.*.insertedAt' => ['required', 'date'],
            'statusHistory.*.insertedBy' => ['required', 'uuid'],
            'insertedAt' => ['required', 'date'],
            'updatedAt' => ['required', 'date'],
            'insertedBy' => ['required', 'uuid'],
            'updatedBy' => ['required', 'uuid'],
            'note' => ['sometimes', 'nullable', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
            'informWith.authMethodId' => ['required_with:informWith', 'uuid'],
        ];
        if ($planUuid !== null) {
            $rules['id'][] = Rule::in([$planUuid]);
        }
        foreach (['encounter', 'author', 'subject', 'managingOrganization'] as $reference) {
            $rules[$reference.'.identifier.value'][] = 'required';
            $rules[$reference.'.identifier.value'][] = 'uuid';
            $rules[$reference.'.identifier.type.coding'] = ['required', 'array', 'min:1'];
            $rules[$reference.'.identifier.type.coding.*.code'] = ['required', 'string'];
            $rules[$reference.'.identifier.type.coding.*.system'] = ['required', 'string'];
        }
        foreach (['category', 'termsOfService', 'addresses.*'] as $concept) {
            $rules[$concept.'.coding'] = ['required', 'array', 'min:1'];
            $rules[$concept.'.coding.*.code'] = ['required', 'string'];
            $rules[$concept.'.coding.*.system'] = ['required', 'string'];
        }
        foreach (['contributor', 'supportingInfo'] as $references) {
            $rules[$references] = ['sometimes', 'array'];
            $rules[$references.'.*.identifier.value'] = ['required', 'uuid'];
            $rules[$references.'.*.identifier.type.coding'] = ['required', 'array', 'min:1'];
            $rules[$references.'.*.identifier.type.coding.*.code'] = ['required', 'string'];
            $rules[$references.'.*.identifier.type.coding.*.system'] = ['required', 'string'];
        }
        Validator::make($data, $rules)->validate();

        return $data;
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
