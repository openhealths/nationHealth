<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;

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
        return $this->get(self::URL_METADATA, $query);
    }

    /**
     * Get configurations for compositions.
     *
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/configurations/get-composition-configurations-by-search-params
     */
    public function getCompositions(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        return $this->get(self::URL_COMPOSITIONS, $query);
    }

    /**
     * Get configurations for devices.
     *
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/configurations/get-devices-configurations-by-search-params
     */
    public function getDevices(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        return $this->get(self::URL_DEVICES, $query);
    }

    /**
     * Get configurations for devices parameters.
     *
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/configurations/get-devices-configurations-by-search-params
     */
    public function getDeviceParameters(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        return $this->get(self::URL_DEVICE_PARAMETERS, $query);
    }

    /**
     * Get configurations for dictionaries.
     *
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/configurations/get-devices-configurations-by-search-params
     */
    public function getDictionaries(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        return $this->get(self::URL_DICTIONARIES, $query);
    }

    /**
     * Get configurations for observations.
     *
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/configurations/get-devices-configurations-by-search-params
     */
    public function getObservations(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        return $this->get(self::URL_OBSERVATIONS, $query);
    }
}
