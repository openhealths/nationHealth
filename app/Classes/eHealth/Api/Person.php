<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Enums\Person\AuthenticationMethod;
use App\Enums\Person\AuthenticationMethodAction;
use App\Enums\Person\ConfidantPersonRelationshipRequestStatus;
use App\Enums\Person\RelationType;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Rules\InDictionary;
use App\Rules\PhoneNumber;
use App\Rules\TaxId;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class Person extends Request
{
    protected const string URL = '/api/persons';
    protected const string URL_V2 = '/api/v2/persons';

    /**
     * Search for a person by parameters.
     *
     * @param  array{
     *     first_name: string,
     *     last_name: string,
     *     second_name?: string,
     *     birth_date: string,
     *     tax_id?: string,
     *     phone_number?: string,
     *     birth_certificate?: string
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/search-for-a-person
     */
    public function searchForPersonByParams(array $query): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateSearch(...));

        $query = $this->format($query, ['birthDate']);

        return $this->get(self::URL, $query);
    }

    /**
     * This method allows to find all persons, which were merged with this person.
     * Also, this endpoint shows all the persons who enter the whole chain of merges to this person.
     *
     * @param  string  $uuid
     * @param  array{id: string, status?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/search-person's-merged-persons
     */
    public function searchPersonsMergedPersons(string $uuid, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query);

        return $this->get(self::URL . "/$uuid/merged_persons", $mergedQuery);
    }

    /**
     * This method is used to obtain full information about person by ID. This method is applicable only if there is an active approval of type 'person'.
     *
     * @param  string  $uuid
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/get-personal-data
     */
    public function getPersonalData(string $uuid): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validatePersonalData(...));
        return $this->get(self::URL . '/' . $uuid . '/personal_data');
    }

    /**
     * Re-send SMS to a person who approve creating or updating data about himself.
     *
     * @param  string  $id
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/get-person-authentication-methods
     */
    public function getAuthMethods(string $id, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateAuthMethods(...));
        $this->setMapper($this->mapAuthMethods(...));

        return $this->get(self::URL . "/$id/authentication_methods", $query);
    }

    /**
     * Get current person's verification status and another information about it.
     *
     * @param  string  $id
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/get-person-verification-details
     */
    public function getPersonVerificationDetails(string $id): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validatePersonVerificationDetails(...));

        return $this->get(self::URL . "/$id/verification");
    }

    /**
     * Get list of active confidant person relationships.
     *
     * @param  string  $id
     * @param  array{is_expired?: bool, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/get-confidant-person-relationships
     */
    public function getConfidantPersonRelationships(string $id, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateConfidantPersonRelationships(...));

        return $this->get(self::URL . "/$id/confidant_person_relationships", $query);
    }

    /**
     * Create new Confidant Person relationship request.
     *
     * @param  string  $id
     * @param  array{confidant_person_id: string, documents_relationship: array}  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/create-new-confidant-person-relationship-request
     */
    public function createConfidantRelationship(string $id, array $data): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateCreateConfidantRelationship(...));

        $data = $this->format($data, ['activeTo', 'issuedAt']);

        return $this->post(self::URL . "/$id/confidant_person_relationship_requests", $data);
    }

    /**
     * Deactivate new Confidant Person relationship request.
     *
     * @param  string  $id  Person identifier
     * @param  string  $relationshipId  Identifier of person relationship that will be deactivated
     * @param  array  $documentsRelationship
     * @param  string|null  $authorizeWith  Identifier of person's auth method
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/deactivate-confidant-person-relationship-request
     */
    public function deactivateConfidantRelationship(
        string $id,
        string $relationshipId,
        array $documentsRelationship,
        ?string $authorizeWith = null
    ): PromiseInterface|EHealthResponse {
        $this->setValidator($this->validateCreateConfidantRelationship(...));

        $payload = [
            'confidant_person_relationship' => [
                'id' => $relationshipId,
                'documents_relationship' => $documentsRelationship
            ]
        ];

        if (!is_null($authorizeWith)) {
            $payload['authorize_with'] = $authorizeWith;
        }

        $payload = $this->format($payload, ['issued_at', 'active_to']);

        return $this->post(self::URL . "/$id/confidant_person_relationship_requests/deactivate", $payload);
    }

    /**
     * Get list of previously created Confidant Person relationship requests.
     *
     * @param  string  $id
     * @param  array{status?: ConfidantPersonRelationshipRequestStatus::class, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/get-confidant-person-relationship-requests-list
     */
    public function getConfidantPersonRelationshipRequestsList(
        string $id,
        array $query = []
    ): PromiseInterface|EHealthResponse {
        $this->setValidator($this->validateConfidantePersonRequests(...));

        return $this->get(self::URL . "/$id/confidant_person_relationship_requests", $query);
    }

    /**
     * Get details of previously created Confidant Person relationship requests.
     *
     * @param  string  $id
     * @param  string  $confidantPersonRelationshipRequestId
     * @param  array{status?: ConfidantPersonRelationshipRequestStatus::class, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/get-confidant-person-relationship-requests-list
     */
    public function getConfidantPersonRelationshipRequestById(
        string $id,
        string $confidantPersonRelationshipRequestId,
        array $query = []
    ): PromiseInterface|EHealthResponse {
        return $this->get(
            self::URL . "/$id/confidant_person_relationship_requests/$confidantPersonRelationshipRequestId",
            $query
        );
    }

    /**
     * Approve previously created Confidant Person relationship request (creation or deactivation).
     *
     * @param  string  $id  Person ID
     * @param  string  $confidantPersonRelationshipRequestId  Confidant Person relationship request ID
     * @param  array{verification_code?: int}  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/approve-confidant-person-relationship-request
     */
    public function approveConfidantPersonRelationshipRequest(
        string $id,
        string $confidantPersonRelationshipRequestId,
        array $data = []
    ): PromiseInterface|EHealthResponse {
        return $this->patch(
            self::URL . "/$id/confidant_person_relationship_requests/$confidantPersonRelationshipRequestId/actions/approve",
            $data ?: (object)$data
        );
    }

    /**
     * Sign previously created Confidant Person relationship request.
     *
     * @param  string  $id  Person ID
     * @param  string  $confidantPersonRelationshipRequestId  Confidant Person relationship request ID
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/sign-confidant-person-relationship-request
     */
    public function signConfidantPersonRelationshipRequest(
        string $id,
        string $confidantPersonRelationshipRequestId,
        array $data = []
    ): PromiseInterface|EHealthResponse {
        return $this->patch(
            self::URL . "/$id/confidant_person_relationship_requests/$confidantPersonRelationshipRequestId/actions/sign",
            $data
        );
    }

    /**
     * Re-send SMS to confidant.
     *
     * @param  string  $id  Person ID
     * @param  string  $confidantPersonRelationshipRequestId
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/resend-authorization-otp-on-confidant-person-relationship
     */
    public function resendAuthOtpOnConfidantPersonRelationship(
        string $id,
        string $confidantPersonRelationshipRequestId,
        array $data = []
    ): PromiseInterface|EHealthResponse {
        return $this->post(
            self::URL . "/$id/confidant_person_relationship_requests/$confidantPersonRelationshipRequestId/actions/resend_otp",
            $data
        );
    }

    /**
     * Adding an authentication method to an existing person, update authentication method and delete it.
     *
     * @param  string  $id
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/create-authentication-method-request
     */
    public function createAuthMethod(string $id, array $data): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateCreateAuthMethod(...));

        return $this->post(self::URL . "/$id/authentication_method_requests", $data);
    }

    /**
     * Deactivation an auth method.
     *
     * @param  string  $id  Person UUID
     * @param  string  $authId  Auth method UUID
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/create-authentication-method-request
     */
    public function deactivateAuthMethod(string $id, string $authId): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateCreateAuthMethod(...));

        $data = [
            'action' => AuthenticationMethodAction::DEACTIVATE->value,
            'authentication_method' => ['id' => $authId]
        ];

        return $this->post(self::URL . "/$id/authentication_method_requests", $data);
    }

    /**
     * Adding an authentication method to an existing person.
     *
     * @param  string  $id  Person identifier
     * @param  AuthenticationMethod  $type
     * @param  string|null  $phoneNumber
     * @param  string|null  $value
     * @param  string|null  $alias
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/create-authentication-method-request
     */
    public function insertAuthMethod(
        string $id,
        AuthenticationMethod $type,
        ?string $phoneNumber = null,
        ?string $value = null,
        ?string $alias = null
    ): PromiseInterface|EHealthResponse {
        $this->setValidator($this->validateCreateAuthMethod(...));

        $authenticationMethod = Arr::whereNotNull([
            'type' => $type->value,
            'phone_number' => $phoneNumber,
            'value' => $value,
            'alias' => $alias
        ]);

        $data = [
            'action' => AuthenticationMethodAction::INSERT->value,
            'authentication_method' => $authenticationMethod
        ];

        return $this->post(self::URL . "/$id/authentication_method_requests", $data);
    }

    /**
     * Update an auth method alias.
     *
     * @param  string  $id  Person UUID
     * @param  string  $authId  Auth method UUID
     * @param  string  $alias
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/create-authentication-method-request
     */
    public function updateAuthMethod(string $id, string $authId, string $alias): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateApproveAuthMethod(...));

        $data = [
            'action' => AuthenticationMethodAction::UPDATE->value,
            'authentication_method' => [
                'id' => $authId,
                'alias' => $alias
            ]
        ];

        return $this->post(self::URL . "/$id/authentication_method_requests", $data);
    }

    /**
     * Approve previously created Authentication method Request.
     *
     * @param  string  $id
     * @param  string  $requestId
     * @param  array{verification_code?: int}  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/approve-authentication-method-request
     */
    public function approveAuthMethod(string $id, string $requestId, array $data = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateApproveAuthMethod(...));

        return $this->patch(
            self::URL . "/$id/authentication_method_requests/$requestId/actions/approve",
            $data ?: (object)$data
        );
    }

    /**
     * Re-send SMS to a person or third person.
     *
     * @param  string  $id
     * @param  string  $requestId
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/persons/resend-authorization-otp-on-authentication-method-request
     */
    public function resendAuthOtp(string $id, string $requestId, array $data = []): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/$id/authentication_method_requests/$requestId/actions/resend_otp", $data);
    }

    protected function validateSearch(EHealthResponse $response): array
    {
        $data = $response->getData();

        $validator = Validator::make($data, [
            '*.birth_country' => ['required', 'string', 'max:255'],
            '*.birth_date' => ['nullable', 'date'],
            '*.birth_settlement' => ['required', 'string', 'max:255'],
            '*.first_name' => ['required', 'string', 'max:255'],
            '*.gender' => ['required', new InDictionary('GENDER')],
            '*.id' => ['nullable', 'uuid'],
            '*.last_name' => ['required', 'string', 'max:255'],
            '*.second_name' => ['nullable', 'string', 'max:255'],
            '*.phones' => ['nullable', 'array'],
            '*.phones.*.number' => ['required', new PhoneNumber()],
            '*.phones.*.type' => ['required', new InDictionary('PHONE_TYPE')],
            '*.tax_id' => ['nullable', new TaxId()]
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    protected function validateAuthMethods(EHealthResponse $response): array
    {
        $data = $response->getData();
        $thirdPerson = AuthenticationMethod::THIRD_PERSON->value;

        $replaced = self::replaceEHealthPropNames($data);

        // Save alias for third person auth method if it is not set
        $replaced = Arr::map($replaced, function ($item) {
            if (isset($item['type']) && $item['type'] === AuthenticationMethod::THIRD_PERSON->value && !isset($item['alias'])) {
                $item['alias'] = __('UNKNOWN');
            }

            return $item;
        });

        $validator = Validator::make($replaced, [
            '*.uuid' => ['required', 'uuid'],
            '*.type' => ['required', 'string', Rule::in(AuthenticationMethod::values())],
            '*.alias' => ["required_if:*.type,$thirdPerson", 'nullable', 'string', 'max:255'],
            '*.ehealth_ended_at' => ['nullable', 'date'],
            '*.value' => ["required_if:*.type,$thirdPerson", 'nullable', 'uuid'],
            '*.phone_number' => ['nullable', 'string', 'max:255'],
            '*.confidant_person' => ['nullable', 'array'],
            '*.confidant_person.documents_person.*.number' => ['nullable', 'string', 'max:255'],
            '*.confidant_person.documents_person.*.type' => ['nullable', new InDictionary('DOCUMENT_TYPE')],
            '*.confidant_person.gender' => ['nullable', new InDictionary('GENDER')],
            '*.confidant_person.name' => ['nullable', 'string', 'max:255'],
            '*.confidant_person.uuid' => ['nullable', 'uuid'],
            '*.confidant_person.no_tax_id' => ['nullable', 'boolean:strict'],
            '*.confidant_person.phones.number' => ['nullable', 'string'],
            '*.confidant_person.tax_id' => ['nullable', 'string'],
            '*.confidant_person.unzr' => ['nullable', 'string']
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    protected function validatePersonVerificationDetails(EHealthResponse $response): array
    {
        $validator = Validator::make($response->getData(), [
            'verification_status' => ['required', new InDictionary('PERSON_VERIFICATION_STATUSES')],
            'details' => ['required', 'array'],
            'details.drfo' => ['present', 'array'],
            'details.drfo.verification_status' => ['required', new InDictionary('PERSON_VERIFICATION_STATUSES')],
            'details.drfo.verification_reason' => ['required', new InDictionary('PERSON_VERIFICATION_STATUS_REASONS')],
            'details.drfo.result' => ['nullable', 'numeric', new InDictionary('DRFO_RESULT')],
            'details.dracs_birth' => ['present', 'array'],
            'details.dracs_birth.verification_status' => ['required', new InDictionary('PERSON_VERIFICATION_STATUSES')],
            'details.dracs_birth.verification_reason' => [
                'required',
                new InDictionary('PERSON_VERIFICATION_STATUS_REASONS')
            ],
            'details.dracs_birth.verification_comment' => ['nullable', 'string'],
            'details.dracs_death' => ['present', 'array'],
            'details.dracs_death.verification_status' => ['required', new InDictionary('PERSON_VERIFICATION_STATUSES')],
            'details.dracs_death.verification_reason' => [
                'required',
                new InDictionary('PERSON_VERIFICATION_STATUS_REASONS')
            ],
            'details.dracs_death.verification_comment' => ['nullable', 'string'],
            'details.dracs_name_change' => ['present', 'array'],
            'details.dracs_name_change.verification_status' => [
                'required',
                new InDictionary('PERSON_VERIFICATION_STATUSES')
            ],
            'details.dracs_name_change.verification_reason' => [
                'required',
                new InDictionary('PERSON_VERIFICATION_STATUS_REASONS')
            ],
            'details.dracs_name_change.verification_comment' => ['nullable', 'string'],
            'details.legal_capacity' => ['present', 'array'],
            'details.legal_capacity.verification_status' => [
                'required',
                new InDictionary('PERSON_VERIFICATION_STATUSES')
            ],
            'details.legal_capacity.verification_reason' => [
                'required',
                new InDictionary('PERSON_VERIFICATION_STATUS_REASONS')
            ],
            'details.mvs_passport' => ['present', 'array'],
            'details.mvs_passport.verification_status' => [
                'required',
                new InDictionary('PERSON_VERIFICATION_STATUSES')
            ],
            'details.mvs_passport.verification_reason' => [
                'required',
                new InDictionary('PERSON_VERIFICATION_STATUS_REASONS')
            ],
            'details.mvs_passport.status' => ['nullable', 'numeric', new InDictionary('EIS_MVS_STATUS')],
            'details.dms_passport' => ['present', 'array'],
            'details.dms_passport.verification_status' => [
                'required',
                new InDictionary('PERSON_VERIFICATION_STATUSES')
            ],
            'details.dms_passport.verification_reason' => [
                'required',
                new InDictionary('PERSON_VERIFICATION_STATUS_REASONS')
            ],
            'details.dms_passport.status' => ['nullable', 'numeric', new InDictionary('EIS_MVS_STATUS')],
            'details.nhs' => ['present', 'array'],
            'details.nhs.verification_status' => ['required', new InDictionary('PERSON_VERIFICATION_STATUSES')],
            'details.nhs.verification_reason' => ['required', new InDictionary('PERSON_VERIFICATION_STATUS_REASONS')],
            'details.nhs.verification_comment' => ['nullable', 'string'],
            'details.unzr' => ['present', 'array'],
            'details.unzr.verification_status' => ['required', new InDictionary('PERSON_VERIFICATION_STATUSES')],
            'details.unzr.verification_reason' => ['required', new InDictionary('PERSON_VERIFICATION_STATUS_REASONS')],
            'details.unzr.status' => ['nullable', 'numeric', new InDictionary('EIS_MVS_STATUS')]
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    protected function validateCreateAuthMethod(EHealthResponse $response): array
    {
        $data = $response->getData();
        $urgent = $response->getUrgent();
        $forValidate = array_merge($data, $urgent);

        $validator = Validator::make($forValidate, [
            'id' => ['required', 'uuid'],
            'channel' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:255'],
            'documents.*.type' => ['nullable', new InDictionary('DOCUMENT_TYPE')],
            'documents.*.url' => ['nullable', 'url']
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    protected function validateApproveAuthMethod(EHealthResponse $response): array
    {
        $data = $response->getData();

        $validator = Validator::make($data, [
            'channel' => ['required', 'string', 'max:255'],
            'id' => ['required', 'uuid'],
            'status' => ['required', 'string', 'max:255']
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    protected function validateCreateConfidantRelationship(EHealthResponse $response): array
    {
        return $this->validateConfidantRelationshipData($response, false);
    }

    protected function validateConfidantPersonRelationships(EHealthResponse $response): array
    {
        $replaced = self::replaceEHealthPropNames($response->getData());

        $validator = Validator::make($replaced, [
            '*.uuid' => ['required', 'uuid'],
            '*.active_to' => ['nullable', 'date'],
            '*.confidant_person' => ['required', 'array'],
            '*.confidant_person.person_id' => ['required', 'uuid'],
            '*.confidant_person.gender' => ['required', new InDictionary('GENDER')],
            '*.confidant_person.name' => ['required', 'string', 'max:255'],
            '*.confidant_person.no_tax_id' => ['required', 'boolean:strict'],
            '*.confidant_person.documents_person' => ['nullable', 'array'],
            '*.confidant_person.documents_person.*.number' => ['nullable', 'string', 'max:255'],
            '*.confidant_person.documents_person.*.type' => ['nullable', new InDictionary('DOCUMENT_TYPE')],
            '*.confidant_person.phones' => ['nullable', 'array'],
            '*.confidant_person.phones.*.number' => ['nullable', 'string', 'max:255'],
            '*.confidant_person.phones.*.type' => ['nullable', new InDictionary('PHONE_TYPE')],
            '*.confidant_person.tax_id' => ['nullable', 'string', 'max:255'],
            '*.confidant_person.unzr' => ['nullable', 'string', 'max:255'],
            '*.documents_relationship' => ['nullable', 'array'],
            '*.documents_relationship.*.number' => ['nullable', 'string', 'max:255'],
            '*.documents_relationship.*.type' => ['nullable', new InDictionary('DOCUMENT_RELATIONSHIP_TYPE')],
            '*.relationship_verification_details' => ['nullable', 'array'],
            '*.relationship_verification_details.verification_comment' => ['nullable', 'string'],
            '*.relationship_verification_details.verification_reason' => ['nullable', 'string', 'max:255'],
            '*.relationship_verification_details.verification_status' => ['nullable', 'string', 'max:255']
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

     protected function validatePersonalData(EHealthResponse $response): array
    {
        $replaced = self::replaceEHealthPropNames($response->getData());

        $validator = Validator::make($replaced, [
            'addresses' => ['required', 'array', 'min:1'],
            'addresses.*.apartment' => ['nullable', 'string'],
            'addresses.*.area' => ['required', 'string'],
            'addresses.*.building' => ['nullable', 'string'],
            'addresses.*.country' => ['required', 'string'],
            'addresses.*.region' => ['nullable', 'string'],
            'addresses.*.settlement' => ['required', 'string'],
            'addresses.*.settlement_id' => ['required', 'string'],
            'addresses.*.settlement_type' => ['required', 'string'],
            'addresses.*.street' => ['nullable', 'string'],
            'addresses.*.street_type' => ['nullable', 'string'],
            'addresses.*.type' => ['required', 'string'],
            'addresses.*.zip' => ['nullable', 'string'],

            'birth_country' => ['required', 'string'],
            'birth_date' => ['required', 'date'],
            'birth_settlement' => ['required', 'string'],

            'confidant_person' => ['nullable', 'array'],
            'confidant_person.*.relation_type' => ['required', 'string', new Enum(RelationType::class)],
            'confidant_person.*.first_name' => ['required', 'string', 'max:255'],
            'confidant_person.*.last_name' => ['required', 'string', 'max:255'],
            'confidant_person.*.second_name' => ['nullable', 'string', 'max:255'],
            'confidant_person.*.birth_date' => ['required', 'date'],
            'confidant_person.*.birth_country' => ['required', 'string'],
            'confidant_person.*.birth_settlement' => ['required', 'string'],
            'confidant_person.*.gender' => ['required', new InDictionary('GENDER')],
            'confidant_person.*.email' => ['nullable', 'email'],
            'confidant_person.*.tax_id' => ['nullable', 'string', 'max:255'],
            'confidant_person.*.secret' => ['required', 'string', 'max:255'],
            'confidant_person.*.unzr' => ['nullable', 'string', 'max:255'],
            'confidant_person.*.preferred_way_communication' => ['nullable', 'string', 'max:255'],
            'confidant_person.*.documents_person' => ['required', 'array', 'min:1'],
            'confidant_person.*.documents_person.*.type' => ['required', 'string', 'nullable', new InDictionary('DOCUMENT_TYPE')],
            'confidant_person.*.documents_person.*.number' => ['required', 'string'],
            'confidant_person.*.documents_person.*.issued_by' => ['nullable', 'string'],
            'confidant_person.*.documents_person.*.issued_at' => ['nullable', 'date_format:Y-m-d'],
            'confidant_person.*.documents_person.*.expiration_date' => ['nullable', 'date_format:Y-m-d'],
            'confidant_person.*.documents_relationship' => ['required', 'array', 'min:1'],
            'confidant_person.*.documents_relationship.*.type' => ['required', 'string', 'nullable', new InDictionary('DOCUMENT_TYPE')],
            'confidant_person.*.documents_relationship.*.number' => ['required', 'string'],
            'confidant_person.*.documents_relationship.*.issued_by' => ['nullable', 'string'],
            'confidant_person.*.documents_relationship.*.issued_at' => ['nullable', 'date_format:Y-m-d'],
            'confidant_person.*.documents_relationship.*.active_to' => ['nullable', 'date_format:Y-m-d'],
            'confidant_person.*.phones' => ['nullable', 'array'],
            'confidant_person.*.phones.*.number' => ['required', 'string', 'max:255'],
            'confidant_person.*.phones.*.type' => ['required', 'string', new InDictionary('PHONE_TYPE')],

            'death_date' => ['nullable', 'date'],

            'documents' => ['required', 'array', 'min:1'],
            'documents.*.type' => ['required', 'string', 'nullable', new InDictionary('DOCUMENT_TYPE')],
            'documents.*.number' => ['required', 'string'],
            'documents.*.issued_by' => ['required', 'string'],
            'documents.*.issued_at' => ['required', 'date_format:Y-m-d'],
            'documents.*.expiration_date' => ['nullable', 'date_format:Y-m-d'],

            'email' => ['nullable', 'email'],

            'emergency_contact.first_name' => ['required', 'string', 'max:255'],
            'emergency_contact.last_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact.second_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact.phones' => ['required', 'array'],
            'emergency_contact.phones.*.number' => ['required', 'string', 'max:255'],
            'emergency_contact.phones.*.type' => ['required', 'string', new InDictionary('PHONE_TYPE')],

            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'second_name' => ['nullable', 'string', 'max:255'],

            'gender' => ['required', new InDictionary('GENDER')],
            'uuid' => ['required', 'uuid'],
            'no_tax_id' => ['required', 'boolean:strict'],

            'phones' => ['nullable', 'array'],
            'phones.*.number' => ['required', 'string', 'max:255'],
            'phones.*.type' => ['required', 'string', new InDictionary('PHONE_TYPE')],

            'preferred_way_communication' => ['nullable', 'string', 'max:255'],
            'secret' => ['required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:255'],
            'unzr' => ['nullable', 'string', 'max:255'],
            'verification_status' => ['required', new InDictionary('PERSON_VERIFICATION_STATUSES')],
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    protected function validateConfidantePersonRequests(EHealthResponse $response): array
    {
        return $this->validateConfidantRelationshipData($response, true);
    }

    /**
     * Replace eHealth property names with the ones used in the application.
     * E.g., id => uuid.
     */
    protected static function replaceEHealthPropNames(array $properties): array
    {
        $replaced = [];

        foreach ($properties as $key => $value) {
            $newKey = match ($key) {
                'id' => 'uuid',
                'ended_at' => 'ehealth_ended_at',
                default => $key
            };

            // Recursive for changing in confidant person id to uuid
            if (is_array($value)) {
                $replaced[$newKey] = self::replaceEHealthPropNames($value);
            } else {
                $replaced[$newKey] = $value;
            }
        }

        return $replaced;
    }

    /**
     * Map validated authentication methods to the application format.
     *
     * @param  array  $validated
     * @return array
     */
    protected function mapAuthMethods(array $validated): array
    {
        return $this->format($validated, ['ehealth_ended_at']);
    }

    private function validateConfidantRelationshipData(EHealthResponse $response, bool $isArray): array
    {
        $data = $response->getData();
        $replaced = self::replaceEHealthPropNames($data);

        $prefix = $isArray ? '*.' : '';
        $rules = [
            $prefix . 'uuid' => ['required', 'uuid'],
            $prefix . 'action' => ['required', 'string'],
            $prefix . 'status' => ['required', Rule::in(ConfidantPersonRelationshipRequestStatus::values())],
            $prefix . 'channel' => ['required', 'string']
        ];

        $validator = Validator::make($replaced, $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }
}
