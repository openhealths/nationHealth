<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;

class Job extends Request
{
    protected const string URL = '/api/jobs';

    /**
     * Used to get the processing status of the async job.
     *
     * @param  string  $uuid
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://medicaleventsmisapi.docs.apiary.io/#reference/medical-events/encounter-data-package/get-async-job-processing-details
     */
    public function getDetails(string $uuid, array $query = []): PromiseInterface|EHealthResponse
    {
        return $this->get(self::URL . "/$uuid", $query);
    }

    /**
     * Fetch job details by raw href returned by ESOZ links.
     * Supports both "/jobs/{id}" and "/api/jobs/{id}" style links.
     *
     * @param  string  $href
     * @param  array  $query
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getDetailsByHref(string $href, array $query = []): PromiseInterface|EHealthResponse
    {
        return $this->get($href, $query);
    }
}
