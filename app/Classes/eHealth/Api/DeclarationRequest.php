<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Declaration\Status;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use App\Exceptions\EHealth\EHealthConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DeclarationRequest extends Request
{
    protected const string URL = '/api/v3/declaration_requests';

    /**
     * If true, groups the response by entities associated with the declarationRequest, e.g., DeclarationRequest itself, Persons, etc.
     */
    public bool $groupByEntities = false;

    /**
     * Create Declaration Request (as part of Declaration creation process) only for an existing person.
     *
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function create(array $data = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));

        return $this->post(self::URL, $data);
    }

    /**
     * Resend sms on previously created Declaration Request V3.
     *
     * @param  string  $id  Declaration ID
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function resendAuthOtp(string $id, array $data = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResentOtp(...));

        return $this->post(self::URL . "/$id/actions/resend_otp", $data);
    }

    /**
     * Upload to the (Signed URL's). All links are generated for one one-page document.
     *
     * @param  string  $uploadUrl
     * @param  UploadedFile  $document
     * @return PromiseInterface|Response
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function uploadDocument(string $uploadUrl, UploadedFile $document): PromiseInterface|Response
    {
        $filePath = $document->getRealPath();
        $fileMime = $document->getMimeType();
        $fileContents = file_get_contents($filePath);

        return Http::withHeaders(['Content-Type' => $fileMime])
            ->withBody($fileContents, $fileMime)
            ->put(trim($uploadUrl));
    }

    /**
     * Approve previously created Declaration Request.
     *
     * @param  string  $id
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function approve(string $id, array $data = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateApproved(...));

        return $this->patch(self::URL . "/$id/actions/approve", $data ?: (object)$data);
    }

    /**
     * Sign Declaration Request.
     *
     * @param  string  $id
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function sign(string $id, array $data = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateSigned(...));

        return $this->patch(self::URL . "/$id/actions/sign", $data);
    }

    /**
     * Reject previously created Declaration Request.
     *
     * @param  string  $id
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function reject(string $id): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));

        return $this->patch(self::URL . "/$id/actions/reject");
    }

    /**
     * Obtain list of previously created Declaration Requests.
     *
     * @param  string  $url
     * @param  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getMany(string $url = self::URL, $query = null): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateMany(...));

        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'] ?? [],
            $query ?? []
        );

        return parent::get($url, $mergedQuery);
    }

    /**
     * Obtain full information about Declaration Request by ID.
     *
     * @param  string  $uuid  Request identifier (UUID)
     * @param  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function get(string $uuid, $query = null): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateOne(...));

        return parent::get(self::URL . "/$uuid", $query);
    }

    /**
     * Validates the list of the declaration requests.
     *
     * @param  EHealthResponse  $response  The response from the eHealth API.
     * @return array The validated data.
     */
    protected function validateMany(EHealthResponse $response): array
    {
        $validator = Validator::make($response->getData(), [
            '*.id' => ['required', 'uuid'],
            '*.start_date' => ['required', 'date'],
            '*.end_date' => ['required', 'date'],
            '*.status' => ['required', 'string'],
            '*.status_reason' => ['nullable', 'string'],
            '*.channel' => ['required', 'string'],
            '*.person_id' => ['required', 'uuid'],
            '*.employee_id' => ['required', 'uuid'],
            '*.division_id' => ['required', 'uuid'],
            '*.legal_entity_id' => ['required', 'uuid'],
            '*.authorize_with' => ['nullable', 'uuid'],
            '*.parent_declaration_id' => ['nullable', 'uuid'],
            '*.system_declaration_limit' => ['nullable', 'numeric'],
            '*.current_declaration_count' => ['nullable', 'numeric']
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'EHealth Declaration Requests list validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validated();
    }

    /**
     * Validates the response of the approve action, which always returns the form to be signed.
     *
     * @param  EHealthResponse  $response  The response from the eHealth API.
     * @return array The validated data.
     */
    protected function validateApproved(EHealthResponse $response): array
    {
        return $this->validateResponse($response, ['data_to_be_signed' => ['required', 'array']]);
    }

    /**
     * Validates the declaration returned by the sign action.
     *
     * @param  EHealthResponse  $response  The response from the eHealth API.
     * @return array The validated data.
     */
    protected function validateSigned(EHealthResponse $response): array
    {
        $validator = Validator::make($response->getData(), [
            'id' => ['required', 'uuid'],
            'declaration_number' => ['nullable', 'string'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date'],
            'signed_at' => ['required', 'date'],
            'status' => [
                'required',
                Rule::in([Status::ACTIVE->value, Status::PENDING_VERIFICATION->value, Status::TERMINATED->value])
            ],
            'declaration_request_id' => ['required', 'uuid'],
            'person_id' => ['required', 'uuid'],
            'legal_entity_id' => ['required', 'uuid'],
            'employee_id' => ['required', 'uuid'],
            'division_id' => ['required', 'uuid'],
            'inserted_at' => ['required', 'date'],
            'is_active' => ['required', 'boolean:strict'],
            'reason' => ['nullable', 'string'],
            'reason_description' => ['nullable', 'string']
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'EHealth Declaration validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validated();
    }

    /**
     * Validates the response of the resend authorization OTP action.
     *
     * @param  EHealthResponse  $response  The response from the eHealth API.
     * @return array The validated data.
     */
    protected function validateResentOtp(EHealthResponse $response): array
    {
        $validator = Validator::make($response->getData(), [
            'id' => ['required', 'uuid'],
            'status' => ['required', Rule::in(['NEW', 'CANCELED', 'VERIFIED', 'UNVERIFIED'])],
            'code_expired_at' => ['required', 'date'],
            'active' => ['required', 'boolean:strict']
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'EHealth OTP validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validated();
    }

    /**
     * Validates the declaration request returned by the create, approve and reject actions.
     *
     * @param  EHealthResponse  $response  The response from the eHealth API.
     * @param  array  $extraRules  Rules that redefine the defaults for a particular action.
     * @return array The validated data.
     */
    protected function validateResponse(EHealthResponse $response, array $extraRules = []): array
    {
        $validator = Validator::make($response->getData(), array_merge([
            'id' => ['required', 'uuid'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date'],
            'person_id' => ['required', 'uuid'],
            'employee_id' => ['required', 'uuid'],
            'division_id' => ['required', 'uuid'],
            'declaration_number' => ['required', 'string'],
            'declaration_id' => ['required', 'uuid'],
            'parent_declaration_id' => ['nullable', 'uuid'],
            'status' => ['required', 'string'],
            'status_reason' => ['nullable', 'string'],
            'system_declaration_limit' => ['nullable', 'numeric'],
            'current_declaration_count' => ['nullable', 'numeric'],
            'channel' => ['required', 'string'],
            'authorize_with' => ['nullable', 'uuid'],
            'data_to_be_signed' => ['nullable', 'array']
        ], $extraRules));

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'EHealth Declaration Request validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validated();
    }

    /**
    * Validates the response for a declaration request.
    *
     * @param  EHealthResponse  $response  The response from the eHealth API.
     * @return array The validated and transformed data.
    */
    protected function validateOne(EHealthResponse $response): array
    {
        $transformedData = self::replaceEHealthPropNames($response->getData());

        $validator = Validator::make($transformedData, [
            'authorize_with' => 'nullable|uuid',
            'channel' => 'required|string',
            'current_declaration_count' => 'nullable|numeric',
            'data_to_be_signed' => 'nullable|array',
            'declaration_uuid' => 'required|uuid',
            'declaration_number' => 'required|string',
            'division_uuid' => 'nullable|uuid',
            'employee_uuid' => 'nullable|uuid',
            'end_date' => 'nullable|date',
            'uuid' => 'required|uuid',
            'legal_entity_uuid' => 'nullable|uuid',
            'person_uuid' => 'nullable|uuid',
            'start_date' => 'nullable|date',
            'status' => 'required|string',
            'status_reason' => 'nullable|string',
            'system_declaration_limit' => 'nullable|numeric',
            'parent_declaration_uuid' => 'nullable|uuid'
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error(
                'EHealth Employee validation failed: ' . implode(', ', $validator->errors()->all())
            );
        }

        return $validator->validated();
    }

    /**
     * Replaces eHealth property names with the ones used in the application (e.g., id -> uuid).
     *
     * @param  array  $properties  Raw properties from a single API item.
     * @return array Properties with application-friendly names.
     */
    protected static function replaceEHealthPropNames(array $properties): array
    {
        $replaced = [];

        foreach ($properties as $name => $value) {
            switch ($name) {
                case 'id':
                    $replaced['uuid'] = $value;
                    break;
                case 'declaration_id':
                    $replaced['declaration_uuid'] = $value;
                    break;
                case 'parent_declaration_id':
                    $replaced['parent_declaration_uuid'] = $value;
                    break;
                case 'division_id':
                    $replaced['division_uuid'] = $value;
                    break;
                case 'employee_id':
                    $replaced['employee_uuid'] = $value;
                    break;
                case 'legal_entity_id':
                    $replaced['legal_entity_uuid'] = $value;
                    break;
                case 'person_id':
                    $replaced['person_uuid'] = $value;
                    break;
                default:
                    $replaced[$name] = $value;
                    break;
            }
        }

        return $replaced;
    }
}
