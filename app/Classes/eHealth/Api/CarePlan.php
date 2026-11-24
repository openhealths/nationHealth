<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\Api\Responses\Collections\CarePlanDetails;
use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Enums\CarePlanStatus;
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
        $this->setValidator($this->validateCreation(...));

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
        $this->setValidator($preserveApiKeys ? $this->validateSnapshot(...) : $this->validateDetails(...));

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

    /** Creation is synchronous (201) or accepted for job processing (202). */
    protected function validateCreation(EHealthResponse $response): CarePlanDetails|array
    {
        return match ($response->getStatusCode()) {
            201 => $this->validateResponse($response),
            202 => $this->validateSubmittedJob($response),
            default => throw ValidationException::withMessages(['response' => __('errors.ehealth.messages.request_error')]),
        };
    }

    protected function validateResponse(EHealthResponse $response): CarePlanDetails
    {
        $validator = Validator::make($response->getData(), $this->validationRules());

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('CarePlan response validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return new CarePlanDetails($validator->validate());
    }

    /** An accepted submission supplies job metadata for the existing poller. */
    protected function validateSubmittedJob(EHealthResponse $response): array
    {
        $validator = Validator::make($response->getData(), [
            'status' => ['required', 'string'],
            'eta' => ['sometimes', 'nullable', 'string'],
            'links' => ['sometimes', 'array', 'list'],
            'links.*' => ['required', 'array'],
            'links.*.entity' => ['sometimes', 'string'],
            'links.*.href' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('CarePlan job validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    protected function validationRules(): array
    {
        $status = Rule::in([
            CarePlanStatus::PENDING->value,
            CarePlanStatus::ACTIVE->value,
            CarePlanStatus::COMPLETED->value,
            CarePlanStatus::CANCELLED->value,
            CarePlanStatus::TERMINATED->value,
        ]);

        $rules = [
            'id' => ['required', 'uuid'],
            'status' => ['required', $status],
            'title' => ['required', 'string'],
            'intent' => ['required', 'in:order'],
            'period' => ['required', 'array'],
            'period.start' => ['required', 'date'],
            'period.end' => ['sometimes', 'nullable', 'date'],
            'requisition' => ['required', 'string'],
            'addresses' => ['required', 'array', 'list'],
            'status_history' => ['present', 'array', 'list'],
            'status_history.*' => ['required', 'array'],
            'status_history.*.status' => ['required', $status],
            'status_history.*.inserted_at' => ['required', 'date'],
            'status_history.*.inserted_by' => ['required', 'uuid'],
            'inserted_at' => ['required', 'date'],
            'updated_at' => ['required', 'date'],
            'inserted_by' => ['required', 'uuid'],
            'updated_by' => ['required', 'uuid'],
            'note' => ['sometimes', 'nullable', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
            'context' => ['sometimes', 'nullable', 'string'],
            'inform_with' => ['sometimes', 'nullable', 'array', 'min:1'],
            'inform_with.auth_method_id' => ['required_with:inform_with', 'uuid'],
        ];

        foreach (['category', 'terms_of_service', 'addresses.*'] as $concept) {
            $rules += $this->conceptRules($concept, 'required');
        }
        foreach (['status_reason', 'status_history.*.status_reason'] as $concept) {
            $rules += $this->conceptRules($concept, 'required_with:' . $concept);
        }
        foreach (['encounter', 'author', 'subject', 'managing_organization'] as $reference) {
            $rules += $this->referenceRules($reference, 'required');
        }
        foreach (['based_on', 'part_of', 'care_manager'] as $reference) {
            $rules += $this->referenceRules($reference, 'required_with:' . $reference);
        }
        foreach (['contributor', 'supporting_info'] as $field) {
            $rules[$field] = ['sometimes', 'array', 'list'];
            $rules += $this->referenceRules($field . '.*', 'required');
        }
        $rules['managing_organization.display_value'] = ['required', 'string'];

        return $rules;
    }

    protected function conceptRules(string $path, string $required): array
    {
        return [
            $path => [$required === 'required' ? 'required' : 'sometimes', 'array', 'min:1'],
            $path . '.coding' => [$required, 'array', 'list', 'min:1'],
            $path . '.coding.*' => ['required', 'array'],
            $path . '.coding.*.code' => ['required', 'string'],
            $path . '.coding.*.system' => ['required', 'string'],
            $path . '.coding.*.display' => ['sometimes', 'nullable', 'string'],
            $path . '.text' => ['sometimes', 'nullable', 'string'],
        ];
    }

    protected function referenceRules(string $path, string $required): array
    {
        return [
            $path => [$required === 'required' ? 'required' : 'sometimes', 'array', 'min:1'],
            $path . '.identifier' => [$required, 'array'],
            $path . '.identifier.value' => [$required, 'uuid'],
            $path . '.display_value' => ['sometimes', 'nullable', 'string'],
        ] + $this->conceptRules($path . '.identifier.type', $required);
    }

    protected function validateSnapshot(EHealthResponse $response): array
    {
        return $this->validateDetails($response, preserveApiKeys: true);
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
