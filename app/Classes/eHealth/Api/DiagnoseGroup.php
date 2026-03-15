<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;

class DiagnoseGroup extends EHealthRequest
{
    public const string URL = '/api/diagnoses_groups';

    /**
     * Receives a catalog of all active Groups of Diagnoses .
     *
     * @param  array{
     *     diagnoses_group_name?: string,
     *     diagnoses_group_code?: string,
     *     diagnosis_name?: string,
     *     diagnosis_code?: string,
     *     page?: int,
     *     page_size?: int
     * }  $filters
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://ehealthmisapi1.docs.apiary.io/#reference/public.-groups-of-diagnoses/get-groups-of-diagnoses-list/get-groups-of-diagnoses-list
     */
    public function getMany(array $filters = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $mergedQuery = array_merge(
            $this->options['query'] ?? [],
            $filters
        );

        return $this->get(self::URL, $mergedQuery);
    }

    /**
     * Returns Group of Diagnoses details filtered by ID with active diagnosis codes.
     *
     * @param  string  $uuid
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getDetails(string $uuid): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL . '/' . $uuid);
    }
}
