<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\Api\Job;
use App\Classes\eHealth\Api\Patient\PatientApiBase;
use App\Classes\eHealth\Api\ServiceRequest as ServiceRequestExecutorApi;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Errors\ServiceRequestErrorTranslator;
use App\Contracts\ApiErrorTranslator;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ServiceRequest extends PatientApiBase
{
    protected function errorTranslator(): ApiErrorTranslator
    {
        return new ServiceRequestErrorTranslator();
    }

    /**
     * Create a signed Service Request in eHealth (PKCS#7).
     *
     * @see REST API Create Service Request [API-007-062-0002]
     */
    public function createSigned(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateSubmission(...));

        return $this->post(self::URL . "/{$patientId}/service_requests", $payload);
    }

    /**
     * Cancel a Service Request (Скасування направлення як entered-in-error).
     */
    public function cancel(string $patientId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateSubmission(...));

        return $this->patch(self::URL . "/{$patientId}/service_requests/{$id}/actions/cancel", $payload);
    }

    /**
     * Recall a Service Request (відміна за непотрібністю, TV 3.17.1.13).
     *
     * @param  array<string, mixed>  $payload
     */
    public function recall(string $patientId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateSubmission(...));

        return $this->patch(self::URL . "/{$patientId}/service_requests/{$id}/actions/recall", $payload);
    }

    /**
     * Search for Service Requests by parameters.
     */
    public function searchForServiceRequestsByParams(array $params): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateMany(...));

        return $this->get('/api/service_requests', $params);
    }

    /**
     * Get Service Requests by search parameters in patient context.
     *
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getBySearchParams(string $patientId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();
        // Patient-context lists omit the requisition-search identity projection in the official contract.
        $this->setValidator(fn (EHealthResponse $response): array => $this->validateMany($response, false));

        $query = array_merge($this->options['query'], $query);

        return $this->get(self::URL . "/{$patientId}/service_requests", $query);
    }

    /**
     * Get a specific Service Request by ID.
     */
    public function getById(string $patientId, string $id, array $query = [], bool $preserveApiKeys = false): PromiseInterface|EHealthResponse
    {
        $this->setValidator(fn (EHealthResponse $response): array => $this->validateDetails($response, $preserveApiKeys));

        return $this->get(self::URL . "/{$patientId}/service_requests/{$id}", $query);
    }

    /**
     * Pre-qualify service request data before creation.
     *
     * @see REST API PreQualify Service Request [API-007-062-0001]
     */
    public function prequalify(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateQualification(...));

        return $this->post(self::URL . "/{$patientId}/service_requests/prequalify", $payload);
    }

    /**
     * Resend SMS with OTP for an active service request.
     *
     * @see REST API Resend SMS on Service Request [API-007-062-0009]
     */
    public function resendSms(string $patientId, string $id): PromiseInterface|EHealthResponse
    {
        $this->setValidator(function (EHealthResponse $response): array {
            // The documented 200 response is empty; 201 returns a resource and 202 a receipt.
            if ($response->getStatusCode() === 200 && $response->getData() === []) {
                return [];
            }

            return $response->getStatusCode() === 202
                ? $this->validateSubmission($response)
                : $this->validateResourceData($response->getData());
        });

        return $this->patch(self::URL . "/{$patientId}/service_requests/{$id}/actions/resend");
    }

    /**
     * Executor actions live on the facility-scoped Service Request API.
     * EHealth::serviceRequest() is bound to this Patient client for create/search,
     * so these methods delegate to Api\ServiceRequest for use / complete / qualify.
     */
    public function qualify(string $id, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->executorApi()->qualify($id, $payload);
    }

    public function process(string $id, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->executorApi()->process($id, $payload);
    }

    public function complete(string $id, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->executorApi()->complete($id, $payload);
    }

    public function cancelUsage(string $id, string $patientId, array $payload = []): PromiseInterface|EHealthResponse
    {
        return $this->executorApi()->cancelUsage($id, $patientId, $payload);
    }

    private function executorApi(): ServiceRequestExecutorApi
    {
        return app(ServiceRequestExecutorApi::class);
    }

    /** Specimen-context reads must not fall back to unrestricted requisition search. */
    public function getBySpecimen(string $specimenUuid, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();
        $this->setValidator(fn (EHealthResponse $response): array => $this->validateMany($response, false));

        return $this->get("/api/specimens/{$specimenUuid}/service_requests", array_merge($this->options['query'], $query));
    }

    protected function validateDetails(EHealthResponse $response, bool $preserveApiKeys = false): array
    {
        $native = $response->getData();
        $this->validateResourceData($native);

        // Validation precedes both native-key signing snapshots and application projections.
        return $preserveApiKeys ? $native : $this->replaceEHealthPropNames($native);
    }

    protected function validateMany(EHealthResponse $response, bool $requireIdentity = true): array
    {
        Validator::make($response->json(), [
            'data' => ['present', 'array', 'list'],
            'paging' => ['sometimes', 'array'],
            'paging.page' => ['required_with:paging', 'integer', 'min:1'],
            'paging.page_size' => ['required_with:paging', 'integer', 'min:1'],
            'paging.total_entries' => ['required_with:paging', 'integer', 'min:0'],
            'paging.total_pages' => ['required_with:paging', 'integer', 'min:0'],
        ])->validate();
        $items = $response->getData();
        if (!array_is_list($items)) {
            throw ValidationException::withMessages(['data' => 'Service Request data must be a list.']);
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw ValidationException::withMessages(['data' => 'Every Service Request must be an object.']);
            }
            $this->validateResourceData($item);
            if ($requireIdentity) {
                Validator::make($item, ['identity' => ['required', 'array'], 'identity.last_name' => ['present', 'nullable', 'string'], 'identity.first_name' => ['present', 'nullable', 'string'], 'identity.second_name' => ['present', 'nullable', 'string'], 'identity.gender' => ['required', 'string'], 'identity.age' => ['required', 'integer', 'min:0']])->validate();
            }
        }

        return $items;
    }

    /** API-007-062-0001 and Qualify by ID return synchronous program results. */
    public function validateQualification(EHealthResponse $response): array
    {
        Validator::make($response->json(), ['data' => ['present', 'array', 'list']])->validate();
        $data = $response->getData();
        if (!array_is_list($data)) {
            throw ValidationException::withMessages(['data' => 'Qualification data must be a list.']);
        }
        Validator::make(['data' => $data], [
            'data' => ['present', 'array'],
            'data.*' => ['required', 'array'],
            'data.*.program_id' => ['required', 'uuid'],
            'data.*.program_name' => ['required', 'string'],
            'data.*.status' => ['required', 'in:VALID,INVALID'],
            'data.*.rejection_reason' => ['sometimes', 'string'],
        ])->validate();

        return $data;
    }

    public function validateSubmission(EHealthResponse $response): array
    {
        $data = Job::validateSubmissionResponse($response);
        Validator::make($data, ['status' => ['required', 'string']])->validate();

        return $data;
    }

    /** Validate every native final resource before choosing the submitted record's result. */
    public function validateSubmissionResult(array $data): array
    {
        if ($data === []) {
            // A processed status-only acknowledgement confirms the submitted snapshot.
            return [];
        }
        if (array_is_list($data)) {
            $results = [];
            foreach ($data as $item) {
                if (!is_array($item)) {
                    throw ValidationException::withMessages(['result' => 'Every Service Request result must be an object.']);
                }
                $results[] = $this->validateSubmissionResult($item);
            }

            return $results[0] ?? [];
        }
        foreach (['response_data', 'result', 'data'] as $key) {
            if (array_key_exists($key, $data)) {
                if (!is_array($data[$key])) {
                    throw ValidationException::withMessages(['result' => 'The Service Request result must be an object or a list.']);
                }

                return $this->validateSubmissionResult($data[$key]);
            }
        }
        $receiptKeys = ['id', 'status', 'job_id', 'status_code', 'links', 'eta'];
        if (array_diff(array_keys($data), $receiptKeys) === []) {
            // Native job receipts may carry only the changed status; resource fields require the full contract.
            Validator::make($data, ['id' => ['sometimes', 'uuid'], 'status' => ['required', 'string']])->validate();

            return $data;
        }

        return $this->validateResourceData($data);
    }

    /** Medical Events 9.15.6 Service Request response contract, before any mapping. */
    public function validateResourceData(array $data): array
    {
        $rules = [
            'id' => ['required', 'uuid'],
            'requisition' => ['required', 'string'],
            'status' => ['required', 'string'],
            'intent' => ['required', 'string'],
            'priority' => ['sometimes', 'string'],
            'status_history' => ['present', 'array', 'list'],
            'status_history.*' => ['required', 'array'],
            'status_history.*.status' => ['required', 'string'],
            'status_history.*.inserted_at' => ['required', 'date'],
            'status_history.*.status_reason' => ['sometimes', 'array'],
            'status_history.*.status_reason.coding' => ['required_with:status_history.*.status_reason', 'array', 'list'],
            'status_history.*.status_reason.coding.*.system' => ['required', 'string'],
            'status_history.*.status_reason.coding.*.code' => ['required', 'string'],
            'status_history.*.status_reason.text' => ['sometimes', 'string'],
            'program_processing_status' => ['sometimes', 'string'],
            'note' => ['present', 'string'],
            'patient_instruction' => ['present', 'string'],
            'expiration_date' => ['required', 'date'],
            'inserted_at' => ['sometimes', 'date'],
            'updated_at' => ['sometimes', 'date'],
            'occurrence_period' => ['required_without:occurrence_date_time', 'array'],
            'occurrence_period.start' => ['required_with:occurrence_period', 'date'],
            'occurrence_period.end' => ['sometimes', 'date', 'after_or_equal:occurrence_period.start'],
            'occurrence_date_time' => ['required_without:occurrence_period', 'date'],
            'identity' => ['sometimes', 'array'],
            'identity.first_name' => ['sometimes', 'nullable', 'string'],
            'identity.last_name' => ['sometimes', 'nullable', 'string'],
            'identity.second_name' => ['sometimes', 'nullable', 'string'],
            'identity.gender' => ['sometimes', 'string'],
            'identity.age' => ['sometimes', 'integer', 'min:0'],
            'inform_with' => ['sometimes', 'array'],
            'inform_with.auth_method_id' => ['sometimes', 'uuid'],
            'explanatory_letter' => ['sometimes', 'string'],
        ];
        foreach (['category', 'performer_type', 'status_reason'] as $field) {
            $rules[$field] = [$field === 'category' ? 'required' : 'sometimes', 'array'];
            $rules[$field.'.coding'] = ['required_with:'.$field, 'array', 'min:1'];
            $rules[$field.'.coding.*.system'] = ['required', 'string'];
            $rules[$field.'.coding.*.code'] = ['required', 'string'];
            $rules[$field.'.text'] = ['sometimes', 'string'];
        }
        foreach (['code', 'subject', 'requester_employee', 'requester_legal_entity', 'used_by_employee', 'used_by_legal_entity', 'program', 'program_service', 'context', 'completed_with', 'performer', 'location_reference'] as $field) {
            $required = in_array($field, ['code', 'subject', 'requester_employee', 'requester_legal_entity', 'used_by_employee', 'used_by_legal_entity'], true);
            $rules += $this->referenceRules($field, $required, identifierRequired: in_array($field, ['program_service', 'completed_with', 'performer', 'location_reference'], true));
        }
        foreach (['reason_reference', 'supporting_info', 'permitted_resources', 'based_on', 'specimens', 'focus'] as $field) {
            $rules[$field] = [$field === 'reason_reference' ? 'present' : 'sometimes', 'array'];
            $rules[$field][] = 'list';
            $rules += $this->referenceRules($field.'.*', true, displayRequired: $field !== 'based_on');
        }
        foreach (['quantity', 'remaining_quantity'] as $field) {
            $rules[$field] = ['sometimes', 'array'];
            $rules[$field.'.value'] = ['required_with:'.$field, 'numeric', 'min:0'];
            $rules[$field.'.system'] = ['required_with:'.$field, 'string'];
            $rules[$field.'.code'] = ['required_with:'.$field, 'string'];
            $rules[$field.'.unit'] = ['sometimes', 'string'];
        }
        foreach (['program_processing_status_history', 'used_by_employee_history', 'used_by_legal_entity_history'] as $field) {
            $rules[$field] = ['sometimes', 'array', 'list'];
            $rules[$field.'.*.inserted_at'] = ['required', 'date'];
            $rules[$field.'.*.inserted_by'] = ['required', 'uuid'];
            if ($field === 'program_processing_status_history') {
                $rules[$field.'.*.program_processing_status'] = ['required', 'string'];
            } else {
                $reference = $field === 'used_by_employee_history' ? 'used_by_employee' : 'used_by_legal_entity';
                $rules += $this->referenceRules($field.'.*.'.$reference, true);
            }
        }
        Validator::make($data, $rules)->after(static function ($validator) use ($data): void {
            if (array_key_exists('occurrence_period', $data) && array_key_exists('occurrence_date_time', $data)) {
                $validator->errors()->add('occurrence_period', 'Only one occurrence representation is allowed.');
            }
        })->validate();

        return $data;
    }

    private function referenceRules(string $field, bool $required, bool $displayRequired = true, bool $identifierRequired = false): array
    {
        return [
            $field => [$required ? 'required' : 'sometimes', 'array'],
            $field.'.identifier' => [$identifierRequired ? 'required_with:'.$field : 'sometimes', 'array'],
            $field.'.identifier.value' => ['required_with:'.$field.'.identifier', 'uuid'],
            $field.'.identifier.type' => ['required_with:'.$field.'.identifier', 'array'],
            $field.'.identifier.type.text' => ['sometimes', 'string'],
            $field.'.identifier.type.coding' => ['required_with:'.$field.'.identifier.type', 'array', 'min:1'],
            $field.'.identifier.type.coding.*.system' => ['required', 'string'],
            $field.'.identifier.type.coding.*.code' => ['required', 'string'],
            $field.'.display_value' => [$displayRequired ? 'present_with:'.$field : 'sometimes', 'string'],
        ];
    }
}
