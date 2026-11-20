<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\Api\Responses\Collections\EquipmentDetails;
use App\Classes\eHealth\Api\Responses\Collections\EquipmentGetMany;
use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\Errors\EquipmentErrorTranslator;
use App\Contracts\ApiErrorTranslator;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use App\Rules\InDictionary;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class Equipment extends Request
{
    protected const string URL = '/api/equipment';

    /**
     * Get list of equipments.
     *
     * @param  array{
     *     division_id?: string,
     *     device_definition_id?: string,
     *     type?: string, // Code from dictionary device_definition_classification_type
     *     status?: \App\Enums\Equipment\Status::class,
     *     model_number?: string,
     *     manufacturer?: string,
     *     availability_status?: \App\Enums\Equipment\AvailabilityStatus::class,
     *     recorder?: string,
     *     inventory_number?: string,
     *     serial_number?: string,
     *     name?: string,
     *     created_from?: string,
     *     created_to?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/equipment/get-equipment-list
     */
    public function getMany(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateMany(...));
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query);

        return $this->get(self::URL, $mergedQuery);
    }

    /**
     * Register equipment and bind it with division of legal entity.
     *
     * @param  array  $data  // Data for API request
     * @return EHealthResponse|PromiseInterface
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/equipment/create-equipment
     */
    public function create(array $data = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));

        return $this->post(self::URL, $data);
    }

    /**
     * Method to changes equipment status to inactive or entered_in_error.
     *
     * @param  string  $uuid
     * @param  array  $data
     * @return EHealthResponse|PromiseInterface
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/equipment/change-equipment-status
     */
    public function changeStatus(string $uuid, array $data): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));

        return $this->patch(self::URL . "/$uuid" . '/actions/change_status', $data);
    }

    /**
     * Method to change equipment availability status.
     *
     * @param  string  $uuid
     * @param  array  $data
     * @return EHealthResponse|PromiseInterface
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/equipment/change-equipment-availability-status
     */
    public function changeAvailabilityStatus(string $uuid, array $data): PromiseInterface|EHealthResponse
    {
        $this->setValidator($this->validateResponse(...));

        return $this->patch(self::URL . "/$uuid" . '/actions/change_availability_status', $data);
    }

    /**
     * Get the translator of the errors the equipment resource returns.
     *
     * @return ApiErrorTranslator
     */
    protected function errorTranslator(): ApiErrorTranslator
    {
        return new EquipmentErrorTranslator();
    }

    /**
     * Validate a single equipment response.
     */
    protected function validateResponse(EHealthResponse $response): EquipmentDetails
    {
        $validator = Validator::make($response->getData(), $this->validationRules());

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return new EquipmentDetails($validator->validate());
    }

    /**
     * Validate list of equipments.
     *
     * @param  EHealthResponse  $response
     * @return EquipmentGetMany
     */
    protected function validateMany(EHealthResponse $response): EquipmentGetMany
    {
        $rules = collect($this->validationRules())
            ->mapWithKeys(static fn ($rule, $key) => ["*.$key" => $rule])
            ->toArray();

        $validator = Validator::make($response->getData(), $rules);

        if ($validator->fails()) {
            Log::channel('e_health_errors')->error('Validation failed: ' . implode(', ', $validator->errors()->all()));
        }

        return new EquipmentGetMany($validator->validate());
    }

    /**
     * List of validation rules for equipment.
     *
     * @return array
     */
    protected function validationRules(): array
    {
        return [
            'availability_status' => ['required', 'string', new InDictionary('equipment_availability_statuses')],
            'device_definition_id' => ['nullable', 'uuid'],
            'division_id' => ['nullable', 'uuid'],
            'error_reason' => ['nullable', 'string', 'max:255'],
            'expiration_date' => ['nullable', 'date'],
            'id' => ['required', 'uuid'],
            'inserted_at' => ['required', 'date'],
            'inserted_by' => ['required', 'uuid'],
            'inventory_number' => ['nullable', 'string', 'max:255'],
            'legal_entity_id' => ['required', 'uuid'],
            'lot_number' => ['nullable', 'string', 'max:255'],
            'manufacture_date' => ['nullable', 'date'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model_number' => ['nullable', 'string', 'max:255'],
            'names' => ['required', 'array'],
            'names.*.name' => ['required', 'string', 'max:255'],
            'names.*.type' => ['required', 'string', new InDictionary('device_name_type')],
            'note' => ['nullable', 'string', 'max:1000'],
            'parent_id' => ['nullable', 'uuid'],
            'properties' => ['nullable', 'array'],
            'properties.*.type' => ['required', 'string', 'max:255', new InDictionary('device_properties')],
            'properties.*.value_integer' => ['nullable', 'integer:strict'],
            'properties.*.value_decimal' => ['nullable', 'decimal'],
            'properties.*.value_boolean' => ['nullable', 'boolean:strict'],
            'properties.*.value_string' => ['nullable', 'string', 'max:255'],
            'recorder' => ['required', 'uuid'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', new InDictionary('equipment_statuses')],
            'type' => ['required', 'string', 'max:255', new InDictionary('device_definition_classification_type')],
            'updated_at' => ['required', 'date'],
            'updated_by' => ['required', 'uuid']
        ];
    }
}
