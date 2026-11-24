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

    /** Fetch one native, validated ServiceRequest for DTO mapping. */
    public function getDetails(string $patientId, string $id, array $query = []): PromiseInterface|EHealthResponse
    {
        return $this->requestDetails(
            $patientId,
            $id,
            $query,
            fn (EHealthResponse $response): ServiceRequestDetails => $this->validateDetails($response, $patientId, $id)
        );
    }

    /** Preserve array results for existing signing, import and execution callers. */
    public function getById(string $patientId, string $id, array $query = [], bool $preserveApiKeys = false): PromiseInterface|EHealthResponse
    {
        $response = $this->requestDetails($patientId, $id, $query, function (EHealthResponse $response) use ($patientId, $id, $preserveApiKeys): array {
            $native = $this->validateDetails($response, $patientId, $id, $preserveApiKeys)->all();
            if ($preserveApiKeys) {
                return $native;
            }

            return $this->replaceEHealthPropNames($native);
        });
        if ($preserveApiKeys) {
            return $response;
        }

        // Legacy referral workflows read getData() directly. Check their response before exposing it.
        $validate = static fn (EHealthResponse $detail): EHealthResponse => tap($detail)->validate();

        return $response instanceof PromiseInterface ? $response->then($validate) : $validate($response);
    }

    protected function requestDetails(string $patientId, string $id, array $query, callable $validator): PromiseInterface|EHealthResponse
    {
        $this->setValidator($validator);

        return $this->get(self::URL . "/{$patientId}/service_requests/{$id}", $query);
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

    /** Validate the native GET detail before returning either representation to its caller. */
    protected function validateDetails(EHealthResponse $response, string $patientId, string $id, bool $preserveApiKeys = false): ServiceRequestDetails
    {
        $envelope = $response->object();
        $detail = $envelope->data ?? null;
        if (!$response->ok() || !$detail instanceof stdClass || isset($envelope->error)) {
            throw ValidationException::withMessages(['data' => __('care-plan.referral_full_details_required')]);
        }

        $this->validateDetailFields($detail, 'data', [
            'string' => ['id', 'requisition', 'status', 'intent', 'note', 'patient_instruction', 'expiration_date'],
            'array' => ['status_history', 'reason_reference'],
            stdClass::class => ['category', 'code', 'subject', 'requester_employee', 'requester_legal_entity',
                'used_by_employee', 'used_by_legal_entity', 'quantity', 'remaining_quantity'],
        ], [
            'string' => ['priority', 'program_processing_status', 'explanatory_letter', 'inserted_at', 'updated_at', 'occurrence_date_time'],
            'array' => ['based_on', 'supporting_info', 'permitted_resources', 'specimens', 'focus',
                'program_processing_status_history', 'used_by_employee_history', 'used_by_legal_entity_history'],
            stdClass::class => ['program', 'context', 'program_service', 'completed_with', 'performer', 'location_reference',
                'performer_type', 'status_reason', 'occurrence_period', 'inform_with'],
        ]);

        Validator::make((array) $detail, ['id' => 'required|uuid', 'status' => 'required|string'])->validate();
        if (strcasecmp($detail->id, $id) !== 0) {
            throw ValidationException::withMessages(['id' => __('care-plan.referral_full_details_required')]);
        }

        if (isset($detail->occurrence_period) === isset($detail->occurrence_date_time)) {
            throw ValidationException::withMessages(['occurrence_period' => __('care-plan.referral_full_details_required')]);
        }
        if (isset($detail->occurrence_period)) {
            $this->validateDetailFields($detail->occurrence_period, 'occurrence_period', ['string' => ['start']], ['string' => ['end']]);
        }
        if (isset($detail->inform_with)) {
            $this->validateDetailFields($detail->inform_with, 'inform_with', [], ['string' => ['auth_method_id']]);
        }

        foreach (['quantity', 'remaining_quantity'] as $key) {
            $this->validateDetailFields($detail->$key, $key, ['string' => ['system', 'code'], 'number' => ['value']], ['string' => ['unit']]);
        }
        foreach (['category', 'performer_type', 'status_reason'] as $key) {
            if (isset($detail->$key)) {
                $this->validateDetailConcept($detail->$key, $key);
            }
        }
        foreach (['code', 'subject', 'requester_employee', 'requester_legal_entity', 'used_by_employee', 'used_by_legal_entity',
            'program', 'context', 'program_service', 'completed_with', 'performer', 'location_reference'] as $key) {
            if (isset($detail->$key)) {
                $this->validateDetailReference($detail->$key, $key, $key);
            }
        }
        foreach (['based_on', 'reason_reference', 'permitted_resources', 'supporting_info', 'specimens', 'focus'] as $key) {
            foreach ($detail->$key ?? [] as $index => $reference) {
                $this->validateDetailReference($reference, "$key.$index", $key);
            }
        }
        $this->validateDetailHistory($detail);

        // Existing imports also accept the legacy patient reference when subject is display-only.
        foreach (['subject.identifier.value', 'patient.identifier.value'] as $path) {
            $value = data_get($detail, $path);
            if ($value !== null && (!is_string($value) || strcasecmp($value, $patientId) !== 0)) {
                throw ValidationException::withMessages([$path => __('care-plan.referral_full_details_required')]);
            }
        }

        return new ServiceRequestDetails($preserveApiKeys ? $this->snapshotFields((array) $detail) : $response->getData());
    }

    /** Keep JSON objects that PHP would otherwise encode as lists in the signed snapshot. */
    protected function snapshotFields(array $fields): array
    {
        foreach ($fields as $key => $value) {
            if ($value instanceof stdClass) {
                $properties = (array) $value;
                $fields[$key] = array_is_list($properties) ? $value : $this->snapshotFields($properties);
            } elseif (is_array($value)) {
                $fields[$key] = $this->snapshotFields($value);
            }
        }

        return $fields;
    }

    protected function validateDetailHistory(stdClass $detail): void
    {
        foreach ($detail->status_history as $index => $entry) {
            $path = "status_history.$index";
            $this->validateDetailFields($entry, $path, ['string' => ['status', 'inserted_at']], [stdClass::class => ['status_reason']]);
            if (isset($entry->status_reason)) {
                $this->validateDetailConcept($entry->status_reason, "$path.status_reason");
            }
        }
        foreach ($detail->program_processing_status_history ?? [] as $index => $entry) {
            $this->validateDetailFields($entry, "program_processing_status_history.$index", [
                'string' => ['program_processing_status', 'inserted_at', 'inserted_by'],
            ]);
        }
        foreach (['used_by_employee', 'used_by_legal_entity'] as $key) {
            $history = $key . '_history';
            foreach ($detail->$history ?? [] as $index => $entry) {
                $path = "$history.$index";
                $this->validateDetailFields($entry, $path, ['string' => ['inserted_at', 'inserted_by'], stdClass::class => [$key]]);
                $this->validateDetailReference($entry->$key, "$path.$key", $key);
            }
        }
    }

    protected function validateDetailReference(mixed $reference, string $path, string $field): void
    {
        $required = $field === 'based_on' ? [] : ['string' => ['display_value']];
        $identifier = [stdClass::class => ['identifier']];
        $requiresIdentifier = in_array($field, ['program_service', 'completed_with', 'performer', 'location_reference'], true);
        $this->validateDetailFields($reference, $path, $requiresIdentifier ? $required + $identifier : $required, $identifier);

        if (isset($reference->identifier)) {
            $clinicalLink = in_array($field, ['reason_reference', 'permitted_resources'], true);
            $type = [stdClass::class => ['type']];
            $this->validateDetailFields($reference->identifier, "$path.identifier", ['string' => ['value']] + ($clinicalLink ? [] : $type), $type);
            if (isset($reference->identifier->type)) {
                $specimenOrFocus = in_array($field, ['specimens', 'focus'], true);
                $this->validateDetailConcept($reference->identifier->type, "$path.identifier.type", $specimenOrFocus, !$clinicalLink && !$specimenOrFocus);
            }
        }
    }

    protected function validateDetailConcept(mixed $concept, string $path, bool $requiresCoding = true, bool $requiresCode = true): void
    {
        $coding = ['array' => ['coding']];
        $this->validateDetailFields($concept, $path, $requiresCoding ? $coding : [], $coding + ['string' => ['text']]);
        foreach ($concept->coding ?? [] as $index => $item) {
            $this->validateDetailFields($item, "$path.coding.$index", ['string' => $requiresCode ? ['system', 'code'] : ['system']], ['string' => ['code']]);
        }
    }

    /** Check wire types before associative decoding loses the distinction between an empty object and a list. */
    protected function validateDetailFields(mixed $object, string $path, array $required, array $optional = []): void
    {
        if (!$object instanceof stdClass) {
            throw ValidationException::withMessages([$path => __('care-plan.referral_full_details_required')]);
        }

        foreach (array_merge_recursive($required, $optional) as $type => $fields) {
            foreach (array_unique($fields) as $field) {
                if (!property_exists($object, $field) && !in_array($field, $required[$type] ?? [], true)) {
                    continue;
                }

                $value = $object->$field ?? null;
                $valid = $type === 'number' ? is_int($value) || is_float($value) : get_debug_type($value) === $type;
                if (!$valid) {
                    throw ValidationException::withMessages(["$path.$field" => __('care-plan.referral_full_details_required')]);
                }
            }
        }
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
