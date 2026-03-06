<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;

class Service extends Request
{
    protected const string URL = '/api/services';

    /**
     * This web service returns a catalog of services that could be submitted in eHealth. The catalog has a tree data structure.
     * Each node represents group of services (or subgroup), except end-node, that represents services themselves.
     * Maximum nesting level is 4.
     *
     * @param  array{code?: string, name?: string, page?: int, page_size?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-programs/medical-program/get-services-dictionary
     */
    public function getMany(array $query = []): PromiseInterface|EHealthResponse
    {
        $this->setDefaultPageSize();

        $mergedQuery = array_merge($this->options['query'], $query);

        return $this->get(self::URL, $mergedQuery);
    }
}
