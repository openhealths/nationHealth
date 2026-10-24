<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Classes\eHealth\MapsCarePlanResources;
use App\Enums\CarePlanActivityKind;
use App\Models\CarePlan;
use App\Repositories\CarePlanActivityRepository;
use Illuminate\Validation\ValidationException;

class CarePlanActivity extends Request
{
    use MapsCarePlanResources;

    protected const string URL = '/api/care_plans';

    public function buildCreateContent(array $resource): array
    {
        Validator::make($resource, [
            'id' => ['required', 'uuid'], 'author' => ['required', 'array', 'min:1'],
            'author.*.identifier.value' => ['required', 'uuid'],
            'carePlan.identifier.value' => ['required', 'uuid'],
            'detail.kind' => ['required', 'in:'.implode(',', array_column(CarePlanActivityKind::cases(), 'value'))],
        ])->validate();
        $detail = $resource['detail'];
        if ($detail['kind'] === CarePlanActivityKind::DEVICE_REQUEST->value) {
            Validator::make($detail, [
                'quantity.value' => ['sometimes', 'integer', 'min:1'],
            ], [], ['quantity.value' => __('care-plan.ehealth_fields.quantity_value')])->validate();
            if (isset($detail['quantity']['value'])) {
                $detail['quantity']['value'] = (int) $detail['quantity']['value'];
            }
        }
        if (isset($detail['productReference']) === isset($detail['productCodeableConcept'])) {
            throw ValidationException::withMessages(['product' => 'Exactly one product variant is required.']);
        }
        $content = array_intersect_key($resource, array_flip(['id', 'author', 'carePlan']));
        $content['detail'] = array_intersect_key($detail, array_flip(['kind', 'productReference', 'productCodeableConcept', 'reasonCode', 'reasonReference', 'goal', 'quantity', 'location', 'performer', 'dailyAmount', 'scheduledPeriod', 'scheduledTiming', 'scheduledString', 'description', 'program', 'doNotPerform']));
        $content['detail']['status'] = 'scheduled';

        return $this->resourcePayload($content);
    }

    public function fetchCancelSnapshot(string $patientUuid, string $planUuid, string $activityUuid): array
    {
        $snapshot = $this->getDetails($patientUuid, $planUuid, $activityUuid)->getData();
        Validator::make($snapshot, ['id' => ['required', 'in:'.$activityUuid], 'detail' => ['required', 'array']])->validate();

        return $snapshot;
    }

    public function buildCancelContent(array $snapshot, array $reason): array
    {
        Validator::make($snapshot, ['id' => ['required', 'uuid'], 'detail' => ['required', 'array']])->validate();
        $snapshot['detail']['status_reason'] = $reason;

        return $snapshot;
    }

    public function buildCompleteContent(array $reason, array $outcomes): array
    {
        Validator::make(['reason' => $reason, 'outcomes' => $outcomes], [
            'reason.coding' => ['required', 'array', 'min:1'],
            'outcomes' => ['required', 'array', 'min:1'],
            'outcomes.*.coding' => ['required', 'array', 'min:1'],
        ])->validate();

        return ['detail' => ['status_reason' => $reason], 'outcome_codeable_concept' => $outcomes];
    }

    public function submitSignedCreate(string $patientUuid, string $planUuid, string $signedContent): array
    {
        return $this->resolveWriteResponse($this->create($patientUuid, $planUuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']));
    }

    public function cancelAndResolve(string $patientUuid, string $planUuid, string $activityUuid, array $payload): array
    {
        return $this->resolveWriteResponse($this->cancel($patientUuid, $planUuid, $activityUuid, $payload));
    }

    public function completeAndResolve(string $patientUuid, string $planUuid, string $activityUuid, array $payload): array
    {
        return $this->resolveWriteResponse($this->complete($patientUuid, $planUuid, $activityUuid, $payload));
    }

    public function fetchAllResources(string $patientUuid, string $planUuid, array $query = []): array
    {
        $resources = [];
        for ($page = 1; ; $page++) {
            $response = $this->getSummary($patientUuid, $planUuid, array_replace($query, ['page' => $page]));
            $items = $response->getData();
            foreach ($items as $item) {
                $id = $this->resourceFromResponse($item)['id'];
                if (isset($resources[$id])) {
                    throw ValidationException::withMessages(['paging' => 'Activity pagination repeated a resource.']);
                }
                $resource = $this->getDetails($patientUuid, $planUuid, $id)->validate();
                if ($resource['id'] !== $id) {
                    throw ValidationException::withMessages(['id' => 'Activity details do not match the requested resource.']);
                }
                $resources[$id] = $resource;
            }
            $lastPage = $response->getPaging()['total_pages'] ?? null;
            if ($items === [] || ($lastPage !== null && $page >= (int) $lastPage)) {
                break;
            }
        }

        return array_values($resources);
    }

    public function synchronize(string $patientUuid, CarePlan $plan, array $query = []): void
    {
        $resources = $this->fetchAllResources($patientUuid, $plan->uuid, $query);
        app(CarePlanActivityRepository::class)->storeResources($resources, $plan);
    }

    public function getRawDetails(string $patientUuid, string $planUuid, string $activityUuid): array
    {
        return $this->getDetails($patientUuid, $planUuid, $activityUuid)->getData();
    }

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
    public function getDetails(string $personId, string $carePlanId, string $activityId): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateDetails(...));

        return $this->get("/api/patients/$personId/care_plans/$carePlanId/activities/$activityId");
    }

    protected function validateDetails(EHealthResponse $response): array
    {
        $data = $this->resourceFromResponse($response->getData());
        if (isset($data['author']['identifier'])) {
            $data['author'] = [$data['author']];
        }
        if (isset($data['status']) && !isset($data['detail']['status'])) {
            $data['detail']['status'] = $data['status'];
        }

        $validator = Validator::make($data, [
            'id' => 'required|uuid',
            'detail.status' => 'required|string',
            'detail.kind' => 'required|string',
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'CarePlanActivity details validation failed: ' . implode(', ', $validator->errors()->all())
            );
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        return $data;
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
                    $transformedData[] = $this->resourceFromResponse($item);
                }
            }
        }

        $validator = Validator::make($transformedData, [
            '*' => 'array',
            '*.id' => 'required|uuid',
            '*.status' => 'nullable|string',
            '*.kind' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'CarePlanActivity many validation failed: ' . implode(', ', $validator->errors()->all())
            );
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        return $transformedData;
    }

}
