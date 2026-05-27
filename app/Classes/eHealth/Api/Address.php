<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;

class Address extends Request
{
    protected const string URL = '/api/uaddresses';
    protected const int PAGE_DEFAULT_NUMBER = 1;
    protected const int PAGE_REGION_SIZE = 30;

    /**
     * Get list of regions by search params.
     *
     * @param  array{name?: string, koatuu?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/search-regions/list-of-regions-by-search-params
     */
    public function getRegions(array $query = []): PromiseInterface|EHealthResponse
    {
        $mergedQuery = array_merge(
            $this->options['query'] ?? [],
            [
                'page' => self::PAGE_DEFAULT_NUMBER,
                'page_size' => self::PAGE_REGION_SIZE
            ],
            $query
        );

        return $this->get(self::URL . '/regions', $mergedQuery);
    }

    /**
     * Get list of districts by search params.
     *
     * @param  array{region_id?: string, region?: string, name?: string, koatuu?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/search-districts/list-of-districts-by-search-params
     */
    public function getDistricts(array $query = []): PromiseInterface|EHealthResponse
    {
        $mergedQuery = array_merge(
            $this->options['query'] ?? [],
            [
                'page' => 1,
                'page_size' => 10
            ],
            $query
        );

        return $this->get(self::URL . '/districts', $mergedQuery);
    }

    /**
     * Use this method to obtain list of cities. If you want to reduce amount of data that is going through your application.
     * Use 'name', 'region', 'district' or 'koatuu classifier' filters and show only cities you are interested in.
     *
     * @param  array{name?: string, region?: string, district?: string, koatuu?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/search-settlements/list-of-cities-by-search-params
     */
    public function getSettlements(array $query = []): PromiseInterface|EHealthResponse
    {
        $mergedQuery = array_merge(
            $this->options['query'] ?? [],
            [
                'page' => 1,
                'page_size' => 10
            ],
            $query
        );

        return $this->get(self::URL . '/settlements', $mergedQuery);
    }

    /**
     * Get list of streets by search params.
     *
     * @param  array{settlement_id: string, name?: string, type?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/search-streets/list-of-streets-by-search-params
     */
    public function getStreets(array $query = []): PromiseInterface|EHealthResponse
    {
        $mergedQuery = array_merge(
            $this->options['query'] ?? [],
            [
                'page' => 1,
                'page_size' => 10
            ],
            $query
        );

        return $this->get(self::URL . '/streets', $mergedQuery);
    }
}
