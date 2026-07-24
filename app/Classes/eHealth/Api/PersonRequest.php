<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Core\Arr;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Rules\Email;
use App\Rules\InDictionary;
use App\Rules\PhoneNumber;
use App\Rules\TaxId;
use App\Rules\Zip;
use App\Models\Person\Person as PersonModel;
use GuzzleHttp\Promise\PromiseInterface;
use App\Exceptions\EHealth\EHealthConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PersonRequest extends Request
{
    protected const string URL = '/api/person_requests';
    protected const string URL_V3 = '/api/v3/person_requests';

    /**
     * Create Person Request v3 (as part of Person creation w/o declaration process).
     *
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/person-requests/create/update-person-request-v3
     */
    public function create(array $data): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));
        $this->setMapper($this->mapResponse(...));

        $data = $this->format($data, ['birthDate', 'issuedAt', 'expirationDate', 'activeTo']);

        // no_tax_id is a required property that carries null for foreign documents without a tax_id;
        // removeEmptyKeys strips that null, so restore the key here to keep it in the payload.
        if (isset($data['person']) && !array_key_exists('no_tax_id', $data['person'])) {
            $data['person']['no_tax_id'] = null;
        }

        return $this->post(self::URL_V3, $data);
    }

    /**
     * Approve previously created Person Request v3.
     *
     * @param  string  $id
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/person-requests/approve-person-request-v3
     */
    public function approve(string $id, array $data = []): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::URL_V3 . "/$id/actions/approve", $data ?: (object)$data);
    }

    /**
     * Reject previously created Person Request v3.
     *
     * @param  string  $id
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/person-requests/reject-person-request-v3
     */
    public function reject(string $id): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::URL_V3 . "/$id/actions/reject");
    }

    /**
     * Sign approved previously created Person Request v3.
     *
     * @param  string  $id
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/person-requests/sign-person-request-v3
     */
    public function signed(string $id, array $data): PromiseInterface|EHealthResponse
    {
        return $this->patch(self::URL_V3 . "/$id/actions/sign", $data);
    }

    /**
     * Obtains details by ID.
     *
     * @param  string  $id
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/person-requests/get-person-request-by-id-v3
     */
    public function getById(string $id, array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));
        $this->setMapper($this->mapResponseById(...));

        return $this->get(self::URL_V3 . "/$id", $query);
    }

    /**
     * Obtains details by setting parameters like status, page, and page size.
     *
     * @param  array{status?: 'NEW'|'APPROVED'|'SIGNED'|'REJECTED'|'CANCELLED', page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/person-requests/get-person-requests-list-v3
     */
    public function getList(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query);

        return $this->get(self::URL_V3, $mergedQuery);
    }

    /**
     * Re-send SMS to a person who approve creating or updating data about himself.
     *
     * @param  string  $id
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/person-requests/resend-authorization-otp-on-person-request
     */
    public function resendAuthOtp(string $id, array $data = []): PromiseInterface|EHealthResponse
    {
        return $this->post(self::URL . "/$id/actions/resend_otp", $data);
    }

    /**
     * Validate response and rename keys.
     *
     * @param  EHealthResponse  $response
     * @return array
     */
    protected function validateResponse(EHealthResponse $response): array
    {
        $data = $response->getData();

        $replaced = self::replaceEHealthPropNames($data);

        $validator = Validator::make($replaced, [
            'uuid' => ['required', 'uuid'],
            'patient_signed' => ['required', 'boolean:strict'],
            'person.addresses' => ['required', 'array'],
            'person.addresses.*.type' => ['required', new InDictionary('ADDRESS_TYPE')],
            'person.addresses.*.country' => ['required', new InDictionary('COUNTRY')],
            'person.addresses.*.area' => ['required', 'string', 'max:255'],
            'person.addresses.*.region' => ['nullable', 'string', 'max:255'],
            'person.addresses.*.settlement' => ['required', 'string', 'max:255'],
            'person.addresses.*.settlement_id' => ['required', 'uuid'],
            'person.addresses.*.street_type' => ['nullable', new InDictionary('STREET_TYPE')],
            'person.addresses.*.building' => ['nullable', 'string', 'max:255'],
            'person.addresses.*.apartment' => ['nullable', 'string', 'max:255'],
            'person.addresses.*.zip' => ['nullable', new Zip()],
            'person.authentication_methods.*.type' => ['required', 'string', 'max:255'],
            'person.authentication_methods.*.phone_number' => ['nullable', new PhoneNumber()],
            'person.authentication_methods.*.value' => ['nullable', 'uuid'],
            'person.authentication_methods.*.alias' => ['nullable', 'string', 'max:255'],
            'person.birth_country' => ['required', 'string', 'max:255'],
            'person.birth_date' => ['required', 'date'],
            'person.birth_settlement' => ['required', 'string', 'max:255'],
            'person.documents' => ['required', 'array'],
            'person.documents.*.type' => ['required', new InDictionary('DOCUMENT_TYPE')],
            'person.documents.*.number' => ['required', 'string', 'max:255'],
            'person.documents.*.issued_by' => ['required', 'string', 'max:255'],
            'person.documents.*.issued_at' => ['required', 'date'],
            'person.documents.*.expiration_date' => ['nullable', 'date'],
            'person.emergency_contact.first_name' => ['required', 'string', 'max:255'],
            'person.emergency_contact.last_name' => ['required', 'string', 'max:255'],
            'person.emergency_contact.second_name' => ['nullable', 'string', 'max:255'],
            'person.emergency_contact.phones.*.type' => ['required', new InDictionary('PHONE_TYPE')],
            'person.emergency_contact.phones.*.number' => ['required', new PhoneNumber()],
            'person.names' => ['required', 'array', 'min:1'],
            'person.names.*.language' => ['required', 'string', 'max:255'],
            'person.names.*.first_name' => ['required', 'string', 'max:255'],
            'person.names.*.last_name' => ['nullable', 'string', 'max:255'],
            'person.names.*.second_name' => ['nullable', 'string', 'max:255'],
            'person.names.*.no_last_name' => ['required', 'boolean'],
            'person.gender' => ['required', new InDictionary('GENDER')],
            'person.email' => ['nullable', new Email()],
            'person.unzr' => ['nullable', 'string', 'max:255'],
            'person.no_tax_id' => ['present', 'nullable', 'boolean:strict'],
            'person.phones.*.type' => ['required', new InDictionary('PHONE_TYPE')],
            'person.phones.*.number' => ['required', new PhoneNumber()],
            'person.secret' => ['required', 'string', 'max:255'],
            'person.tax_id' => ['nullable', new TaxId()],
            'person.confidant_person' => ['sometimes', 'array'],
            'person.confidant_person.person_id' => ['sometimes', 'uuid'],
            'person.confidant_person.documents_relationship' => ['sometimes', 'array'],
            'person.confidant_person.documents_relationship.*.type' => [
                'sometimes',
                new InDictionary('DOCUMENT_RELATIONSHIP_TYPE')
            ],
            'person.confidant_person.documents_relationship.*.number' => ['sometimes', 'string', 'max:255'],
            'person.confidant_person.documents_relationship.*.issued_by' => ['sometimes', 'string', 'max:255'],
            'person.confidant_person.documents_relationship.*.issued_at' => ['sometimes', 'date'],
            'person.confidant_person.documents_relationship.*.active_to' => ['sometimes', 'date'],
            'process_disclosure_data_consent' => ['required', 'boolean:strict'],
            'status' => ['required', 'string', 'max:255']
        ]);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return $validator->validate();
    }

    /**
     * Move certain fields to person array.
     *
     * @param  array  $data
     * @return array
     */
    protected function mapResponse(array $data): array
    {
        $moveToPersonFields = ['uuid', 'status', 'process_disclosure_data_consent', 'patient_signed'];

        return ['person' => array_merge($data['person'], Arr::only($data, $moveToPersonFields))];
    }

    /**
     * Map response for create person by it.
     *
     * @param  array  $data
     * @return array
     */
    protected function mapResponseById(array $data): array
    {
        $moveToPersonFields = ['uuid', 'process_disclosure_data_consent', 'patient_signed'];

        if (isset($data['person']['confidant_person']['person_id'])) {
            $data['person']['confidant_person']['person_id'] =
                PersonModel::whereUuid($data['person']['confidant_person']['person_id'])->value('id');
        }

        return ['person' => array_merge($data['person'], Arr::only($data, $moveToPersonFields))];
    }

    /**
     * Replace eHealth property names with the ones used in the application.
     * E.g., id => uuid.
     */
    protected static function replaceEHealthPropNames(array $properties): array
    {
        $replaced = [];

        foreach ($properties as $name => $value) {
            $newName = match ($name) {
                'id' => 'uuid',
                default => $name
            };

            $replaced[$newName] = $value;
        }

        return $replaced;
    }
}
