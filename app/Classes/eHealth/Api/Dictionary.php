<?php

declare(strict_types=1);

namespace App\Classes\eHealth\Api;

use App\Classes\eHealth\EHealthRequest as Request;
use App\Classes\eHealth\EHealthResponse;
use App\Exceptions\EHealth\EHealthResponseException;
use App\Exceptions\EHealth\EHealthValidationException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;

class Dictionary extends Request
{
    protected const string URL = '/api/v2/dictionaries';

    /**
     * Each dictionary is an object that contains not only a code and description of a value, but also a status of the value.
     * In addition, it can represent hierarchical dictionaries with subordinate (child) values
     *
     * @param  array{name?: string, is_active?: bool, value_code?: string, value_description?: bool, value_is_active?: int}  $query
     * @return PromiseInterface|EHealthResponse
     * @throws ConnectionException|EHealthValidationException|EHealthResponseException
     *
     * @see https://uaehealthapi.docs.apiary.io/#reference/public.-medical-service-provider-integration-layer/dictionaries/get-dictionaries-v2
     */
    public function getMany(array $query = []): PromiseInterface|EHealthResponse
    {
        return $this->timeout(300)->get(self::URL, $query);
    }
}
