<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthConnectionException;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;

interface EHealthApi
{
    /**
     * Get a list of the resource records.
     *
     * @param  array  $query  Filter and pagination parameters of the list endpoint
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getMany(array $query = []): PromiseInterface|EHealthResponse;

    /**
     * Get a resource record by its eHealth identifier.
     *
     * @param  string  $id  Unique eHealth identifier
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function getById(string $id): PromiseInterface|EHealthResponse;

    /**
     * Create a resource record.
     *
     * @param  array  $data  Request payload
     * @return PromiseInterface|EHealthResponse
     * @throws EHealthConnectionException|EHealthValidationException|EHealthResponseException
     */
    public function create(array $data): PromiseInterface|EHealthResponse;
}
