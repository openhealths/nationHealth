<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api\Patient;

use App\Classes\eHealth\Api\Job;
use App\Classes\eHealth\Api\Responses\Collections\ServiceRequestDetails;
use App\Classes\eHealth\Api\ServiceRequest as ServiceRequestExecutorApi;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Errors\ServiceRequestErrorTranslator;
use App\Contracts\ApiErrorTranslator;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use stdClass;

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
        $this->setValidator(Job::validateSubmissionResponse(...));

        return $this->post(self::URL . "/{$patientId}/service_requests", $payload);
    }

    /**
     * Cancel a Service Request (Скасування направлення як entered-in-error).
     */
    public function cancel(string $patientId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator(Job::validateSubmissionResponse(...));

        return $this->patch(self::URL . "/{$patientId}/service_requests/{$id}/actions/cancel", $payload);
    }

    /**
     * Recall a Service Request (відміна за непотрібністю, TV 3.17.1.13).
     *
     * @param  array<string, mixed>  $payload
     */
    public function recall(string $patientId, string $id, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator(Job::validateSubmissionResponse(...));

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
        $this->setValidator($this->validateMany(...));

        $query = array_merge($this->options['query'], $query);

        return $this->get(self::URL . "/{$patientId}/service_requests", $query);
    }

    /**
     * Get a patient's referral by UUID. Calling validate() returns ServiceRequestDetails with eHealth field names.
     * Its signaturePayload preserves nested JSON objects and eHealth extension fields.
     *
     * @see https://docs-preprod.ehealth.gov.ua/specs/medical-events.yaml Get Service request details
     */
    public function getDetails(string $patientId, string $id, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator(fn (EHealthResponse $response): ServiceRequestDetails => $this->validateDetails($response, $patientId, $id));

        return $this->get(self::URL . "/{$patientId}/service_requests/{$id}", $query);
    }

    /**
     * Get the same patient referral for callers that expect an array from validate().
     * By default id becomes uuid and audit fields use their local names.
     * Set preserveApiKeys to keep the field names supplied by eHealth.
     *
     * @deprecated Use getDetails()->validate(). Remove this adapter after the array and getData() callers migrate.
     */
    public function getById(string $patientId, string $id, array $query = [], bool $preserveApiKeys = false): EHealthResponse
    {
        $this->setValidator(function (EHealthResponse $response) use ($patientId, $id, $preserveApiKeys): array {
            $details = $this->validateDetails($response, $patientId, $id)->all();

            return $preserveApiKeys ? $details : $this->replaceEHealthPropNames($details);
        });

        $response = $this->get(self::URL . "/{$patientId}/service_requests/{$id}", $query);
        if (!$preserveApiKeys) {
            // The legacy lifecycle service imports getData() directly, so check the referral before returning it.
            $response->validate();
        }

        return $response;
    }

    /**
     * Pre-qualify service request data before creation.
     *
     * @see REST API PreQualify Service Request [API-007-062-0001]
     */
    public function prequalify(string $patientId, array $payload): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/{$patientId}/service_requests/prequalify", $payload);
    }

    /**
     * Resend SMS with OTP for an active service request.
     *
     * @see REST API Resend SMS on Service Request [API-007-062-0009]
     */
    public function resendSms(string $patientId, string $id): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/{$patientId}/service_requests/{$id}/actions/resend", []);
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

    /** Validate the response from the eHealth Get Service request details endpoint. */
    protected function validateDetails(EHealthResponse $response, string $patientId, string $id): ServiceRequestDetails
    {
        $document = $response->object()->data ?? null;
        $data = $response->json('data');
        $rules = $this->detailRules($patientId, $id);
        $types = [];

        // Associative decoding loses the difference between {} and []. Keep the original types for the schema rules.
        foreach ($rules as $field => $rule) {
            $fieldRules = is_array($rule) ? $rule : explode('|', $rule);
            if (!in_array('array', $fieldRules, true)) {
                continue;
            }

            $value = data_get($document, $field);
            $values = str_contains($field, '*') ? ($value ?? []) : [$value];
            $group = in_array('list', $fieldRules, true) ? 'lists' : 'objects';
            foreach ($values as $item) {
                if ($item !== null) {
                    $types[$group][] = get_debug_type($item);
                }
            }
        }

        $validator = Validator::make([
            'http_status' => $response->status(),
            ...array_intersect_key((array) $response->object(), ['error' => true]),
            'data' => $data,
            'types' => $types,
        ], [
            'http_status' => 'in:200',
            'error' => 'missing',
            'data' => 'required|array',
            'types.lists.*' => 'in:array',
            'types.objects.*' => 'in:' . stdClass::class,
            ...collect($rules)->mapWithKeys(static fn (string|array $rule, string $field): array => ["data.$field" => $rule])->all(),
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('ServiceRequest details validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        $validator->validate();

        return new ServiceRequestDetails($data, (array) $document);
    }

    /** Rules for the complete patient referral returned by GET /api/patients/{patient_id}/service_requests/{service_request_id}. */
    protected function detailRules(string $patientId, string $id): array
    {
        $patientIdentity = 'regex:/^' . preg_quote($patientId, '/') . '$/iD';
        $rules = [
            'id' => ['required', 'uuid', 'regex:/^' . preg_quote($id, '/') . '$/iD'],
            'requisition' => 'present|string',
            'status' => 'required|string',
            'intent' => 'present|string',
            'priority' => 'string',
            'program_processing_status' => 'string',
            'category' => 'present|array|required_array_keys:coding',
            'performer_type' => 'array|required_array_keys:coding',
            'status_reason' => 'array|required_array_keys:coding',
            'status_history' => 'present|array|list',
            'status_history.*' => 'array|required_array_keys:status,inserted_at',
            'status_history.*.status' => 'string',
            'status_history.*.inserted_at' => 'string',
            'status_history.*.status_reason' => 'array|required_array_keys:coding',
            'program_processing_status_history' => 'array|list',
            'program_processing_status_history.*' => 'array|required_array_keys:program_processing_status,inserted_at,inserted_by',
            'program_processing_status_history.*.program_processing_status' => 'string',
            'program_processing_status_history.*.inserted_at' => 'string',
            'program_processing_status_history.*.inserted_by' => 'string',
            'note' => 'present|string',
            'patient_instruction' => 'present|string',
            'expiration_date' => 'present|string',
            'explanatory_letter' => 'string',
            'inserted_at' => 'string',
            'updated_at' => 'string',
            'occurrence_period' => 'required_without:data.occurrence_date_time|missing_with:data.occurrence_date_time|array|required_array_keys:start',
            'occurrence_period.start' => 'string',
            'occurrence_period.end' => 'string',
            'occurrence_date_time' => 'required_without:data.occurrence_period|string',
            'inform_with' => 'array',
            'inform_with.auth_method_id' => 'string',
        ];

        foreach (['quantity', 'remaining_quantity'] as $field) {
            $rules[$field] = 'present|array|required_array_keys:value,system,code';
            $rules["$field.value"] = 'required|numeric:strict';
            $rules["$field.system"] = 'string';
            $rules["$field.code"] = 'string';
            $rules["$field.unit"] = 'string';
        }

        $references = [
            'code' => 'present|array|required_array_keys:display_value',
            'subject' => 'present|array|required_array_keys:display_value',
            'requester_employee' => 'present|array|required_array_keys:display_value',
            'requester_legal_entity' => 'present|array|required_array_keys:display_value',
            'used_by_employee' => 'present|array|required_array_keys:display_value',
            'used_by_legal_entity' => 'present|array|required_array_keys:display_value',
            'program' => 'array|required_array_keys:display_value',
            'context' => 'array|required_array_keys:display_value',
            'program_service' => 'array|required_array_keys:identifier,display_value',
            'completed_with' => 'array|required_array_keys:identifier,display_value',
            'performer' => 'array|required_array_keys:identifier,display_value',
            'location_reference' => 'array|required_array_keys:identifier,display_value',
        ];
        foreach (['used_by_employee', 'used_by_legal_entity'] as $field) {
            $history = $field . '_history';
            $rules[$history] = 'array|list';
            $rules["$history.*"] = "array|required_array_keys:$field,inserted_at,inserted_by";
            $rules["$history.*.inserted_at"] = 'string';
            $rules["$history.*.inserted_by"] = 'string';
            $references["$history.*.$field"] = 'array|required_array_keys:display_value';
        }
        foreach (['reason_reference', 'permitted_resources', 'based_on', 'supporting_info', 'specimens', 'focus'] as $field) {
            $rules[$field] = $field === 'reason_reference' ? 'present|array|list' : 'array|list';
            $references["$field.*"] = $field === 'based_on' ? 'array' : 'array|required_array_keys:display_value';
        }

        $concepts = ['category', 'performer_type', 'status_reason', 'status_history.*.status_reason'];
        foreach ($references as $field => $rule) {
            $rules[$field] = $rule;
            $rules["$field.display_value"] = 'string';
            $type = "$field.identifier.type";
            $clinicalLink = in_array($field, ['reason_reference.*', 'permitted_resources.*'], true);
            $rules["$field.identifier"] = $clinicalLink ? 'array|required_array_keys:value' : 'array|required_array_keys:type,value';
            $rules["$field.identifier.value"] = 'string';
            $rules[$type] = in_array($field, ['specimens.*', 'focus.*'], true) ? 'array|required_array_keys:coding' : 'array';
            $concepts[] = $type;
        }
        foreach ($concepts as $field) {
            $rules["$field.text"] = 'string';
            $rules["$field.coding"] = 'array|list';
            $codeOptional = in_array($field, ['reason_reference.*.identifier.type', 'permitted_resources.*.identifier.type',
                'specimens.*.identifier.type', 'focus.*.identifier.type'], true);
            $rules["$field.coding.*"] = $codeOptional ? 'array|required_array_keys:system' : 'array|required_array_keys:system,code';
            $rules["$field.coding.*.system"] = 'string';
            $rules["$field.coding.*.code"] = 'string';
        }

        $rules['subject.identifier.value'] = ['sometimes', 'required', 'string', $patientIdentity];
        $rules['patient.identifier.value'] = ['sometimes', 'required', 'string', $patientIdentity];

        return $rules;
    }

    protected function validateMany(EHealthResponse $response): array
    {
        $transformedData = [];
        $items = $response->getData();
        if (isset($items['data']) && is_array($items['data'])) {
            $items = $items['data'];
        }
        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_array($item)) {
                    $transformedData[] = $this->replaceEHealthPropNames($item);
                }
            }
        }

        $validator = Validator::make($transformedData, [
            '*' => 'array',
            '*.uuid' => 'required|string',
            '*.status' => 'required|string',
            '*.requisition' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'ServiceRequest many validation failed: ' . implode(', ', $validator->errors()->all())
            );
            throw new ValidationException($validator);
        }

        return $response->getData();
    }
}
