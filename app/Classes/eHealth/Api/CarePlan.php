<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Dto\CarePlan\Ehealth as CarePlanCreateData;
use App\Dto\FormCollection;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Classes\eHealth\MapsCarePlanResources;
use App\Classes\eHealth\EHealth;
use App\Repositories\CarePlanRepository;

class CarePlan extends Request
{
    use MapsCarePlanResources;

    protected const string URL = '/api/care_plans';

    public function buildCreateContent(array $resource): array
    {
        Validator::make($resource, [
            'id' => ['required', 'uuid'], 'title' => ['required', 'string'],
            'category.coding' => ['required', 'array', 'min:1'],
            'period.start' => ['required', 'date'],
            'encounter.identifier.value' => ['required', 'uuid'],
            'author.identifier.value' => ['required', 'uuid'],
            'addresses' => ['required', 'array', 'min:1'],
            'termsOfService.coding' => ['required', 'array', 'min:1'],
        ])->validate();

        return $this->resourcePayload(CarePlanCreateData::fromSource(new FormCollection($resource))->toArray());
    }

    public function buildCancelContent(array $snapshot, array $statusReason): array
    {
        Validator::make($snapshot, ['id' => ['required', 'uuid']])->validate();
        unset($snapshot['activities']);
        $snapshot['status_reason'] = $statusReason;

        return $snapshot;
    }

    public function fetchCancelSnapshot(string $patientUuid, string $planUuid): array
    {
        $snapshot = $this->getDetails($patientUuid, $planUuid)->getData();
        Validator::make($snapshot, ['id' => ['required', 'in:'.$planUuid]])->validate();

        return $snapshot;
    }

    public function submitSignedCreate(string $patientUuid, string $signedContent): array
    {
        return $this->resolveWriteResponse($this->create($patientUuid, ['signed_data' => $signedContent, 'signed_data_encoding' => 'base64']));
    }

    public function cancelAndResolve(string $patientUuid, string $planUuid, array $payload): array
    {
        return $this->resolveWriteResponse($this->cancel($patientUuid, $planUuid, $payload));
    }

    public function completeAndResolve(string $patientUuid, string $planUuid, array $payload): array
    {
        return $this->resolveWriteResponse($this->complete($patientUuid, $planUuid, $payload));
    }

    /** Fetch full details and the entire activity collection before opening a SQL transaction. */
    public function synchronizeResources(array $summaries, ?int $personId, int $legalEntityId, ?string $patientUuid = null, ?string $token = null): void
    {
        $summaries = $summaries['data'] ?? $summaries;
        $repository = app(CarePlanRepository::class);
        foreach ($summaries as $summary) {
            $summary = $this->resourceFromResponse($summary);
            $patient = $repository->resolvePatientContext($personId, $patientUuid ?? data_get($summary, 'subject.identifier.value'));
            if ($token !== null) {
                $this->withToken($token);
            }
            $resource = $this->getDetails($patient['uuid'], $summary['id'])->validate();
            if ($resource['id'] !== $summary['id']) {
                throw ValidationException::withMessages(['id' => 'Care Plan details do not match the requested resource.']);
            }
            $activityApi = EHealth::carePlanActivity();
            if ($token !== null) {
                $activityApi->withToken($token);
            }
            $activities = $activityApi->fetchAllResources($patient['uuid'], $resource['id']);
            $repository->storeAggregate($resource, $activities, $patient['id'], $legalEntityId);
        }
    }

    public function getRawDetails(string $patientUuid, string $planUuid): array
    {
        return $this->getDetails($patientUuid, $planUuid)->getData();
    }

    public function synchronizePatient(string $patientUuid, int $personId, int $legalEntityId, array $query = []): void
    {
        $summaries = $this->collectSummaries(fn (int $page) => $this->getBySearchParams($patientUuid, array_replace($query, ['page' => $page])));
        $this->synchronizeResources($summaries, $personId, $legalEntityId, $patientUuid);
    }

    public function synchronizeSearch(array $query, int $legalEntityId): void
    {
        $summaries = $this->collectSummaries(fn (int $page) => $this->getMany(array_replace($query, ['page' => $page])));
        $this->synchronizeResources($summaries, null, $legalEntityId);
    }

    private function collectSummaries(callable $fetch): array
    {
        $summaries = [];
        $seen = [];
        for ($page = 1; ; $page++) {
            $response = $fetch($page);
            $items = $response->getData();
            foreach ($items as $item) {
                $item = $this->resourceFromResponse($item);
                if (isset($seen[$item['id']])) {
                    throw ValidationException::withMessages(['paging' => 'Care Plan pagination repeated a resource.']);
                }
                $seen[$item['id']] = true;
                $summaries[] = $item;
            }
            $lastPage = $response->getPaging()['total_pages'] ?? null;
            if ($items === [] || ($lastPage !== null && $page >= (int) $lastPage)) {
                break;
            }
        }

        return $summaries;
    }

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

    protected function validateDetails(EHealthResponse $response): array
    {
        $data = $this->resourceFromResponse($response->getData());

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

        return $data;
    }

    protected function validateMany(EHealthResponse $response): array
    {
        $transformedData = [];
        foreach ($response->getData() as $item) {
            $transformedData[] = $this->resourceFromResponse($item);
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
