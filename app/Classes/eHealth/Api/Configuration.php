<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Classes\eHealth\SchemaResponseContract;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

class Configuration extends Request
{
    protected const string URL_METADATA = '/api/configurations/metadata';
    protected const string URL_COMPOSITIONS = '/api/composition_configurations';
    protected const string URL_DEVICES = '/api/devices_configurations';
    protected const string URL_DEVICE_PARAMETERS = '/api/device_parameters_configurations';
    protected const string URL_DICTIONARIES = '/api/dictionaries_configurations';
    protected const string URL_OBSERVATIONS = '/api/observation_configurations';

    /**
     * Used to get the last updated_at (the latest updated_at date of any configuration within a single array of configurations dates)
     *
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/configurations/get-configurations-metadata
     */
    public function getMetadata(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator(static fn (EHealthResponse $response): array => SchemaResponseContract::validate($response, 'GET /api/configurations/metadata'));

        return $this->get(self::URL_METADATA, $query);
    }

    /**
     * Get configurations for compositions.
     *
     * @param  array{
     *     type?: string,
     *     category?: string,
     *     is_active?: bool,
     *     inserted_at_from?: string,
     *     inserted_at_to?: string,
     *     updated_at_from?: string,
     *     updated_at_to?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/configurations/get-composition-configurations-by-search-params
     */
    public function getCompositions(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();
        $this->setValidator($this->validateCompositions(...));

        return $this->get(self::URL_COMPOSITIONS, $this->normalizeActiveFilter($query));
    }

    protected function validateCompositions(EHealthResponse $response): array
    {
        SchemaResponseContract::validate($response, 'GET /api/composition_configurations', 'composition');
        $body = $response->json();
        Validator::make(is_array($body) ? $body : [], [
            'data' => ['present', 'array', 'list'],
            'data.*' => ['required', 'array'],
            'data.*.id' => ['required', 'uuid'],
            'data.*.type' => ['required', 'array'],
            'data.*.type.coding' => ['required', 'array', 'min:1'],
            'data.*.type.coding.*.system' => ['required', 'string'],
            'data.*.type.coding.*.code' => ['required', 'string'],
            'data.*.category' => ['required', 'array'],
            'data.*.category.coding' => ['required', 'array', 'min:1'],
            'data.*.category.coding.*.system' => ['required', 'string'],
            'data.*.category.coding.*.code' => ['required', 'string'],
            'data.*.is_active' => ['required', 'boolean'],
            'data.*.settings' => ['required', 'string', 'json'],
            'data.*.inserted_at' => ['required', 'date'],
            'data.*.updated_at' => ['required', 'date'],
            'data.*.inserted_by' => ['required', 'uuid'],
            'data.*.updated_by' => ['required', 'uuid'],
            'paging' => ['required', 'array'],
            'paging.page_number' => ['required', 'integer', 'min:1'],
            'paging.page_size' => ['required', 'integer', 'min:1'],
            'paging.total_pages' => ['required', 'integer', 'min:0'],
            'paging.total_entries' => ['required', 'integer', 'min:0'],
        ])->validate();

        return array_map(static function (array $row): array {
            // Decode only after the complete native catalog envelope has passed validation.
            try {
                $settings = json_decode($row['settings'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw ValidationException::withMessages(['settings' => __('compositions.errors.invalid_response')]);
            }
            if (!is_array($settings) || ($settings !== [] && array_is_list($settings))
                || !is_object(json_decode($row['settings']))) {
                throw ValidationException::withMessages(['settings' => __('compositions.errors.invalid_response')]);
            }
            $row['decoded_settings'] = $settings;

            return $row;
        }, $body['data']);
    }

    /**
     * Get configurations for devices.
     *
     * @param  array{
     *     code?: string,
     *     system?: string,
     *     is_active?: bool,
     *     inserted_at_from?: string,
     *     inserted_at_to?: string,
     *     updated_at_from?: string,
     *     updated_at_to?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/configurations/get-devices-configurations-by-search-params
     */
    public function getDevices(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator(static fn (EHealthResponse $response): array => SchemaResponseContract::validate($response, 'GET /api/devices_configurations'));

        $this->setDefaultPageSize();

        return $this->get(self::URL_DEVICES, $this->normalizeActiveFilter($query));
    }

    /**
     * Get configurations for devices parameters.
     *
     * @param  array{
     *     code?: string,
     *     system?: string,
     *     is_active?: bool,
     *     inserted_at_from?: string,
     *     inserted_at_to?: string,
     *     updated_at_from?: string,
     *     updated_at_to?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/configurations/get-devices-configurations-by-search-params
     */
    public function getDeviceParameters(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator(static fn (EHealthResponse $response): array => SchemaResponseContract::validate($response, 'GET /api/device_parameters_configurations'));

        $this->setDefaultPageSize();

        return $this->get(self::URL_DEVICE_PARAMETERS, $this->normalizeActiveFilter($query));
    }

    /**
     * Get configurations for dictionaries.
     *
     * @param  array{
     *     name?: string,
     *     is_active?: bool,
     *     inserted_at_from?: string,
     *     inserted_at_to?: string,
     *     updated_at_from?: string,
     *     updated_at_to?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/configurations/get-devices-configurations-by-search-params
     */
    public function getDictionaries(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator(static fn (EHealthResponse $response): array => SchemaResponseContract::validate($response, 'GET /api/dictionaries_configurations'));

        $this->setDefaultPageSize();

        return $this->get(self::URL_DICTIONARIES, $this->normalizeActiveFilter($query));
    }

    /**
     * Get configurations for observations.
     *
     * @param  array{
     *     code?: string,
     *     system?: string,
     *     is_active?: bool,
     *     inserted_at_from?: string,
     *     inserted_at_to?: string,
     *     updated_at_from?: string,
     *     updated_at_to?: string,
     *     page?: int,
     *     page_size?: int
     * }  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/configurations/get-devices-configurations-by-search-params
     */
    public function getObservations(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setValidator(static fn (EHealthResponse $response): array => SchemaResponseContract::validate($response, 'GET /api/observation_configurations'));

        $this->setDefaultPageSize();

        return $this->get(self::URL_OBSERVATIONS, $query);
    }

}
