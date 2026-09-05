<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Enums\Person\Gender;
use App\Enums\Preperson\Status;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Rules\InDictionary;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;

class Preperson extends Request
{
    protected const string URL = '/api/prepersons';

    /**
     * Create unidentified person.
     * There is one necessary field in payload - external_id which serves as identifier of unidentified person in external system.
     * Preperson has been created without using digital sign.
     *
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://ehealthmisapi1.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/preperson/create-preperson
     */
    public function create(array $data): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));

        return $this->post(self::URL, $data);
    }

    /**
     * Method enables to modify all the preperson fields except note and status.
     *
     * @param  string  $id
     * @param  array  $data
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://ehealthmisapi1.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/preperson/update-preperson
     */
    public function update(string $id, array $data): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));

        return $this->patch(self::URL . "/$id", $data);
    }

    /**
     * Returns preperson details by its MPI identifier.
     *
     * @param  string  $id
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://ehealthmisapi1.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/preperson/get-preperson-by-id
     */
    public function getById(string $id): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));

        return $this->get(self::URL . "/$id");
    }

    /**
     * Validate preperson response for create, update and details requests.
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
     * List of validation rules for preperson response fields.
     *
     * @return array
     */
    protected function validationRules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'external_id' => ['required', 'string', 'regex:/^[0-9]{8,10}\.[0-9]{8,10}\.[0-9]{1,10}$/'],
            'first_name' => ['nullable', 'string'],
            'last_name' => ['nullable', 'string'],
            'second_name' => ['nullable', 'string'],
            'gender' => ['nullable', new Enum(Gender::class)],
            'birth_date' => ['nullable', 'date'],
            'emergency_contact' => ['nullable', 'array'],
            'emergency_contact.first_name' => ['nullable', 'string'],
            'emergency_contact.last_name' => ['nullable', 'string'],
            'emergency_contact.second_name' => ['nullable', 'string'],
            'emergency_contact.phones' => ['nullable', 'array'],
            'emergency_contact.phones.*.type' => ['nullable', new InDictionary('PHONE_TYPE')],
            'emergency_contact.phones.*.number' => ['nullable', 'regex:/^\+[0-9]{11,12}$/'],
            'death_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
            'status' => ['required', new Enum(Status::class)],
            'ehealth_inserted_at' => ['required', 'date'],
            'ehealth_inserted_by' => ['required', 'uuid'],
            'ehealth_updated_at' => ['required', 'date'],
            'ehealth_updated_by' => ['required', 'uuid']
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
