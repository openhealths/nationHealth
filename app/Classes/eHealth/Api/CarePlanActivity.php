<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\Api\Job;
use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CarePlanActivity extends Request
{
    protected const string URL = '/api/care_plans';

    /**
     * Create a new Care Plan Activity in eHealth.
     *
     * @param  string  $patientId
     * @param  string  $carePlanId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function create(string $patientId, string $carePlanId, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator(Job::validateSubmissionResponse(...));

        return $this->post("/api/patients/{$patientId}/care_plans/{$carePlanId}/activities", $payload);
    }

    /**
     * Cancel a Care Plan Activity (API-007-006-0005).
     * Requires Digital Signature (DS). Request body is signed_data only; the signed PKCS#7
     * content must be the activity from DB with $.detail.status_reason set.
     *
     * @param  string  $personId
     * @param  string  $carePlanId
     * @param  string  $activityId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function cancel(string $personId, string $carePlanId, string $activityId, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator(Job::validateSubmissionResponse(...));

        return $this->patch("/api/patients/$personId/care_plans/$carePlanId/activities/$activityId/actions/cancel", $payload);
    }

    /**
     * Complete a Care Plan Activity.
     * Note: This does NOT require a Digital Signature (DS).
     * The payload must contain 'outcome_codeable_concept'.
     *
     * @param  string  $personId
     * @param  string  $carePlanId
     * @param  string  $activityId
     * @param  array  $payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function complete(string $personId, string $carePlanId, string $activityId, array $payload): PromiseInterface|EHealthResponse
    {
        $this->setValidator(Job::validateSubmissionResponse(...));

        return $this->patch("/api/patients/$personId/care_plans/$carePlanId/activities/$activityId/actions/complete", $payload);
    }

    /**
     * Fetch a summary of Care Plan Activities.
     *
     * @param  string  $personId
     * @param  string  $carePlanId
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     */
    public function getSummary(string $personId, string $carePlanId, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateMany(...));

        return $this->get("/api/patients/$personId/care_plans/$carePlanId/activities", $query);
    }

    /**
     * Fetch details of a single Care Plan Activity.
     *
     * @param  string  $personId
     * @param  string  $carePlanId
     * @param  string  $activityId
     * @return PromiseInterface|EHealthResponse
     */
    public function getDetails(string $personId, string $carePlanId, string $activityId, bool $preserveApiKeys = false): PromiseInterface|EHealthResponse
    {
        $this->setValidator(fn (EHealthResponse $response): array => $this->validateDetails($response, $preserveApiKeys));

        return $this->get("/api/patients/$personId/care_plans/$carePlanId/activities/$activityId");
    }

    protected function validateDetails(EHealthResponse $response, bool $preserveApiKeys = false): array
    {
        $native = $response->getData();
        if (array_is_list($native) && count($native) === 1) {
            $native = $native[0];
        }
        $this->validateResource($native);

        // Validate the native snapshot before any signing or presentation projection.
        return $preserveApiKeys ? $native : Arr::toCamelCase($native);
    }

    protected function validateMany(EHealthResponse $response): array
    {
        $native = $response->getData();
        Validator::make(['data' => $native], ['data' => ['present', 'array', 'list'], 'data.*' => ['required', 'array']])->validate();
        foreach ($native as $item) {
            $this->validateResource($item);
        }

        return Arr::toCamelCase($native);
    }

    private function validateResource(array $native): void
    {
        $rules = require resource_path('ehealth/care-plan-activity-response-rules.php');
        $validator = Validator::make(['resource' => $native], $rules);
        $validator->after(static function ($validator) use ($native): void {
            $detail = $native['detail'] ?? [];
            if (!is_array($detail)) {
                return;
            }
            if (isset($detail['product_reference']) === isset($detail['product_codeable_concept'])) {
                $validator->errors()->add('resource.detail.product_reference', 'Exactly one product variant is required.');
            }
            $timing = array_intersect(['scheduled_period', 'scheduled_timing', 'scheduled_string'], array_keys($detail));
            if (count($timing) > 1) {
                $validator->errors()->add('resource.detail.scheduled_period', 'Schedule alternatives are mutually exclusive.');
            }
        });
        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Care Plan Activity response validation failed', ['fields' => $validator->errors()->keys()]);
            throw new ValidationException($validator);
        }
    }
    public function assertRegisteredInEHealth(?string $personUuid, ?string $carePlanUuid, ?string $activityUuid): void
    {

        if (!$personUuid || !$carePlanUuid || !$activityUuid) {
            throw new RuntimeException(__('care-plan.activity_ehealth_missing_identifiers'));
        }

        try {
            $response = $this->getDetails($personUuid, $carePlanUuid, $activityUuid);
            if (!$response->successful()) {
                throw new RuntimeException(__('care-plan.activity_not_in_ehealth'));
            }

            // An HTTP 200 alone cannot confirm registration of the requested activity.
            $activityData = $response->validate();
            if (($activityData['id'] ?? null) !== $activityUuid
                || data_get($activityData, 'carePlan.identifier.value') !== $carePlanUuid) {
                throw new RuntimeException(__('care-plan.activity_not_in_ehealth'));
            }
        } catch (EHealthResponseException $exception) {
            Log::warning('Care plan activity registration could not be confirmed', [
                'activity_uuid' => $activityUuid,
                'care_plan_uuid' => $carePlanUuid,
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException(__('care-plan.activity_not_in_ehealth'));
        }
    }
}
