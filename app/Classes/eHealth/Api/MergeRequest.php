<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\MergeRequest\Status;
use App\Models\Person\Person;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;

class MergeRequest extends Request
{
    protected const string URL = '/api/merge_requests';

    /**
     * Search merge requests, optionally filtered by master person, merge person and status.
     *
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/merge-request/get-merge-requests
     */
    public function getMergeRequests(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateListResponse(...));
        $this->setMapper($this->mapMergeRequests(...));
        $this->setDefaultPageSize();

        return $this->get(self::URL, array_merge($this->options['query'], $query));
    }

    /**
     * Create a request to merge a preperson's records into an identified (master) person.
     *
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/merge-request/create-merge-request
     */
    public function create(array $data): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));

        return $this->post(self::URL, $data);
    }

    /**
     * Approve a previously created merge request, confirming it with the patient's authentication.
     *
     * @param  string  $id
     * @param  array{verification_code?: int}  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/merge-request/approve-merge-request
     */
    public function approve(string $id, array $data = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateApproveResponse(...));

        return $this->patch(self::URL . "/$id/actions/approve", $data ?: (object)$data);
    }

    /**
     * Re-send the OTP to the patient for confirming the merge request.
     *
     * @param  string  $id
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/merge-request/resend-otp
     */
    public function resendOtp(string $id, array $data = []): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/$id/actions/resend_otp", $data ?: (object)$data);
    }

    /**
     * Reject a merge request, e.g. when the printed consent form contains errors.
     *
     * @param  string  $id
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/merge-request/reject-merge-request
     */
    public function reject(string $id): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));

        return $this->patch(self::URL . "/$id/actions/reject");
    }

    /**
     * Sign an approved merge request with the doctor's qualified digital signature.
     *
     * @param  string  $id
     * @param  array{signed_content: string, signed_content_encoding: string}  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/merge-request/sign-merge-request
     */
    public function sign(string $id, array $data): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));

        return $this->patch(self::URL . "/$id/actions/sign", $data);
    }

    /**
     * Validate a merge request response.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateResponse(EHealthResponse $response): array
    {
        $validator = Validator::make(
            self::replaceEHealthPropNames($response->getData()),
            $this->validationRules()
        );

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    /**
     * Validate an approve merge request response, which additionally carries the consent document to sign.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateApproveResponse(EHealthResponse $response): array
    {
        $validator = Validator::make(
            self::replaceEHealthPropNames($response->getData()),
            $this->approveValidationRules()
        );

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    /**
     * Validate a merge requests list response.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateListResponse(EHealthResponse $response): array
    {
        $replaced = array_map(
            static fn (array $mergeRequest): array => self::replaceEHealthPropNames($mergeRequest),
            $response->getData()
        );

        $validator = Validator::make($replaced, [
            '*.uuid' => ['required', 'uuid'],
            '*.master_person_id' => ['required', 'uuid'],
            '*.merge_person_id' => ['required', 'uuid'],
            '*.status' => ['required', new Enum(Status::class)],
            '*.ehealth_inserted_at' => ['required', 'date'],
            '*.ehealth_inserted_by' => ['required', 'uuid'],
            '*.ehealth_updated_at' => ['required', 'date'],
            '*.ehealth_updated_by' => ['required', 'uuid']
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    /**
     * Map the validated merge requests list into rows for the local merge_requests table, resolving the master
     * person UUID to its local id (null when the identified patient is not stored locally) and stamping the
     * preperson these requests belong to.
     *
     * @param  array  $mergeRequests
     * @param  int  $prepersonId
     * @return array
     */
    protected function mapMergeRequests(array $mergeRequests, int $prepersonId): array
    {
        $masterPersonIds = Person::whereIn('uuid', array_column($mergeRequests, 'master_person_id'))
            ->pluck('id', 'uuid');

        return array_map(static fn (array $mergeRequest): array => [
            ...$mergeRequest,
            'master_person_id' => $masterPersonIds[$mergeRequest['master_person_id']] ?? null,
            'merge_person_id' => $prepersonId
        ], $mergeRequests);
    }

    /**
     * List of validation rules for merge request response fields.
     *
     * @return array
     */
    protected function validationRules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'master_person_id' => ['required', 'uuid'],
            'merge_person_id' => ['required', 'uuid'],
            'status' => ['required', 'string'],
            'ehealth_inserted_at' => ['required', 'date'],
            'ehealth_inserted_by' => ['required', 'uuid'],
            'ehealth_updated_at' => ['required', 'date'],
            'ehealth_updated_by' => ['required', 'uuid']
        ];
    }

    /**
     * Validation rules for the approve response, which extends the base fields with the consent document to sign.
     *
     * @return array
     */
    protected function approveValidationRules(): array
    {
        return [
            ...$this->validationRules(),
            'data_to_be_signed' => ['required', 'array'],
            'data_to_be_signed.content' => ['required', 'string'],
            'data_to_be_signed.id' => ['required', 'uuid'],
            'data_to_be_signed.status' => ['required', 'string'],
            'data_to_be_signed.patient_signed' => ['required', 'boolean'],
            'data_to_be_signed.master_person' => ['required', 'array'],
            'data_to_be_signed.merge_person' => ['required', 'array']
        ];
    }

    /**
     * Replace eHealth property names with the ones used in the application.
     *
     * @param  array  $properties
     * @return array
     */
    protected static function replaceEHealthPropNames(array $properties): array
    {
        $replaced = [];

        foreach ($properties as $name => $value) {
            $newName = match ($name) {
                'id' => 'uuid',
                'inserted_at' => 'ehealth_inserted_at',
                'inserted_by' => 'ehealth_inserted_by',
                'updated_at' => 'ehealth_updated_at',
                'updated_by' => 'ehealth_updated_by',
                default => $name
            };

            $replaced[$newName] = $value;
        }

        return $replaced;
    }
}
